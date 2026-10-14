<?php

namespace App\Services\Contacts;

use App\Models\CommercialContact;
use App\Models\ContactPhoneVerification;
use App\Models\HubSpotCompany;
use App\Models\HubSpotContact;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Índice de pessoas para a tela /contatos.
 *
 * Não converte estabelecimento da Receita em pessoa e não vincula CNPJ
 * usando domínio, negócio, nome ou relações indiretas do HubSpot.
 */
final class ContactPeopleDirectory
{
    /**
     * @return LengthAwarePaginator<int, stdClass>
     */
    public function paginate(
        string $search = '',
        string $segment = 'all',
        string $origin = 'all',
        string $whatsapp = 'all',
        ?string $companyKey = null,
        int $page = 1,
        int $perPage = 12,
        string $order = 'recent',
    ): LengthAwarePaginator {
        $page = max(1, min($page, 100000));
        $perPage = in_array($perPage, [12, 25, 50], true) ? $perPage : 12;

        $query = $this->query($search, $segment, $origin, $whatsapp, $companyKey);

        if ($order === 'name') {
            $query->orderBy('person_name')->orderBy('person_id');
        } else {
            $query->orderByDesc('last_activity_at')->orderBy('person_name')->orderBy('person_id');
        }

        /** @var LengthAwarePaginator<int, stdClass> $result */
        $result = $query->paginate($perPage, ['*'], 'contactsPage', $page);
        $keys = array_map(static fn (stdClass $row): string => (string) $row->source_key, $result->items());
        $checks = ContactPhoneVerification::query()
            ->whereIn('source_key', $keys === [] ? ['__none__'] : $keys)
            ->get()
            ->groupBy('source_key');

        $result->through(static function (stdClass $row) use ($checks): stdClass {
            $byField = [];
            foreach (['phone', 'mobile_phone'] as $field) {
                $digits = ContactDirectory::digits((string) ($row->{$field} ?? ''));
                if ($digits === '') {
                    continue;
                }
                $entry = $checks->get((string) $row->source_key)
                    ?->first(static fn (ContactPhoneVerification $v): bool => $v->phone_normalized === $digits);
                $found = $entry?->whatsapp_status;
                $byField[$field] = $found === 'yes' || $found === 'no' ? $found : 'unknown';
            }
            $statuses = array_values($byField);
            $row->whatsapp_status = match (true) {
                $statuses === [] => 'none',
                in_array('yes', $statuses, true) => 'yes',
                count(array_filter($statuses, static fn (string $value): bool => $value === 'no')) === count($statuses) => 'no',
                default => 'unknown',
            };
            $row->phone_statuses = $byField;
            $row->person_name = trim((string) $row->person_name) ?: 'Contato sem nome';
            $row->company_label = trim((string) $row->company_label) ?: 'Sem empresa vinculada';

            return $row;
        });

        return $result;
    }

    /**
     * Totais reais, sem transformar telefone cadastral em contato pessoal.
     *
     * @return array{all:int,hubspot:int,manual:int,clients:int,deals:int,unlinked:int,wa_yes:int,wa_unknown:int}
     */
    public function totals(): array
    {
        $hubspot = HubSpotContact::query()->count();
        $manual = CommercialContact::query()->count();

        return [
            'all' => $hubspot + $manual,
            'hubspot' => $hubspot,
            'manual' => $manual,
            'clients' => $this->query('', 'clients')->count(),
            'deals' => $this->query('', 'deals')->count(),
            'unlinked' => $this->query('', 'unlinked')->count(),
            'wa_yes' => $this->query('', 'wa_yes')->count(),
            'wa_unknown' => $this->query('', 'wa_unknown')->count(),
        ];
    }

    /**
     * Verifica se uma pessoa selecionada pode ser inspecionada.
     * Usado exclusivamente na tela protegida por commercial.manager.
     */
    public function personExists(string $sourceKey): bool
    {
        if (preg_match('/^hubspot:([0-9]+)$/D', $sourceKey, $parts) === 1) {
            return HubSpotContact::query()->where('hubspot_id', $parts[1])->exists();
        }
        if (preg_match('/^manual:([0-9]+)$/D', $sourceKey, $parts) === 1) {
            return CommercialContact::query()->whereKey((int) $parts[1])->exists();
        }

        return false;
    }

    public function companyContext(stdClass $person): ?string
    {
        $direct = (int) ($person->direct_company_id ?? 0);
        if ($direct > 0) {
            return 'local:'.$direct;
        }
        $mirrorId = (int) ($person->mirror_id ?? 0);
        if ($mirrorId === 0) {
            return null;
        }

        $mirror = HubSpotCompany::query()->find($mirrorId);
        if ($mirror === null) {
            return null;
        }
        if ($mirror->hasTrustedFiscalLink()) {
            return 'local:'.(int) $mirror->company_id;
        }

        return 'hubspot:'.$mirrorId;
    }

    private function query(
        string $search,
        string $segment,
        string $origin = 'all',
        string $whatsapp = 'all',
        ?string $companyKey = null,
    ): Builder {
        // Uma pessoa HubSpot aparece uma só vez, mesmo com várias empresas associadas.
        $hubspot = DB::table('hubspot_contacts as h')
            ->selectRaw("'hubspot' AS person_type, h.id AS person_id, h.hubspot_id AS external_id")
            ->selectRaw("('hubspot:' || h.hubspot_id) AS source_key")
            ->selectRaw("TRIM(COALESCE(h.first_name, '') || ' ' || COALESCE(h.last_name, '')) AS person_name")
            ->selectRaw('h.job_title, h.email, h.phone, h.mobile_phone, h.owner_name, h.last_activity_at')
            ->selectRaw('NULL AS notes, NULL AS company_id, NULL AS hubspot_company_external_id')
            ->selectRaw('h.company_name AS company_hint');

        $manual = DB::table('commercial_contacts as m')
            ->selectRaw("'manual' AS person_type, m.id AS person_id, NULL AS external_id")
            ->selectRaw("('manual:' || CAST(m.id AS VARCHAR)) AS source_key")
            ->selectRaw('m.name AS person_name, m.job_title, m.email, m.phone, m.mobile_phone')
            ->selectRaw('NULL AS owner_name, m.updated_at AS last_activity_at')
            ->selectRaw('m.notes, m.company_id, m.hubspot_company_external_id, NULL AS company_hint');

        $primaryLink = DB::table('hubspot_company_contact')
            ->select('hubspot_contact_id')
            ->selectRaw('MIN(hubspot_company_id) AS hubspot_company_id')
            ->groupBy('hubspot_contact_id');

        $query = DB::query()
            ->fromSub($hubspot->unionAll($manual), 'p')
            ->leftJoinSub($primaryLink, 'link', static function (JoinClause $join): void {
                $join->on('link.hubspot_contact_id', '=', 'p.person_id')
                    ->where('p.person_type', '=', 'hubspot');
            })
            ->leftJoin('hubspot_companies as hc', static function (JoinClause $join): void {
                $join->on('hc.id', '=', 'link.hubspot_company_id')
                    ->orOn('hc.hubspot_id', '=', 'p.hubspot_company_external_id');
            })
            ->leftJoin('companies as c', 'c.id', '=', 'p.company_id')
            ->select('p.*')
            ->selectRaw('hc.id AS mirror_id, hc.hubspot_id AS mirror_external_id, hc.lifecycle_stage AS company_stage')
            ->selectRaw('c.id AS direct_company_id, c.uuid AS direct_company_uuid, c.cnpj_root AS direct_cnpj')
            ->selectRaw('hc.company_id AS mirror_fiscal_candidate_id, hc.match_source AS mirror_match_source')
            ->selectRaw("COALESCE(NULLIF(c.corporate_name, ''), NULLIF(hc.name, ''), NULLIF(p.company_hint, ''), 'Sem empresa vinculada') AS company_label")
            ->selectRaw("CASE WHEN LOWER(COALESCE(hc.lifecycle_stage, '')) = 'customer' OR EXISTS (SELECT 1 FROM company_crm_checks crm WHERE crm.company_id = p.company_id AND crm.status = 'client') THEN 1 ELSE 0 END AS is_client")
            ->selectRaw($this->dealsSql().' AS deals_count');

        $search = mb_strtolower(trim(mb_substr($search, 0, 120)));
        if ($search !== '') {
            $like = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search).'%';
            $query->where(static function (Builder $q) use ($like): void {
                foreach ([
                    'p.person_name', 'p.job_title', 'p.email', 'p.phone', 'p.mobile_phone',
                    'c.corporate_name', 'hc.name', 'p.company_hint', 'c.cnpj_root',
                ] as $column) {
                    $q->orWhereRaw('LOWER(COALESCE('.$column.', \'\')) LIKE ? ESCAPE \'!\'', [$like]);
                }
                // Busca por CNPJ de HubSpot só usa empresa local se o vínculo for fiscalmente seguro.
                $q->orWhereExists(static function (Builder $fiscal) use ($like): void {
                    $fiscal->selectRaw('1')->from('hubspot_company_contact as hcc')
                        ->join('hubspot_companies as trusted', 'trusted.id', '=', 'hcc.hubspot_company_id')
                        ->join('companies as fiscal_company', 'fiscal_company.id', '=', 'trusted.company_id')
                        ->where('p.person_type', 'hubspot')
                        ->whereColumn('hcc.hubspot_contact_id', 'p.person_id')
                        ->where(static function (Builder $match) use ($like): void {
                            $match->whereRaw("LOWER(COALESCE(fiscal_company.cnpj_root, '')) LIKE ? ESCAPE '!'", [$like])
                                ->orWhereRaw("LOWER(COALESCE(fiscal_company.corporate_name, '')) LIKE ? ESCAPE '!'", [$like]);
                        });
                    self::trustedMirror($fiscal, 'trusted');
                });
            });
        }

        if ($origin === 'hubspot' || $origin === 'manual') {
            $query->where('p.person_type', $origin);
        }

        switch ($segment) {
            case 'clients':
                $query->where(static function (Builder $q): void {
                    $q->whereRaw("LOWER(COALESCE(hc.lifecycle_stage, '')) = 'customer'")
                        ->orWhereExists(static function (Builder $crm): void {
                            $crm->selectRaw('1')->from('company_crm_checks as cc')
                                ->whereColumn('cc.company_id', 'p.company_id')->where('cc.status', 'client');
                        });
                });
                break;
            case 'deals':
                $query->whereRaw('('.$this->dealsSql().') > 0');
                break;
            case 'unlinked':
            case 'orphan':
                $query->whereNull('hc.id')->whereNull('p.company_id');
                break;
            case 'hubspot':
                $query->where('p.person_type', 'hubspot');
                break;
            case 'manual':
                $query->where('p.person_type', 'manual');
                break;
            case 'receita':
            case 'fiscal':
                $query->where(static function (Builder $q): void {
                    $q->whereNotNull('p.company_id')
                        ->orWhere(static function (Builder $m): void {
                            $m->whereNotNull('hc.company_id');
                            self::trustedMirror($m);
                        });
                });
                break;
            case 'with_email':
                $query->whereNotNull('p.email')->where('p.email', '<>', '');
                break;
            case 'with_phone':
                $query->where(static function (Builder $q): void {
                    $q->whereNotNull('p.phone')->where('p.phone', '<>', '')
                        ->orWhereNotNull('p.mobile_phone')->where('p.mobile_phone', '<>', '');
                });
                break;
            case 'wa_yes':
                $whatsapp = 'yes';
                break;
            case 'wa_unknown':
                $whatsapp = 'unknown';
                break;
            case 'without_people':
                $query->whereRaw('1=0');
                break;
        }

        // O filtro considera somente o telefone ATUAL e diferencia "sem número".
        $phone = self::phoneSql('p.phone');
        $mobile = self::phoneSql('p.mobile_phone');
        $phonePresent = $phone." <> ''";
        $mobilePresent = $mobile." <> ''";
        $hasPhone = '('.$phonePresent.' OR '.$mobilePresent.')';
        $yes = '('.self::confirmationSql($phone, 'yes').' OR '.self::confirmationSql($mobile, 'yes').')';
        $allNo = '('.$hasPhone.' AND (NOT '.$phonePresent.' OR '.self::confirmationSql($phone, 'no').') '
            .'AND (NOT '.$mobilePresent.' OR '.self::confirmationSql($mobile, 'no').'))';
        if ($whatsapp === 'yes') {
            $query->whereRaw($yes);
        } elseif ($whatsapp === 'no') {
            $query->whereRaw('NOT '.$yes)->whereRaw($allNo);
        } elseif ($whatsapp === 'unknown') {
            $query->whereRaw($hasPhone)->whereRaw('NOT '.$yes)->whereRaw('NOT '.$allNo);
        }

        if ($companyKey !== null && preg_match('/^(local|hubspot|orphan):(\d+)$/D', $companyKey, $parts) === 1) {
            $kind = $parts[1];
            $id = (int) $parts[2];
            if ($kind === 'orphan') {
                $query->where('p.person_type', 'hubspot')->whereNull('hc.id');
            } elseif ($kind === 'hubspot') {
                $mirror = HubSpotCompany::query()->find($id);
                $query->where(static function (Builder $q) use ($id, $mirror): void {
                    $q->where(static function (Builder $hubspot) use ($id): void {
                        $hubspot->where('p.person_type', 'hubspot')
                            ->whereExists(static function (Builder $assoc) use ($id): void {
                                $assoc->selectRaw('1')->from('hubspot_company_contact as hcc')
                                    ->whereColumn('hcc.hubspot_contact_id', 'p.person_id')
                                    ->where('hcc.hubspot_company_id', $id);
                            });
                    });
                    if ($mirror !== null) {
                        $q->orWhere('p.hubspot_company_external_id', $mirror->hubspot_id);
                    }
                });
            } else {
                $query->where(static function (Builder $q) use ($id): void {
                    $q->where('p.company_id', $id)->orWhereExists(static function (Builder $assoc) use ($id): void {
                        $assoc->selectRaw('1')->from('hubspot_company_contact as hcc')
                            ->join('hubspot_companies as linked', 'linked.id', '=', 'hcc.hubspot_company_id')
                            ->whereColumn('hcc.hubspot_contact_id', 'p.person_id')
                            ->where('p.person_type', 'hubspot')
                            ->where('linked.company_id', $id);
                        self::trustedMirror($assoc, 'linked');
                    });
                });
            }
        }

        return $query;
    }

    /**
     * Normalização SQL suficiente para os formatos usuais BR (+55, DDD, hífen,
     * espaços, pontos e barras). Compatível com PostgreSQL e SQLite.
     *
     * @param  'p.phone'|'p.mobile_phone'  $column
     * @return literal-string
     */
    private static function phoneSql(string $column): string
    {
        $value = 'COALESCE('.$column.", '')";
        foreach ([' ', '(', ')', '-', '+', '.', '/'] as $symbol) {
            $value = "REPLACE($value, '$symbol', '')";
        }

        return $value;
    }

    /**
     * Monta a condição SQL somente com colunas e status fixos do sistema.
     *
     * @param  literal-string  $digitsSql
     * @param  'yes'|'no'  $status
     * @return literal-string
     */
    private static function confirmationSql(string $digitsSql, string $status): string
    {
        return 'EXISTS (SELECT 1 FROM contact_phone_verifications cv '
            .'WHERE cv.source_key = p.source_key '
            .'AND cv.phone_normalized = '.$digitsSql
            ." AND cv.whatsapp_status = '".$status."')";
    }

    /**
     * A contagem de negócios usa apenas associações efetivas do espelho.
     *
     * @return literal-string
     */
    private function dealsSql(): string
    {
        return "CASE WHEN p.person_type = 'hubspot' "
            .'THEN (SELECT COUNT(*) FROM hubspot_deal_contact dc WHERE dc.hubspot_contact_id = p.person_id) '
            .'ELSE (SELECT COUNT(*) FROM hubspot_company_deal cd WHERE cd.hubspot_company_id = hc.id) END';
    }

    /**
     * Reflete o bloqueio de fontes inseguras definido em HubSpotCompany.
     * O ! escapa o sublinhado, que não pode funcionar como coringa SQL.
     *
     * @param  'hc'|'linked'|'trusted'  $alias
     */
    private static function trustedMirror(Builder $query, string $alias = 'hc'): void
    {
        $col = 'LOWER(TRIM('.$alias.'.match_source))';
        $query->where(static function (Builder $trusted) use ($col, $alias): void {
            $trusted->whereNull($alias.'.match_source')
                ->orWhere(static function (Builder $safe) use ($col): void {
                    $safe->whereRaw($col.' NOT IN (?, ?)', HubSpotCompany::UNSAFE_FISCAL_MATCH_SOURCES)
                        ->whereRaw($col." NOT LIKE ? ESCAPE '!'", ['hubspot!_related!_%'])
                        ->whereRaw($col." NOT LIKE ? ESCAPE '!'", ['%inherited!_cnpj%'])
                        ->whereRaw($col." NOT LIKE ? ESCAPE '!'", ['%propagated!_cnpj%']);
                });
        });
    }
}
