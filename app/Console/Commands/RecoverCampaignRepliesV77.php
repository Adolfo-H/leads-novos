<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\EmailCampaignInboxCursor;
use App\Models\EmailCampaignRecipient;
use App\Services\Campaigns\CampaignAudience;
use App\Services\Campaigns\CampaignInbox;
use Illuminate\Console\Command;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Recuperação explícita de respostas recentes que o cursor IMAP já ultrapassou.
 * Não lê corpo/HTML/anexos, não envia mensagens e não associa por assunto.
 */
final class RecoverCampaignRepliesV77 extends Command
{
    protected $signature = 'campaigns:recover-replies
        {--hours=72 : Janela retrospectiva em horas (1-168)}
        {--limit=600 : Máximo de cabeçalhos por execução (1-1200)}
        {--sender= : Opcional: restringir busca a um endereço remetente}
        {--apply : Registrar apenas respostas com Message-ID e remetente exatos}';

    protected $description = 'Diagnostica/recupera respostas IMAP recentes, sem reenviar nem alterar a caixa postal';

    public function handle(CampaignInbox $inbox): int
    {
        $hours = (int) $this->option('hours');
        $limit = (int) $this->option('limit');
        $sender = trim(self::ciScalarStringV65($this->option('sender')));
        if ($hours < 1 || $hours > 168 || $limit < 1 || $limit > 1200) {
            $this->error('Informe --hours de 1 a 168 e --limit de 1 a 1200.');

            return self::FAILURE;
        }
        if ($sender !== '' && filter_var($sender, FILTER_VALIDATE_EMAIL) === false) {
            $this->error('O remetente precisa ser um endereço de e-mail válido.');

            return self::FAILURE;
        }
        if (! (bool) config('campaign_mail.imap_enabled')) {
            $this->warn('IMAP desabilitado. Verifique CAMPAIGN_IMAP_ENABLED no ambiente.');

            return self::FAILURE;
        }
        $mailbox = (string) config('campaign_mail.imap_username', '');
        $host = (string) config('campaign_mail.imap_host', '');
        $password = (string) config('campaign_mail.imap_password', '');
        if ($mailbox === '' || $host === '' || $password === '') {
            $this->error('Configuração IMAP incompleta. Não compartilhe credenciais no chat.');

            return self::FAILURE;
        }
        $cursor = EmailCampaignInboxCursor::query()->where('mailbox', $mailbox)->first();
        if ($cursor === null || (string) $cursor->uid_validity === '') {
            $this->error('Cursor IMAP ainda não inicializado. Execute primeiro: artisan campaigns:sync-replies');

            return self::FAILURE;
        }

        $request = [
            'host' => $host,
            'port' => (int) config('campaign_mail.imap_port', 993),
            'username' => $mailbox,
            'password' => $password,
            'folder' => (string) config('campaign_mail.imap_folder', 'INBOX'),
            'known_validity' => (string) $cursor->uid_validity,
            'since_uid' => (int) $cursor->last_uid,
            'hours' => $hours,
            'limit' => $limit,
            'sender' => $sender,
        ];
        $process = new Process([
            (string) config('campaign_mail.python', 'python3'),
            base_path('tools/email/imap_recover_v77.py'),
        ], base_path());
        $process->setTimeout(240);

        try {
            $process->setInput(json_encode($request, JSON_THROW_ON_ERROR));
            $process->run();
            if (! $process->isSuccessful()) {
                $this->error('Não foi possível consultar os cabeçalhos IMAP recentes. Verifique credenciais, UIDVALIDITY e conexão com o servidor.');

                return self::FAILURE;
            }
            /** @var mixed $decoded */
            $decoded = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($decoded) || ! isset($decoded['uidvalidity'], $decoded['last_uid'], $decoded['messages'], $decoded['found_in_window']) || ! is_array($decoded['messages'])) {
                $this->error('O leitor de recuperação retornou um formato inválido.');

                return self::FAILURE;
            }
            /** @var array{uidvalidity:string,last_uid:int,initialized:bool,messages:list<array<string,mixed>>,found_in_window:int,truncated:bool,parse_errors:int} $batch */
            $batch = $decoded;
            if ((string) $cursor->uid_validity !== $batch['uidvalidity']) {
                $this->error('A caixa IMAP foi reinicializada (UIDVALIDITY mudou). Nenhum dado foi alterado.');

                return self::FAILURE;
            }

            $count = count($batch['messages']);
            $this->info("Cabeçalhos encontrados na janela: {$batch['found_in_window']}; examinados: {$count}.");
            if ($batch['truncated']) {
                $this->warn('Busca parcialmente limitada. Use --sender ou aumente --limit (até 1200) para refinar.');
            }
            if ($batch['parse_errors'] > 0) {
                $this->warn("Cabeçalhos ilegíveis ignorados: {$batch['parse_errors']}.");
            }
            $metrics = $this->diagnose($batch['messages']);
            $this->line('Candidatos com identificador de envio e remetente correspondentes: '.$metrics['matched']);
            if ($metrics['sender_known_no_reference'] > 0) {
                $this->warn('Mensagens de remetentes conhecidos, sem referência correspondente: '.$metrics['sender_known_no_reference'].'. Não são vinculadas automaticamente.');
            }
            if ($metrics['sender_mismatch'] > 0) {
                $this->warn('Cabeçalhos que referenciam um envio, mas vêm de outro remetente: '.$metrics['sender_mismatch'].'. Exigem conferência manual.');
            }

            if (! (bool) $this->option('apply')) {
                $this->comment('Diagnóstico somente leitura. Use --apply para registrar as respostas EXATAMENTE correlacionadas.');

                return self::SUCCESS;
            }
            if ($metrics['matched'] === 0) {
                $this->warn('Nenhuma resposta comercial correspondeu com segurança. Nada foi gravado; confira remetente, Message-ID e Reply-To no Outlook.');

                return self::SUCCESS;
            }

            $imported = $inbox->import($mailbox, $batch);
            $this->info("Respostas identificadas e registradas agora: {$imported} (inclui respostas de teste, quando houver).");
            $this->comment('Nenhum e-mail foi enviado ou marcado como lido. A análise usa apenas cabeçalhos IMAP.');

            return self::SUCCESS;
        } catch (Throwable $exception) {
            report($exception);
            $this->error('Falha na recuperação IMAP ('.class_basename($exception).'). Verifique o log local, sem compartilhar senhas.');

            return self::FAILURE;
        }
    }

    /**
     * Métricas de diagnóstico. Não coleta conteúdo de mensagens nem salva dados.
     *
     * @param  list<array<string,mixed>>  $messages
     * @return array{matched:int,sender_known_no_reference:int,sender_mismatch:int}
     */
    private function diagnose(array $messages): array
    {
        $senders = [];
        $refsByUid = [];
        $allReferences = [];
        foreach ($messages as $message) {
            $uid = (int) ($message['uid'] ?? 0);
            $sender = CampaignAudience::normalizedEmail((string) ($message['from_email'] ?? ''));
            if ($uid < 1 || filter_var($sender, FILTER_VALIDATE_EMAIL) === false) {
                continue;
            }
            $auto = mb_strtolower(trim((string) ($message['auto_submitted'] ?? '')));
            if ($auto !== '' && $auto !== 'no') {
                continue;
            }
            $senders[$sender] = true;
            $references = (string) ($message['in_reply_to'] ?? '').' '.(string) ($message['references'] ?? '');
            preg_match_all('/<([A-Za-z0-9.\-]+@[A-Za-z0-9.\-]+)>/', $references, $matches);
            $refs = array_values(array_unique($matches[1]));
            $refsByUid[$uid] = ['sender' => $sender, 'refs' => $refs];
            foreach ($refs as $ref) {
                $allReferences[$ref] = true;
            }
        }
        if ($refsByUid === []) {
            return ['matched' => 0, 'sender_known_no_reference' => 0, 'sender_mismatch' => 0];
        }

        $knownSender = [];
        $knownReference = [];
        foreach (EmailCampaignRecipient::query()->whereNotNull('attempted_at')
            ->whereIn('normalized_email', array_keys($senders))->pluck('normalized_email') as $email) {
            $knownSender[(string) $email] = true;
        }
        if ($allReferences !== []) {
            foreach (EmailCampaignRecipient::query()->whereNotNull('attempted_at')
                ->whereIn('message_id', array_keys($allReferences))->get(['normalized_email', 'message_id']) as $recipient) {
                $knownReference[(string) $recipient->message_id][(string) $recipient->normalized_email] = true;
            }
        }

        $matched = 0;
        $noRef = 0;
        $differentSender = 0;
        foreach ($refsByUid as $item) {
            $sender = $item['sender'];
            $references = $item['refs'];
            $anyMatchingId = false;
            $sameSender = false;
            foreach ($references as $reference) {
                if (isset($knownReference[$reference])) {
                    $anyMatchingId = true;
                    if (isset($knownReference[$reference][$sender])) {
                        $sameSender = true;
                    }
                }
            }
            if ($sameSender) {
                $matched++;
            } elseif ($anyMatchingId) {
                $differentSender++;
            } elseif (isset($knownSender[$sender])) {
                $noRef++;
            }
        }

        return ['matched' => $matched, 'sender_known_no_reference' => $noRef, 'sender_mismatch' => $differentSender];
    }

    /** A fonte externa pode retornar estruturas. Somente escalares são convertidos em texto. */
    private static function ciScalarStringV65(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
