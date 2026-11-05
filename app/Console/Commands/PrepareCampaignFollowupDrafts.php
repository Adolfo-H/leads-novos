<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Campaigns\CampaignSequencePlanner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Automatiza somente a CRIAÇÃO DE RASCUNHOS; envio continua sempre manual e sujeito aos gates. */
final class PrepareCampaignFollowupDrafts extends Command
{
    protected $signature = 'campaigns:prepare-followup-drafts';

    protected $description = 'Prepara rascunhos de follow-up vencidos, sem enviar e-mails (desativado por padrão)';

    public function handle(CampaignSequencePlanner $planner): int
    {
        // EC_CAMPAIGNS_FOLLOWUP_V294: agendar apenas planos com opt-in explícito.
        // Planos legados seguem desativados salvo flag existente.
        $globalEnabled = (bool) config('campaign_mail.followup_drafts_enabled', false);
        $rows = DB::table('email_campaign_sequences')->where('status', 'planned')
            ->when(! $globalEnabled, static fn ($q) => $q->where('auto_prepare', true))
            ->orderBy('id')->limit(20)->get();
        $prepared = 0;
        foreach ($rows as $row) {
            $user = User::query()->find((int) $row->created_by);
            if ($user === null || ! $user->isCommercialManager()) {
                continue;
            }
            try {
                if ($planner->prepare($user, (int) $row->id) !== null) {
                    $prepared++;
                }
            } catch (ValidationException $exception) {
                $this->warn('Plano #'.$row->id.' exige revisão de limite; nenhum envio realizado.');
            }
        }
        $this->info('Rascunhos preparados: '.$prepared.'. Nenhum e-mail enviado.');

        return self::SUCCESS;
    }
}
