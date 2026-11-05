<?php

namespace App\Services\Contacts;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Operações explícitas do gestor: números individuais e revisão do formato. */
final class WhatsappManualPhoneV16
{
    /** @return array{queued:int,registered:int,skipped:int,batches:int} */
    public function queue(User $actor, string $raw): array
    {
        abort_unless($actor->isCommercialManager(), 403);
        $items = preg_split('/[\r\n;,]+/', trim($raw)) ?: [];
        $items = array_values(array_filter(array_map('trim', $items)));
        if (count($items) < 1 || count($items) > 10) {
            throw ValidationException::withMessages(['wa16_numbers' => 'Informe de 1 a 10 números, um em cada linha.']);
        }
        $numbers = [];
        foreach ($items as $rawNumber) {
            $normalized = BrazilPhoneNumber::inspect($rawNumber);
            if ($normalized === null || ! in_array($normalized['kind'], ['mobile', 'landline'], true)
                || ! preg_match('/^55\d{10,11}$/D', (string) $normalized['e164'])) {
                throw ValidationException::withMessages(['wa16_numbers' => 'Número inválido ou incompleto: '.mb_substr($rawNumber, 0, 25).'. Use Revisar formato antes de consultar.']);
            }
            $numbers[$normalized['e164']] = $normalized;
        }

        return DB::transaction(function () use ($actor, $numbers): array {
            // Serializa orçamento, agendador e pedidos pontuais usando a mesma linha de controle.
            $settings = DB::table('whatsapp_web_scan_settings')->where('id', 1)->lockForUpdate()->first();
            abort_unless($settings !== null, 503);
            $budget = app(WhatsappWebDailyCoordinator::class)->budget($settings);
            $agents = app(WhatsappWebControlPanel::class)->agents()->keyBy('session');
            $validAgents = [];
            foreach ([1, 2] as $session) {
                $agent = $agents->get($session);
                if ($agent && $agent->online
                    && ! ($agent->batch && in_array($agent->batch->status, ['queued', 'running'], true))
                    && $budget['sessions'][$session]['remaining'] > 0) {
                    $validAgents[$session] = true;
                }
            }
            if ($validAgents === []) {
                throw ValidationException::withMessages(['wa16_numbers' => 'Nenhum agente livre e conectado ou sem saldo diário. Conecte uma das contas.']);
            }
            $timestamp = now('UTC');
            $sourceScan = (string) (DB::table('whatsapp_phone_sources')->value('scan_id') ?? Str::uuid());
            $registered = 0;
            $groups = [1 => [], 2 => []];
            $skipped = 0;
            foreach ($numbers as $number) {
                $e164 = $number['e164'];
                // Não cria um novo contato fictício; somente um telefone do índice e origem informada pelo gestor.
                $existing = DB::table('whatsapp_phone_numbers')->where('phone_key', $number['key'])->first();
                if ($existing === null) {
                    DB::table('whatsapp_phone_numbers')->insertOrIgnore([
                        'phone_key' => $number['key'], 'digits' => $number['digits'], 'e164' => $e164,
                        'kind' => $number['kind'], 'created_at' => $timestamp, 'updated_at' => $timestamp,
                        'last_scanned_at' => $timestamp,
                    ]);
                    $existing = DB::table('whatsapp_phone_numbers')->where('phone_key', $number['key'])->first();
                    $registered++;
                }
                $phoneId = (int) $existing->id;
                if (! DB::table('whatsapp_phone_sources')->where('phone_id', $phoneId)->exists()) {
                    DB::table('whatsapp_manual_numbers_v16')->insertOrIgnore([
                        'e164' => $e164, 'created_by' => $actor->id, 'created_at' => $timestamp, 'updated_at' => $timestamp,
                    ]);
                    $manualId = DB::table('whatsapp_manual_numbers_v16')->where('e164', $e164)->value('id');
                    DB::table('whatsapp_phone_sources')->upsert([[
                        'phone_id' => $phoneId, 'source_key' => 'manual-entry:'.$manualId,
                        'origin' => 'manual', 'phone_field' => 'phone', 'raw_digits' => $number['digits'],
                        'contact_label' => 'Número informado na Central', 'company_label' => null,
                        'scan_id' => $sourceScan, 'created_at' => $timestamp, 'updated_at' => $timestamp,
                    ]], ['source_key', 'phone_field'], ['phone_id', 'scan_id', 'updated_at']);
                    DB::table('whatsapp_phone_numbers')->where('id', $phoneId)->update([
                        'contacts_count' => 1, 'occurrences_count' => 1, 'updated_at' => $timestamp,
                    ]);
                }
                $session = $phoneId % 2 === 0 ? 1 : 2;
                $latest = DB::table('whatsapp_web_probe_results')->where('phone_id', $phoneId)->first(['checked_at']);
                if ($existing->status !== 'unknown' || ($latest && Carbon::parse($latest->checked_at)->gt(now('UTC')->subDay()))) {
                    $skipped++;

                    continue;
                }
                $groups[$session][] = $phoneId;
            }
            $queued = 0;
            $batches = 0;
            foreach ([1, 2] as $session) {
                if (! isset($validAgents[$session]) || $groups[$session] === []) {
                    $skipped += count($groups[$session]);

                    continue;
                }
                $limit = (int) $budget['sessions'][$session]['remaining'];
                $targets = array_slice($groups[$session], 0, $limit);
                $skipped += count($groups[$session]) - count($targets);
                if ($targets === []) {
                    continue;
                }
                DB::table('whatsapp_web_scan_batches')->insert([
                    'session' => $session, 'requested_by' => $actor->id,
                    'requested_limit' => count($targets), 'status' => 'queued',
                    'scan_scope' => 'all', 'target_phone_ids' => json_encode($targets, JSON_THROW_ON_ERROR),
                    'created_at' => $timestamp, 'updated_at' => $timestamp,
                ]);
                $queued += count($targets);
                $batches++;
            }
            if ($queued === 0) {
                throw ValidationException::withMessages(['wa16_numbers' => 'Nenhum número apto: já confirmado, consultado hoje ou pertence a uma conta desconectada.']);
            }

            return ['queued' => $queued, 'registered' => $registered, 'skipped' => $skipped, 'batches' => $batches];
        }, 2);
    }

    public function review(User $actor, int $phoneId, string $decision, ?string $corrected = null, ?string $note = null): void
    {
        abort_unless($actor->isCommercialManager(), 403);
        if (! in_array($decision, ['corrected', 'invalid'], true)) {
            abort(422);
        }
        $normalized = $decision === 'corrected' ? BrazilPhoneNumber::inspect($corrected) : null;
        if ($decision === 'corrected' && ($normalized === null || $normalized['e164'] === null
            || ! in_array($normalized['kind'], ['mobile', 'landline'], true))) {
            throw ValidationException::withMessages(['wa16_format' => 'A correção exige número completo com DDD correto. Não inventamos o nono dígito.']);
        }
        DB::transaction(static function () use ($actor, $phoneId, $decision, $normalized, $note): void {
            $original = DB::table('whatsapp_phone_numbers')->where('id', $phoneId)->lockForUpdate()->first();
            abort_unless($original !== null && $original->kind === 'review', 404);
            $when = now('UTC');
            DB::table('whatsapp_phone_format_reviews_v16')->upsert([[
                'original_phone_id' => $phoneId, 'decision' => $decision,
                'corrected_e164' => $normalized['e164'] ?? null,
                'note' => mb_substr(trim((string) $note), 0, 300), 'reviewed_by' => $actor->id,
                'created_at' => $when, 'updated_at' => $when,
            ]], ['original_phone_id'], ['decision', 'corrected_e164', 'note', 'reviewed_by', 'updated_at']);
            if ($decision === 'corrected') {
                DB::table('whatsapp_manual_numbers_v16')->insertOrIgnore([
                    'e164' => $normalized['e164'], 'created_by' => $actor->id,
                    'created_at' => $when, 'updated_at' => $when,
                ]);
                // Adiciona imediatamente a versão corrigida ao índice, preservando
                // o registro original para auditoria e sem modificar Receita/HubSpot.
                DB::table('whatsapp_phone_numbers')->upsert([[
                    'phone_key' => $normalized['key'], 'digits' => $normalized['digits'],
                    'e164' => $normalized['e164'], 'kind' => $normalized['kind'],
                    'created_at' => $when, 'updated_at' => $when,
                    'last_scanned_at' => $when,
                ]], ['phone_key'], ['digits', 'e164', 'kind', 'last_scanned_at', 'updated_at']);
                $newId = (int) DB::table('whatsapp_phone_numbers')
                    ->where('phone_key', $normalized['key'])->value('id');
                $manualId = (int) DB::table('whatsapp_manual_numbers_v16')
                    ->where('e164', $normalized['e164'])->value('id');
                $source = DB::table('whatsapp_phone_sources')->where('phone_id', $phoneId)
                    ->first(['company_label']);
                $scan = (string) (DB::table('whatsapp_phone_sources')->value('scan_id') ?? Str::uuid());
                DB::table('whatsapp_phone_sources')->upsert([[
                    'source_key' => 'manual-entry:'.$manualId, 'phone_field' => 'phone',
                    'phone_id' => $newId, 'origin' => 'manual',
                    'raw_digits' => $normalized['digits'],
                    'contact_label' => 'Formato corrigido pelo gestor',
                    'company_label' => $source?->company_label,
                    'scan_id' => $scan, 'created_at' => $when, 'updated_at' => $when,
                ]], ['source_key', 'phone_field'], ['phone_id', 'scan_id', 'updated_at']);
                // Atualização agregada apenas para um telefone, sem N+1.
                DB::table('whatsapp_phone_numbers')->where('id', $newId)->update([
                    'contacts_count' => DB::table('whatsapp_phone_sources')->where('phone_id', $newId)
                        ->distinct('source_key')->count('source_key'),
                    'occurrences_count' => DB::table('whatsapp_phone_sources')->where('phone_id', $newId)->count(),
                    'updated_at' => $when,
                ]);
            }
        });
    }
}
