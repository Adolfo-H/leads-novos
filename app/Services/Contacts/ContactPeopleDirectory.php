<?php

namespace App\Services\Contacts;

use App\Models\CommercialContact;
use App\Models\ContactPhoneVerification;
use App\Models\Establishment;
use App\Models\HubSpotCompany;
use App\Models\HubSpotContact;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Diretório único: pessoas reais e canais cadastrais institucionais da Receita.
 * EC_RECEITA_UNIFIED_V10
 *
 * Não converte estabelecimento da Receita em pessoa e não vincula CNPJ
 * usando domínio, negócio, nome ou relações indiretas do HubSpot.
 */
final class ContactPeopleDirectory
{
    // Ativar SOMENTE após comparar na base real.
    private const PACOTE_05_CLIENTS_PREFILTER_ENABLED = true;

    // Somente ativar após equivalência de registros e ganho no PostgreSQL.
    private const PACOTE_06_DEALS_PREFILTER_ENABLED = true;

    // PACOTE_09: somente após comparação SQL; não altera filtros.
    private const PACOTE_09_TWO_PHASE_ENABLED = true;

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
        bool $fastUnfilteredCount = false,
        ?bool $forceClientPrefilter = null,
        ?bool $forceDealPrefilter = null,
        ?bool $forceTwoPhase = null,
    ): LengthAwarePaginator {
        $page = max(1, min($page, 100000));
        $perPage = in_array($perPage, [12, 25, 50], true) ? $perPage : 12;

        // A otimização só é elegível para o filtro simples "Clientes".
        // Pesquisas, outras origens e vínculos específicos preservam a SQL original.
        $clientPrefilter = ($forceClientPrefilter ?? self::PACOTE_05_CLIENTS_PREFILTER_ENABLED)
            && $segment === 'clients' && trim($search) === '' && $origin === 'all'
            && $whatsapp === 'all' && $companyKey === null;
        // Habilitado apenas no filtro puro "Com negócios"; os demais caminhos permanecem idênticos.
        $dealPrefilter = ($forceDealPrefilter ?? self::PACOTE_06_DEALS_PREFILTER_ENABLED)
            && $segment === 'deals' && trim($search) === '' && $origin === 'all'
            && $whatsapp === 'all' && $companyKey === null;
        $query = $this->query($search, $segment, $origin, $whatsapp, $companyKey, $clientPrefilter, $dealPrefilter);

        if ($order === 'name') {
            $query->orderBy('person_name')->orderBy('person_id');
        } else {
            $query->orderByDesc('last_activity_at')->orderBy('person_name')->orderBy('person_id');
        }

        // No cenário padrão, o UNION ALL contém uma linha por registro
        // de cada fonte. Evitamos o COUNT(*) sobre todos os joins do diretório.
        $plainDirectory = $fastUnfilteredCount && trim($search) === ''
            && $segment === 'all'
            && $origin === 'all'
            && $whatsapp === 'all'
            && $companyKey === null;

        if ($plainDirectory) {
            $items = ($forceTwoPhase ?? self::PACOTE_09_TWO_PHASE_ENABLED)
                ? $this->twoPhasePlainItems($query, $page, $perPage, $order)
                : $query->forPage($page, $perPage)->get();
            $result = new LengthAwarePaginator(
                $items,
                $this->quickAllCount(),
                $perPage,
                $page,
                [
                    'path' => LengthAwarePaginator::resolveCurrentPath(),
                    'pageName' => 'contactsPage',
                ],
            );
        } else {
            /** @var LengthAwarePaginator<int, stdClass> $result */
            $result = $query->paginate($perPage, ['*'], 'contactsPage', $page);
        }
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
     * Cache do workspace: somente JSON simples, nunca objeto PHP serializado.
     * Assim o Redis não precisa reconstruir classes PHP a partir do payload.
     *
     * @return LengthAwarePaginator<int, stdClass>
     */
    public function paginateForWorkspace(
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

        if (app()->runningUnitTests() || app()->environment('testing')) {
            return $this->paginate($search, $segment, $origin, $whatsapp, $companyKey, $page, $perPage, $order, true);
        }

        // clearCachedTotals() do Pacote 03 já incrementa essa versão.
        $version = (int) Cache::get('prospector:contacts:page-version:v3', 0);
        $fingerprint = serialize([
            $version, auth()->id(), $search, $segment, $origin,
            $whatsapp, $companyKey, $page, $perPage, $order,
        ]);
        // Versão v4 ignora automaticamente os objetos antigos do cache v3.
        $key = 'prospector:contacts:page:v6:'.hash('sha256', $fingerprint);

        $payload = Cache::remember(
            $key,
            12,
            function () use ($search, $segment, $origin, $whatsapp, $companyKey, $page, $perPage, $order): string {
                $result = $this->paginate(
                    $search, $segment, $origin, $whatsapp, $companyKey,
                    $page, $perPage, $order, true,
                );

                $data = [
                    'total' => $result->total(),
                    'current_page' => $result->currentPage(),
                    'per_page' => $result->perPage(),
                    // As linhas vêm do Query Builder e contêm campos simples.
                    // Inclui os estados WhatsApp já enriquecidos pelo paginate().
                    'rows' => array_map(
                        static fn (stdClass $row): array => get_object_vars($row),
                        $result->items(),
                    ),
                ];

                return (string) json_encode($data, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
            },
        );

        $result = $this->restoreCachedPage($payload, $page, $perPage);
        if ($result !== null) {
            return $result;
        }

        // Um payload inválido nunca deve derrubar a tela.
        // Remove somente esta chave e consulta diretamente o banco.
        Cache::forget($key);

        return $this->paginate($search, $segment, $origin, $whatsapp, $companyKey, $page, $perPage, $order, true);
    }

    /**
     * Reconstrói um paginator NOVO a partir de JSON sem desserializar objetos.
     *
     * @return LengthAwarePaginator<int, stdClass>|null
     */
    private function restoreCachedPage(mixed $payload, int $page, int $perPage): ?LengthAwarePaginator
    {
        if (! is_string($payload)) {
            return null;
        }

        try {
            $snapshot = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (! is_array($snapshot)
            || ! is_int($snapshot['total'] ?? null)
            || $snapshot['total'] < 0
            || ($snapshot['current_page'] ?? null) !== $page
            || ($snapshot['per_page'] ?? null) !== $perPage
            || ! is_array($snapshot['rows'] ?? null)
            || ! array_is_list($snapshot['rows'])
            || count($snapshot['rows']) > $perPage) {
            return null;
        }

        /** @var list<stdClass> $people */
        $people = [];
        foreach ($snapshot['rows'] as $row) {
            if (! is_array($row) || ! is_string($row['source_key'] ?? null)) {
                return null;
            }

            /** @var stdClass $person */
            $person = (object) $row;
            $people[] = $person;
        }

        return new LengthAwarePaginator(
            $people,
            $snapshot['total'],
            $perPage,
            $page,
            [
                'path' => LengthAwarePaginator::resolveCurrentPath(),
                'pageName' => 'contactsPage',
            ],
        );
    }

    /**
     * PACOTE_09: consulta somente as chaves ordenadas antes dos detalhes.
     * Usa o mesmo contrato de ordenação da query() e não infere vínculos.
     * Se existir divergência entre as duas leituras (p.ex. sincronização
     * concorrente), volta à consulta original, sem descartar registros.
     *
     * @return Collection<int, stdClass>
     */
    private function twoPhasePlainItems(Builder $fullQuery, int $page, int $perPage, string $order): Collection
    {
        $hubspot = DB::table('hubspot_contacts as h')
            ->selectRaw("('hubspot:' || h.hubspot_id) AS source_key")
            ->selectRaw('h.id AS person_id, h.last_activity_at')
            ->selectRaw("TRIM(COALESCE(h.first_name, '') || ' ' || COALESCE(h.last_name, '')) AS person_name");

        $manual = DB::table('commercial_contacts as m')
            ->selectRaw("('manual:' || CAST(m.id AS VARCHAR)) AS source_key")
            ->selectRaw('m.id AS person_id, m.updated_at AS last_activity_at, m.name AS person_name');

        $receita = ReceitaContactChannels::query()
            ->selectRaw("('receita:' || CAST(e.uuid AS VARCHAR)) AS source_key")
            ->selectRaw('e.id AS person_id, e.source_updated_at AS last_activity_at')
            ->selectRaw("CASE WHEN e.type = 'matrix' THEN 'Canal Receita — Matriz' ELSE 'Canal Receita — Filial' END AS person_name");

        $keyQuery = DB::query()
            ->fromSub($hubspot->unionAll($manual)->unionAll($receita), 'p')
            ->select('p.source_key');

        if ($order === 'name') {
            $keyQuery->orderBy('person_name')->orderBy('person_id');
        } else {
            $keyQuery->orderByDesc('last_activity_at')->orderBy('person_name')->orderBy('person_id');
        }

        $keys = [];
        foreach ($keyQuery->forPage($page, $perPage)->get() as $person) {
            $keys[] = (string) $person->source_key;
        }

        if ($keys === []) {
            return collect();
        }

        // Nunca escolher silenciosamente uma linha quando uma chave duplicou.
        if (count(array_unique($keys)) !== count($keys)) {
            return $fullQuery->forPage($page, $perPage)->get();
        }

        // Todos os detalhes/associações continuam vindo da query() original.
        // Somente o conjunto candidato foi reduzido aos IDs da página.
        $rows = (clone $fullQuery)->whereIn('p.source_key', $keys)->get();
        if ($rows->count() !== count($keys)) {
            return $fullQuery->forPage($page, $perPage)->get();
        }

        /** @var array<string, stdClass> $indexed */
        $indexed = [];
        foreach ($rows as $row) {
            $key = (string) $row->source_key;
            if (isset($indexed[$key])) {
                return $fullQuery->forPage($page, $perPage)->get();
            }
            $indexed[$key] = $row;
        }

        /** @var list<stdClass> $ordered */
        $ordered = [];
        foreach ($keys as $key) {
            if (! isset($indexed[$key])) {
                return $fullQuery->forPage($page, $perPage)->get();
            }
            $ordered[] = $indexed[$key];
        }

        return collect($ordered);
    }

    /**
     * A consulta sem filtros possui uma linha por pessoa HubSpot, contato
     * manual e canal Receita elegível. As demais segmentações conservam o
     * count() original, pois podem depender de associações e filtros.
     */
    private function quickAllCount(): int
    {
        $calculate = static fn (): int => HubSpotContact::query()->count()
            + CommercialContact::query()->count()
            + ReceitaContactChannels::query()->count();

        if (app()->runningUnitTests() || app()->environment('testing')) {
            return $calculate();
        }

        return (int) Cache::remember('prospector:contacts:plain-count:v3', 12, $calculate);
    }

    /**
     * Totais reais, sem transformar telefone cadastral em contato pessoal.
     *
     * @return array{all:int,hubspot:int,manual:int,receita:int,clients:int,deals:int,unlinked:int,wa_yes:int,wa_unknown:int}
     */
    public function totals(): array
    {
        // PACOTE_04_INDICADORES_ATIVO — equivalência verificada na base atual.
        return $this->totalsLean();
    }

    /**
     * PACOTE_04_AGGREGATED_COUNTERS - avaliação SQL sem alterar filtros públicos.
     * Consolida as cinco consultas de segmentação em uma agregação condicional.
     * Ainda mantém três contagens por origem, como no método histórico.
     * Usa a MESMA query base e os MESMOS predicados dos filtros.
     * Compatível com PostgreSQL e SQLite (SUM/CASE, sem FILTER específico do PG).
     *
     * @return array{all:int,hubspot:int,manual:int,receita:int,clients:int,deals:int,unlinked:int,wa_yes:int,wa_unknown:int}
     */
    public function totalsAggregated(): array
    {
        $hubspot = HubSpotContact::query()->count();
        $manual = CommercialContact::query()->count();
        $receita = ReceitaContactChannels::query()->count();

        $phone = self::phoneSql('p.phone');
        $mobile = self::phoneSql('p.mobile_phone');
        $phonePresent = $phone." <> ''";
        $mobilePresent = $mobile." <> ''";
        $hasPhone = '('.$phonePresent.' OR '.$mobilePresent.')';
        $yes = '('.self::confirmationSql($phone, 'yes').' OR '.self::confirmationSql($mobile, 'yes').')';
        $allNo = '('.$hasPhone.' AND (NOT '.$phonePresent.' OR '.self::confirmationSql($phone, 'no').') '
            .'AND (NOT '.$mobilePresent.' OR '.self::confirmationSql($mobile, 'no').'))';

        $conditions = [
            'clients' => "(LOWER(COALESCE(hc.lifecycle_stage, '')) = 'customer' "
                .'OR EXISTS (SELECT 1 FROM company_crm_checks cc '
                ."WHERE cc.company_id = p.company_id AND cc.status = 'client'))",
            'deals' => $this->hasDealsSql(),
            'unlinked' => '(hc.id IS NULL AND p.company_id IS NULL)',
            'wa_yes' => $yes,
            'wa_unknown' => '('.$hasPhone.' AND NOT '.$yes.' AND NOT '.$allNo.')',
        ];

        // Não carregamos as 24.846 linhas em PHP: o SQL devolve UMA linha.
        // Limpa a lista de campos selecionados pela query() original.
        $aggregate = $this->query('', 'all')->select([]);
        $selectors = [];
        foreach ($conditions as $name => $predicate) {
            // Nomes/expressões são fixos no código (nunca vêm da entrada do usuário).
            $selectors[] = 'COALESCE(SUM(CASE WHEN '.$predicate.' THEN 1 ELSE 0 END), 0) AS '.$name;
        }
        $counts = $aggregate->selectRaw(implode(', ', $selectors))->first();

        return [
            'all' => $hubspot + $manual + $receita,
            'receita' => $receita,
            'hubspot' => $hubspot,
            'manual' => $manual,
            'clients' => (int) ($counts->clients ?? 0),
            'deals' => (int) ($counts->deals ?? 0),
            'unlinked' => (int) ($counts->unlinked ?? 0),
            'wa_yes' => (int) ($counts->wa_yes ?? 0),
            'wa_unknown' => (int) ($counts->wa_unknown ?? 0),
        ];
    }

    /**
     * PACOTE_07_INDICADORES_LEVES — contagens com consultas especializadas.
     *
     * Preserva a semântica validada dos filtros Pacotes 05/06, sem ampliar
     * herança de vínculos comerciais/fiscais. Telefones e confirmações são
     * contados sem JOIN de empresa. Não usa cache e não altera dados.
     *
     * @return array{all:int,hubspot:int,manual:int,receita:int,clients:int,deals:int,unlinked:int,wa_yes:int,wa_unknown:int}
     */
    public function totalsLean(): array
    {
        // As três origens continuam separadas; canal Receita nunca vira pessoa.
        $hubspot = HubSpotContact::query()->count();
        $manual = CommercialContact::query()->count();
        $receita = ReceitaContactChannels::query()->count();

        // As restrições adicionais são NECESSÁRIAS, nunca substituem a regra final.
        // Estes mesmos pré-filtros já foram confrontados com a base real.
        $clients = $this->query('', 'clients', 'all', 'all', null, true, false)->count();
        $deals = $this->query('', 'deals', 'all', 'all', null, false, true)->count();
        $unlinked = $this->query('', 'unlinked')->count();

        // Projeção mínima. Excluímos exclusivamente joins que não participam
        // dos indicadores de confirmação de telefone/WhatsApp.
        $hubspotPhones = DB::table('hubspot_contacts as h')
            ->selectRaw("('hubspot:' || h.hubspot_id) AS source_key")
            ->selectRaw('h.phone AS phone, h.mobile_phone AS mobile_phone');
        $manualPhones = DB::table('commercial_contacts as m')
            ->selectRaw("('manual:' || CAST(m.id AS VARCHAR)) AS source_key")
            ->selectRaw('m.phone AS phone, m.mobile_phone AS mobile_phone');
        $receitaPhones = ReceitaContactChannels::query()
            ->selectRaw("('receita:' || CAST(e.uuid AS VARCHAR)) AS source_key")
            ->selectRaw("NULLIF(TRIM(e.phone_1), '') AS phone, NULLIF(TRIM(e.phone_2), '') AS mobile_phone");

        $normalPhone = self::phoneSql('p.phone');
        $normalMobile = self::phoneSql('p.mobile_phone');
        $phonePresent = $normalPhone." <> ''";
        $mobilePresent = $normalMobile." <> ''";
        $hasPhone = '('.$phonePresent.' OR '.$mobilePresent.')';

        // A constraint UNIQUE(source_key, phone_normalized) assegura que
        // estes dois joins não multiplicam as linhas do diretório.
        $phoneTotals = DB::query()
            ->fromSub($hubspotPhones->unionAll($manualPhones)->unionAll($receitaPhones), 'p')
            ->leftJoin('contact_phone_verifications as cv_phone', static function (JoinClause $join) use ($normalPhone): void {
                $join->on('cv_phone.source_key', '=', 'p.source_key')
                    ->whereRaw('cv_phone.phone_normalized = '.$normalPhone);
            })
            ->leftJoin('contact_phone_verifications as cv_mobile', static function (JoinClause $join) use ($normalMobile): void {
                $join->on('cv_mobile.source_key', '=', 'p.source_key')
                    ->whereRaw('cv_mobile.phone_normalized = '.$normalMobile);
            });

        // COALESCE respeita a lógica EXISTS anterior, mesmo com NULLs.
        // A condição 'yes' não exige telefone preenchido, igual à anterior.
        $yes = "(COALESCE(cv_phone.whatsapp_status, '') = 'yes' "
            ."OR COALESCE(cv_mobile.whatsapp_status, '') = 'yes')";
        $allNo = '('.$hasPhone.' AND (NOT '.$phonePresent
            ." OR COALESCE(cv_phone.whatsapp_status, '') = 'no') "
            .'AND (NOT '.$mobilePresent
            ." OR COALESCE(cv_mobile.whatsapp_status, '') = 'no'))";
        $unknown = '('.$hasPhone.' AND NOT '.$yes.' AND NOT '.$allNo.')';

        $counts = $phoneTotals
            ->selectRaw('COALESCE(SUM(CASE WHEN '.$yes.' THEN 1 ELSE 0 END), 0) AS wa_yes')
            ->selectRaw('COALESCE(SUM(CASE WHEN '.$unknown.' THEN 1 ELSE 0 END), 0) AS wa_unknown')
            ->first();

        // Mantém exatamente a ordem do contrato de totalsAggregated().
        // PHP compara arrays associativos com ordem das chaves em === / toBe().
        return [
            'all' => $hubspot + $manual + $receita,
            'receita' => $receita,
            'hubspot' => $hubspot,
            'manual' => $manual,
            'clients' => (int) $clients,
            'deals' => (int) $deals,
            'unlinked' => (int) $unlinked,
            'wa_yes' => (int) ($counts->wa_yes ?? 0),
            'wa_unknown' => (int) ($counts->wa_unknown ?? 0),
        ];
    }

    /**
     * Reutiliza os totais globais por 25 segundos entre requests Livewire.
     * totals() permanece sempre atual para relatorios e testes.
     * O cache eventualmente expira mesmo quando o espelho HubSpot e atualizado
     * fora do fluxo manual de Contatos.
     *
     * @return array{all:int,hubspot:int,manual:int,receita:int,clients:int,deals:int,unlinked:int,wa_yes:int,wa_unknown:int}
     */
    public function totalsCached(): array
    {
        /** @var array{all:int,hubspot:int,manual:int,receita:int,clients:int,deals:int,unlinked:int,wa_yes:int,wa_unknown:int} $totals */
        $totals = Cache::remember('prospector:contacts:totals:v2', 25, fn (): array => $this->totals());

        return $totals;
    }

    public function clearCachedTotals(): void
    {
        Cache::forget('prospector:contacts:totals:v2');
        Cache::forget('prospector:contacts:plain-count:v3');
        // Invalida os snapshots de página sem precisar apagar chaves por filtro.
        Cache::increment('prospector:contacts:page-version:v3');
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

        if (preg_match('/^receita:([0-9a-fA-F-]{36})$/D', $sourceKey, $parts) === 1) {
            return Establishment::query()->where('uuid', $parts[1])
                ->whereNotNull('company_id')->exists();
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
        bool $clientPrefilter = false,
        bool $dealPrefilter = false,
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

        // Canais cadastrais de estabelecimento (matriz/filial). São linhas empresariais,
        // não pessoas do HubSpot nem contatos pessoais presumidos.
        $receita = ReceitaContactChannels::query()
            ->selectRaw("'receita' AS person_type, e.id AS person_id, e.cnpj AS external_id")
            ->selectRaw("('receita:' || CAST(e.uuid AS VARCHAR)) AS source_key")
            ->selectRaw("CASE WHEN e.type = 'matrix' THEN 'Canal Receita — Matriz' ELSE 'Canal Receita — Filial' END AS person_name")
            ->selectRaw("'Contato cadastral da empresa' AS job_title")
            ->selectRaw("NULLIF(TRIM(e.email), '') AS email, NULLIF(TRIM(e.phone_1), '') AS phone, NULLIF(TRIM(e.phone_2), '') AS mobile_phone")
            ->selectRaw('NULL AS owner_name, e.source_updated_at AS last_activity_at')
            ->selectRaw('NULL AS notes, e.company_id AS company_id, NULL AS hubspot_company_external_id')
            ->selectRaw('rc.corporate_name AS company_hint');

        // PACOTE_05_PREFILTER_CLIENTS: critério NECESSÁRIO, nunca substitui o filtro final.
        // Reduz candidatos antes do UNION/JOIN. Não inventa vínculos fiscais.
        if ($clientPrefilter) {
            $hubspot->whereExists(static function (Builder $candidate): void {
                $candidate->selectRaw('1')
                    ->from('hubspot_company_contact as pre_hcc')
                    ->join('hubspot_companies as pre_hc', 'pre_hc.id', '=', 'pre_hcc.hubspot_company_id')
                    ->whereColumn('pre_hcc.hubspot_contact_id', 'h.id')
                    ->whereRaw("LOWER(COALESCE(pre_hc.lifecycle_stage, '')) = 'customer'");
            });

            $manual->where(static function (Builder $candidate): void {
                $candidate->whereExists(static function (Builder $crm): void {
                    $crm->selectRaw('1')->from('company_crm_checks as pre_cc')
                        ->whereColumn('pre_cc.company_id', 'm.company_id')
                        ->where('pre_cc.status', 'client');
                })->orWhereExists(static function (Builder $mirror): void {
                    $mirror->selectRaw('1')->from('hubspot_companies as pre_hc')
                        ->whereColumn('pre_hc.hubspot_id', 'm.hubspot_company_external_id')
                        ->whereRaw("LOWER(COALESCE(pre_hc.lifecycle_stage, '')) = 'customer'");
                });
            });

            $receita->whereExists(static function (Builder $crm): void {
                $crm->selectRaw('1')->from('company_crm_checks as pre_cc')
                    ->whereColumn('pre_cc.company_id', 'e.company_id')
                    ->where('pre_cc.status', 'client');
            });
        }

        // PACOTE_06_PREFILTER_DEALS: reduz candidatos ANTES do UNION e dos JOINs.
        // O hasDealsSql() permanece como filtro FINAL, preservando a regra comercial:
        // pessoas HubSpot só herdam negócios associados à própria pessoa;
        // contatos manuais só herdam negócios do espelho empresarial vinculado;
        // canal Receita não vira pessoa ou negócio por associação presumida.
        if ($dealPrefilter) {
            $hubspot->whereExists(static function (Builder $candidate): void {
                $candidate->selectRaw('1')
                    ->from('hubspot_deal_contact as pre_dc')
                    ->whereColumn('pre_dc.hubspot_contact_id', 'h.id');
            });

            $manual->whereExists(static function (Builder $candidate): void {
                $candidate->selectRaw('1')
                    ->from('hubspot_companies as pre_hc')
                    ->join('hubspot_company_deal as pre_cd', 'pre_cd.hubspot_company_id', '=', 'pre_hc.id')
                    ->whereColumn('pre_hc.hubspot_id', 'm.hubspot_company_external_id');
            });

            $receita->whereRaw('1=0');
        }

        $primaryLink = DB::table('hubspot_company_contact')
            ->select('hubspot_contact_id')
            ->selectRaw('MIN(hubspot_company_id) AS hubspot_company_id')
            ->groupBy('hubspot_contact_id');

        $query = DB::query()
            ->fromSub($hubspot->unionAll($manual)->unionAll($receita), 'p')
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
                    'p.person_name', 'p.job_title', 'p.email', 'p.phone', 'p.mobile_phone', 'p.external_id',
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

        if (in_array($origin, ['hubspot', 'manual', 'receita'], true)) {
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
                $query->whereRaw($this->hasDealsSql());
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
                $query->where('p.person_type', 'receita');
                break;
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
        return "CASE WHEN p.person_type = 'receita' THEN 0 WHEN p.person_type = 'hubspot' "
            .'THEN (SELECT COUNT(*) FROM hubspot_deal_contact dc WHERE dc.hubspot_contact_id = p.person_id) '
            .'ELSE (SELECT COUNT(*) FROM hubspot_company_deal cd WHERE cd.hubspot_company_id = hc.id) END';
    }

    /**
     * Para filtrar existencia de negocio, evita o custo de COUNT(*) > 0.
     * Receita e um canal institucional e nao herda negocios de pessoas.
     *
     * @return literal-string
     */
    private function hasDealsSql(): string
    {
        return "((p.person_type = 'hubspot' AND EXISTS ("
            .'SELECT 1 FROM hubspot_deal_contact dc '
            .'WHERE dc.hubspot_contact_id = p.person_id)) '
            ."OR (p.person_type = 'manual' AND EXISTS ("
            .'SELECT 1 FROM hubspot_company_deal cd '
            .'WHERE cd.hubspot_company_id = hc.id)))';
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
