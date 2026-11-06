<?php

namespace App\Services\Contacts;

use Illuminate\Support\Facades\DB;

/**
 * V21: varredura de TODO o índice ativo classificado como revisão de formato.
 * Salva somente overrides auditáveis, sem editar Receita/HubSpot nem consultar WhatsApp.
 * Um telefone formatado corretamente NÃO comprova que pertence ao contato.
 */
final class WhatsappFullFormatSweepV21
{
    /**
     * @return array{reviewed:int,already_reviewed:int,non_candidates:int,no_ddd:int,invalid_ddd_or_prefix:int,long_or_combined:int,other:int,candidates:int,already:int,eligible:int,eligible_existing_unknown:int,eligible_existing_verified:int,blocked_original:int,blocked_destination:int,blocked_history:int,blocked_key:int,applied:int,examples:list<string>}
     */
    public function execute(bool $apply = false, bool $includeVerifiedDestinations = false): array
    {
        $stats = [
            'reviewed' => 0,
            'already_reviewed' => 0,
            'non_candidates' => 0,
            'no_ddd' => 0,
            'invalid_ddd_or_prefix' => 0,
            'long_or_combined' => 0,
            'other' => 0,
            'candidates' => 0,
            'already' => 0,
            'eligible' => 0,
            'eligible_existing_unknown' => 0,
            'eligible_existing_verified' => 0,
            'blocked_original' => 0,
            'blocked_destination' => 0,
            'blocked_history' => 0,
            'blocked_key' => 0,
            'applied' => 0,
            'examples' => [],
        ];

        $scan = function () use ($apply, $includeVerifiedDestinations, &$stats): void {
            if ($apply) {
                // Mesma trava da sincronização do índice. Não interfere no Laravel Sail.
                if (DB::getDriverName() === 'pgsql') {
                    DB::select('SELECT pg_advisory_xact_lock(27041, 917)');
                }
                if (DB::table('whatsapp_web_scan_batches')
                    ->whereIn('status', ['queued', 'running'])->exists()) {
                    throw new \RuntimeException('Há consultas WhatsApp em fila ou andamento. Termine os lotes antes de corrigir o índice.');
                }
            }

            DB::table('whatsapp_phone_numbers as n')
                ->leftJoin('whatsapp_phone_format_reviews_v16 as review', 'review.original_phone_id', '=', 'n.id')
                ->leftJoin('whatsapp_phone_auto_corrections_v18 as auto', 'auto.original_phone_id', '=', 'n.id')
                ->where('n.kind', 'review')
                ->where('n.contacts_count', '>', 0)
                ->select([
                    'n.id', 'n.phone_key', 'n.digits', 'n.status', 'n.contacts_count',
                    'review.id as review_id', 'auto.id as auto_id',
                ])
                ->chunkById(250, function ($rows) use ($apply, $includeVerifiedDestinations, &$stats): void {
                    $possible = [];
                    foreach ($rows as $row) {
                        $stats['reviewed']++;
                        if ($row->review_id !== null) {
                            $stats['already_reviewed']++;

                            continue;
                        }
                        $candidate = self::candidate((string) $row->digits);
                        if ($candidate === null) {
                            $stats['non_candidates']++;
                            $reason = self::unresolvedReason((string) $row->digits);
                            $stats[$reason]++;

                            continue;
                        }
                        $stats['candidates']++;
                        if ($row->auto_id !== null) {
                            $stats['already']++;

                            continue;
                        }
                        if ($row->status !== 'unknown') {
                            $stats['blocked_original']++;

                            continue;
                        }
                        // Caso haja um índice importado com chave divergente,
                        // inserir override não teria efeito na futura sincronização.
                        $original = BrazilPhoneNumber::inspect((string) $row->digits);
                        if ($original === null || $original['kind'] !== 'review'
                            || $original['key'] !== (string) $row->phone_key) {
                            $stats['blocked_key']++;

                            continue;
                        }
                        $possible[(int) $row->id] = ['row' => $row, 'e164' => $candidate];
                    }

                    if ($possible === []) {
                        return;
                    }
                    $targetE164s = array_values(array_unique(array_column($possible, 'e164')));
                    // Procurar todas as linhas com esse E164: uma segunda linha não
                    // canônica seria ambígua e não pode ser sobrescrita.
                    $destinations = DB::table('whatsapp_phone_numbers')
                        ->whereIn('e164', $targetE164s)
                        ->get(['id', 'phone_key', 'e164', 'kind', 'status', 'evidence'])
                        ->groupBy('e164');
                    $ids = array_values(array_unique(array_merge(
                        array_keys($possible),
                        $destinations->flatMap(static fn ($rows) => $rows->pluck('id'))
                            ->map(static fn ($id): int => (int) $id)->all(),
                    )));
                    $probes = DB::table('whatsapp_web_probe_results')
                        ->whereIn('phone_id', $ids)->pluck('phone_id')->flip();
                    $events = DB::table('whatsapp_phone_events')
                        ->whereIn('phone_id', $ids)->whereIn('new_status', ['yes', 'no'])
                        ->pluck('phone_id')->flip();
                    $legacy = DB::table('contact_phone_verifications as v')
                        ->join('whatsapp_phone_sources as s', static function ($join): void {
                            $join->on('s.source_key', '=', 'v.source_key')
                                ->on('s.raw_digits', '=', 'v.phone_normalized');
                        })
                        ->whereIn('s.phone_id', $ids)
                        ->whereIn('v.whatsapp_status', ['yes', 'no'])
                        ->distinct()->pluck('s.phone_id')->flip();

                    $changes = [];
                    $when = now('UTC');
                    foreach ($possible as $id => $item) {
                        $targets = $destinations->get($item['e164']);
                        if ($targets !== null && $targets->count() !== 1) {
                            $stats['blocked_destination']++;

                            continue;
                        }
                        $target = $targets?->first();
                        $destId = (int) ($target->id ?? 0);
                        $origHadHistory = $probes->has($id) || $events->has($id) || $legacy->has($id);
                        if ($origHadHistory) {
                            $stats['blocked_original']++;

                            continue;
                        }

                        if ($target !== null && ((string) $target->phone_key !== $item['e164']
                            || (string) $target->kind !== 'mobile')) {
                            $stats['blocked_destination']++;

                            continue;
                        }

                        $historical = $target !== null && ($probes->has($destId)
                            || $events->has($destId) || $legacy->has($destId)
                            || trim((string) ($target->evidence ?? '')) !== '');
                        $verified = $target !== null && in_array((string) $target->status, ['yes', 'no'], true);
                        if ($target !== null && (! in_array((string) $target->status, ['unknown', 'yes', 'no'], true)
                            || ($verified && ! $includeVerifiedDestinations))) {
                            $stats['blocked_destination']++;

                            continue;
                        }
                        // Uma linha unknown com histórico de confirmação/consulta não
                        // deve voltar a ser tratada como um destino inédito.
                        if ($target !== null && ! $verified && $historical) {
                            $stats['blocked_history']++;

                            continue;
                        }
                        if ($verified && ! $includeVerifiedDestinations) {
                            $stats['blocked_destination']++;

                            continue;
                        }

                        $stats['eligible']++;
                        if ($target !== null && $verified) {
                            $stats['eligible_existing_verified']++;
                        } elseif ($target !== null) {
                            $stats['eligible_existing_unknown']++;
                        }
                        if (count($stats['examples']) < 10) {
                            $digits = (string) $item['row']->digits;
                            $stats['examples'][] = substr($digits, 0, 2).'••••'.substr($digits, -4)
                                .' → +55 ('.substr($item['e164'], 2, 2).') 9••••'.substr($item['e164'], -4);
                        }
                        if ($apply) {
                            $changes[] = [
                                'original_phone_id' => $id,
                                'original_phone_key' => (string) $item['row']->phone_key,
                                'original_digits' => (string) $item['row']->digits,
                                'corrected_e164' => (string) $item['e164'],
                                'rule' => 'full_sweep_mobile_ninth_digit_v21',
                                'applied_at' => $when,
                                'created_at' => $when,
                                'updated_at' => $when,
                            ];
                        }
                    }
                    if ($apply && $changes !== []) {
                        $stats['applied'] += DB::table('whatsapp_phone_auto_corrections_v18')
                            ->insertOrIgnore($changes);
                    }
                }, 'n.id', 'id');
        };

        if ($apply) {
            DB::transaction($scan, 2);
        } else {
            $scan();
        }

        return $stats;
    }

    /** Candidatos de formato: DDD + 8 dígitos móveis antigos, com prefixos opcionais conhecidos. */
    public static function candidate(string $raw): ?string
    {
        $digits = (string) preg_replace('/\D+/', '', $raw);
        $length = strlen($digits);
        $national = match (true) {
            $length === 10 => $digits,
            $length === 12 && str_starts_with($digits, '55') => substr($digits, 2),
            $length === 11 && str_starts_with($digits, '0') => substr($digits, 1),
            $length === 14 && str_starts_with($digits, '0055') => substr($digits, 4),
            $length === 13 && str_starts_with($digits, '0')
                && in_array((int) substr($digits, 1, 2), [12, 14, 15, 17, 21, 23, 25, 31, 32, 41, 43, 65], true) => substr($digits, 3),
            default => null,
        };
        if ($national === null) {
            return null;
        }

        return BrazilPhoneNumber::legacyMobileCandidate($national);
    }

    /** @return 'no_ddd'|'invalid_ddd_or_prefix'|'long_or_combined'|'other' */
    private static function unresolvedReason(string $raw): string
    {
        $digits = (string) preg_replace('/\D+/', '', $raw);
        if (in_array(strlen($digits), [8, 9], true)) {
            return 'no_ddd';
        }
        if (strlen($digits) > 15) {
            return 'long_or_combined';
        }
        if (in_array(strlen($digits), [10, 12], true)) {
            return 'invalid_ddd_or_prefix';
        }

        return 'other';
    }
}
