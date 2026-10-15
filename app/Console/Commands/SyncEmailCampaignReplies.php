<?php

namespace App\Console\Commands;

use App\Models\EmailCampaignInboxCursor;
use App\Services\Campaigns\CampaignInbox;
use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

class SyncEmailCampaignReplies extends Command
{
    protected $signature = 'campaigns:sync-replies';

    protected $description = 'Importa cabeçalhos de respostas IMAP relacionadas a envios do Prospector';

    public function handle(CampaignInbox $inbox): int
    {
        if (! config('campaign_mail.imap_enabled')) {
            $this->line('IMAP desabilitado. Habilite CAMPAIGN_IMAP_ENABLED após os testes.');

            return self::SUCCESS;
        }

        $mailbox = (string) config('campaign_mail.imap_username');
        if ($mailbox === '' || ! config('campaign_mail.imap_password')) {
            $this->error('Credenciais IMAP ausentes. Verifique o .env local (não compartilhe senhas).');

            return self::FAILURE;
        }
        $cursor = EmailCampaignInboxCursor::query()->where('mailbox', $mailbox)->first();
        $input = [
            'host' => (string) config('campaign_mail.imap_host'),
            'port' => (int) config('campaign_mail.imap_port', 993),
            'username' => $mailbox,
            'password' => (string) config('campaign_mail.imap_password'),
            'folder' => (string) config('campaign_mail.imap_folder', 'INBOX'),
            'since_uid' => (int) ($cursor->last_uid ?? 0),
            'known_validity' => (string) ($cursor->uid_validity ?? ''),
            'limit' => 60,
        ];
        $command = [(string) config('campaign_mail.python', 'python3'), base_path('tools/email/imap_fetch.py')];
        $process = new Process($command, base_path());
        $process->setTimeout(90);
        try {
            $process->setInput(json_encode($input, JSON_THROW_ON_ERROR));
            $process->run();
            if (! $process->isSuccessful()) {
                $this->error('O leitor IMAP falhou (sem expor credenciais). Verifique se python3 existe no container Sail e se o IMAP está acessível.');

                return self::FAILURE;
            }
            /** @var mixed $batch */
            $batch = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($batch) || ! isset($batch['uidvalidity'], $batch['last_uid'], $batch['initialized'], $batch['messages']) || ! is_array($batch['messages'])) {
                $this->error('Formato de resposta IMAP inválido.');

                return self::FAILURE;
            }
            /** @var array{uidvalidity:string,last_uid:int,initialized:bool,messages:list<array<string,mixed>>} $batch */
            $count = $inbox->import($mailbox, $batch);
            if ($batch['initialized']) {
                $this->line('Cursor IMAP inicializado. Mensagens antigas não foram lidas.');
            }
            $this->info('Novas respostas identificadas: '.$count);

            return self::SUCCESS;
        } catch (\Throwable $exception) {
            report($exception);
            $this->error('Erro na sincronização IMAP: '.class_basename($exception).'.');

            return self::FAILURE;
        }
    }
}
