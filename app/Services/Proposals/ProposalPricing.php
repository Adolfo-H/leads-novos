<?php

namespace App\Services\Proposals;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;

final class ProposalPricing
{
    public const OFFICIAL = ['LICENCIAMENTO', 'ACOMPANHAMENTO', 'RETROATIVAS'];

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
                'servicos_abrangidos' => self::OFFICIAL,
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
            throw ValidationException::withMessages(['service_codes' => 'A geração automática está aprovada somente para Licenciamento, Acompanhamento e Retroativas.']);
        }
        $date = (string) ($fields['price_date'] ?? now('America/Sao_Paulo')->toDateString());
        $settings = $this->configuration($date);
        $input = array_merge($settings, [
            'services' => $services,
            'price_date' => $date,
            'recurring_total' => (int) ($fields['recurring_total'] ?? 0),
            'retro_total' => (int) ($fields['retro_total'] ?? 0),
            'license_status' => (string) ($fields['license_status'] ?? 'NAO_ATIVO'),
        ]);
        $script = resource_path('proposals/bin/quote.py');
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
        $result['pricing_config'] = $settings;

        return $result;
    }
}
