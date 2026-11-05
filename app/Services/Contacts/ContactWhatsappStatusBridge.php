<?php

namespace App\Services\Contacts;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Ponte bidirecional: Contatos -> índice de telefones únicos -> Contatos.
 * Todas as alterações de status são locais, atômicas e têm rastro de auditoria.
 * Nunca consulta WhatsApp ou HubSpot externo, não envia mensagens.
 */
final class ContactWhatsappStatusBridge
{
    public function change(User $actor, string $sourceKey, string $digits, string $field, string $status): void
    {
        abort_unless($actor->isCommercialManager() || $actor->isCommercialSeller(), 403);
        abort_unless(in_array($field, ['phone', 'mobile_phone'], true), 422);
        abort_unless(in_array($status, ['yes', 'no', 'unknown'], true), 422);

        $digits = ContactDirectory::digits($digits);
        $parsed = BrazilPhoneNumber::inspect($digits);
        if ($parsed === null || $digits === '') {
            throw ValidationException::withMessages(['contact' => 'Número vazio ou inválido.']);
        }
        if ($parsed['kind'] === 'review' && $status !== 'unknown') {
            throw ValidationException::withMessages([
                'contact' => 'Este número precisa de correção de formato antes da confirmação. Abra Verificação WhatsApp para conferir a orientação.',
            ]);
        }

        // Não aceitar fonte arbitrária nem um número diferente do gravado na origem.
        $source = $this->trustedSource($sourceKey, $field, $digits);
        abort_unless($source !== null, 409);

        DB::transaction(function () use ($actor, $sourceKey, $digits, $field, $status, $parsed, $source): void {
            // Mesmo lock global da indexação V1 para evitar corrida com o job diário.
            if (DB::getDriverName() === 'pgsql') {
                DB::select('SELECT pg_advisory_xact_lock(27041, 917)');
            }
            $now = now('UTC');

            // O índice é incremental: números novos aparecem imediatamente na Central.
            DB::table('whatsapp_phone_numbers')->upsert([[
                'phone_key' => $parsed['key'],
                'digits' => $digits,
                'e164' => $parsed['e164'],
                'kind' => $parsed['kind'],
                'created_at' => $now,
                'updated_at' => $now,
            ]], ['phone_key'], ['updated_at']);

            $phone = DB::table('whatsapp_phone_numbers')
                ->where('phone_key', $parsed['key'])->lockForUpdate()->first();
            if ($phone === null) {
                throw new \RuntimeException('Telefone não encontrado após a inclusão no índice.');
            }

            $formerId = DB::table('whatsapp_phone_sources')
                ->where('source_key', $sourceKey)->where('phone_field', $field)->value('phone_id');
            $scanId = DB::table('whatsapp_phone_sources')
                ->where('source_key', $sourceKey)->where('phone_field', $field)->value('scan_id');

            DB::table('whatsapp_phone_sources')->upsert([[
                'source_key' => $sourceKey,
                'phone_id' => $phone->id,
                'origin' => $source['origin'],
                'phone_field' => $field,
                'raw_digits' => $digits,
                'contact_label' => mb_substr($source['name'], 0, 180),
                'company_label' => $source['company'] !== null ? mb_substr($source['company'], 0, 180) : null,
                'scan_id' => $scanId ?: (string) Str::uuid(),
                'created_at' => $now,
                'updated_at' => $now,
            ]], ['source_key', 'phone_field'], [
                'phone_id', 'origin', 'raw_digits', 'contact_label', 'company_label', 'updated_at',
            ]);

            $this->refreshCounts((int) $phone->id);
            if ($formerId !== null && (int) $formerId !== (int) $phone->id) {
                $this->refreshCounts((int) $formerId);
            }

            if ($phone->status !== $status) {
                $evidence = $status === 'unknown' ? 'directory_manual_reset' : 'directory_manual_check';
                DB::table('whatsapp_phone_numbers')->where('id', $phone->id)->update([
                    'status' => $status,
                    'evidence' => $status === 'unknown' ? null : $evidence,
                    'verified_at' => $status === 'unknown' ? null : $now,
                    'verified_by' => $status === 'unknown' ? null : $actor->id,
                    'updated_at' => $now,
                ]);
                DB::table('whatsapp_phone_events')->insert([
                    'phone_id' => $phone->id,
                    'actor_id' => $actor->id,
                    'old_status' => $phone->status,
                    'new_status' => $status,
                    'evidence' => $evidence,
                    'note' => 'Marcado manualmente na tela Contatos; sem consulta automática ao WhatsApp.',
                    'created_at' => $now,
                ]);
            }

            // Propaga o mesmo resultado aos números ORIGINAIS de todas as fontes.
            // A tela Contatos busca exatamente source_key + dígitos do campo original.
            $references = DB::table('whatsapp_phone_sources')
                ->where('phone_id', $phone->id)
                ->select('source_key', 'raw_digits')->distinct()->get();
            if ($status === 'unknown') {
                foreach ($references->chunk(100) as $batch) {
                    DB::table('contact_phone_verifications')->where(static function ($query) use ($batch): void {
                        foreach ($batch as $ref) {
                            $query->orWhere(static function ($pair) use ($ref): void {
                                $pair->where('source_key', $ref->source_key)
                                    ->where('phone_normalized', $ref->raw_digits);
                            });
                        }
                    })->delete();
                }
            } else {
                foreach ($references->chunk(300) as $batch) {
                    $rows = [];
                    foreach ($batch as $ref) {
                        $rows[] = [
                            'source_key' => $ref->source_key,
                            'phone_normalized' => $ref->raw_digits,
                            'whatsapp_status' => $status,
                            'verified_by' => $actor->id,
                            'verified_at' => $now,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];
                    }
                    DB::table('contact_phone_verifications')->upsert(
                        $rows, ['source_key', 'phone_normalized'],
                        ['whatsapp_status', 'verified_by', 'verified_at', 'updated_at'],
                    );
                }
            }
        }, 2);
    }

    private function refreshCounts(int $id): void
    {
        DB::table('whatsapp_phone_numbers')->where('id', $id)->update([
            'contacts_count' => DB::table('whatsapp_phone_sources')
                ->where('phone_id', $id)->distinct()->count('source_key'),
            'occurrences_count' => DB::table('whatsapp_phone_sources')
                ->where('phone_id', $id)->count(),
            'updated_at' => now('UTC'),
        ]);
    }

    /** @return array{origin:string,name:string,company:?string}|null */
    private function trustedSource(string $sourceKey, string $field, string $digits): ?array
    {
        if (str_starts_with($sourceKey, 'hubspot:')) {
            $id = substr($sourceKey, 8);
            $row = DB::table('hubspot_contacts')->where('hubspot_id', $id)
                ->select('first_name', 'last_name', 'company_name', $field)->first();
            if ($row !== null && ContactDirectory::digits($row->{$field}) === $digits) {
                return [
                    'origin' => 'hubspot',
                    'name' => trim((string) $row->first_name.' '.(string) $row->last_name) ?: 'Contato HubSpot',
                    'company' => $row->company_name,
                ];
            }
        }
        if (preg_match('/^manual:(\d+)$/D', $sourceKey, $matches) === 1) {
            $row = DB::table('commercial_contacts as m')
                ->leftJoin('companies as c', 'c.id', '=', 'm.company_id')
                ->where('m.id', (int) $matches[1])
                ->select('m.name', 'c.corporate_name', 'm.'.$field.' as phone_value')->first();
            if ($row !== null && ContactDirectory::digits($row->phone_value) === $digits) {
                return ['origin' => 'manual', 'name' => $row->name, 'company' => $row->corporate_name];
            }
        }
        if (str_starts_with($sourceKey, 'receita:')) {
            $id = substr($sourceKey, 8);
            $column = $field === 'phone' ? 'phone_1' : 'phone_2';
            $row = DB::table('establishments as e')
                ->join('companies as c', 'c.id', '=', 'e.company_id')
                ->where('e.uuid', $id)
                ->select('e.type', 'c.corporate_name', 'e.'.$column.' as phone_value')->first();
            if ($row !== null && ContactDirectory::digits($row->phone_value) === $digits) {
                return [
                    'origin' => 'receita',
                    'name' => $row->type === 'matrix' ? 'Canal Receita — Matriz' : 'Canal Receita — Filial',
                    'company' => $row->corporate_name,
                ];
            }
        }

        return null;
    }
}
