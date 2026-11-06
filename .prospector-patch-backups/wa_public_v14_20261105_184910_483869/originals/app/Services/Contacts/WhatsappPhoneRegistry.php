<?php

namespace App\Services\Contacts;

use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Inventário LOCAL por telefone único. Nunca chama WhatsApp/HubSpot nem envia mensagens.
 * A importação é transacional e idempotente; as decisões humanas são auditadas.
 */
final class WhatsappPhoneRegistry
{
    /** @return array{numbers:int,references:int,mobile:int,landline:int,review:int} */
    public function sync(): array
    {
        $result = DB::transaction(function (): array {
            // Evita duas reconstruções concorrentes no PostgreSQL.
            if (DB::getDriverName() === 'pgsql') {
                DB::select('SELECT pg_advisory_xact_lock(27041, 917)');
            }

            $scanId = (string) Str::uuid();
            $timestamp = now('UTC');
            $numbers = [];
            $links = [];

            $add = static function (string $origin, string $sourceKey, string $name, ?string $company, string $field, ?string $phone)
                use (&$numbers, &$links, $scanId, $timestamp): void {
                $info = BrazilPhoneNumber::inspect($phone);
                if ($info === null) {
                    return;
                }
                $key = $info['key'];
                if (! isset($numbers[$key])) {
                    $numbers[$key] = [
                        'phone_key' => $key,
                        'digits' => $info['digits'],
                        'e164' => $info['e164'],
                        'kind' => $info['kind'],
                        'last_scanned_at' => $timestamp,
                        'created_at' => $timestamp,
                        'updated_at' => $timestamp,
                    ];
                }
                // source_key+phone_field é identidade da fonte, mesmo que o telefone mude.
                $identity = $sourceKey.'|'.$field;
                $links[$identity] = [
                    'phone_key' => $key, // Convertido para phone_id após o upsert de números.
                    'source_key' => $sourceKey,
                    'origin' => $origin,
                    'phone_field' => $field,
                    'raw_digits' => $info['digits'],
                    'contact_label' => mb_substr($name, 0, 180),
                    'company_label' => $company !== null ? mb_substr($company, 0, 180) : null,
                    'scan_id' => $scanId,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ];
            };

            DB::table('hubspot_contacts')->select([
                'id', 'hubspot_id', 'first_name', 'last_name', 'company_name', 'phone', 'mobile_phone',
            ])->chunkById(700, static function ($rows) use ($add): void {
                foreach ($rows as $row) {
                    $source = 'hubspot:'.$row->hubspot_id;
                    $name = trim((string) $row->first_name.' '.(string) $row->last_name) ?: 'Contato HubSpot';
                    $add('hubspot', $source, $name, $row->company_name, 'phone', $row->phone);
                    $add('hubspot', $source, $name, $row->company_name, 'mobile_phone', $row->mobile_phone);
                }
            });

            DB::table('commercial_contacts as m')
                ->leftJoin('companies as c', 'c.id', '=', 'm.company_id')
                ->select('m.id', 'm.name', 'm.phone', 'm.mobile_phone', 'c.corporate_name')
                ->chunkById(700, static function ($rows) use ($add): void {
                    foreach ($rows as $row) {
                        $source = 'manual:'.$row->id;
                        $add('manual', $source, $row->name, $row->corporate_name, 'phone', $row->phone);
                        $add('manual', $source, $row->name, $row->corporate_name, 'mobile_phone', $row->mobile_phone);
                    }
                }, 'm.id', 'id');

            // Mesma população do diretório Receita: canais de estabelecimentos com telefone ou e-mail.
            ReceitaContactChannels::query()
                ->select('e.id', 'e.uuid', 'e.type', 'e.phone_1', 'e.phone_2', 'rc.corporate_name')
                ->chunkById(700, static function ($rows) use ($add): void {
                    foreach ($rows as $row) {
                        $source = 'receita:'.$row->uuid;
                        $name = $row->type === 'matrix' ? 'Canal Receita — Matriz' : 'Canal Receita — Filial';
                        $add('receita', $source, $name, $row->corporate_name, 'phone', $row->phone_1);
                        $add('receita', $source, $name, $row->corporate_name, 'mobile_phone', $row->phone_2);
                    }
                }, 'e.id', 'id');

            // Nunca apagar a última fotografia se a origem foi esvaziada por um refresh interrompido.
            if ($links === [] && DB::table('whatsapp_phone_sources')->exists()) {
                throw new \RuntimeException('Nenhuma fonte com telefone encontrada; a fotografia existente foi preservada.');
            }

            foreach (array_chunk(array_values($numbers), 350) as $batch) {
                DB::table('whatsapp_phone_numbers')->upsert(
                    $batch, ['phone_key'], ['digits', 'e164', 'kind', 'last_scanned_at', 'updated_at'],
                );
            }

            $ids = [];
            foreach (array_chunk(array_keys($numbers), 350) as $keys) {
                foreach (DB::table('whatsapp_phone_numbers')->whereIn('phone_key', $keys)->pluck('id', 'phone_key') as $key => $id) {
                    $ids[(string) $key] = (int) $id;
                }
            }

            $pending = [];
            foreach ($links as $link) {
                $key = $link['phone_key'];
                unset($link['phone_key']);
                $link['phone_id'] = $ids[$key];
                $pending[] = $link;
                if (count($pending) === 350) {
                    DB::table('whatsapp_phone_sources')->upsert(
                        $pending, ['source_key', 'phone_field'],
                        ['phone_id', 'origin', 'raw_digits', 'contact_label', 'company_label', 'scan_id', 'updated_at'],
                    );
                    $pending = [];
                }
            }
            if ($pending !== []) {
                DB::table('whatsapp_phone_sources')->upsert(
                    $pending, ['source_key', 'phone_field'],
                    ['phone_id', 'origin', 'raw_digits', 'contact_label', 'company_label', 'scan_id', 'updated_at'],
                );
            }

            DB::table('whatsapp_phone_sources')->where('scan_id', '<>', $scanId)->delete();

            // Duas subconsultas indexadas, sem milhares de UPDATEs e sem N+1.
            DB::statement(<<<'SQL'
UPDATE whatsapp_phone_numbers
SET contacts_count = (
    SELECT COUNT(DISTINCT source_key)
    FROM whatsapp_phone_sources s WHERE s.phone_id = whatsapp_phone_numbers.id
), occurrences_count = (
    SELECT COUNT(*) FROM whatsapp_phone_sources s WHERE s.phone_id = whatsapp_phone_numbers.id
)
SQL);

            $this->inheritExistingConfirmations();
            $this->mirrorKnownToContactDirectory();

            DB::table('whatsapp_phone_sync_runs')->insert([
                'numbers_count' => count($numbers),
                'references_count' => count($links),
                'finished_at' => $timestamp,
            ]);

            $kinds = ['mobile' => 0, 'landline' => 0, 'review' => 0];
            foreach ($numbers as $phone) {
                $kinds[$phone['kind']]++;
            }

            return [
                'numbers' => count($numbers), 'references' => count($links),
                'mobile' => $kinds['mobile'], 'landline' => $kinds['landline'], 'review' => $kinds['review'],
            ];
        }, 2);

        app(ContactPeopleDirectory::class)->clearCachedTotals();

        return $result;
    }

    /**
     * Aproveita status já conferidos na Central antiga somente se não houver contradição.
     * Uma decisão já registrada na nova central nunca é sobrescrita por esta rotina.
     */
    private function inheritExistingConfirmations(): void
    {
        $rows = DB::table('contact_phone_verifications as v')
            ->join('whatsapp_phone_sources as s', static function ($join): void {
                $join->on('v.source_key', '=', 's.source_key')
                    ->on('v.phone_normalized', '=', 's.raw_digits');
            })
            ->join('whatsapp_phone_numbers as n', 'n.id', '=', 's.phone_id')
            ->where('n.status', 'unknown')
            ->whereIn('v.whatsapp_status', ['yes', 'no'])
            ->select('s.phone_id', 'v.whatsapp_status', 'v.verified_by', 'v.verified_at')
            ->get()->groupBy('phone_id');

        foreach ($rows as $id => $checks) {
            $statuses = $checks->pluck('whatsapp_status')->unique();
            if ($statuses->count() !== 1) {
                continue;
            }
            $mostRecent = $checks->sortByDesc('verified_at')->first();
            DB::table('whatsapp_phone_numbers')->where('id', (int) $id)->where('status', 'unknown')->update([
                'status' => $statuses->first(), 'evidence' => 'legacy_manual',
                'verified_by' => $mostRecent?->verified_by,
                'verified_at' => $mostRecent?->verified_at,
                'updated_at' => now('UTC'),
            ]);
        }
    }

    /**
     * Mantém os novos contatos e os relatórios antigos alinhados com o status por número.
     * Quando um contato é adicionado a um telefone já confirmado, a sincronização
     * o atualiza sem exigir nova conferência e sem enviar qualquer mensagem.
     */
    private function mirrorKnownToContactDirectory(): void
    {
        DB::table('whatsapp_phone_sources as s')
            ->join('whatsapp_phone_numbers as n', 'n.id', '=', 's.phone_id')
            ->whereIn('n.status', ['yes', 'no'])
            ->select('s.id', 's.source_key', 's.raw_digits', 'n.status', 'n.verified_by', 'n.verified_at')
            ->chunkById(350, static function ($references): void {
                $now = now('UTC');
                $batch = [];
                foreach ($references as $source) {
                    $key = $source->source_key.'|'.$source->raw_digits;
                    $batch[$key] = [
                        'source_key' => $source->source_key,
                        'phone_normalized' => $source->raw_digits,
                        'whatsapp_status' => $source->status,
                        'verified_by' => $source->verified_by,
                        'verified_at' => $source->verified_at,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
                if ($batch !== []) {
                    DB::table('contact_phone_verifications')->upsert(
                        array_values($batch), ['source_key', 'phone_normalized'],
                        ['whatsapp_status', 'verified_by', 'verified_at', 'updated_at'],
                    );
                }
            }, 's.id', 'id');
    }

    /** @return array<string,int> */
    public function totals(): array
    {
        $row = DB::table('whatsapp_phone_numbers')->where('contacts_count', '>', 0)
            ->selectRaw('COUNT(*) AS total')
            ->selectRaw("SUM(CASE WHEN kind = 'mobile' THEN 1 ELSE 0 END) AS mobile")
            ->selectRaw("SUM(CASE WHEN kind = 'landline' THEN 1 ELSE 0 END) AS landline")
            ->selectRaw("SUM(CASE WHEN kind = 'review' THEN 1 ELSE 0 END) AS review")
            ->selectRaw("SUM(CASE WHEN status = 'yes' THEN 1 ELSE 0 END) AS yes")
            ->selectRaw("SUM(CASE WHEN status = 'no' THEN 1 ELSE 0 END) AS no")
            ->selectRaw("SUM(CASE WHEN status = 'unknown' AND kind <> 'review' THEN 1 ELSE 0 END) AS pending")
            ->first();

        return [
            'total' => (int) ($row->total ?? 0), 'mobile' => (int) ($row->mobile ?? 0),
            'landline' => (int) ($row->landline ?? 0), 'review' => (int) ($row->review ?? 0),
            'yes' => (int) ($row->yes ?? 0), 'no' => (int) ($row->no ?? 0),
            'pending' => (int) ($row->pending ?? 0),
        ];
    }

    /** @return LengthAwarePaginator<int,object> */
    public function search(string $text, string $kind, string $status, string $origin, int $page, string $webFilter = 'all', int $perPage = 20, string $public = 'all'): LengthAwarePaginator
    {
        $query = DB::table('whatsapp_phone_numbers as n')
            ->where('n.contacts_count', '>', 0)->select('n.*');

        if (in_array($kind, ['mobile', 'landline', 'review'], true)) {
            $query->where('n.kind', $kind);
        }
        if (in_array($status, ['yes', 'no', 'unknown'], true)) {
            $query->where('n.status', $status);
        }
        if (in_array($origin, ['hubspot', 'manual', 'receita'], true)) {
            $query->whereExists(static function ($q) use ($origin): void {
                $q->selectRaw('1')->from('whatsapp_phone_sources as s')
                    ->whereColumn('s.phone_id', 'n.id')->where('s.origin', $origin);
            });
        }

        // EC_WA_WEB_CONTROL_V6: filtro SQL sobre resultados técnicos, antes da paginação.
        if ($webFilter === 'never') {
            $query->whereNotExists(static function ($sub): void {
                $sub->selectRaw('1')->from('whatsapp_web_probe_results as wpr')
                    ->whereColumn('wpr.phone_id', 'n.id');
            });
        } elseif (in_array($webFilter, ['consulted', 'found', 'not_found', 'inconclusive'], true)) {
            $query->whereExists(static function ($sub) use ($webFilter): void {
                $sub->selectRaw('1')->from('whatsapp_web_probe_results as wpr')
                    ->whereColumn('wpr.phone_id', 'n.id');
                if ($webFilter === 'inconclusive') {
                    $sub->whereIn('wpr.result', ['inconclusive', 'error']);
                } elseif ($webFilter !== 'consulted') {
                    $sub->where('wpr.result', $webFilter);
                }
            });
        }

        $text = mb_substr(trim($text), 0, 100);
        if ($text !== '') {
            $digits = preg_replace('/\D+/', '', $text) ?: '';
            $nameTerm = '%'.mb_strtolower(str_replace(['%', '_'], ['\\%', '\\_'], $text)).'%';
            $query->where(static function ($q) use ($digits, $nameTerm): void {
                if ($digits !== '') {
                    $q->where('n.digits', 'like', '%'.$digits.'%')
                        ->orWhere('n.e164', 'like', '%'.$digits.'%');
                }
                $q->orWhereExists(static function ($linked) use ($nameTerm): void {
                    $linked->selectRaw('1')->from('whatsapp_phone_sources as s')
                        ->whereColumn('s.phone_id', 'n.id')
                        ->where(static function ($names) use ($nameTerm): void {
                            $names->whereRaw("LOWER(s.contact_label) LIKE ? ESCAPE '\\'", [$nameTerm])
                                ->orWhereRaw("LOWER(COALESCE(s.company_label, '')) LIKE ? ESCAPE '\\'", [$nameTerm]);
                        });
                });
            });
        }

        // EC_WA_PUBLIC_SITES_V10: evidência pública não é confirmação de conta ativa.
        if ($public === 'published') {
            $query->whereExists(static function ($q): void {
                $q->selectRaw('1')->from('whatsapp_public_site_evidences as pse')
                    ->where(static function ($match): void {
                        $match->whereColumn('pse.phone_id', 'n.id')
                            ->orWhereColumn('pse.global_phone_id', 'n.id');
                    }); // EC_WA_PUBLIC_COMPANY_V11
            });
        } elseif ($public === 'not_published') {
            $query->whereNotExists(static function ($q): void {
                $q->selectRaw('1')->from('whatsapp_public_site_evidences as pse')
                    ->where(static function ($match): void {
                        $match->whereColumn('pse.phone_id', 'n.id')
                            ->orWhereColumn('pse.global_phone_id', 'n.id');
                    }); // EC_WA_PUBLIC_COMPANY_V11
            });
        }
        return $query->orderByRaw("CASE n.kind WHEN 'mobile' THEN 0 WHEN 'landline' THEN 1 ELSE 2 END")
            ->orderByRaw("CASE n.status WHEN 'unknown' THEN 0 ELSE 1 END")
            ->orderByDesc('n.contacts_count')->orderBy('n.id')
            ->paginate(in_array($perPage, [20, 50, 100], true) ? $perPage : 20, ['*'], 'waPage', max(1, min($page, 100000))); // EC_WA_FULL_DIRECTORY_V8_REGISTRY
    }

    /**
     * Confirmação MANUAL por conta telefônica. Espelha o resultado em todas as
     * fontes, pois a UI de Contatos já consome contact_phone_verifications.
     */
    public function record(User $actor, int $phoneId, string $status, string $note): void
    {
        abort_unless($actor->isCommercialManager(), 403);
        if (! in_array($status, ['yes', 'no', 'unknown'], true)) {
            throw ValidationException::withMessages(['wa_status' => 'Estado de verificação inválido.']);
        }

        $note = trim(mb_substr($note, 0, 300));
        if ($status === 'no' && mb_strlen($note) < 10) {
            throw ValidationException::withMessages([
                'wa_note' => 'Descreva brevemente como o aplicativo indicou que este número não possui WhatsApp (mínimo 10 caracteres).',
            ]);
        }

        DB::transaction(function () use ($actor, $phoneId, $status, $note): void {
            $record = DB::table('whatsapp_phone_numbers')->where('id', $phoneId)
                ->where('contacts_count', '>', 0)->lockForUpdate()->first();
            abort_unless($record !== null, 404);
            if ($record->kind === 'review' && $status !== 'unknown') {
                throw ValidationException::withMessages(['wa_status' => 'Corrija o formato na origem antes de confirmar.']);
            }

            $evidence = match ($status) {
                'yes' => 'manual_app_confirmed',
                'no' => 'manual_app_unavailable',
                default => 'manual_reset',
            };
            $now = now('UTC');
            DB::table('whatsapp_phone_numbers')->where('id', $phoneId)->update([
                'status' => $status,
                'evidence' => $status === 'unknown' ? null : $evidence,
                'verified_at' => $status === 'unknown' ? null : $now,
                'verified_by' => $status === 'unknown' ? null : $actor->id,
                'updated_at' => $now,
            ]);
            DB::table('whatsapp_phone_events')->insert([
                'phone_id' => $phoneId, 'actor_id' => $actor->id,
                'old_status' => $record->status, 'new_status' => $status,
                'evidence' => $evidence, 'note' => $note === '' ? null : $note,
                'created_at' => $now,
            ]);

            // Deduplicar por origem+digitos: phone e mobile podem conter o mesmo número.
            $references = DB::table('whatsapp_phone_sources')->where('phone_id', $phoneId)
                ->select('source_key', 'raw_digits')->distinct()->get();
            if ($status === 'unknown') {
                foreach ($references->chunk(120) as $batch) {
                    DB::table('contact_phone_verifications')->where(static function ($q) use ($batch): void {
                        foreach ($batch as $source) {
                            $q->orWhere(static function ($pair) use ($source): void {
                                $pair->where('source_key', $source->source_key)
                                    ->where('phone_normalized', $source->raw_digits);
                            });
                        }
                    })->delete();
                }
            } else {
                foreach ($references->chunk(350) as $batch) {
                    $rows = [];
                    foreach ($batch as $source) {
                        $rows[] = [
                            'source_key' => $source->source_key,
                            'phone_normalized' => $source->raw_digits,
                            'whatsapp_status' => $status, 'verified_by' => $actor->id,
                            'verified_at' => $now, 'created_at' => $now, 'updated_at' => $now,
                        ];
                    }
                    DB::table('contact_phone_verifications')->upsert(
                        $rows, ['source_key', 'phone_normalized'],
                        ['whatsapp_status', 'verified_by', 'verified_at', 'updated_at'],
                    );
                }
            }
        });

        app(ContactPeopleDirectory::class)->clearCachedTotals();
    }
}
