<?php

namespace App\Services\Campaigns;

use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Symfony\Component\Process\Process;
use Throwable;

/** Read only; never runs formulas, imports attachments, or contacts external services. */
final class CampaignImportReader
{
    public const MAX_ROWS = 5000;

    public const MAX_COLUMNS = 128;

    public const MAX_BYTES = 3145728;

    /** @return array{columns:list<string>,rows:list<list<string>>,kind:string} */
    public function read(?UploadedFile $file, string $paste): array
    {
        if ($file === null) {
            if (trim($paste) === '' || strlen($paste) > 262144) {
                $this->fail('Cole uma lista de e-mails de até 256 KiB.');
            }
            // Text inputs also pass through the local extractor; no shell interpolation.
            $temp = tempnam(sys_get_temp_dir(), 'ec-import-');
            if ($temp === false) {
                $this->fail('Não foi possível criar o arquivo temporário.');
            }
            try {
                chmod($temp, 0600);
                if (file_put_contents($temp, $paste) === false) {
                    $this->fail('Não foi possível ler a lista.');
                }

                return $this->extract($temp, 'txt');
            } finally {
                @unlink($temp);
            }
        }
        $extension = strtolower($file->getClientOriginalExtension());
        $path = $file->getRealPath();
        if ($path === false || ! in_array($extension, ['csv', 'xlsx', 'xls', 'docx', 'doc', 'pdf', 'txt'], true)) {
            $this->fail('Formato não suportado. Use CSV, Excel, Word, PDF ou TXT.');
        }
        if ($extension === 'xls') {
            return $this->readXls($path);
        }

        return $this->extract($path, $extension);
    }

    /** @return array{columns:list<string>,rows:list<list<string>>,kind:string} */
    private function extract(string $path, string $extension): array
    {
        try {
            $process = new Process([
                'python3', base_path('tools/email/campaign_import_extract.py'), $path, $extension,
            ], base_path());
            $process->setTimeout(45);
            $process->run();
            if (! $process->isSuccessful()) {
                $error = trim($process->getErrorOutput());
                $this->fail(match ($error) {
                    'missing_pdf_tool' => 'O servidor precisa do pdftotext (poppler-utils). Confira o README do pacote.',
                    'missing_doc_tool' => 'O servidor precisa do antiword para abrir DOC antigo. Você também pode salvar como DOCX.',
                    'too_many_rows' => 'O arquivo excede 5.000 linhas ou 128 colunas. Revise a origem e use um arquivo menor.',
                    'too_large', 'unsafe_archive' => 'Arquivo muito grande ou estrutura compactada insegura.',
                    default => 'Não foi possível ler esse arquivo. Confirme o formato e se ele não está protegido por senha.',
                });
            }
            if (strlen($process->getOutput()) > self::MAX_BYTES) {
                $this->fail('Prévia acima do limite de armazenamento.');
            }
            $decoded = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable) {
            $this->fail('Não foi possível concluir a leitura local do arquivo.');
        }

        return $this->validated($decoded);
    }

    /** @return array{columns:list<string>,rows:list<list<string>>,kind:string} */
    private function readXls(string $path): array
    {
        try {
            $reader = IOFactory::createReader('Xls');
            $reader->setReadDataOnly(true);
            $info = $reader->listWorksheetInfo($path);
            $sheet = $info[0] ?? null;
            if ($sheet === null || $sheet['totalRows'] > self::MAX_ROWS + 1
                || $sheet['totalColumns'] > self::MAX_COLUMNS) {
                $this->fail('A primeira planilha XLS excede os limites de leitura ou está vazia.');
            }
            $reader->setLoadSheetsOnly($sheet['worksheetName']);
            $workbook = $reader->load($path);
            try {
                // Do not calculate formulas or use hyperlinks. Formula cells are excluded.
                $rows = [];
                foreach ($workbook->getSheet(0)->toArray(null, false, false, false) as $raw) {
                    $row = [];
                    foreach ($raw as $value) {
                        $cell = is_scalar($value) ? trim((string) $value) : '';
                        $row[] = str_starts_with($cell, '=') ? '' : mb_substr($cell, 0, 512);
                    }
                    if (array_filter($row, static fn (string $cell): bool => $cell !== '') !== []) {
                        $rows[] = $row;
                    }
                }
            } finally {
                $workbook->disconnectWorksheets();
            }
            $first = $rows[0] ?? [];
            $hasHeader = ! array_filter($first, static fn (string $cell): bool => filter_var($cell, FILTER_VALIDATE_EMAIL) !== false);
            $columns = [];
            foreach ($first as $index => $cell) {
                $columns[] = $hasHeader && $cell !== '' ? mb_substr($cell, 0, 120) : 'Coluna '.($index + 1);
            }
            if ($hasHeader) {
                array_shift($rows);
            }

            return $this->validated(['columns' => $columns, 'rows' => $rows, 'kind' => 'table']);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable) {
            $this->fail('Não foi possível ler o Excel antigo. Salve como XLSX ou CSV e tente novamente.');
        }
    }

    /** @return array{columns:list<string>,rows:list<list<string>>,kind:string} */
    private function validated(mixed $data): array
    {
        if (! is_array($data) || ! is_array($data['columns'] ?? null) || ! is_array($data['rows'] ?? null)
            || count($data['columns']) < 1 || count($data['columns']) > self::MAX_COLUMNS || count($data['rows']) > self::MAX_ROWS) {
            $this->fail('Estrutura da prévia inválida ou acima dos limites.');
        }
        $columns = [];
        foreach ($data['columns'] as $col) {
            if (! is_string($col)) {
                $this->fail('Colunas inválidas.');
            }
            $columns[] = mb_substr($col, 0, 120);
        }
        $rows = [];
        foreach ($data['rows'] as $raw) {
            if (! is_array($raw) || count($raw) !== count($columns)) {
                $this->fail('As linhas do arquivo não correspondem às colunas.');
            }
            $row = [];
            foreach ($raw as $cell) {
                if (! is_string($cell)) {
                    $this->fail('Célula inválida.');
                }
                $row[] = mb_substr($cell, 0, 512);
            }
            $rows[] = $row;
        }
        $result = ['columns' => $columns, 'rows' => $rows, 'kind' => ($data['kind'] ?? '') === 'text' ? 'text' : 'table'];
        if (strlen(json_encode($result, JSON_THROW_ON_ERROR)) > self::MAX_BYTES) {
            $this->fail('Prévia acima do limite de armazenamento.');
        }

        return $result;
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['import_file' => $message]);
    }
}
