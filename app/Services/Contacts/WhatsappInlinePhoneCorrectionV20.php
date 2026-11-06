<?php

namespace App\Services\Contacts;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Revisão pontual de formato diretamente no diretório WhatsApp.
 * Altera apenas o índice local e os vínculos nele contidos, sem sobrescrever as
 * origens Receita/HubSpot, sem consultar WhatsApp e sem marcar contas como ativas.
 */
final class WhatsappInlinePhoneCorrectionV20
{
    /** Retorna a quantidade de associações do índice migradas. */
    public function correct(User $actor, int $phoneId, string $corrected, string $note = ''): int
    {
        abort_unless($actor->isCommercialManager(), 403);

        $info = BrazilPhoneNumber::inspect($corrected);
        if ($info === null || $info['e164'] === null
            || ! in_array($info['kind'], ['mobile', 'landline'], true)) {
            throw ValidationException::withMessages([
                'inlineCorrectedPhone' => 'Informe um telefone completo e válido, com DDD. Não inferimos DDD ou nono dígito.',
            ]);
        }
        $e164 = (string) $info['e164'];
        $note = trim(mb_substr($note, 0, 300));

        $moved = (int) DB::transaction(function () use ($actor, $phoneId, $e164, $info, $note): int {
            // A fila usa IDs do índice; não remapear durante uma consulta ativa.
            if (DB::table('whatsapp_web_scan_batches')->whereIn('status', ['queued', 'running'])->exists()) {
                throw ValidationException::withMessages([
                    'inlineCorrectedPhone' => 'Há consultas em andamento. Finalize os lotes antes de corrigir os vínculos.',
                ]);
            }

            $original = DB::table('whatsapp_phone_numbers')->where('id', $phoneId)->lockForUpdate()->first();
            abort_unless($original !== null, 404);
            if ($original->kind !== 'review' || $original->status !== 'unknown'
                || (int) $original->contacts_count < 1) {
                throw ValidationException::withMessages([
                    'inlineCorrectedPhone' => 'Esse telefone não está mais pendente de revisão. Atualize a lista.',
                ]);
            }
            if (DB::table('whatsapp_phone_auto_corrections_v18')
                ->where('original_phone_id', $phoneId)->exists()
                || DB::table('whatsapp_phone_format_reviews_v16')
                    ->where('original_phone_id', $phoneId)->exists()) {
                throw ValidationException::withMessages([
                    'inlineCorrectedPhone' => 'Já existe uma decisão registrada para este telefone. Atualize a lista.',
                ]);
            }

            // Não reaproveitar resultados de verificação nem da origem nem do destino.
            $destination = DB::table('whatsapp_phone_numbers')
                ->where('e164', $e164)->orderBy('id')->lockForUpdate()->first();
            $ids = [$phoneId];
            if ($destination !== null) {
                $ids[] = (int) $destination->id;
                if ((string) $destination->phone_key !== $e164
                    || $destination->status !== 'unknown'
                    || trim((string) ($destination->evidence ?? '')) !== '') {
                    throw ValidationException::withMessages([
                        'inlineCorrectedPhone' => 'O telefone de destino já tem identificação ou confirmação própria. Não é seguro unir os vínculos.',
                    ]);
                }
            }
            if (DB::table('whatsapp_web_probe_results')->whereIn('phone_id', $ids)->exists()
                || DB::table('whatsapp_phone_events')->whereIn('phone_id', $ids)
                    ->whereIn('new_status', ['yes', 'no'])->exists()
                || DB::table('contact_phone_verifications as v')
                    ->join('whatsapp_phone_sources as s', static function ($join): void {
                        $join->on('s.source_key', '=', 'v.source_key')
                            ->on('s.raw_digits', '=', 'v.phone_normalized');
                    })->whereIn('s.phone_id', $ids)
                    ->whereIn('v.whatsapp_status', ['yes', 'no'])->exists()) {
                throw ValidationException::withMessages([
                    'inlineCorrectedPhone' => 'O telefone antigo ou o destino já possui consulta/confirmação. Não serão misturados automaticamente.',
                ]);
            }

            $now = now('UTC');
            if ($destination === null) {
                DB::table('whatsapp_phone_numbers')->insertOrIgnore([
                    'phone_key' => $e164,
                    'digits' => (string) $info['digits'],
                    'e164' => $e164,
                    'kind' => (string) $info['kind'],
                    'status' => 'unknown',
                    'last_scanned_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $destination = DB::table('whatsapp_phone_numbers')
                    ->where('phone_key', $e164)->lockForUpdate()->first();
                if ($destination === null || (string) $destination->e164 !== $e164
                    || $destination->status !== 'unknown') {
                    throw ValidationException::withMessages([
                        'inlineCorrectedPhone' => 'Não foi possível preparar o número corrigido com segurança.',
                    ]);
                }
            }
            $targetId = (int) $destination->id;

            // O mapeamento torna a correção persistente entre futuras sincronizações.
            DB::table('whatsapp_phone_auto_corrections_v18')->insert([
                'original_phone_id' => $phoneId,
                'original_phone_key' => (string) $original->phone_key,
                'original_digits' => (string) $original->digits,
                'corrected_e164' => $e164,
                'rule' => 'reviewed_in_directory_v20',
                'applied_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            DB::table('whatsapp_phone_format_reviews_v16')->insert([
                'original_phone_id' => $phoneId,
                'decision' => 'corrected',
                'corrected_e164' => $e164,
                'note' => $note,
                'reviewed_by' => $actor->id,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            // Uma única atualização SQL preserva as fontes e os dígitos originais.
            $moved = DB::table('whatsapp_phone_sources')
                ->where('phone_id', $phoneId)
                ->update(['phone_id' => $targetId, 'updated_at' => $now]);

            foreach ([$phoneId, $targetId] as $id) {
                $sources = DB::table('whatsapp_phone_sources')->where('phone_id', $id);
                DB::table('whatsapp_phone_numbers')->where('id', $id)->update([
                    'contacts_count' => (clone $sources)->distinct()->count('source_key'),
                    'occurrences_count' => (clone $sources)->count(),
                    'updated_at' => $now,
                ]);
            }

            return (int) $moved;
        }, 2);

        app(ContactPeopleDirectory::class)->clearCachedTotals();

        return $moved;
    }

    public function invalid(User $actor, int $phoneId, string $note = ''): void
    {
        app(WhatsappManualPhoneV16::class)->review($actor, $phoneId, 'invalid', null, $note);
    }
}
