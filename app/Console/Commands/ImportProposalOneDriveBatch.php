<?php

// EC_PROPOSALS_OD_IMPORT_V1 - importacao historica sem SMTP.

namespace App\Console\Commands;

use App\Models\EcProposal;
use App\Models\User;
use App\Services\Proposals\ProposalPricing;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/** Importação histórica controlada: não emite números, não altera CRM e não envia e-mails. */
final class ImportProposalOneDriveBatch extends Command
{
    protected $signature = 'proposals:onedrive-import
        {--manifest= : Ex. proposals/import-batches/lote01/revisado.csv}
        {--limit=200 : Máximo de novos arquivos por execução}
        {--apply : Gravar documentos e registros importados}
        {--confirm= : Exige IMPORTAR_HISTORICO}
        {--actor= : E-mail de usuário gestor responsável pela importação}';

    protected $description = 'Validar ou importar um lote revisado do OneDrive para propostas históricas';

    /** @var array<string, string> */
    private const MIME = [
        'pdf' => 'application/pdf',
        'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'odt' => 'application/vnd.oasis.opendocument.text',
        'rtf' => 'application/rtf',
        'xls' => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'xlsm' => 'application/vnd.ms-excel.sheet.macroEnabled.12',
        'ods' => 'application/vnd.oasis.opendocument.spreadsheet',
    ];

    public function handle(): int
    {
        $manifest = $this->option('manifest');
        if (! is_string($manifest)
            || preg_match('~^proposals/import-batches/[A-Za-z0-9_-]+/revisado\.csv$~D', $manifest) !== 1) {
            $this->error('Manifesto inválido. Use um lote em proposals/import-batches/<LOTE>/revisado.csv.');

            return self::FAILURE;
        }
        if (! Storage::disk('local')->exists($manifest)) {
            $this->error('Manifesto não encontrado no armazenamento privado.');

            return self::FAILURE;
        }

        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
        if (! is_int($limit) || $limit < 1 || $limit > 1000) {
            $this->error('O limite deve estar entre 1 e 1000.');

            return self::FAILURE;
        }

        $apply = $this->option('apply') === true;
        $actor = null;
        if ($apply) {
            if ($this->option('confirm') !== 'IMPORTAR_HISTORICO') {
                $this->error('Para gravar: --apply --confirm=IMPORTAR_HISTORICO');

                return self::FAILURE;
            }
            $actorEmail = $this->option('actor');
            if (! is_string($actorEmail) || trim($actorEmail) === '') {
                $this->error('Informe --actor=email@empresa de um gestor do Prospector.');

                return self::FAILURE;
            }
            $actor = User::query()->where('email', trim($actorEmail))->first();
            if (! $actor instanceof User || ! $actor->isCommercialManager()) {
                $this->error('Gestor não encontrado ou não autorizado.');

                return self::FAILURE;
            }
        }

        $root = substr($manifest, 0, -strlen('/revisado.csv'));
        $file = fopen(Storage::disk('local')->path($manifest), 'rb');
        if ($file === false) {
            $this->error('Não foi possível abrir o manifesto.');

            return self::FAILURE;
        }

        $candidateRows = [];
        $errors = [];
        $repeated = 0;
        $seen = [];
        $reviewed = 0;
        try {
            $header = fgetcsv($file, 0, ';', '"', '');
            if (! is_array($header)) {
                $this->error('CSV sem cabeçalho.');

                return self::FAILURE;
            }
            $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]) ?? (string) $header[0];
            $required = ['arquivo_relativo', 'arquivo_lote', 'sha256', 'bytes', 'cliente_revisado', 'revisado', 'importar', 'servico_sugerido'];
            if (array_diff($required, $header) !== []) {
                $this->error('CSV não contém os campos esperados da revisão.');

                return self::FAILURE;
            }
            while (($values = fgetcsv($file, 0, ';', '"', '')) !== false) {
                if ($values === [null]) {
                    continue;
                }
                if (count($values) > count($header)) {
                    $errors[] = 'CSV com campos extras em linha selecionada.';

                    continue;
                }
                $line = array_combine($header, array_pad($values, count($header), ''));
                /** @var array<string, string> $row */
                $row = array_map(static fn (mixed $item): string => is_string($item) ? $item : '', $line);
                if (mb_strtoupper(trim($row['importar'])) !== 'SIM') {
                    continue;
                }
                $reviewed++;
                if (mb_strtoupper(trim($row['revisado'])) !== 'SIM') {
                    $errors[] = $row['arquivo_relativo'].': revisado != SIM';

                    continue;
                }
                $sha = strtolower(trim($row['sha256']));
                if (! preg_match('/^[a-f0-9]{64}$/D', $sha)) {
                    $errors[] = 'SHA-256 inválido: '.$row['arquivo_relativo'];

                    continue;
                }
                $identity = self::identity($row, $sha);
                if (isset($seen[$identity])) {
                    $repeated++;

                    continue;
                }
                $seen[$identity] = true;
                if (DB::table('ec_proposals')->where('legacy_code', $identity)->exists()) {
                    $repeated++;

                    continue;
                }
                if (count($candidateRows) >= $limit) {
                    continue;
                }
                $err = $this->checkRow($row, $root, $sha);
                if ($err !== null) {
                    $errors[] = $row['arquivo_relativo'].': '.$err;

                    continue;
                }
                $candidateRows[] = $row;
            }
        } finally {
            fclose($file);
        }

        $this->line(sprintf('Itens revisados: %d | novos candidatos: %d | já existentes/duplicados: %d | problemas: %d',
            $reviewed, count($candidateRows), $repeated, count($errors)));
        foreach (array_slice($errors, 0, 20) as $error) {
            $this->error($error);
        }
        if ($errors !== []) {
            $this->error('ABORTADO: corrija a revisão e prepare um novo lote. Nada foi importado.');

            return self::FAILURE;
        }
        if (! $apply) {
            $this->warn('SIMULAÇÃO: nenhuma alteração no banco e nenhum documento copiado para o histórico.');

            return self::SUCCESS;
        }
        $copied = [];
        $inserted = 0;
        try {
            DB::transaction(function () use ($candidateRows, $root, $actor, &$copied, &$inserted): void {
                // Impede importadores concorrentes e serializa com a emissão oficial.
                DB::table('ec_proposal_numbering')->where('id', 1)->lockForUpdate()->first();
                foreach ($candidateRows as $row) {
                    $sha = strtolower(trim($row['sha256']));
                    $identity = self::identity($row, $sha);
                    if (DB::table('ec_proposals')->where('legacy_code', $identity)->exists()) {
                        continue;
                    }
                    $externalDealId = trim($row['id_negocio_hubspot'] ?? '');
                    $externalCompanyId = trim($row['id_empresa_hubspot'] ?? '');
                    $dealId = $externalDealId !== ''
                        ? DB::table('hubspot_deals')->where('hubspot_id', $externalDealId)->value('id') : null;
                    $companyId = $externalCompanyId !== ''
                        ? DB::table('hubspot_companies')->where('hubspot_id', $externalCompanyId)->value('id') : null;
                    $service = trim($row['servico_sugerido']);
                    $uuid = (string) Str::uuid();
                    $file = $row['arquivo_lote'];
                    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
                    $destination = 'proposals/'.$uuid.'/original-'.$sha.'.'.$ext;
                    if (! Storage::disk('local')->copy($root.'/'.$file, $destination)) {
                        throw new RuntimeException('Falha ao armazenar original: '.$row['arquivo_relativo']);
                    }
                    $copied[] = $destination;
                    $number = trim($row['numero_confirmado'] ?? '');
                    $proposal = EcProposal::query()->create([
                        'public_id' => $uuid,
                        'origin' => 'imported',
                        'status' => 'imported',
                        'legacy_code' => $identity,
                        'client_name' => trim($row['cliente_revisado']),
                        'service_codes' => in_array($service, ProposalPricing::ALL, true) ? [$service] : [],
                        'proposal_numbers' => null, // Históricos somente após conciliação manual.
                        'issued_on' => trim($row['data_emissao'] ?? '') !== '' ? $row['data_emissao'] : null,
                        'hubspot_company_id' => $companyId !== null ? (int) $companyId : null,
                        'hubspot_deal_id' => $dealId !== null ? (int) $dealId : null,
                        'notes' => $number !== '' ? 'Número histórico informado para conciliação: '.$number : null,
                        'created_by' => (int) $actor->getKey(),
                        'updated_by' => (int) $actor->getKey(),
                    ]);
                    DB::table('ec_proposal_documents')->insert([
                        'proposal_id' => $proposal->id,
                        'kind' => $ext === 'pdf' ? 'historical_pdf' : 'historical_original',
                        'service' => in_array($service, ProposalPricing::ALL, true) ? $service : null,
                        'storage_path' => $destination,
                        'original_name' => mb_substr(basename($row['arquivo_relativo']), 0, 255),
                        'sha256' => $sha,
                        'bytes' => (int) $row['bytes'],
                        'mime' => self::MIME[$ext],
                        'created_at' => now('UTC'),
                        'updated_at' => now('UTC'),
                    ]);
                    DB::table('ec_proposal_events')->insert([
                        'proposal_id' => $proposal->id,
                        'event' => 'historical_onedrive_import',
                        'metadata' => json_encode([
                            'source' => 'onedrive',
                            'path_relative' => $row['arquivo_relativo'],
                            'sha256' => $sha,
                            'hubspot_deal_external_id' => $externalDealId ?: null,
                            'hubspot_company_external_id' => $externalCompanyId ?: null,
                            'source_reviewer' => (string) $actor->email,
                            'historic_number_candidate' => $number ?: null,
                            'historic_number_verified' => $row['numero_verificado'] ?? 'NAO',
                            'hubspot_status_not_accepted_evidence' => true,
                        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                        'actor_id' => (int) $actor->getKey(),
                        'created_at' => now('UTC'),
                    ]);
                    $inserted++;
                }
            });
        } catch (Throwable $exception) {
            foreach ($copied as $file) {
                Storage::disk('local')->delete($file);
            }
            report($exception);
            $this->error('Importação cancelada e transação revertida: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf('IMPORTAÇÃO CONCLUÍDA: %d arquivo(s) registrados; %d duplicado(s) ignorados.', $inserted, $repeated));
        $this->warn('Status das propostas: IMPORTADA. Nenhum aceite, número ou e-mail foi criado.');
        $this->line('Pode repetir o comando: documentos importados serão ignorados pelo SHA-256.');

        return self::SUCCESS;
    }

    /**
     * Um mesmo PDF pode ser usado em dois negócios distintos; não fundir negócios.
     * Duas cópias idênticas do mesmo negócio/cliente/serviço são uma só entrada.
     *
     * @param  array<string, string>  $row
     */
    private static function identity(array $row, string $sha): string
    {
        $values = [
            $sha, trim($row['id_negocio_hubspot'] ?? ''),
            trim($row['id_empresa_hubspot'] ?? ''),
            mb_strtolower(trim($row['cliente_revisado'])),
            trim($row['servico_sugerido']),
        ];

        return 'onedrive:'.substr(hash('sha256', implode('|', $values)), 0, 48);
    }

    /** @param array<string, string> $row */
    private function checkRow(array $row, string $root, string $sha): ?string
    {
        $name = trim($row['cliente_revisado']);
        if (mb_strlen($name) < 2 || mb_strlen($name) > 220) {
            return 'cliente_revisado não preenchido ou muito longo';
        }
        $relative = $row['arquivo_lote'];
        if (preg_match('/^files\/[a-f0-9]{64}\.(pdf|doc|docx|odt|rtf|xls|xlsx|xlsm|ods)$/D', $relative) !== 1
            || ! str_starts_with($relative, 'files/'.$sha.'.')) {
            return 'arquivo_lote inválido ou não corresponde ao hash';
        }
        $path = $root.'/'.$relative;
        if (! Storage::disk('local')->exists($path)) {
            return 'arquivo do lote não disponível (OneDrive não está montado no contêiner)';
        }
        if ((string) Storage::disk('local')->size($path) !== trim($row['bytes'])) {
            return 'tamanho do arquivo mudou desde a revisão';
        }
        if (hash_file('sha256', Storage::disk('local')->path($path)) !== $sha) {
            return 'hash do arquivo mudou desde a revisão';
        }
        foreach (['id_negocio_hubspot' => 'hubspot_deals', 'id_empresa_hubspot' => 'hubspot_companies'] as $field => $table) {
            $external = trim($row[$field] ?? '');
            if ($external === '') {
                continue;
            }
            if (preg_match('/^[0-9]{1,20}$/D', $external) !== 1
                || ! DB::table($table)->where('hubspot_id', $external)->exists()) {
                return 'ID não encontrado no espelho local: '.$field.'='.$external;
            }
        }
        $deal = trim($row['id_negocio_hubspot'] ?? '');
        $company = trim($row['id_empresa_hubspot'] ?? '');
        if ($deal !== '' && $company !== '') {
            $linked = DB::table('hubspot_company_deal as link')
                ->join('hubspot_deals as d', 'd.id', '=', 'link.hubspot_deal_id')
                ->join('hubspot_companies as c', 'c.id', '=', 'link.hubspot_company_id')
                ->where('d.hubspot_id', $deal)->where('c.hubspot_id', $company)->exists();
            if (! $linked) {
                return 'Negócio não vinculado à empresa escolhida no espelho HubSpot';
            }
        }
        $service = trim($row['servico_sugerido']);
        if ($service !== '' && ! in_array($service, ProposalPricing::ALL, true)) {
            return 'serviço diferente do catálogo; deixe vazio para indefinido';
        }
        $date = trim($row['data_emissao'] ?? '');
        if ($date !== '') {
            if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $date, $m) !== 1
                || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
                return 'data_emissao precisa ser AAAA-MM-DD';
            }
        }
        $number = trim($row['numero_confirmado'] ?? '');
        if ($number !== '' && (preg_match('/^[0-9]{1,10}$/D', $number) !== 1
            || mb_strtoupper(trim($row['numero_verificado'] ?? '')) !== 'SIM')) {
            return 'número histórico requer numero_verificado=SIM';
        }

        return null;
    }
}
