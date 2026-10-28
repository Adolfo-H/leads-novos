<?php

namespace App\Console\Commands;

use App\Models\EmailCampaignInboxCursor;
use App\Services\Campaigns\CampaignImapHealth;
use App\Services\Campaigns\CampaignInbox;
use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

/**
 * Reparação EXPLÍCITA de respostas recentes não importadas.
 * Scanner IMAP somente leitura: sem corpo, sem anexos, sem alterar mensagens.
 * A correlação permanece a mesma do CampaignInbox (Message-ID + remetente real).
 */
final class RecoverCampaignRepliesV71 extends Command
{
    protected $signature = 'campaigns:recover-replies-v71
        {--days=7 : Número de dias recentes para recuperar (1 a 30)}
        {--limit=250 : Quantidade máxima de cabeçalhos IMAP (1 a 500)}
        {--apply : Ler os cabeçalhos e registrar APENAS respostas correlacionadas}';

    protected $description = 'Diagnostica e recupera respostas IMAP recentes perdidas após a inicialização do cursor';

    public function handle(CampaignImapHealth $health, CampaignInbox $inbox): int
    {
        $days = filter_var($this->option('days'), FILTER_VALIDATE_INT);
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
        if ($days === false || $days < 1 || $days > 30 || $limit === false || $limit < 1 || $limit > 500) {
            $this->error('Informe --days entre 1 e 30 e --limit entre 1 e 500.');

            return self::FAILURE;
        }

        $enabled = (bool) config('campaign_mail.imap_enabled', false);
        $mailbox = trim((string) config('campaign_mail.imap_username', ''));
        $host = trim((string) config('campaign_mail.imap_host', ''));
        $password = (string) config('campaign_mail.imap_password', '');
        $replyTo = trim((string) config('campaign_mail.reply_to', ''));
        $cursor = $mailbox === '' ? null : EmailCampaignInboxCursor::query()->where('mailbox', $mailbox)->first();
        $snapshot = $health->snapshot();

        $this->line('Leitura IMAP: '.($enabled ? 'habilitada' : 'desabilitada'));
        $this->line('Saúde: '.$snapshot['label']);
        $this->line('Host: '.($host !== '' ? $host : 'não configurado'));
        $this->line('Caixa: '.($mailbox !== '' ? $this->maskedEmail($mailbox) : 'não configurada'));
        $this->line('Último processamento: '.($snapshot['last_sync_at'] ?? 'nunca'));
        $this->line('Último UID: '.(int) ($cursor->last_uid ?? 0));
        $this->line('Respostas comerciais já registradas: '.$snapshot['campaign_replies']);
        $this->line('Respostas de teste rastreado: '.$snapshot['tracked_replies']);
        $this->line('Agendamento normal: campaigns:sync-replies (a cada 5 minutos).');

        if ($mailbox !== '' && $replyTo !== '' && strcasecmp($mailbox, $replyTo) !== 0) {
            $this->warn('ATENÇÃO: caixa IMAP e Reply-To são diferentes. Confirme que a resposta chega à caixa monitorada.');
        }
        if ($cursor === null) {
            $this->warn('Primeira sincronização normal ignora mensagens antigas: a recuperação explícita cobre este caso.');
        }
        if (! $this->option('apply')) {
            $this->comment('Diagnóstico somente leitura. Nenhuma conexão IMAP foi aberta.');
            $this->comment('Para recuperar mensagens recentes, após configurar o IMAP, execute com --apply.');

            return self::SUCCESS;
        }
        if (! $enabled || $host === '' || $mailbox === '' || $password === '') {
            $this->error('IMAP ainda não está configurado. Preencha CAMPAIGN_IMAP_ENABLED, IMAP_HOST, IMAP_USERNAME e IMAP_PASSWORD no .env local.');

            return self::FAILURE;
        }
        if ((int) config('campaign_mail.imap_port', 993) !== 993
            || (string) config('campaign_mail.imap_folder', 'INBOX') !== 'INBOX') {
            $this->error('A recuperação utiliza somente INBOX com TLS IMAP na porta 993.');

            return self::FAILURE;
        }

        $input = [
            'host' => $host,
            'port' => 993,
            'username' => $mailbox,
            'password' => $password,
            'folder' => 'INBOX',
            'days' => $days,
            'limit' => $limit,
        ];
        $process = new Process([
            (string) config('campaign_mail.python', 'python3'),
            base_path('tools/email/imap_recover_headers_v71.py'),
        ], base_path());
        $process->setTimeout(180);
        $process->setInput(json_encode($input, JSON_THROW_ON_ERROR));
        try {
            $process->run();
            if (! $process->isSuccessful()) {
                // O script Python devolve somente o tipo do erro, nunca credenciais.
                $errorType = trim($process->getErrorOutput());
                if (! preg_match('/^IMAP_RECOVER_ERROR:[A-Za-z][A-Za-z0-9_]*$/D', $errorType)) {
                    $errorType = 'Falha de conexão ou autenticação';
                }
                $this->error('Falha no acesso IMAP: '.$errorType);

                return self::FAILURE;
            }
            /** @var mixed $decoded */
            $decoded = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($decoded)
                || ! is_string($decoded['uidvalidity'] ?? null)
                || ! is_int($decoded['last_uid'] ?? null)
                || ! is_bool($decoded['initialized'] ?? null)
                || ! is_array($decoded['messages'] ?? null)
                || ! is_int($decoded['scanned'] ?? null)
                || ! is_int($decoded['available'] ?? null)
                || ! is_bool($decoded['limited'] ?? null)) {
                $this->error('Resposta do leitor IMAP inválida. Nenhum dado importado.');

                return self::FAILURE;
            }
            if (count($decoded['messages']) !== $decoded['scanned'] || $decoded['scanned'] > $limit) {
                $this->error('Contagem de mensagens IMAP inconsistente. Nenhum dado importado.');

                return self::FAILURE;
            }
            /** @var array{uidvalidity:string,last_uid:int,initialized:bool,messages:list<array<string,mixed>>} $batch */
            $batch = $decoded;
            // Caixa vazia: não criar cursor artificial em UID=0 nem reabrir o histórico pessoal.
            $matched = $decoded['scanned'] > 0 ? $inbox->import($mailbox, $batch) : 0;
            $this->info('Cabeçalhos recentes examinados: '.$decoded['scanned']);
            $this->info('Respostas vinculadas a campanhas ou testes: '.$matched);
            if ($decoded['limited']) {
                $this->warn('Existem mais mensagens nesta janela. Para ampliar, use --limit=500 (máximo).');
            }
            if ($matched === 0) {
                $this->warn('Nenhuma resposta correlacionada. Verifique se o IMAP aponta para a caixa correta e se o Outlook preservou In-Reply-To/References do Message-ID enviado.');
            }
            $this->line('Nenhum e-mail foi enviado. Não lemos corpos, anexos ou conteúdo não correlacionado.');

            return self::SUCCESS;
        } catch (\Throwable $exception) {
            report($exception);
            $this->error('Falha ao importar respostas: '.class_basename($exception).'.');

            return self::FAILURE;
        }
    }

    private function maskedEmail(string $email): string
    {
        $parts = explode('@', $email, 2);
        if (count($parts) !== 2) {
            return '(caixa configurada)';
        }
        $first = mb_substr($parts[0], 0, 2);

        return $first.'***@'.$parts[1];
    }
}
