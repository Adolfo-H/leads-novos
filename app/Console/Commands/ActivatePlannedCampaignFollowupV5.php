<?php

namespace App\Console\Commands;

use App\Models\EmailCampaign;
use App\Models\User;
use App\Services\Campaigns\CampaignFollowupSettingsV5;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Migra apenas a continuidade especificada na instalacao; nunca envia e-mail. */
final class ActivatePlannedCampaignFollowupV5 extends Command
{
    protected $signature = 'campaigns:activate-existing-followup
        {--campaign= : Nome exato da campanha inicial}
        {--plan= : Nome exato do plano de follow-up}
        {--rounds=2 : Quantidade maxima de follow-ups}
        {--apply : Gravar a autorizacao da continuidade existente}';

    protected $description = 'Ativa somente o plano indicado, sem criar campanhas ou enviar SMTP';

    public function handle(CampaignFollowupSettingsV5 $settings): int
    {
        $campaignName = trim((string) $this->option('campaign'));
        $planName = trim((string) $this->option('plan'));
        $rounds = (int) $this->option('rounds');
        if ($campaignName === '' || $planName === '' || $rounds < 1 || $rounds > 4) {
            $this->error('Informe --campaign, --plan e --rounds entre 1 e 4.');

            return self::FAILURE;
        }
        $roots = EmailCampaign::query()->where('name', $campaignName)
            ->whereNull('followup_root_campaign_id')->limit(2)->get();
        if ($roots->count() !== 1) {
            $this->warn('Campanha não encontrada de forma única; nenhuma mudança feita.');

            return self::SUCCESS;
        }
        $root = $roots->first();
        $plans = DB::table('email_campaign_sequences')
            ->where('email_campaign_id', $root->id)->where('name', $planName)
            ->whereIn('status', ['planned', 'active', 'paused'])->limit(2)->get();
        if ($plans->count() !== 1) {
            $this->warn('Plano não encontrado de forma única; nenhuma mudança feita.');

            return self::SUCCESS;
        }
        $plan = $plans->first();
        $this->info('Plano #'.$plan->id.' da campanha #'.$root->id.': '.$plan->status);
        if (! $this->option('apply')) {
            $this->line('Prévia: use --apply para ativar. Nenhum e-mail será enviado por este comando.');

            return self::SUCCESS;
        }
        $creator = User::query()->find((int) $plan->created_by);
        if ($creator === null || ! $creator->isCommercialManager()) {
            $this->warn('Autor gestor da sequência ausente; nenhuma mudança feita.');

            return self::SUCCESS;
        }
        try {
            $id = $settings->activateExisting($creator, (int) $root->id, (int) $plan->id, $rounds);
            $this->info('Continuacao automatica configurada: plano #'.$id.'. Nenhum e-mail enviado.');
        } catch (Throwable $error) {
            report($error);
            $this->error('Não foi possível converter o plano; revise a situação da campanha e o log.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
