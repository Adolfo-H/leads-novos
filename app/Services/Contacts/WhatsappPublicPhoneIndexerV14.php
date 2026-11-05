<?php

namespace App\Services\Contacts;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Uma evidência publicada vira CANAL institucional, jamais uma pessoa ou comprovação de conta ativa. */
final class WhatsappPublicPhoneIndexerV14
{
    /** @return array{channels:int,numbers:int} */
    public function syncDomain(string $domain): array
    {
        $domain = SafePublicWhatsappWebsiteFetcher::domain($domain);
        if ($domain === null) {
            return ['channels' => 0, 'numbers' => 0];
        }

        return DB::transaction(function () use ($domain): array {
            $items = DB::table('whatsapp_public_company_channels as c')
                ->join('whatsapp_public_site_evidences as e', 'e.id', '=', 'c.evidence_id')
                ->join('hubspot_companies as h', 'h.id', '=', 'c.hubspot_company_id')
                ->where('e.domain', $domain)
                ->select('c.id', 'c.e164', 'h.name as company_name')->get();

            $phones = [];
            $links = [];
            $now = now('UTC');
            $scan = (string) (DB::table('whatsapp_phone_sources')->value('scan_id') ?? Str::uuid());
            foreach ($items as $channel) {
                $info = BrazilPhoneNumber::inspect((string) $channel->e164);
                if ($info === null || $info['e164'] === null) {
                    continue;
                }
                $phones[$info['key']] = [
                    'phone_key' => $info['key'], 'digits' => $info['digits'], 'e164' => $info['e164'],
                    'kind' => $info['kind'], 'last_scanned_at' => $now, 'created_at' => $now, 'updated_at' => $now,
                ];
                $links[] = ['phone_key' => $info['key'], 'channel_id' => (int) $channel->id,
                    'raw_digits' => $info['digits'], 'company_name' => mb_substr((string) $channel->company_name, 0, 180)];
            }
            foreach (array_chunk(array_values($phones), 200) as $chunk) {
                DB::table('whatsapp_phone_numbers')->upsert($chunk, ['phone_key'],
                    ['digits', 'e164', 'kind', 'last_scanned_at', 'updated_at']);
            }
            $ids = [];
            foreach (array_chunk(array_keys($phones), 200) as $keys) {
                foreach (DB::table('whatsapp_phone_numbers')->whereIn('phone_key', $keys)->pluck('id', 'phone_key') as $key => $id) {
                    $ids[(string) $key] = (int) $id;
                }
            }
            $sourceRows = [];
            foreach ($links as $link) {
                $sourceRows[] = [
                    'source_key' => 'public:'.$link['channel_id'], 'phone_field' => 'phone',
                    'phone_id' => $ids[$link['phone_key']], 'origin' => 'public',
                    'raw_digits' => $link['raw_digits'], 'contact_label' => 'WhatsApp público empresarial',
                    'company_label' => $link['company_name'], 'scan_id' => $scan,
                    'created_at' => $now, 'updated_at' => $now,
                ];
            }
            foreach (array_chunk($sourceRows, 200) as $chunk) {
                DB::table('whatsapp_phone_sources')->upsert($chunk, ['source_key', 'phone_field'],
                    ['phone_id', 'origin', 'raw_digits', 'contact_label', 'company_label', 'scan_id', 'updated_at']);
            }
            $uniqueIds = array_values(array_unique(array_values($ids)));
            // Uma atualização set-based por lote, sem UPDATE/COUNT em loop (evita N+1).
            foreach (array_chunk($uniqueIds, 200) as $batch) {
                $placeholders = implode(',', array_fill(0, count($batch), '?'));
                DB::update("UPDATE whatsapp_phone_numbers
                    SET contacts_count = (SELECT COUNT(DISTINCT s.source_key)
                        FROM whatsapp_phone_sources s WHERE s.phone_id = whatsapp_phone_numbers.id),
                        occurrences_count = (SELECT COUNT(*)
                        FROM whatsapp_phone_sources s WHERE s.phone_id = whatsapp_phone_numbers.id)
                    WHERE id IN ($placeholders)", $batch);
            }
            // Relaciona evidências já salvas ao número único sem presumir vínculo fiscal.
            if ($uniqueIds !== []) {
                DB::update('UPDATE whatsapp_public_site_evidences
                    SET global_phone_id = (SELECT n.id FROM whatsapp_phone_numbers n
                        WHERE n.e164 = whatsapp_public_site_evidences.e164 LIMIT 1),
                        updated_at = ?
                    WHERE domain = ? AND e164 IN (SELECT e164 FROM whatsapp_phone_numbers
                        WHERE id IN ('.implode(',', array_fill(0, count($uniqueIds), '?')).'))',
                    array_merge([$now, $domain], $uniqueIds));
            }

            return ['channels' => count($sourceRows), 'numbers' => count($uniqueIds)];
        });
    }
}
