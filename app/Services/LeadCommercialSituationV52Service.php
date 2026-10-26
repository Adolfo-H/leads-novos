<?php

namespace App\Services;

use App\Models\Company;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * V52 — somente a situação apresentada. Não grava/modifica o work_status
 * original: o espelho é responsabilidade exclusiva da sincronização HubSpot.
 *
 * O mesmo LeadCrmSituationService que alimenta o filtro Situação CRM decide
 * os rótulos, com consultas agrupadas (não há consultas por linha da tela).
 *
 * Precedência: Cliente > Aguardando retorno (filtro já existente) >
 * Conhecido <= 30 dias > Reprospecção >= 90 dias.
 * Não inferir falta de contato quando não há uma data de atividade segura.
 */
final class LeadCommercialSituationV52Service
{
    /**
     * Somente oportunidades futuras e convertidas recebem substituições.
     * As demais situações comerciais não são modificadas.
     *
     * @param Collection<int, Company> $companies
     * @return array<int, 'client'|'known'|'reprospecting'>
     */
    public function forCompanies(Collection $companies): array
    {
        if ($companies->isEmpty()) {
            return [];
        }

        // A tela fiscal e os exportadores já podem entregar as relações carregadas.
        // loadMissing em lote evita uma consulta por empresa.
        (new EloquentCollection($companies->all()))->loadMissing('hubSpotLead');

        $overrides = [];
        $futureIds = [];
        foreach ($companies as $company) {
            $id = (int) $company->id;
            $status = (string) ($company->hubSpotLead->work_status ?? '');
            if ($status === 'converted') {
                $overrides[$id] = 'client';
            } elseif ($status === 'future') {
                $futureIds[$id] = $id;
            }
        }

        if ($futureIds === []) {
            return $overrides;
        }

        $crm = app(LeadCrmSituationService::class);
        // Lotes fixos: seguro para exportações grandes, inclusive SQLite em testes.
        foreach (array_chunk(array_values($futureIds), 400) as $ids) {
            $client = $this->matchingIds($crm, $ids, 'client');
            $known = $this->matchingIds($crm, $ids, 'known');
            $reprospecting = $this->matchingIds($crm, $ids, 'reprospecting');

            foreach ($ids as $id) {
                if (isset($client[$id])) {
                    $overrides[$id] = 'client';
                } elseif (isset($known[$id])) {
                    $overrides[$id] = 'known';
                } elseif (isset($reprospecting[$id])) {
                    $overrides[$id] = 'reprospecting';
                }
                // 31–89 dias, ausência de data ou tarefa pendente: mantém 'future'
                // para não apresentar uma evidência comercial que não existe.
            }
        }

        return $overrides;
    }

    /**
     * @param list<int> $ids
     * @return array<int, true>
     */
    private function matchingIds(
        LeadCrmSituationService $crm,
        array $ids,
        string $situation,
    ): array {
        if ($ids === []) {
            return [];
        }

        // O filtro V15 usa o alias 'work' na mesma fonte da tabela Leads.
        $query = Company::query()
            ->select('companies.id')
            ->leftJoin(
                'company_hubspot_leads as work',
                'work.company_id',
                '=',
                'companies.id',
            )
            ->whereIn('companies.id', $ids);

        $matching = [];
        foreach ($crm->apply($query, $situation)->pluck('companies.id') as $id) {
            $matching[(int) $id] = true;
        }

        return $matching;
    }
}
