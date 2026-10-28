<?php

namespace App\Console\Commands;

use App\Models\EmailCampaign;
use App\Models\EmailCampaignRecipient;
use App\Models\EmailCampaignReply;
use Illuminate\Console\Command;

/** Relatório local; não conecta ao IMAP, não recupera mensagens e não envia e-mail. */
final class AuditCampaignRepliesV71 extends Command
{
    protected $signature = 'campaigns:reply-audit {campaign : ID da campanha}';

    protected $description = 'Audita os identificadores de envio e a contagem de respostas registradas da campanha';

    public function handle(): int
    {
        $campaignId = filter_var($this->argument('campaign'), FILTER_VALIDATE_INT);
        if ($campaignId === false || $campaignId < 1) {
            $this->error('ID de campanha inválido.');

            return self::FAILURE;
        }
        $campaign = EmailCampaign::query()->find($campaignId);
        if ($campaign === null) {
            $this->error('Campanha não encontrada na base local.');

            return self::FAILURE;
        }
        $recipients = EmailCampaignRecipient::query()->where('email_campaign_id', $campaignId);
        $total = (clone $recipients)->count();
        $sent = (clone $recipients)->where('status', 'sent')->count();
        $withMessageId = (clone $recipients)->whereNotNull('message_id')->where('message_id', '!=', '')->count();
        $withAttempt = (clone $recipients)->whereNotNull('attempted_at')->count();
        $replied = (clone $recipients)->whereNotNull('replied_at')->count();
        $replyRecords = EmailCampaignReply::query()->where('email_campaign_id', $campaignId)->count();

        $this->line('Campanha #'.$campaignId.' — '.$campaign->status);
        $this->line('Destinatários: '.$total);
        $this->line('Aceitos SMTP: '.$sent);
        $this->line('Com tentativa registrada: '.$withAttempt);
        $this->line('Com Message-ID rastreável: '.$withMessageId);
        $this->line('Destinatários com resposta marcada: '.$replied);
        $this->line('Respostas importadas via IMAP: '.$replyRecords);
        if ($sent > 0 && $withMessageId < $sent) {
            $this->warn('Há mensagens aceitas sem Message-ID rastreável: o vínculo automático pode falhar.');
        }
        if ($sent > 0 && $replyRecords === 0) {
            $this->warn('Sem resposta correlacionada. Verifique o diagnóstico IMAP e recupere a janela recente, se necessário.');
        }
        $this->line('Nenhum e-mail foi enviado ou lido nesta auditoria.');

        return self::SUCCESS;
    }
}
