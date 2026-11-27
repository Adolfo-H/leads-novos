<?php

// EC_PROPOSALS_V27

namespace App\Services\Proposals;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;

final class ProposalPricing
{
    public const LEGACY = ['LICENCIAMENTO', 'ACOMPANHAMENTO', 'RETROATIVAS'];

    public const OFFICIAL = ['LICENCIAMENTO', 'ACOMPANHAMENTO', 'RETROATIVAS', 'DFE', 'NFSE'];

    public const ALL = ['LICENCIAMENTO', 'ACOMPANHAMENTO', 'RETROATIVAS', 'DFE', 'NFSE'];

    /** @return array<string, mixed> */
    public function configuration(string $date): array
    {
        $prices = [];
        foreach (self::OFFICIAL as $service) {
            $row = DB::table('ec_proposal_price_versions')
                ->where('service', $service)->where('approved', true)
                ->where('effective_from', '<=', $date)
                ->orderByDesc('effective_from')->orderByDesc('id')->first();
            if ($row === null) {
                if (! in_array($service, self::LEGACY, true)) {
                    continue;
                }
                throw ValidationException::withMessages(['prices' => 'Tabela aprovada indisponível: '.$service]);
            }
            $prices[$service] = json_decode((string) $row->configuration, true, 512, JSON_THROW_ON_ERROR);
        }
        $adjustments = DB::table('ec_proposal_adjustments')
            ->where('effective_from', '<=', $date)->orderBy('effective_from')->orderBy('id')->get();
        $tax = DB::table('ec_proposal_tax_profiles')
            ->where('approved', true)->where('effective_from', '<=', $date)
            ->orderByDesc('effective_from')->orderByDesc('id')->first();
        if ($tax === null) {
            throw ValidationException::withMessages(['prices' => 'Perfil de tributos aprovado indisponível.']);
        }
        $events = [];
        foreach ($adjustments as $event) {
            $events[] = [
                'id' => (string) $event->event_key,
                'vigencia_inicio' => (string) $event->effective_from,
                'percentual' => (string) $event->percentage,
                'status' => 'APROVADO', 'motivo' => (string) $event->reason,
            ];
        }

        return [
            'prices' => $prices,
            'adjustments' => [
                'versao' => 'PROSPECTOR_DATABASE', 'status' => 'APROVADO',
                'abrangencia' => 'TODOS_OS_SERVICOS_E_TODAS_AS_FAIXAS',
                'servicos_abrangidos' => self::LEGACY,
                'reajustes' => $events,
            ],
            'rates' => json_decode((string) $tax->rates, true, 512, JSON_THROW_ON_ERROR),
            'tax_version' => (string) $tax->version,
        ];
    }

    /** @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    public function quote(array $fields): array
    {
        /** @var list<string> $services */
        $services = array_values(array_unique(array_filter((array) ($fields['service_codes'] ?? []), 'is_string')));
        if ($services === [] || array_diff($services, self::OFFICIAL) !== []) {
            throw ValidationException::withMessages(['service_codes' => 'Selecione serviços com modelo e tabela aprovados.']);
        }
        $date = (string) ($fields['price_date'] ?? now('America/Sao_Paulo')->toDateString());
        $settings = $this->configuration($date);
        foreach (array_intersect($services, ['DFE', 'NFSE']) as $service) {
            $template = DB::table('ec_proposal_templates')->where('service', $service)->where('ready_to_issue', true)->first();
            if ($template === null || ! isset($settings['prices'][$service]) || ! is_file(resource_path('proposals/official/'.(string) $template->source_file))) {
                throw ValidationException::withMessages(['service_codes' => 'Modelo ou tabela indisponível na data-base: '.$service]);
            }
        }
        $input = array_merge($settings, [
            'services' => $services,
            'price_date' => $date,
            'recurring_total' => (int) ($fields['recurring_total'] ?? 0),
            'retro_total' => (int) ($fields['retro_total'] ?? 0),
            'dfe_cnpj_count' => $fields['dfe_cnpj_count'] ?? null,
            'dfe_total' => $fields['dfe_total'] ?? null,
            'nfse_cnpj_count' => $fields['nfse_cnpj_count'] ?? null,
            'nfse_total' => $fields['nfse_total'] ?? null,
            'license_status' => (string) ($fields['license_status'] ?? 'NAO_ATIVO'),
        ]);
        $result = $this->runCalculator($input);
        $result['pricing_config'] = $settings;

        return $result;
    }

    /** @return array<string, mixed> */
    public function tables(string $date): array
    {
        return $this->runCalculator(array_merge($this->configuration($date), ['price_date' => $date, 'mode' => 'tables']));
    }

    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function runCalculator(array $input): array
    {
        $script = resource_path('proposals/bin/quote_v27.py');
        $process = new Process(['python3', $script], base_path());
        $process->setInput(json_encode($input, JSON_THROW_ON_ERROR));
        $process->setTimeout(20);
        $process->run();
        $result = json_decode($process->getOutput(), true);
        if (! $process->isSuccessful() || ! is_array($result) || isset($result['error'])) {
            throw ValidationException::withMessages([
                'prices' => is_array($result) && is_string($result['error'] ?? null)
                    ? $result['error'] : 'Falha na calculadora oficial. Confira Python e as tabelas de preços.',
            ]);
        }

        return $result;
    }
}
