<?php

namespace App\Services;

/**
 * Apresentação exclusivamente visual do ICP v3 já calculado.
 * Não altera score, regras de priorização ou dados persistidos.
 */
final class DossierIcpPresentationV60Service
{
    /**
     * @param  array<string, mixed>  $factors
     * @return list<array{key: string, label: string, reason: string, points: int, max: int, pct: float}>
     */
    public function rows(array $factors): array
    {
        $order = ['cnae', 'state', 'size', 'capital', 'legal_nature', 'regional_relevance'];
        $rows = [];

        foreach ($order as $key) {
            $raw = $factors[$key] ?? null;
            if (! is_array($raw)) {
                continue;
            }

            $max = max(0, (int) ($raw['max'] ?? 0));
            $points = max(0, (int) ($raw['points'] ?? 0));
            $rows[] = [
                'key' => $key,
                'label' => $this->label($key, $raw),
                'reason' => trim((string) ($raw['reason'] ?? '')),
                'points' => $points,
                'max' => $max,
                'pct' => $max > 0 ? round(min(100, 100 * $points / $max), 1) : 0.0,
            ];
        }

        return $rows;
    }

    /** @param array<string, mixed> $factor */
    private function label(string $key, array $factor): string
    {
        return match ($key) {
            'cnae' => $this->cnaeLabel($factor),
            'state' => $this->stateLabel($factor),
            'size' => $this->sizeLabel($factor),
            'capital' => $this->capitalLabel($factor),
            'legal_nature' => $this->legalNatureLabel($factor),
            'regional_relevance' => $this->regionalLabel($factor),
            default => 'Fator do ICP',
        };
    }

    /** @param array<string, mixed> $factor */
    private function cnaeLabel(array $factor): string
    {
        $points = (int) ($factor['points'] ?? 0);
        $kind = match (true) {
            $points >= 30 => 'principal prioritário',
            $points >= 20 => 'secundário prioritário',
            default => 'sem CNAE prioritário identificado',
        };

        return 'CNAE do grupo: '.$kind;
    }

    /** @param array<string, mixed> $factor */
    private function stateLabel(array $factor): string
    {
        $matched = $this->stringValues($factor['matched_states'] ?? []);
        $states = $this->stringValues($factor['states'] ?? []);

        if ($matched !== []) {
            return 'Estado: '.$this->naturalList($matched).' prioritários';
        }

        if ($states !== []) {
            return 'Estado: '.$this->naturalList($states).' (fora do perfil prioritário)';
        }

        return 'Estado: não informado';
    }

    /** @param array<string, mixed> $factor */
    private function sizeLabel(array $factor): string
    {
        $code = trim((string) ($factor['value'] ?? ''));
        $description = trim((string) ($factor['description'] ?? ''));
        if ($code === '') {
            return 'Porte: não informado';
        }
        if ($description === '') {
            return 'Porte: código '.$code;
        }

        return 'Porte: código '.$code.' ('.$description.')';
    }

    /** @param array<string, mixed> $factor */
    private function capitalLabel(array $factor): string
    {
        $amount = $factor['value'] ?? null;
        if (! is_numeric($amount)) {
            return 'Capital social: não informado';
        }
        $capital = (float) $amount;
        $formatted = match (true) {
            $capital >= 1_000_000 => 'R$ '.$this->displayNumber($capital / 1_000_000).' milhões',
            $capital >= 1_000 => 'R$ '.$this->displayNumber($capital / 1_000).' mil',
            default => 'R$ '.number_format($capital, 2, ',', '.'),
        };
        $comparison = $capital > 1_000_000 ? ' > R$ 1 milhão' : ' ≤ R$ 1 milhão';

        return 'Capital: '.$formatted.$comparison;
    }

    /** @param array<string, mixed> $factor */
    private function legalNatureLabel(array $factor): string
    {
        $code = trim((string) ($factor['code'] ?? ''));
        $description = trim((string) ($factor['description'] ?? ''));
        $normalized = strtolower($description);

        if (str_contains($normalized, 'limitada')) {
            $description = 'Ltda.';
        } elseif (str_contains($normalized, 'sociedade anônima') || str_contains($normalized, 'sociedade anonima')) {
            $description = 'S.A.';
        }

        if ($code === '' && $description === '') {
            return 'Natureza jurídica: não informada';
        }
        if ($code === '') {
            return 'Natureza jurídica: '.$description;
        }

        return 'Natureza jurídica: '.$code.($description !== '' ? ' ('.$description.')' : '');
    }

    /** @param array<string, mixed> $factor */
    private function regionalLabel(array $factor): string
    {
        $count = max(0, (int) ($factor['establishments'] ?? 0));

        return 'Relevância regional: '.$count.' '.($count === 1 ? 'unidade ativa' : 'unidades ativas');
    }

    private function displayNumber(float $value): string
    {
        return number_format($value, abs($value - round($value)) < 0.01 ? 0 : 1, ',', '.');
    }

    /**
     * @return list<string>
     */
    private function stringValues(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $values = [];
        foreach ($raw as $item) {
            if (! is_scalar($item)) {
                continue;
            }
            $value = trim((string) $item);
            if ($value !== '' && ! in_array($value, $values, true)) {
                $values[] = $value;
            }
        }

        return $values;
    }

    /** @param list<string> $values */
    private function naturalList(array $values): string
    {
        if (count($values) <= 1) {
            return implode('', $values);
        }

        $last = (string) array_pop($values);

        return implode(', ', $values).' e '.$last;
    }
}
