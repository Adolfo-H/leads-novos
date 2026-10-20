<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Campaigns\CampaignSequencePlanner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Automatiza somente a CRIAÇÃO DE RASCUNHOS; envio continua sempre manual e sujeito aos gates. */
final class PrepareCampaignFollowupDrafts extends Command
{
    protected $signature = 'campaigns:prepare-followup-drafts';

    protected $description = 'Prepara rascunhos de follow-up vencidos, sem enviar e-mails (desativado por padrão)';

    public function handle(CampaignSequencePlanner $planner): int
    {
        if (! config('campaign_mail.followup_drafts_enabled', false) || config('campaign_mail.live_enabled')) {
            $this->line('Automação de rascunhos desativada ou envio comercial ligado; nada alterado.');

            return self::SUCCESS;
        }
        $rows = DB::table('email_campaign_sequences')->where('status', 'planned')->orderBy('id')->limit(20)->get();
        $prepared = 0;
        foreach ($rows as $row) {
            $user = User::query()->find((int) $row->created_by);
            if ($user === null || ! $user->isCommercialManager()) {
                continue;
            }
            if ($planner->prepare($user, (int) $row->id) !== null) {
                $prepared++;
            }
        }
        $this->info('Rascunhos preparados: '.$prepared.'. Nenhum e-mail enviado.');

        return self::SUCCESS;
    }
}
