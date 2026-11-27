<?php

// EC_PROPOSALS_V27

namespace App\Services\Proposals;

use App\Models\EcProposal;
use App\Models\User;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;

final class ProposalWorkflow
{
    /** @param array<string, mixed> $values */
    public function saveDraft(User $actor, array $values, ?EcProposal $proposal = null): EcProposal
    {
        abort_unless($actor->isCommercialManager() || $actor->isCommercialSeller(), 403);
        if ($proposal !== null && $proposal->status !== 'draft') {
            throw ValidationException::withMessages(['proposal' => 'Somente rascunhos podem ser editados.']);
        }
        $services = array_values(array_unique((array) ($values['service_codes'] ?? [])));
        if ($services === [] || array_diff($services, ProposalPricing::ALL) !== []) {
            throw ValidationException::withMessages(['service_codes' => 'Selecione pelo menos um serviço válido.']);
        }
        if ($proposal?->parent_id) {
            $parent = EcProposal::query()->findOrFail($proposal->parent_id);
            if ($services !== (array) $parent->service_codes) {
                throw ValidationException::withMessages(['service_codes' => 'Uma revisão mantém os mesmos serviços. Para novo escopo, duplique como nova proposta com nova numeração.']);
            }
        }
        // Salvar rascunho incompleto é permitido; cotação exige os volumes obrigatórios.
        $quote = null;
        $official = array_diff($services, ProposalPricing::OFFICIAL) === [];
        $hasVolumes = (! array_intersect($services, ['LICENCIAMENTO', 'ACOMPANHAMENTO']) || (int) ($values['recurring_total'] ?? 0) > 0)
            && (! in_array('RETROATIVAS', $services, true) || (int) ($values['retro_total'] ?? 0) > 0)
            && (! in_array('DFE', $services, true) || ((int) ($values['dfe_cnpj_count'] ?? 0) > 0 && isset($values['dfe_total'])))
            && (! in_array('NFSE', $services, true) || ((int) ($values['nfse_cnpj_count'] ?? 0) > 0 && (int) ($values['nfse_total'] ?? 0) > 0));
        if ($official && $hasVolumes) {
            $quote = app(ProposalPricing::class)->quote($values);
        }
        $attributes = [
            'client_name' => trim((string) $values['client_name']),
            'client_contact' => trim((string) ($values['client_contact'] ?? '')) ?: null,
            'client_email' => trim((string) ($values['client_email'] ?? '')) ?: null,
            'client_cnpj' => trim((string) ($values['client_cnpj'] ?? '')) ?: null,
            'company_id' => $values['company_id'] ?? null,
            'hubspot_company_id' => $values['hubspot_company_id'] ?? null,
            'hubspot_deal_id' => $values['hubspot_deal_id'] ?? null,
            'service_codes' => $services,
            'price_date' => (string) ($values['price_date'] ?? now('America/Sao_Paulo')->toDateString()),
            'recurring_total' => ($values['recurring_total'] ?? null) ?: null,
            'recurring_from' => ($values['recurring_from'] ?? null) ?: null,
            'recurring_to' => ($values['recurring_to'] ?? null) ?: null,
            'retro_total' => ($values['retro_total'] ?? null) ?: null,
            'dfe_cnpj_count' => in_array('DFE', $services, true) ? ($values['dfe_cnpj_count'] ?? null) : null,
            'dfe_total' => in_array('DFE', $services, true) ? ($values['dfe_total'] ?? null) : null,
            'nfse_cnpj_count' => in_array('NFSE', $services, true) ? ($values['nfse_cnpj_count'] ?? null) : null,
            'nfse_total' => in_array('NFSE', $services, true) ? ($values['nfse_total'] ?? null) : null,
            'retro_from' => ($values['retro_from'] ?? null) ?: null,
            'retro_to' => ($values['retro_to'] ?? null) ?: null,
            'license_status' => (string) ($values['license_status'] ?? 'NAO_ATIVO'),
            'retro_deadline' => trim((string) ($values['retro_deadline'] ?? '')) ?: null,
            'validity_days' => (int) ($values['validity_days'] ?? 30),
            'payment_days' => (int) ($values['payment_days'] ?? 10),
            'sales_responsible' => trim((string) ($values['sales_responsible'] ?? '')) ?: null,
            'notes' => trim((string) ($values['notes'] ?? '')) ?: null,
            'price_snapshot' => $quote,
            'monthly_final' => $quote['monthly_final'] ?? null,
            'one_time_final' => $quote['one_time_final'] ?? null,
            'updated_by' => $actor->id,
        ];

        return DB::transaction(function () use ($proposal, $attributes, $actor): EcProposal {
            if ($proposal === null) {
                $attributes['public_id'] = (string) Str::uuid();
                $attributes['status'] = 'draft';
                $attributes['created_by'] = $actor->id;
                $proposal = EcProposal::query()->create($attributes);
                $this->event($proposal, $actor, 'draft_created');
            } else {
                $proposal->update($attributes);
                $this->event($proposal, $actor, 'draft_updated');
            }

            return $proposal;
        });
    }

    /** @param array<string, mixed> $meta */
    public function event(EcProposal $proposal, User $actor, string $type, array $meta = []): void
    {
        DB::table('ec_proposal_events')->insert([
            'proposal_id' => $proposal->id,
            'event' => $type,
            'metadata' => json_encode($meta, JSON_THROW_ON_ERROR),
            'actor_id' => $actor->id,
            'created_at' => now('UTC'),
        ]);
    }

    /** @return array<string,int> */
    private function reserveNumbers(EcProposal $proposal): array
    {
        $counter = DB::table('ec_proposal_numbering')->where('id', 1)->lockForUpdate()->first();
        if ($counter === null || $counter->reconciled_at === null) {
            throw ValidationException::withMessages(['numbering' => 'Numeração bloqueada: importe o histórico e confirme o último número emitido em Configurações.']);
        }
        $number = (int) $counter->next_number;
        $assigned = [];
        $codes = array_values(array_intersect(ProposalPricing::OFFICIAL, (array) $proposal->service_codes));
        foreach ($codes as $code) {
            if (DB::table('ec_proposal_number_ledger')->where('number', $number)->exists()) {
                throw ValidationException::withMessages(['numbering' => 'Conflito na numeração global; interrompido sem emitir.']);
            }
            $assigned[$code] = $number;
            DB::table('ec_proposal_number_ledger')->insert([
                'number' => $number, 'proposal_id' => $proposal->id,
                'service' => $code, 'source' => 'issued',
                'created_at' => now('UTC'), 'updated_at' => now('UTC'),
            ]);
            $number++;
        }
        DB::table('ec_proposal_numbering')->where('id', 1)
            ->update(['next_number' => $number, 'updated_at' => now('UTC')]);

        return $assigned;
    }

    public function issue(User $actor, EcProposal $proposal): EcProposal
    {
        abort_unless($actor->isCommercialManager(), 403);
        // Falhas de dependências são detectadas ANTES de consumir qualquer número.
        $readiness = new Process([
            'python3', '-c',
            'import shutil; import playwright.sync_api, fitz, bs4; assert (shutil.which("chromium") or shutil.which("chromium-browser") or shutil.which("google-chrome")), "Chromium ausente"',
        ]);
        $readiness->setTimeout(12);
        $readiness->run();
        if (! $readiness->isSuccessful()) {
            throw ValidationException::withMessages(['proposal' => 'Renderizador PDF indisponível. Configure Python/Playwright/PyMuPDF/BeautifulSoup e Chromium; nenhum número foi reservado.']);
        }
        // Reservas são atômicas e nunca são reutilizadas, inclusive após falha de PDF.
        $proposal = DB::transaction(function () use ($proposal, $actor): EcProposal {
            $locked = EcProposal::query()->whereKey($proposal->id)->lockForUpdate()->firstOrFail();
            if (! in_array($locked->status, ['draft', 'generation_failed'], true)) {
                throw ValidationException::withMessages(['proposal' => 'Proposta já emitida ou não editável.']);
            }
            $this->assertComplete($locked);
            if ($locked->status === 'draft') {
                $quote = app(ProposalPricing::class)->quote([
                    'service_codes' => $locked->service_codes,
                    'price_date' => self::formatDate($locked->price_date, 'Y-m-d'),
                    'recurring_total' => $locked->recurring_total,
                    'retro_total' => $locked->retro_total,
                    'dfe_cnpj_count' => $locked->getAttribute('dfe_cnpj_count'),
                    'dfe_total' => $locked->getAttribute('dfe_total'),
                    'nfse_cnpj_count' => $locked->getAttribute('nfse_cnpj_count'),
                    'nfse_total' => $locked->getAttribute('nfse_total'),
                    'license_status' => $locked->license_status,
                ]);
                if ($locked->parent_id !== null) {
                    $parent = EcProposal::query()->whereKey($locked->parent_id)->lockForUpdate()->firstOrFail();
                    abort_unless(in_array($parent->status, ['issued', 'sent', 'accepted', 'partially_accepted'], true), 422);
                    if ((array) $parent->service_codes !== (array) $locked->service_codes || ! $parent->proposal_numbers) {
                        throw ValidationException::withMessages(['proposal' => 'Revisão inconsistente com a proposta original.']);
                    }
                    $locked->setAttribute('proposal_numbers', $parent->proposal_numbers);
                } else {
                    $locked->setAttribute('proposal_numbers', $this->reserveNumbers($locked));
                }
                $locked->setAttribute('price_snapshot', $quote);
                $locked->monthly_final = $quote['monthly_final'];
                $locked->one_time_final = $quote['one_time_final'];
                $locked->issued_on = now('America/Sao_Paulo')->toDateString();
                $locked->issued_at = now('UTC');
            }
            $locked->status = 'generating';
            $locked->issue_error = null;
            $locked->setAttribute('updated_by', (int) $actor->getKey());
            $locked->save();
            $this->event($locked, $actor, 'numbers_reserved', ['numbers' => $locked->proposal_numbers]);

            return $locked;
        }, 3);

        try {
            $this->renderOfficial($proposal);
            $proposal->forceFill(['status' => 'issued', 'issue_error' => null])->save();
            $this->event($proposal, $actor, 'issued', ['numbers' => $proposal->proposal_numbers]);
        } catch (\Throwable $exception) {
            report($exception);
            $proposal->forceFill([
                'status' => 'generation_failed',
                'issue_error' => mb_substr($exception->getMessage(), 0, 1000),
            ])->save();
            $this->event($proposal, $actor, 'generation_failed');
            throw ValidationException::withMessages([
                'proposal' => 'A numeração foi reservada e preservada. Falha ao gerar PDF: '.$exception->getMessage().'. Corrija o renderizador e use Reprocessar, sem criar outro número.',
            ]);
        }

        return $proposal->fresh() ?? $proposal;
    }

    private function assertComplete(EcProposal $proposal): void
    {
        $services = (array) ($proposal->service_codes ?? []);
        if ($services === [] || array_diff($services, ProposalPricing::OFFICIAL) !== []) {
            throw ValidationException::withMessages(['proposal' => 'Selecione serviços com modelos e preços aprovados.']);
        }
        if (! filter_var($proposal->client_email, FILTER_VALIDATE_EMAIL) || trim((string) $proposal->client_contact) === '') {
            throw ValidationException::withMessages(['proposal' => 'Informe nome e e-mail do responsável principal.']);
        }
        if (! is_string($proposal->client_logo_path) || ! Storage::disk('local')->exists($proposal->client_logo_path)) {
            throw ValidationException::withMessages(['proposal' => 'Anexe o logo do cliente antes da emissão.']);
        }
        if (array_intersect($services, ['LICENCIAMENTO', 'ACOMPANHAMENTO']) !== [] && (
            (int) $proposal->recurring_total < 1 || ! $proposal->recurring_from || ! $proposal->recurring_to
        )) {
            throw ValidationException::withMessages(['proposal' => 'Informe volume e período dos últimos 12 meses.']);
        }
        if (in_array('DFE', $services, true) && ((int) $proposal->getAttribute('dfe_cnpj_count') < 1 || (int) $proposal->getAttribute('dfe_cnpj_count') > 350 || $proposal->getAttribute('dfe_total') === null)) {
            throw ValidationException::withMessages(['proposal' => 'Informe de 1 a 350 CNPJs e o volume anual de DF-e.']);
        }
        if (in_array('NFSE', $services, true) && ((int) $proposal->getAttribute('nfse_cnpj_count') < 1 || (int) $proposal->getAttribute('nfse_total') < 6)) {
            throw ValidationException::withMessages(['proposal' => 'Informe CNPJs e volume anual de NFS-e com média arredondada positiva.']);
        }
        if (in_array('RETROATIVAS', $services, true) && (
            (int) $proposal->retro_total < 1 || ! $proposal->retro_from || ! $proposal->retro_to
        )) {
            throw ValidationException::withMessages(['proposal' => 'Informe volume e período das retroativas.']);
        }
    }

    private function renderOfficial(EcProposal $proposal): void
    {
        $working = storage_path('app/private/proposals/tmp/'.$proposal->public_id.'-'.Str::random(8));
        $source = $working.'/source';
        $out = $working.'/out';
        File::ensureDirectoryExists($working);
        try {
            if (! File::copyDirectory(resource_path('proposals/official'), $source)) {
                throw new \RuntimeException('Não foi possível copiar as fontes oficiais para geração.');
            }
            // Atributos Eloquent com cast JSON são lidos como arrays em execução;
            // validar o snapshot em vez de assumir tipos só por PHPDoc ou schema.
            $quote = $proposal->getAttribute('price_snapshot');
            if (! is_array($quote)) {
                throw new \RuntimeException('Snapshot de preços ausente ou inválido.');
            }
            $cfg = $quote['pricing_config'] ?? null;
            if (! is_array($cfg)) {
                throw new \RuntimeException('Configuração congelada da proposta indisponível.');
            }
            $prices = $cfg['prices'] ?? null;
            $adjustments = $cfg['adjustments'] ?? null;
            $rates = $cfg['rates'] ?? null;
            if (! is_array($prices) || ! is_array($adjustments) || ! is_array($rates)) {
                throw new \RuntimeException('Snapshot de preços, reajustes ou tributos incompleto.');
            }
            $priceFiles = [
                'LICENCIAMENTO' => '06_PRECOS_LICENCIAMENTO.json',
                'ACOMPANHAMENTO' => '07_PRECOS_ACOMPANHAMENTO.json',
                'RETROATIVAS' => '08_PRECOS_RETROATIVAS.json',
            ];
            foreach ($prices as $service => $price) {
                if (! is_string($service) || ! isset($priceFiles[$service])) {
                    continue;
                }
                file_put_contents($source.'/'.$priceFiles[$service], json_encode($price, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            }
            $tax = json_decode((string) file_get_contents($source.'/05_TRIBUTOS_ATUAIS.json'), true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($tax)) {
                throw new \RuntimeException('Perfil tributário oficial inválido.');
            }
            $tax['aliquotas_percentuais'] = $rates;
            file_put_contents($source.'/05_TRIBUTOS_ATUAIS.json', json_encode($tax, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            file_put_contents($source.'/26_CONTROLE_REAJUSTES_ANUAIS.json', json_encode($adjustments, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            $logo = Storage::disk('local')->path((string) $proposal->client_logo_path);
            $services = array_values(array_intersect(ProposalPricing::OFFICIAL, (array) $proposal->service_codes));
            $input = [
                'solicitacao' => 'GERAR_PROPOSTA',
                'data_emissao' => self::formatDate($proposal->issued_on, 'Y-m-d'),
                'data_base_precificacao' => self::formatDate($proposal->price_date, 'Y-m-d'),
                'numeros' => $proposal->proposal_numbers,
                'servicos' => $services,
                'cliente' => [
                    'razao_social' => $proposal->client_name,
                    'nome_fantasia' => '', 'cnpj' => $proposal->client_cnpj ?: '',
                    'logo' => $logo,
                    'responsavel_principal' => ['nome' => $proposal->client_contact, 'email' => $proposal->client_email],
                ],
                'base_recorrente' => [
                    'quantidade_total_12_meses' => (int) $proposal->recurring_total,
                    'inicio' => self::formatDate($proposal->recurring_from, 'd/m/Y'),
                    'fim' => self::formatDate($proposal->recurring_to, 'd/m/Y'),
                ],
                'retroativas' => [
                    'quantidade_total' => (int) $proposal->retro_total,
                    'inicio' => self::formatDate($proposal->retro_from, 'd/m/Y'),
                    'fim' => self::formatDate($proposal->retro_to, 'd/m/Y'),
                    'licenciamento_status' => $proposal->license_status ?: 'NAO_ATIVO',
                    'prazo' => $proposal->retro_deadline ?: '',
                ],
                'condicoes' => [
                    'validade_dias' => (int) $proposal->validity_days,
                    'pagamento_dias' => (int) $proposal->payment_days,
                    'responsavel_comercial' => $proposal->sales_responsible ?: '',
                    'observacoes' => $proposal->notes ?: '',
                    'revisao' => $proposal->revision > 0 ? 'REV'.$proposal->revision : '',
                ],
            ];
            File::ensureDirectoryExists($out);
            $inputPath = $working.'/input.json';
            file_put_contents($inputPath, json_encode($input, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            $hasNewServices = array_intersect($services, ['DFE', 'NFSE']) !== [];
            if ($hasNewServices) {
                $input['snapshot_v27'] = $quote;
                file_put_contents($inputPath, json_encode($input, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            }
            $command = $hasNewServices
                ? ['python3', resource_path('proposals/bin/render_v27.py'), '--input', $inputPath, '--out', $out, '--source', $source]
                : ['python3', $source.'/20_GERADOR_DOCUMENTOS_EXPORTCONTROL.py', '--input', $inputPath, '--out', $out];
            $process = new Process($command, $working);
            $process->setTimeout($hasNewServices ? 360 : 180);
            $process->run();
            if (! $process->isSuccessful()) {
                throw new \RuntimeException('Gerador oficial: '.mb_substr($process->getErrorOutput(), 0, 600));
            }
            $generated = json_decode($process->getOutput(), true);
            if (! is_array($generated) || ! isset($generated['pdfs']) || ! is_array($generated['pdfs'])) {
                throw new \RuntimeException('Gerador não retornou o registro dos PDFs.');
            }
            foreach ($generated['pdfs'] as $service => $filePath) {
                $this->addGeneratedDocument($proposal, (string) $filePath, 'proposal_pdf', (string) $service);
            }
            if (is_string($generated['pdf_consolidado'] ?? null)) {
                $this->addGeneratedDocument($proposal, $generated['pdf_consolidado'], 'consolidated_pdf');
            }
            if (is_string($generated['html_apresentacao'] ?? null)) {
                $this->addGeneratedDocument($proposal, $generated['html_apresentacao'], 'presentation_html');
            }
            foreach ((array) ($generated['htmls'] ?? []) as $service => $filePath) {
                $this->addGeneratedDocument($proposal, (string) $filePath, 'source_html', (string) $service);
            }
            $audit = $out.'/REGISTRO_OPORTUNIDADE_E_CALCULOS_REV1.json';
            $this->addGeneratedDocument($proposal, $audit, 'audit_json');
        } finally {
            File::deleteDirectory($working);
        }
    }

    /**
     * Eloquent date casts may be inferred as strings by static analysis.
     * Accept both the raw SQL string and a DateTime cast, without losing dates.
     */
    private static function formatDate(mixed $value, string $format): ?string
    {
        if ($value instanceof DateTimeInterface) {
            return CarbonImmutable::instance($value)->format($format);
        }
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->format($format);
        } catch (\Throwable) {
            return null;
        }
    }

    private function addGeneratedDocument(EcProposal $proposal, string $file, string $kind, ?string $service = null): void
    {
        if (! is_file($file) || filesize($file) < 100) {
            throw new \RuntimeException('Documento não gerado corretamente.');
        }
        $extension = strtolower((string) pathinfo($file, PATHINFO_EXTENSION));
        $mime = match ($extension) {
            'pdf' => 'application/pdf', 'html' => 'text/html', default => 'application/json',
        };
        $name = basename($file);
        $path = 'proposals/'.$proposal->public_id.'/'.$name;
        $bytes = (string) file_get_contents($file);
        if (! Storage::disk('local')->put($path, $bytes)) {
            throw new \RuntimeException('Falha ao guardar documento em área privada.');
        }
        DB::table('ec_proposal_documents')->updateOrInsert(
            ['storage_path' => $path],
            [
                'proposal_id' => $proposal->id, 'kind' => $kind, 'service' => $service,
                'original_name' => $name, 'sha256' => hash('sha256', $bytes),
                'bytes' => strlen($bytes), 'mime' => $mime,
                'created_at' => now('UTC'), 'updated_at' => now('UTC'),
            ],
        );
    }

    public function createRevision(User $actor, EcProposal $original): EcProposal
    {
        abort_unless($actor->isCommercialManager(), 403);
        abort_unless(in_array($original->status, ['issued', 'sent', 'accepted', 'partially_accepted'], true), 422);

        return DB::transaction(function () use ($actor, $original): EcProposal {
            $parentId = (int) ($original->parent_id ?: $original->id);
            $base = EcProposal::query()->whereKey($parentId)->lockForUpdate()->firstOrFail();
            $max = (int) EcProposal::query()->where('parent_id', $parentId)->max('revision');
            $values = $original->only([
                'client_name', 'client_contact', 'client_email', 'client_cnpj', 'client_logo_path',
                'company_id', 'hubspot_company_id', 'hubspot_deal_id', 'service_codes',
                'recurring_total', 'recurring_from', 'recurring_to', 'retro_total', 'retro_from',
                'retro_to', 'license_status', 'retro_deadline', 'validity_days',
                'payment_days', 'sales_responsible', 'notes', 'price_date',
                'dfe_cnpj_count', 'dfe_total', 'nfse_cnpj_count', 'nfse_total',
            ]);
            $values['parent_id'] = $parentId;
            $values['revision'] = $max + 1;
            $values['proposal_numbers'] = $base->proposal_numbers;
            $values['public_id'] = (string) Str::uuid();
            $values['status'] = 'draft';
            $values['origin'] = 'revision';
            $values['created_by'] = $actor->id;
            $values['updated_by'] = $actor->id;
            $values['price_snapshot'] = $original->price_snapshot;
            $values['monthly_final'] = $original->monthly_final;
            $values['one_time_final'] = $original->one_time_final;
            $copy = EcProposal::query()->create($values);
            $this->event($copy, $actor, 'revision_draft_created', ['parent_id' => $parentId]);

            return $copy;
        });
    }

    public function reconcileNumbering(User $actor, int $lastConfirmed): void
    {
        abort_unless($actor->isCommercialManager(), 403);
        DB::transaction(function () use ($actor, $lastConfirmed): void {
            $row = DB::table('ec_proposal_numbering')->where('id', 1)->lockForUpdate()->first();
            abort_unless($row !== null, 500);
            $largest = (int) (DB::table('ec_proposal_number_ledger')->max('number') ?? 999);
            $already = (int) ($row->last_confirmed ?? 999);
            if ($lastConfirmed < 999 || $lastConfirmed < $largest || $lastConfirmed < $already || $lastConfirmed + 1 < (int) $row->next_number) {
                throw ValidationException::withMessages(['last_number' => 'Informe um número maior ou igual ao histórico/reservas existentes.']);
            }
            DB::table('ec_proposal_numbering')->where('id', 1)->update([
                'next_number' => $lastConfirmed + 1,
                'last_confirmed' => $lastConfirmed,
                'reconciled_at' => now('UTC'),
                'reconciled_by' => $actor->id,
                'updated_at' => now('UTC'),
            ]);
        });
    }
}
