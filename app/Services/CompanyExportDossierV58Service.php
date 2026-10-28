<?php

namespace App\Services;

use App\Models\Company;
use App\Models\CompanyExportEvidence;
use App\Models\CompanyExportIntelligence;
use DateTimeInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Leitura comercial EXPLICÁVEL das evidências já gravadas.
 *
 * O índice 0–100 é força dos indícios, não probabilidade estatística de
 * exportação nem confirmação fiscal. Evidência neutra jamais vira prova.
 * Este serviço não altera as classificações persistidas, scores SDR ou HubSpot.
 */
final class CompanyExportDossierV58Service
{
    /** @var array<string, list<string>> */
    private const PRODUCTS = [
        'Soja' => ['soja', 'soy', 'soybean'],
        'Milho' => ['milho', 'corn', 'maize'],
        'Grãos' => ['graos', 'grain'],
        'Açúcar' => ['acucar', 'sugar'],
        'Algodão' => ['algodao', 'cotton'],
        'Café' => ['cafe', 'coffee'],
        'Trigo' => ['trigo', 'wheat'],
        'Óleo vegetal' => ['oleo vegetal', 'vegetable oil'],
        'Farelo' => ['farelo', 'soy meal'],
        'Carne' => ['carne bovina', 'beef', 'chicken', 'frango'],
    ];

    /** @var array<string, list<string>> */
    private const MARKETS = [
        'China' => ['china'],
        'Ásia' => ['asia', 'asian market'],
        'Europa' => ['europa', 'europe'],
        'Estados Unidos' => ['estados unidos', 'united states'],
        'Oriente Médio' => ['oriente medio', 'middle east'],
        'América Latina' => ['america latina', 'latin america'],
        'Argentina' => ['argentina'],
        'Chile' => ['chile'],
        'Mercado internacional' => ['mercado internacional', 'international market'],
    ];

    /** @var array<string, list<string>> */
    private const OPERATIONS = [
        'trading' => ['trading', 'comercial exportadora'],
        'exportação' => ['exportacao', 'exportador', 'exporta', 'comercio exterior', 'foreign trade'],
        'armazenagem' => ['armazenagem', 'armazenamento', 'storage'],
        'logística' => ['logistica', 'logistics'],
        'venda para exportação' => ['fim especifico de exportacao', 'venda para exportacao'],
    ];

    /** @var array<string, list<string>> */
    private const CONTEXT = [
        'direct' => ['exporta para', 'exportou para', 'exportacao direta', 'exportacao de', 'embarques para', 'produz e exporta', 'comercio exterior', 'exportacao'],
        'indirect' => ['trading', 'comercial exportadora', 'exportacao indireta', 'fim especifico de exportacao', 'venda para exportacao'],
    ];

    /**
     * Coeficientes visíveis no código. Evitam que resultados de diretórios
     * genéricos sejam considerados tão fortes quanto fontes oficiais.
     *
     * @var array<string, float>
     */
    private const SOURCE_FACTORS = [
        'government' => 1.00,
        'company_site' => 0.95,
        'news' => 0.85,
        'industry' => 0.80,
        'other' => 0.65,
    ];

    /**
     * @return array<string, mixed>
     */
    public function build(Company $company): array
    {
        $company->loadMissing(['exportIntelligence', 'exportEvidence']);
        $intelligence = $company->exportIntelligence;
        $evidences = $company->exportEvidence;

        $direct = $this->dimension($evidences, 'direct', $intelligence);
        $indirect = $this->dimension($evidences, 'indirect', $intelligence);
        $hasConfirmed = (bool) ($direct['confirmed_positive'] || $indirect['confirmed_positive']);
        $hasPositive = (bool) ($direct['positive_count'] || $indirect['positive_count']);
        $hasContext = (bool) ($direct['context_count'] || $indirect['context_count']);
        $hasOpposing = (bool) ($direct['negative_count'] || $indirect['negative_count']);
        $index = max((int) $direct['index'], (int) $indirect['index']);

        $label = match (true) {
            $hasOpposing && ($hasPositive || $hasConfirmed) => 'Em verificação',
            $hasConfirmed => 'Sim, confirmado',
            $hasPositive => 'Possivelmente exporta',
            $hasContext && ! $hasOpposing => 'Possivelmente exporta',
            $hasContext => 'Em verificação',
            default => 'Não identificada',
        };

        $baseText = $evidences->map(static function (CompanyExportEvidence $item): string {
            return trim((string) ($item->title ?? '')).' '.trim((string) $item->evidence_text);
        })->implode(' ');
        $normalized = $this->normalize($baseText);

        $products = $this->extractTerms($normalized, self::PRODUCTS);
        $markets = $this->extractTerms($normalized, self::MARKETS);
        $operations = $this->extractTerms($normalized, self::OPERATIONS);

        $distinctSources = $evidences->map(fn (CompanyExportEvidence $e): string => $this->sourceKey($e))
            ->unique()->count();
        $evidenceCount = $evidences->count();

        if ($evidenceCount === 0) {
            $reading = 'Ainda não há fontes públicas registradas para avaliar a atuação exportadora desta empresa.';
        } else {
            $reading = 'A pesquisa registrou '.$evidenceCount.' evidência(s) em '.$distinctSources.' fonte(s) distinta(s).';
            if ($operations !== []) {
                $reading .= ' Cita '.mb_strtolower(implode(', ', array_slice($operations, 0, 3))).'.';
            }
            $reading .= match (true) {
                $hasConfirmed => ' Há evidência favorável validada por revisão humana.',
                $hasPositive && $hasOpposing => ' Há sinais positivos e contrários; é necessário conferir as fontes.',
                $hasPositive => ' Há indícios favoráveis, mas não comprovação fiscal da operação.',
                $hasContext => ' São pistas contextuais; a modalidade exportadora ainda precisa ser verificada.',
                default => ' Nenhuma fonte forneceu indício suficiente de exportação.',
            };
        }

        $productText = $products === [] ? 'Não identificados' : implode(', ', array_slice($products, 0, 4));
        $marketText = $markets === [] ? 'Não identificados' : implode(', ', array_slice($markets, 0, 3));

        return [
            'status' => $label,
            'index' => $index,
            'evidence_count' => $evidenceCount,
            'source_count' => $distinctSources,
            'reading' => Str::limit($reading, 310),
            'products' => $productText,
            'markets' => $marketText,
            'dimensions' => [$direct, $indirect],
            'has_support' => $hasPositive || $hasConfirmed,
        ];
    }

    /**
     * Apenas data ORIGINAL publicada pela fonte. Nunca observed_at, created_at,
     * researched_at ou data de execução do crawler.
     */
    public function publicationDate(CompanyExportEvidence $evidence): ?string
    {
        $candidates = [
            data_get($evidence, 'metadata.source_published_at'),
            data_get($evidence, 'metadata.published_at'),
            data_get($evidence, 'metadata.published_date'),
            data_get($evidence, 'metadata.publication_date'),
            data_get($evidence, 'metadata.datePublished'),
            data_get($evidence, 'metadata.article_published_at'),
        ];

        foreach ($candidates as $candidate) {
            $value = null;
            if ($candidate instanceof DateTimeInterface) {
                $value = $candidate->format('Y-m-d');
            } elseif (is_string($candidate)) {
                $value = trim($candidate);
            }

            if ($value === null || preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:[T\s].*)?$/', $value, $matches) !== 1) {
                continue;
            }

            $year = (int) $matches[1];
            $month = (int) $matches[2];
            $day = (int) $matches[3];
            if ($year < 1990 || ! checkdate($month, $day, $year)) {
                continue;
            }
            $isoDate = sprintf('%04d-%02d-%02d', $year, $month, $day);
            if ($isoDate > today()->toDateString()) {
                continue;
            }

            return sprintf('%02d/%02d/%04d', $day, $month, $year);
        }

        return null;
    }

    /**
     * @param  Collection<int, CompanyExportEvidence>  $evidences
     * @return array<string, int|string|bool>
     */
    private function dimension(Collection $evidences, string $key, ?CompanyExportIntelligence $intelligence): array
    {
        $positive = [];
        $negative = [];
        $context = [];
        // DOSSIE_EXPORTACAO_V582: testar null uma só vez antes de acessar os atributos.
        // Evita os avisos PHPStan de nullsafe desnecessário sem mudar a regra comercial.
        $confirmedPositive = false;
        if ($intelligence !== null) {
            if ($key === 'direct') {
                $confirmedPositive = (bool) $intelligence->direct_confirmed
                    && $intelligence->direct_status === 'yes';
            } else {
                $confirmedPositive = (bool) $intelligence->indirect_confirmed
                    && $intelligence->indirect_status === 'yes';
            }
        }
        $confirmedNegative = false;
        $dimensionKeys = $key === 'indirect' ? ['indirect', 'trading'] : ['direct'];

        foreach ($evidences as $evidence) {
            if (! in_array((string) $evidence->dimension, $dimensionKeys, true)) {
                continue;
            }
            $sourceKey = $this->sourceKey($evidence);
            $value = max(0, min(100, (int) $evidence->confidence));
            $factor = self::SOURCE_FACTORS[(string) $evidence->source_type] ?? self::SOURCE_FACTORS['other'];
            $weighted = (int) round($value * $factor);
            $signal = (string) $evidence->signal;
            if ($signal === 'positive') {
                $positive[$sourceKey] = max($positive[$sourceKey] ?? 0, $weighted);
                if ($evidence->is_confirmed) {
                    $confirmedPositive = $confirmedPositive || $key === 'direct' || (string) $evidence->dimension === 'indirect';
                }
            } elseif ($signal === 'negative') {
                $negative[$sourceKey] = max($negative[$sourceKey] ?? 0, $weighted);
                if ($evidence->is_confirmed) {
                    $confirmedNegative = true;
                }
            } else {
                $normalized = $this->normalize(trim((string) ($evidence->title ?? '')).' '.(string) $evidence->evidence_text);
                if ($this->containsAny($normalized, self::CONTEXT[$key])) {
                    $context[$sourceKey] = max($context[$sourceKey] ?? 0, $weighted);
                }
            }
        }

        $positiveCount = count($positive);
        $negativeCount = count($negative);
        $contextCount = count($context);

        if ($confirmedPositive && $confirmedNegative) {
            $index = 50;
        } elseif ($confirmedPositive && $negativeCount > 0) {
            $index = max(20, 100 - (int) round(max($negative) * 0.50));
        } elseif ($confirmedPositive) {
            $index = 100;
        } elseif ($positiveCount > 0) {
            $index = min(95, max($positive) + min(20, ($positiveCount - 1) * 8));
            if ($negativeCount > 0) {
                $index = max(0, $index - (int) round(max($negative) * 0.50));
            }
        } elseif ($contextCount > 0) {
            // Evidência neutra = pista contextual; nunca se transforma em prova.
            $index = min(35, (int) round(max($context) * 0.35) + min(12, ($contextCount - 1) * 4));
        } else {
            $index = 0;
        }

        $index = max(0, min(100, $index));
        $badge = match (true) {
            $confirmedPositive && $confirmedNegative => 'Conflito validado',
            $confirmedPositive && $negativeCount > 0 => 'Sinais divergentes',
            $confirmedPositive => 'Sim, validada',
            $positiveCount > 0 && $negativeCount > 0 => 'Sinais divergentes',
            $positiveCount > 0 => 'Possível',
            $contextCount > 0 => 'Indício contextual',
            $confirmedNegative => 'Sinal negativo validado',
            $negativeCount > 0 => 'Indício contrário',
            default => 'Não identificada',
        };

        $note = match (true) {
            $confirmedPositive && $confirmedNegative => 'Revisões positivas e negativas em conflito; checagem obrigatória.',
            $confirmedPositive && $negativeCount > 0 => 'Sinal positivo validado, mas há fonte em sentido contrário.',
            $confirmedPositive => 'Evidência positiva confirmada por revisão humana.',
            $positiveCount > 0 && $negativeCount > 0 => 'Fontes positivas e contrárias; revisar antes de classificar.',
            $positiveCount > 0 => $positiveCount.' fonte(s) independente(s) com sinal positivo.',
            $contextCount > 0 => $contextCount.' fonte(s) citam o tema, sem comprovar a operação.',
            $confirmedNegative => 'Evidência negativa confirmada por revisão humana.',
            $negativeCount > 0 => 'Há fonte(s) com sinal contrário à modalidade.',
            default => 'Nenhum sinal específico nas fontes disponíveis.',
        };

        return [
            'key' => $key,
            'label' => $key === 'direct' ? 'Direta' : 'Indireta',
            'index' => $index,
            'badge' => $badge,
            'note' => $note,
            'positive_count' => $positiveCount,
            'negative_count' => $negativeCount,
            'context_count' => $contextCount,
            'confirmed_positive' => $confirmedPositive,
        ];
    }

    private function normalize(string $text): string
    {
        $stripped = Str::lower(Str::ascii($text));

        return trim((string) preg_replace('/[^a-z0-9]+/', ' ', $stripped));
    }

    /**
     * @param  list<string>  $terms
     */
    private function containsAny(string $text, array $terms): bool
    {
        foreach ($terms as $term) {
            $normalized = $this->normalize($term);
            if ($normalized !== '' && preg_match('/(?:^| )'.preg_quote($normalized, '/').'(?= |$)/', $text) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, list<string>>  $dictionary
     * @return list<string>
     */
    private function extractTerms(string $normalizedText, array $dictionary): array
    {
        $found = [];
        foreach ($dictionary as $label => $terms) {
            if ($this->containsAny($normalizedText, $terms)) {
                $found[] = $label;
            }
        }

        return $found;
    }

    private function sourceKey(CompanyExportEvidence $evidence): string
    {
        $url = trim((string) $evidence->source_url);
        if ($url !== '') {
            $host = parse_url($url, PHP_URL_HOST);
            $path = parse_url($url, PHP_URL_PATH);
            if (is_string($host) && $host !== '') {
                return Str::lower($host.'|'.(is_string($path) ? rtrim($path, '/') : ''));
            }
        }

        return 'evidence:'.(string) $evidence->id;
    }
}
