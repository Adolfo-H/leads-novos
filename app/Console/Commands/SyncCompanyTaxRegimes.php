<?php

namespace App\Console\Commands;

use App\Models\Company;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/** Enriquecimento local, sem consultar serviços fiscais externos nem HubSpot. */
final class SyncCompanyTaxRegimes extends Command
{
    protected $signature = 'prospector:sync-tax-regimes {--batch=200} {--limit=0}';

    protected $description = 'Atualiza optantes Simples/MEI das empresas existentes pela base RFB local';

    public function handle(): int
    {
        $size = max(1, min(400, (int) $this->option('batch')));
        $limit = max(0, (int) $this->option('limit'));
        $processed = 0;
        $baseUrl = rtrim((string) config('services.receita_local.base_url'), '/');
        try {
            Company::query()->select(['id', 'cnpj_root'])->orderBy('id')
                ->chunkById($size, function ($companies) use ($baseUrl, $limit, &$processed): bool {
                    if ($limit > 0 && $processed >= $limit) {
                        return false;
                    }
                    $subset = $limit > 0 ? $companies->take($limit - $processed) : $companies;
                    $roots = $subset->pluck('cnpj_root')->map(
                        static fn ($root): string => (string) $root
                    )->all();
                    if ($roots === []) {
                        return false;
                    }
                    $response = Http::acceptJson()->connectTimeout(5)->timeout(60)
                        ->get($baseUrl.'/tax-regimes', ['roots' => implode(',', $roots)]);
                    if (! $response->successful() || ! is_array($response->json('statuses'))
                        || ! is_string($response->json('period'))) {
                        throw new RuntimeException('Base fiscal local indisponível ou incompleta; nenhuma inferência realizada.');
                    }
                    $statuses = $response->json('statuses');
                    $period = $response->json('period');
                    foreach ($subset as $company) {
                        $tax = $statuses[(string) $company->cnpj_root] ?? null;
                        if (! is_array($tax) || ! array_key_exists('simples_option', $tax)
                            || ! array_key_exists('mei_option', $tax)) {
                            throw new RuntimeException('O serviço não informou o regime de todas as empresas; confira a base.');
                        }
                        DB::table('companies')
                            ->where('id', $company->id)
                            ->update([
                                'simples_option' => $tax['simples_option'],
                                'mei_option' => $tax['mei_option'],
                                'tax_source_period' => $period,
                                'updated_at' => now(),
                            ]);
                        $processed++;
                    }
                    $this->line("Empresas verificadas: {$processed}");

                    return true;
                });
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
        $this->info("Regimes fiscais verificados: {$processed} empresa(s).");

        return self::SUCCESS;
    }
}
