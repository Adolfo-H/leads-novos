<?php

use App\Services\Proposals\ProposalAttachmentAcceptanceV29;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

try {
    $mode = $argv[1] ?? 'preview';
    if (! in_array($mode, ['preview', 'apply'], true) || ($mode === 'apply' && ($argv[2] ?? '') !== 'CONCILIAR_ANEXOS_V29')) {
        throw new RuntimeException('Modo inválido. Use preview ou apply CONCILIAR_ANEXOS_V29.');
    }
    $token = (string) config('services.hubspot.access_token', '');
    if ($token === '') {
        throw new RuntimeException('HUBSPOT_ACCESS_TOKEN ausente na configuração Laravel.');
    }
    $svc = app(ProposalAttachmentAcceptanceV29::class);
    $inventory = $svc->inventory();
    if ($inventory['documents'] === []) {
        throw new RuntimeException('Nenhum documento local íntegro foi encontrado.');
    }
    echo 'Documentos locais íntegros: '.count($inventory['documents']).PHP_EOL;
    $process = new Process(['python3', __DIR__.'/attachment_scan_v29.py'], base_path());
    $process->setInput(json_encode(['token' => $token, 'documents' => $inventory['documents']], JSON_THROW_ON_ERROR));
    unset($token);
    $process->setTimeout(3600);
    $process->run(static function (string $type, string $buffer): void {
        if ($type === Process::ERR) {
            fwrite(STDERR, $buffer);
        }
    });
    if (! $process->isSuccessful()) {
        throw new RuntimeException('Falha na leitura dos anexos. Nenhum aceite alterado. Confira as permissões e a mensagem acima.');
    }
    $report = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
    if (! is_array($report)) {
        throw new RuntimeException('Resposta do scanner inválida.');
    }
    $report['local_invalid'] = $inventory['invalid'];
    $report['reconciliation'] = $svc->process($report, $mode === 'apply');
    $disk = Storage::disk('local');
    $dir = 'proposals/attachment-audit-v29';
    $path = $dir.'/'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(4)).'.json';
    $data = json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
    if (! $disk->put($path, $data)) {
        throw new RuntimeException('Falha ao gravar relatório privado; evidências aplicadas permanecem nos eventos.');
    }
    chmod($disk->path($path), 0600);
    $tmp = $path.'.latest';
    if (! $disk->put($tmp, $data)) {
        throw new RuntimeException('Falha ao gravar índice do relatório.');
    }
    chmod($disk->path($tmp), 0600);
    if (! rename($disk->path($tmp), $disk->path($dir.'/latest.json'))) {
        throw new RuntimeException('Falha ao publicar relatório.');
    }
    $r = $report['reconciliation'];
    echo json_encode(['modo' => $mode, 'negocios_elegiveis' => $report['eligible_deals'], 'etapas' => $report['stage_counts'], 'anexos_conferidos' => $report['attachments_checked'], 'propostas_comprovadas' => count($r['confirmed']), 'novos_aceites' => $r['new'], 'aceites_anteriores_sem_prova' => count($r['prior_unverified']), 'anexos_sem_correspondencia_ou_negocios_sem_anexo' => count($report['unmatched']), 'conflitos' => count($report['conflicts']), 'erros_de_acesso' => count($report['errors']), 'varredura_completa' => $report['complete'], 'relatorio' => $path], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL;
    if (! $report['complete']) {
        fwrite(STDERR, "ATENÇÃO: leitura parcial. Apenas correspondências comprovadas foram processadas; consulte o relatório para permissões/erros.\n");
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'ERRO V29: '.$e->getMessage().PHP_EOL);
    exit(1);
}
