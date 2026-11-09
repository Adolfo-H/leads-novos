<?php

namespace App\Services\Contacts;

use App\Jobs\ResearchWhatsappCompanyWebV87;
use App\Models\User;
use App\Support\TextNormalizer;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * V88: pesquisa uma empresa digitada pelo gestor, sem depender do snapshot V85/V86.
 * Reutiliza o executor, resultados e auditoria V87; não altera o CRM nem confirma WhatsApp.
 */
final class WhatsappCompanyQuickResearchV88
{
    private const RUNS = 'whatsapp_company_web_runs_v87';

    private const FINDINGS = 'whatsapp_company_web_findings_v87';

    public function keyFor(string $name): string
    {
        $normalized = TextNormalizer::companyName($name) ?? '';

        return 'name:'.hash('sha256', $normalized);
    }

    /** @return array{tavily:bool,brave:bool} */
    public function providers(): array
    {
        return app(WhatsappCompanyWebResearchV87::class)->providers();
    }

    public function latest(string $key): ?object
    {
        if (! preg_match('/^name:[a-f0-9]{64}$/D', $key)) {
            return null;
        }

        return DB::table(self::RUNS)->where('target_key', $key)->latest('id')->first();
    }

    /** @return Collection<int, \stdClass> */
    public function findings(?object $run): Collection
    {
        if ($run === null) {
            /** @var Collection<int, \stdClass> $empty */
            $empty = collect();

            return $empty;
        }

        /** @var Collection<int, \stdClass> $rows */
        $rows = DB::table(self::FINDINGS)->where('run_id', (int) data_get($run, 'id'))
            ->orderByRaw("CASE WHEN kind = 'explicit_whatsapp_link' THEN 0 ELSE 1 END")
            ->orderBy('phone_e164')->limit(120)->get();

        return $rows;
    }

    public function start(User $actor, string $rawName): int
    {
        abort_unless($actor->isCommercialManager(), 403);
        $name = trim(preg_replace('/\s+/u', ' ', $rawName) ?? '');
        $normalized = TextNormalizer::companyName($name) ?? '';
        if (mb_strlen($name) < 3 || mb_strlen($name) > 150
            || preg_match('/[A-Z]{3,}/', $normalized) !== 1) {
            throw ValidationException::withMessages([
                'wa88_name' => 'Informe o nome da empresa (mínimo 3 letras). Ex.: Agroterenas.',
            ]);
        }

        // O domínio só é usado se houver um único domínio explícito em Companies
        // do HubSpot que correspondam ao nome. Nunca procurar sites por adivinhação.
        $domain = SafePublicWhatsappWebsiteFetcher::domain($name)
            ?? $this->knownDomain($normalized);
        $providers = $this->providers();
        if ($domain === null && ! $providers['tavily'] && ! $providers['brave']) {
            throw ValidationException::withMessages([
                'wa88_name' => 'Para pesquisar pelo nome, configure Tavily ou Brave no Prospector. Não foi encontrado site único já cadastrado para essa empresa.',
            ]);
        }
        $key = $this->keyFor($name);
        $created = false;

        $runId = DB::transaction(function () use ($key, $name, $domain, $actor, &$created): int {
            if (DB::getDriverName() === 'pgsql') {
                // Impede duas pesquisas simultâneas do mesmo nome em requisições concorrentes.
                $lock = (int) hexdec(substr(hash('sha256', $key), 0, 7));
                DB::select('SELECT pg_advisory_xact_lock(27088, ?)', [$lock]);
            }
            $last = DB::table(self::RUNS)->where('target_key', $key)
                ->latest('id')->lockForUpdate()->first();
            if ($last !== null) {
                $status = (string) data_get($last, 'status');
                $updated = Carbon::parse((string) data_get($last, 'updated_at'), 'UTC');
                if (in_array($status, ['queued', 'running'], true)) {
                    if ($updated->greaterThan(now('UTC')->subMinutes(6))) {
                        return (int) data_get($last, 'id');
                    }
                    DB::table(self::RUNS)->where('id', (int) data_get($last, 'id'))
                        ->whereIn('status', ['queued', 'running'])
                        ->update([
                            'status' => 'timed_out', 'error_code' => 'job_timeout',
                            'finished_at' => now('UTC'), 'updated_at' => now('UTC'),
                        ]);
                } elseif ($status === 'completed' && $updated->greaterThan(now('UTC')->subMinutes(10))) {
                    // Reutiliza uma consulta recente: não gasta créditos ao clicar novamente.
                    return (int) data_get($last, 'id');
                } elseif (in_array($status, ['failed', 'unavailable'], true)
                    && $updated->greaterThan(now('UTC')->subMinute())) {
                    // Evita loop de tentativas se a API estiver sem créditos/indisponível.
                    return (int) data_get($last, 'id');
                }
            }

            $created = true;

            return (int) DB::table(self::RUNS)->insertGetId([
                'target_key' => $key,
                'company_label' => mb_substr($name, 0, 180),
                'domain' => $domain,
                'created_by' => $actor->id,
                'status' => 'queued',
                'findings_count' => 0,
                'created_at' => now('UTC'), 'updated_at' => now('UTC'),
            ]);
        }, 3);

        if ($created) {
            try {
                ResearchWhatsappCompanyWebV87::dispatch($runId)
                    ->onConnection('redis')->onQueue('default');
            } catch (Throwable $error) {
                report($error);
                app(WhatsappCompanyWebResearchV87::class)->markFailed($runId);
                throw ValidationException::withMessages([
                    'wa88_name' => 'A pesquisa não entrou na fila. Confira Redis e o worker default.',
                ]);
            }
        }

        return $runId;
    }

    private function knownDomain(string $normalized): ?string
    {
        $like = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($normalized));
        $possible = DB::table('hubspot_companies')
            ->whereRaw("LOWER(COALESCE(name, '')) LIKE ? ESCAPE '!'", [$like.'%'])
            ->limit(25)->get(['name', 'domain']);

        $matches = $possible->filter(static function (object $row) use ($normalized): bool {
            $candidate = TextNormalizer::companyName((string) data_get($row, 'name', '')) ?? '';

            return $candidate === $normalized || str_starts_with($candidate, $normalized.' ');
        });
        $domains = $matches->map(static fn (object $row): ?string => SafePublicWhatsappWebsiteFetcher::domain((string) data_get($row, 'domain', '')))
            ->filter()->unique()->values();

        // Mais de um domínio sugere empresas diferentes; não misturar os sites.
        return $domains->count() === 1 ? (string) $domains->first() : null;
    }
}
