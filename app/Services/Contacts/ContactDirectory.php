<?php

namespace App\Services\Contacts;

use App\Models\CommercialContact;
use App\Models\Company;
use App\Models\ContactPhoneVerification;
use App\Models\Establishment;
use App\Models\HubSpotCompany;
use App\Models\HubSpotContact;
use App\Models\HubSpotDeal;
use App\Services\CustomerRegistryService;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Leitura unificada: empresas fiscais verificadas e empresas independentes do HubSpot.
 * Nunca presume identidade fiscal com base em contato, negócio, domínio ou razão social.
 */
final class ContactDirectory
{
    /** @return array{hubspot_contacts:int,manual_contacts:int,orphan_contacts:int} */
    public function counts(): array
    {
        return [
            'hubspot_contacts' => HubSpotContact::query()->count(),
            'manual_contacts' => CommercialContact::query()->count(),
            'orphan_contacts' => $this->orphanCount(),
        ];
    }

    public function orphanCount(): int
    {
        return HubSpotContact::query()->doesntHave('companies')->count();
    }

    /** @return LengthAwarePaginator<int, object> */
    public function companies(string $search, string $segment, int $page = 1): LengthAwarePaginator
    {
        $search = mb_strtolower(trim($search));
        $like = '%'.$search.'%';
        $rawIdentifier = strtoupper((string) preg_replace('/[^a-zA-Z0-9]/', '', $search));

        $local = Company::query()
            ->selectRaw("'local' AS directory_type")
            ->addSelect([
                'companies.id AS record_id',
                'companies.corporate_name AS company_name',
                'companies.cnpj_root AS cnpj_root',
            ])
            ->selectRaw('NULL AS lifecycle_stage')
            ->selectRaw('NULL AS owner_name');

        $hubspot = HubSpotCompany::query()
            ->selectRaw("'hubspot' AS directory_type")
            ->addSelect([
                'hubspot_companies.id AS record_id',
                'hubspot_companies.name AS company_name',
            ])
            ->selectRaw('NULL AS cnpj_root')
            ->addSelect([
                'hubspot_companies.lifecycle_stage AS lifecycle_stage',
                'hubspot_companies.owner_name AS owner_name',
            ])
            ->where(function (EloquentBuilder $q): void {
                $q->whereNull('hubspot_companies.company_id')
                    ->orWhere(function (EloquentBuilder $unsafe): void {
                        $unsafe->unsafeFiscalLink();
                    });
            });

        if ($search !== '') {
            $local->where(function (EloquentBuilder $q) use ($like, $rawIdentifier): void {
                $q->whereRaw('LOWER(companies.corporate_name) LIKE ?', [$like]);

                if ($rawIdentifier !== '') {
                    $q->orWhere('companies.cnpj_root', 'like', '%'.$rawIdentifier.'%');
                }

                $q->orWhereHas('establishments', function (EloquentBuilder $est) use ($like, $rawIdentifier): void {
                    $est->whereRaw('LOWER(COALESCE(establishments.email, \'\')) LIKE ?', [$like])
                        ->orWhereRaw('LOWER(COALESCE(establishments.phone_1, \'\')) LIKE ?', [$like]);
                    if ($rawIdentifier !== '') {
                        $est->orWhere('establishments.cnpj', 'like', '%'.$rawIdentifier.'%');
                    }
                });

                $q->orWhereHas('hubSpotCompanies.contacts', function (EloquentBuilder $contact) use ($like): void {
                    $this->contactMatches($contact, $like);
                });

                $q->orWhereExists(function (QueryBuilder $manual) use ($like): void {
                    $manual->selectRaw('1')->from('commercial_contacts as mc')
                        ->whereColumn('mc.company_id', 'companies.id')
                        ->where(function (QueryBuilder $text) use ($like): void {
                            $text->whereRaw('LOWER(mc.name) LIKE ?', [$like])
                                ->orWhereRaw('LOWER(COALESCE(mc.email, \'\')) LIKE ?', [$like])
                                ->orWhereRaw('LOWER(COALESCE(mc.phone, \'\')) LIKE ?', [$like]);
                        });
                });
            });

            $hubspot->where(function (EloquentBuilder $q) use ($like): void {
                $q->whereRaw('LOWER(COALESCE(hubspot_companies.name, \'\')) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(COALESCE(hubspot_companies.domain, \'\')) LIKE ?', [$like])
                    ->orWhereHas('contacts', function (EloquentBuilder $contact) use ($like): void {
                        $this->contactMatches($contact, $like);
                    })
                    ->orWhereExists(function (QueryBuilder $manual) use ($like): void {
                        $manual->selectRaw('1')->from('commercial_contacts as mc')
                            ->whereColumn('mc.hubspot_company_external_id', 'hubspot_companies.hubspot_id')
                            ->where(function (QueryBuilder $text) use ($like): void {
                                $text->whereRaw('LOWER(mc.name) LIKE ?', [$like])
                                    ->orWhereRaw('LOWER(COALESCE(mc.email, \'\')) LIKE ?', [$like]);
                            });
                    });
            });
        }

        if ($segment === 'orphan') {
            $local->whereRaw('1=0');
            $hubspot->whereRaw('1=0');
        } elseif ($segment === 'clients') {
            $local->where(function (EloquentBuilder $q): void {
                $q->whereHas('crmCheck', fn (EloquentBuilder $c) => $c->where('status', 'client'))
                    ->orWhereExists(function (QueryBuilder $registry): void {
                        $registry->selectRaw('1')->from('customer_registry_entries as cre')
                            ->where('cre.enabled', true)
                            ->where(function (QueryBuilder $match): void {
                                $match->whereColumn('cre.cnpj_root', 'companies.cnpj_root')
                                    ->orWhere(function (QueryBuilder $name): void {
                                        $name->whereNull('cre.cnpj_root')
                                            ->whereColumn('cre.normalized_name', 'companies.normalized_name');
                                    });
                            });
                    });
            });
            $hubspot->whereRaw('LOWER(COALESCE(hubspot_companies.lifecycle_stage, \'\')) = ?', ['customer']);
        } elseif ($segment === 'deals') {
            $local->whereHas('hubSpotCompanies.deals');
            $hubspot->whereHas('deals');
        } elseif ($segment === 'without_people') {
            $local->whereDoesntHave('hubSpotCompanies.contacts')
                ->whereNotExists(function (QueryBuilder $q): void {
                    $q->selectRaw('1')->from('commercial_contacts as mc')
                        ->whereColumn('mc.company_id', 'companies.id');
                });
            $hubspot->whereDoesntHave('contacts')
                ->whereNotExists(function (QueryBuilder $q): void {
                    $q->selectRaw('1')->from('commercial_contacts as mc')
                        ->whereColumn('mc.hubspot_company_external_id', 'hubspot_companies.hubspot_id');
                });
        } elseif ($segment === 'hubspot') {
            $local->whereHas('hubSpotCompanies');
        } elseif ($segment === 'receita') {
            $local->whereHas('establishments', function (EloquentBuilder $q): void {
                $q->whereNotNull('phone_1')->orWhereNotNull('phone_2')->orWhereNotNull('email');
            });
            $hubspot->whereRaw('1=0');
        }

        if ($segment === 'without_people' || $segment === 'deals' || $segment === 'hubspot') {
            // Sem filtros extras: os escopos acima preservam a origem real.
        } elseif ($segment === 'all' && $search === '') {
            // Evita listar milhões de CNPJs sem qualquer canal de contato.
            $local->where(function (EloquentBuilder $q): void {
                $q->whereHas('establishments', function (EloquentBuilder $e): void {
                    $e->whereNotNull('phone_1')->orWhereNotNull('phone_2')->orWhereNotNull('email');
                })->orWhereHas('hubSpotCompanies.contacts')
                    ->orWhereExists(function (QueryBuilder $m): void {
                        $m->selectRaw('1')->from('commercial_contacts as mc')->whereColumn('mc.company_id', 'companies.id');
                    });
            });
            $hubspot->where(function (EloquentBuilder $q): void {
                $q->whereHas('contacts')->orWhereHas('deals')
                    ->orWhereExists(function (QueryBuilder $m): void {
                        $m->selectRaw('1')->from('commercial_contacts as mc')
                            ->whereColumn('mc.hubspot_company_external_id', 'hubspot_companies.hubspot_id');
                    });
            });
        }

        $union = $local->toBase()->unionAll($hubspot->toBase());

        return DB::query()->fromSub($union, 'directory')
            ->orderBy('company_name')
            ->orderBy('directory_type')
            ->orderBy('record_id')
            ->paginate(14, ['*'], 'companyPage', max(1, $page));
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  EloquentBuilder<TModel>  $q
     */
    private function contactMatches(EloquentBuilder $q, string $like): void
    {
        $q->whereRaw("LOWER(TRIM(COALESCE(hubspot_contacts.first_name, '') || ' ' || COALESCE(hubspot_contacts.last_name, ''))) LIKE ?", [$like])
            ->orWhereRaw('LOWER(COALESCE(hubspot_contacts.first_name, \'\')) LIKE ?', [$like])
            ->orWhereRaw('LOWER(COALESCE(hubspot_contacts.last_name, \'\')) LIKE ?', [$like])
            ->orWhereRaw('LOWER(COALESCE(hubspot_contacts.email, \'\')) LIKE ?', [$like])
            ->orWhereRaw('LOWER(COALESCE(hubspot_contacts.phone, \'\')) LIKE ?', [$like])
            ->orWhereRaw('LOWER(COALESCE(hubspot_contacts.mobile_phone, \'\')) LIKE ?', [$like]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function selected(string $key, int $page = 1, string $personSearch = '', string $personOrigin = 'all', string $waFilter = 'all'): ?array
    {
        if (! preg_match('/^(local|hubspot|orphan):(\d+)$/', $key, $parts)) {
            return null;
        }

        $kind = $parts[1];
        $id = (int) $parts[2];
        $mirrorIds = [];
        $mirrorExternalIds = [];
        $companyId = null;
        $name = '';
        $cnpj = null;
        $companyUuid = null;
        $status = 'Não classificado';
        $originLabel = '';
        $receipt = [];

        if ($kind === 'local') {
            $company = Company::query()->with('crmCheck')->find($id);
            if (! $company) {
                return null;
            }
            $companyId = (int) $company->id;
            $name = (string) $company->corporate_name;
            $cnpj = (string) $company->cnpj_root;
            $companyUuid = (string) $company->uuid;
            $mirrors = HubSpotCompany::query()->trustedFiscalLink()->where('company_id', $companyId)->get();
            $mirrorIds = $mirrors->pluck('id')->map(fn ($v) => (int) $v)->all();
            $mirrorExternalIds = $mirrors->pluck('hubspot_id')->filter()->map(fn ($v) => (string) $v)->all();
            $registry = app(CustomerRegistryService::class)->find($company) !== null;
            $status = $registry || $company->crmCheck?->status === 'client' ? 'Cliente ExportControl' : ($company->crmCheck?->status === 'opportunity' ? 'Oportunidade no CRM' : 'Cadastro da Receita');
            $originLabel = 'Cadastro fiscal verificado';
            $receipt = Establishment::query()->where('company_id', $companyId)
                ->where(function (EloquentBuilder $q): void {
                    $q->whereNotNull('phone_1')->orWhereNotNull('phone_2')->orWhereNotNull('email');
                })
                ->orderBy('type')->limit(50)->get()->map(function (Establishment $e): array {
                    return [
                        'id' => $e->id,
                        'source_key' => 'receita:'.$e->uuid,
                        'kind' => $e->type,
                        'cnpj' => $e->cnpj,
                        'city' => $e->municipality_name,
                        'uf' => $e->state,
                        'email' => $e->email,
                        'phone_1' => $e->phone_1,
                        'phone_2' => $e->phone_2,
                        'source' => $e->source,
                    ];
                })->all();
        } elseif ($kind === 'hubspot') {
            $mirror = HubSpotCompany::query()->find($id);
            if (! $mirror || $mirror->hasTrustedFiscalLink()) {
                return null;
            }
            $name = (string) ($mirror->name ?: 'Empresa sem nome no HubSpot');
            $mirrorIds = [$id];
            $mirrorExternalIds = [(string) $mirror->hubspot_id];
            $status = mb_strtolower((string) $mirror->lifecycle_stage) === 'customer' ? 'Cliente indicado pelo HubSpot' : 'Empresa do HubSpot';
            $originLabel = 'Sem vínculo fiscal confirmado';
        } else {
            if ($id !== 0) {
                return null;
            }
            $name = 'Contatos sem empresa vinculada';
            $status = 'Revisar associações';
            $originLabel = 'Contatos órfãos no espelho HubSpot';
        }

        $people = $this->peopleQuery($kind, $companyId, array_values($mirrorIds), array_values($mirrorExternalIds), $personSearch, $personOrigin, $waFilter)
            ->orderBy('person_name')
            ->orderBy('person_id')
            ->paginate(12, ['*'], 'peoplePage', max(1, $page));

        $sourceKeys = array_values(array_unique(array_merge(
            array_map(static fn (object $p): string => (string) data_get($p, 'source_key', ''), $people->items()),
            array_column($receipt, 'source_key'),
        )));
        $verifications = ContactPhoneVerification::query()
            ->whereIn('source_key', $sourceKeys ?: ['__none__'])
            ->get()
            ->mapWithKeys(static fn (ContactPhoneVerification $v): array => [
                $v->source_key.'|'.$v->phone_normalized => $v->whatsapp_status,
            ])->all();

        $deals = $mirrorIds === [] ? collect() : HubSpotDeal::query()
            ->whereHas('companies', fn (EloquentBuilder $q) => $q->whereIn('hubspot_companies.id', $mirrorIds))
            ->latest('id')->limit(12)->get();

        return compact('kind', 'id', 'companyId', 'name', 'cnpj', 'companyUuid', 'status', 'originLabel', 'receipt', 'people', 'deals', 'mirrorIds', 'mirrorExternalIds', 'verifications');
    }

    /**
     * @param  list<int>  $mirrorIds
     * @param  list<string>  $mirrorExternalIds
     */
    private function peopleQuery(string $kind, ?int $companyId, array $mirrorIds, array $mirrorExternalIds, string $search, string $origin, string $waFilter): QueryBuilder
    {
        $hubspot = DB::table('hubspot_contacts as h')
            ->selectRaw("'hubspot' AS person_type")
            ->selectRaw('h.id AS person_id, h.hubspot_id AS external_id')
            ->selectRaw("('hubspot:' || h.hubspot_id) AS source_key")
            ->selectRaw("TRIM(COALESCE(h.first_name, '') || ' ' || COALESCE(h.last_name, '')) AS person_name")
            ->selectRaw('h.job_title AS job_title, h.email AS email, h.phone AS phone, h.mobile_phone AS mobile_phone')
            ->selectRaw('h.owner_name AS owner_name, h.lifecycle_stage AS lifecycle_stage, h.last_activity_at AS last_activity_at')
            ->selectRaw('NULL AS notes');

        if ($kind === 'orphan') {
            $hubspot->whereNotExists(function (QueryBuilder $q): void {
                $q->selectRaw('1')->from('hubspot_company_contact as pivot')
                    ->whereColumn('pivot.hubspot_contact_id', 'h.id');
            });
        } elseif ($mirrorIds !== []) {
            $hubspot->whereIn('h.id', DB::table('hubspot_company_contact')
                ->select('hubspot_contact_id')->whereIn('hubspot_company_id', $mirrorIds));
        } else {
            $hubspot->whereRaw('1=0');
        }

        $manual = DB::table('commercial_contacts as m')
            ->selectRaw("'manual' AS person_type")
            ->selectRaw('m.id AS person_id, NULL AS external_id')
            ->selectRaw("('manual:' || CAST(m.id AS VARCHAR)) AS source_key")
            ->selectRaw('m.name AS person_name, m.job_title AS job_title, m.email AS email, m.phone AS phone, m.mobile_phone AS mobile_phone')
            ->selectRaw('NULL AS owner_name, NULL AS lifecycle_stage, m.updated_at AS last_activity_at, m.notes AS notes');

        if ($kind === 'orphan') {
            $manual->whereRaw('1=0');
        } elseif ($companyId !== null || $mirrorExternalIds !== []) {
            $manual->where(function (QueryBuilder $q) use ($companyId, $mirrorExternalIds): void {
                if ($companyId !== null) {
                    $q->where('m.company_id', $companyId);
                }
                if ($mirrorExternalIds !== []) {
                    $q->orWhereIn('m.hubspot_company_external_id', $mirrorExternalIds);
                }
            });
        } else {
            $manual->whereRaw('1=0');
        }

        $query = DB::query()->fromSub($hubspot->unionAll($manual), 'people');
        $search = mb_strtolower(trim($search));
        if ($search !== '') {
            $like = '%'.$search.'%';
            $query->where(function (QueryBuilder $q) use ($like): void {
                $q->whereRaw('LOWER(people.person_name) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(COALESCE(people.job_title, \'\')) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(COALESCE(people.email, \'\')) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(COALESCE(people.phone, \'\')) LIKE ?', [$like]);
            });
        }

        if (in_array($origin, ['hubspot', 'manual'], true)) {
            $query->where('people.person_type', $origin);
        } elseif ($origin === 'email') {
            $query->whereNotNull('people.email')->where('people.email', '<>', '');
        }

        if ($waFilter === 'yes' || $waFilter === 'no') {
            $query->whereExists(function (QueryBuilder $q) use ($waFilter): void {
                $q->selectRaw('1')->from('contact_phone_verifications as cv')
                    ->whereColumn('cv.source_key', 'people.source_key')
                    ->where('cv.whatsapp_status', $waFilter);
            });
            if ($waFilter === 'no') {
                $query->whereNotExists(function (QueryBuilder $q): void {
                    $q->selectRaw('1')->from('contact_phone_verifications as cv')
                        ->whereColumn('cv.source_key', 'people.source_key')
                        ->where('cv.whatsapp_status', 'yes');
                });
            }
        } elseif ($waFilter === 'unknown') {
            $query->whereNotExists(function (QueryBuilder $q): void {
                $q->selectRaw('1')->from('contact_phone_verifications as cv')
                    ->whereColumn('cv.source_key', 'people.source_key')
                    ->whereIn('cv.whatsapp_status', ['yes', 'no']);
            });
        }

        return $query;
    }

    /** @return array{company_id:int|null,hubspot_company_external_id:string|null} */
    public function manualTarget(string $key): array
    {
        $data = $this->selected($key);
        if (! $data || $data['kind'] === 'orphan') {
            throw ValidationException::withMessages(['contact' => 'Selecione uma empresa válida.']);
        }

        if ($data['kind'] === 'local') {
            return ['company_id' => $data['companyId'], 'hubspot_company_external_id' => null];
        }

        return ['company_id' => null, 'hubspot_company_external_id' => $data['mirrorExternalIds'][0]];
    }

    public function manualInSelection(string $key, int $manualId): ?CommercialContact
    {
        $contact = CommercialContact::query()->find($manualId);
        if (! $contact) {
            return null;
        }
        $selection = $this->selected($key);
        if (! $selection || $selection['kind'] === 'orphan') {
            return null;
        }
        if ($selection['companyId'] !== null && (int) $contact->company_id === (int) $selection['companyId']) {
            return $contact;
        }

        return $contact->hubspot_company_external_id !== null
            && in_array($contact->hubspot_company_external_id, $selection['mirrorExternalIds'], true)
                ? $contact : null;
    }

    /** @return array{source_key:string,phone:string}|null */
    public function verifiablePhone(string $selectedKey, string $type, int $id, string $field): ?array
    {
        $selection = $this->selected($selectedKey);
        if (! $selection) {
            return null;
        }
        if ($type === 'hubspot' && in_array($field, ['phone', 'mobile_phone'], true)) {
            $q = HubSpotContact::query()->whereKey($id);
            if ($selection['kind'] === 'orphan') {
                $q->doesntHave('companies');
            } else {
                $mirrorIds = $selection['mirrorIds'];
                $q->whereHas('companies', fn (EloquentBuilder $company) => $company->whereIn('hubspot_companies.id', $mirrorIds));
            }
            $contact = $q->first();
            if ($contact && trim((string) $contact->{$field}) !== '') {
                return ['source_key' => 'hubspot:'.$contact->hubspot_id, 'phone' => (string) $contact->{$field}];
            }
        }
        if ($type === 'manual' && in_array($field, ['phone', 'mobile_phone'], true)) {
            $contact = $this->manualInSelection($selectedKey, $id);
            if ($contact && trim((string) $contact->{$field}) !== '') {
                return ['source_key' => 'manual:'.$contact->id, 'phone' => (string) $contact->{$field}];
            }
        }
        if ($type === 'receita' && $selection['companyId'] !== null && in_array($field, ['phone_1', 'phone_2'], true)) {
            $establishment = Establishment::query()->whereKey($id)
                ->where('company_id', $selection['companyId'])->first();
            if ($establishment && trim((string) $establishment->{$field}) !== '') {
                return ['source_key' => 'receita:'.$establishment->uuid, 'phone' => (string) $establishment->{$field}];
            }
        }

        return null;
    }

    public static function digits(?string $phone): string
    {
        return (string) preg_replace('/\D+/', '', $phone ?? '');
    }

    public static function whatsappLink(?string $phone): ?string
    {
        $digits = self::digits($phone);
        if (strlen($digits) === 10 || strlen($digits) === 11) {
            $digits = '55'.$digits;
        }
        if (! preg_match('/^\d{12,13}$/', $digits)) {
            return null;
        }

        return 'https://wa.me/'.$digits;
    }

    public static function dialLink(?string $phone): ?string
    {
        $digits = self::digits($phone);

        return strlen($digits) >= 8 && strlen($digits) <= 15 ? 'tel:+'.$digits : null;
    }
}
