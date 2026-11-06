<?php

namespace App\Services\Contacts;

use Illuminate\Support\Facades\DB;

/**
 * Corrige APENAS o índice local, mediante confirmação explícita do operador.
 * Não edita Receita/HubSpot, não confirma WhatsApp e preserva os dígitos originais.
 * Telefones de oito dígitos com DDD produzem hipóteses, não prova de titularidade.
 * V19: quando explicitamente solicitado, reutiliza somente destino canônico
 * sem verificação ou consulta registrada. Nunca confirma o número automaticamente.
 */
final class WhatsappFormatAutoCorrectionV18
{
    /**
     * @return array{reviewed:int,candidates:int,eligible:int,shared:int,conflict:int,already:int,applied:int,examples:list<string>}
     */
    public function execute(bool $apply = false, int $maxLinks = 5, bool $mergeExistingUnknown = false): array
    {
        $maxLinks = max(1, min(100, $maxLinks));
        // Uma reconciliação de contatos não deve competir com consulta WhatsApp ativa.
        if ($apply && DB::table('whatsapp_web_scan_batches')
            ->whereIn('status', ['queued', 'running'])->exists()) {
            throw new \RuntimeException('Existe consulta WhatsApp pendente ou em andamento. Finalize-a antes de atualizar vínculos.');
        }
        $result = [
            'reviewed' => 0,
            'candidates' => 0,
            'eligible' => 0,
            'shared' => 0,
            'conflict' => 0,
            'already' => 0,
            'applied' => 0,
            'examples' => [],
        ];

        DB::table('whatsapp_phone_numbers as n')
            ->leftJoin('whatsapp_phone_format_reviews_v16 as review', 'review.original_phone_id', '=', 'n.id')
            ->leftJoin('whatsapp_phone_auto_corrections_v18 as auto', 'auto.original_phone_id', '=', 'n.id')
            ->where('n.kind', 'review')
            ->where('n.status', 'unknown')
            ->where('n.contacts_count', '>', 0)
            ->whereNull('review.id')
            ->select(['n.id', 'n.phone_key', 'n.digits', 'n.contacts_count', 'auto.id as auto_id'])
            ->chunkById(250, function ($batch) use ($apply, $maxLinks, $mergeExistingUnknown, &$result): void {
                $result['reviewed'] += $batch->count();
                $candidates = [];
                foreach ($batch as $row) {
                    $e164 = BrazilPhoneNumber::legacyMobileCandidate((string) $row->digits);
                    if ($e164 === null) {
                        continue;
                    }
                    $result['candidates']++;
                    if ($row->auto_id !== null) {
                        $result['already']++;

                        continue;
                    }
                    // Um número antigo compartilhado por muitas empresas é suspeito.
                    // Não migra esses vínculos silenciosamente.
                    if ((int) $row->contacts_count > $maxLinks) {
                        $result['shared']++;

                        continue;
                    }
                    $candidates[(int) $row->id] = ['row' => $row, 'e164' => $e164];
                }
                if ($candidates === []) {
                    return;
                }

                $candidateE164 = array_values(array_unique(array_column($candidates, 'e164')));
                $known = DB::table('whatsapp_phone_numbers')
                    ->whereIn('e164', $candidateE164)
                    ->get(['id', 'phone_key', 'e164', 'status', 'evidence', 'contacts_count'])
                    ->keyBy('e164');
                $idsToCheck = array_values(array_unique(array_merge(
                    array_keys($candidates),
                    $known->pluck('id')->map(static fn ($id): int => (int) $id)->all(),
                )));
                // A proteção vale para o número antigo E para o destino já indexado.
                $probed = DB::table('whatsapp_web_probe_results')
                    ->whereIn('phone_id', $idsToCheck)
                    ->pluck('phone_id')->flip();
                $humanEvents = DB::table('whatsapp_phone_events')
                    ->whereIn('phone_id', $idsToCheck)
                    ->whereIn('new_status', ['yes', 'no'])
                    ->pluck('phone_id')->flip();
                // A compatibilidade com verificações antigas impede reatribuição
                // mesmo quando o status consolidado ainda aparece como unknown.
                $legacyVerified = DB::table('contact_phone_verifications as v')
                    ->join('whatsapp_phone_sources as s', static function ($join): void {
                        $join->on('s.source_key', '=', 'v.source_key')
                            ->on('s.raw_digits', '=', 'v.phone_normalized');
                    })
                    ->whereIn('s.phone_id', $idsToCheck)
                    ->whereIn('v.whatsapp_status', ['yes', 'no'])
                    ->distinct()->pluck('s.phone_id')->flip();
                $now = now('UTC');
                $changes = [];
                foreach ($candidates as $id => $candidate) {
                    $dest = $known->get($candidate['e164']);
                    // Só conciliamos números já indexados por decisão EXPLÍCITA.
                    // O destino precisa ser o telefone canônico e não ter consulta,
                    // confirmação atual ou histórico de confirmação.
                    $destId = (int) ($dest->id ?? 0);
                    $oldBlocked = $probed->has($id)
                        || $humanEvents->has($id) || $legacyVerified->has($id);
                    $destBlocked = $dest !== null && (
                        (string) $dest->phone_key !== $candidate['e164']
                        || (string) $dest->status !== 'unknown'
                        || ((string) ($dest->evidence ?? '')) !== ''
                        || $probed->has($destId)
                        || $humanEvents->has($destId)
                        || $legacyVerified->has($destId)
                        || ((int) $dest->contacts_count > 0 && (
                            ! $mergeExistingUnknown || (int) $dest->contacts_count > $maxLinks
                        ))
                    );
                    if ($oldBlocked || $destBlocked) {
                        $result['conflict']++;

                        continue;
                    }
                    $result['eligible']++;
                    if (count($result['examples']) < 8) {
                        $raw = (string) $candidate['row']->digits;
                        $result['examples'][] = substr($raw, 0, 2).'••••'.substr($raw, -4)
                            .' → +55 ('.substr($candidate['e164'], 2, 2).') 9••••'.substr($candidate['e164'], -4);
                    }
                    if ($apply) {
                        $changes[] = [
                            'original_phone_id' => $id,
                            'original_phone_key' => (string) $candidate['row']->phone_key,
                            'original_digits' => (string) $candidate['row']->digits,
                            'corrected_e164' => $candidate['e164'],
                            'rule' => 'legacy_mobile_ninth_digit_candidate',
                            'applied_at' => $now,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];
                    }
                }
                if ($apply && $changes !== []) {
                    DB::transaction(function () use ($changes, &$result): void {
                        // Idempotente: nunca substitui uma revisão anterior.
                        $result['applied'] += DB::table('whatsapp_phone_auto_corrections_v18')
                            ->insertOrIgnore($changes);
                    }, 2);
                }
            }, 'n.id', 'id');

        return $result;
    }
}
