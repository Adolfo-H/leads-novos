<?php

namespace App\Services\Campaigns;

use App\Models\EmailCampaign;
use App\Models\EmailCampaignBounce;
use App\Models\EmailCampaignRecipient;
use App\Models\EmailCampaignReply;
use App\Models\EmailCampaignSuppression;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Monta e inicia automaticamente rodadas previamente autorizadas pelo gestor.
 * O envio real continua EXCLUSIVAMENTE no dispatcher/worker existentes (SMTP, cotas, unsubscribe).
 * Não faz retry de envios incertos, não acessa o SMTP e não atualiza o HubSpot.
 */
final class CampaignAutomaticFollowups
{
    public function tick(): int
    {
        if (! $this->isReady()) {
            return 0;
        }

        $ids = DB::table('email_campaign_sequences')
            ->where('automatic', true)
            ->where('status', 'active')
            ->orderBy('id')
            ->limit(15)
            ->pluck('id');

        $started = 0;
        foreach ($ids as $id) {
            try {
                $started += $this->startNextRound((int) $id) ? 1 : 0;
            } catch (\Throwable $exception) {
                report($exception);
                Log::warning('Automatic campaign round needs review', [
                    'sequence_id' => (int) $id,
                    'reason' => class_basename($exception),
                ]);
            }
        }

        return $started;
    }

    public function isReady(): bool
    {
        if (! (bool) config('campaign_mail.enabled')
            || ! (bool) config('campaign_mail.live_enabled')
            || ! (bool) config('campaign_mail.auto_followups_enabled')) {
            return false;
        }
        if (! app(CampaignUnsubscribeLink::class)->isReady()) {
            return false;
        }

        // Sem IMAP sincronizado não sabemos se uma pessoa respondeu.
        // Isso exige CAMPAIGN_IMAP_ENABLED e sincronização efetiva.
        return app(CampaignImapHealth::class)->snapshot()['state'] === 'healthy';
    }

    public function setPaused(User $user, int $sequenceId, bool $pause): void
    {
        abort_unless($user->isCommercialManager(), 403);
        DB::table('email_campaign_sequences')
            ->where('id', $sequenceId)
            ->where('automatic', true)
            ->where('status', $pause ? 'active' : 'paused')
            ->update(['status' => $pause ? 'paused' : 'active', 'updated_at' => now('UTC')]);
        // Não cancela campanhas já iniciadas: o botão pausa apenas próximas rodadas.
    }

    private function startNextRound(int $sequenceId): bool
    {
        return DB::transaction(function () use ($sequenceId): bool {
            // A mesma trava de quota global do dispatcher/start evita preparar duas rodadas concorrentes.
            $gate = DB::table('email_campaign_dispatch_gates')
                ->where('id', 1)->lockForUpdate()->first();
            if ($gate === null) {
                return false;
            }
            $plan = DB::table('email_campaign_sequences')
                ->where('id', $sequenceId)->lockForUpdate()->first();
            if ($plan === null || ! (bool) $plan->automatic || $plan->status !== 'active') {
                return false;
            }
            if ((int) $plan->sent_rounds >= (int) $plan->max_rounds) {
                DB::table('email_campaign_sequences')->where('id', $sequenceId)
                    ->update(['status' => 'finished', 'updated_at' => now('UTC')]);

                return false;
            }
            $user = User::query()->find((int) $plan->created_by);
            if ($user === null || ! $user->isCommercialManager()) {
                return false;
            }
            $root = EmailCampaign::query()->find((int) $plan->email_campaign_id);
            $previous = EmailCampaign::query()->find((int) ($plan->last_campaign_id ?: $plan->email_campaign_id));
            if ($root === null || $previous === null || $root->status === 'canceled') {
                return false;
            }
            if ($previous->status === 'canceled') {
                DB::table('email_campaign_sequences')->where('id', $sequenceId)
                    ->update(['status' => 'paused', 'updated_at' => now('UTC')]);

                return false;
            }
            if ($previous->status !== 'completed' || $previous->completed_at === null
                || CarbonImmutable::parse($previous->completed_at)->greaterThan(now('UTC')->subDays((int) $plan->delay_days))) {
                return false;
            }

            // A rodada inicia apenas depois de TODOS os envios SMTP confirmados
            // da anterior completarem o intervalo configurado.
            $deadline = now('UTC')->subDays((int) $plan->delay_days);
            if (EmailCampaignRecipient::query()->where('email_campaign_id', $previous->id)
                ->where('status', 'sent')->where('sent_at', '>', $deadline)->exists()) {
                return false;
            }

            $source = EmailCampaignRecipient::query()
                ->where('email_campaign_id', $previous->id)
                ->where('status', 'sent')
                ->whereNull('replied_at')
                ->whereNull('excluded_from_sequences_at')
                ->whereNotNull('sent_at')
                ->orderBy('id')->get();
            if ($source->isEmpty()) {
                DB::table('email_campaign_sequences')->where('id', $sequenceId)
                    ->update(['status' => 'finished', 'updated_at' => now('UTC')]);

                return false;
            }
            $emails = $source->pluck('normalized_email')->unique()->values()->all();
            $campaignIds = EmailCampaign::query()
                ->whereKey($root->id)
                ->orWhere('followup_root_campaign_id', $root->id)
                ->pluck('id')->all();

            // Consulta em LOTE de todas as mensagens anteriores deste fluxo.
            // Uma resposta, descadastro, bounce ou envio incerto em QUALQUER rodada exclui o contato.
            $history = EmailCampaignRecipient::query()
                ->whereIn('email_campaign_id', $campaignIds)
                ->whereIn('normalized_email', $emails)
                ->get(['id', 'normalized_email', 'status', 'replied_at', 'excluded_from_sequences_at']);
            $historyIds = $history->pluck('id')->all();
            $repliedIds = EmailCampaignReply::query()
                ->whereIn('email_campaign_recipient_id', $historyIds)
                ->pluck('email_campaign_recipient_id')->flip();
            $bouncedIds = EmailCampaignBounce::query()
                ->whereIn('email_campaign_recipient_id', $historyIds)
                ->pluck('email_campaign_recipient_id')->flip();
            $blockedEmails = EmailCampaignSuppression::query()
                ->whereIn('normalized_email', $emails)
                ->pluck('normalized_email')->flip();

            foreach ($history as $row) {
                if ($row->replied_at !== null || $row->excluded_from_sequences_at !== null
                    || in_array($row->status, ['uncertain', 'suppressed'], true)
                    || $repliedIds->has($row->id) || $bouncedIds->has($row->id)) {
                    $blockedEmails->put($row->normalized_email, 1);
                }
            }

            $eligible = $source->filter(static fn (EmailCampaignRecipient $row): bool => ! $blockedEmails->has($row->normalized_email));
            $cap = max(1, (int) config('campaign_mail.max_recipients', 100));
            if ($eligible->count() > $cap) {
                // Nunca enviar apenas um pedaço e marcar o plano como concluído.
                Log::warning('Automatic campaign audience exceeds configured limit', [
                    'sequence_id' => $sequenceId, 'eligible' => $eligible->count(), 'limit' => $cap,
                ]);

                return false;
            }
            if ($eligible->isEmpty()) {
                DB::table('email_campaign_sequences')->where('id', $sequenceId)
                    ->update(['status' => 'finished', 'updated_at' => now('UTC')]);

                return false;
            }

            $round = (int) $plan->sent_rounds + 2;
            $campaign = EmailCampaign::query()->create([
                'name' => mb_substr('[Rodada '.$round.'] '.$plan->name, 0, 180),
                'subject' => $plan->subject,
                'body' => $plan->body,
                'status' => 'draft',
                'from_address' => $root->from_address,
                'from_name' => $root->from_name,
                'reply_to' => $root->reply_to,
                'created_by' => $user->id,
                'followup_root_campaign_id' => $root->id,
                'followup_round' => $round,
            ]);
            foreach ($eligible as $original) {
                $copy = $campaign->recipients()->create([
                    'source_key' => $original->source_key,
                    'email' => $original->email,
                    'normalized_email' => $original->normalized_email,
                    'contact_name' => $original->contact_name,
                    'company_name' => $original->company_name,
                    'job_title' => $original->job_title,
                    'status' => 'pending',
                ]);
                DB::table('email_campaign_sequence_links')->insert([
                    'sequence_id' => $sequenceId,
                    'original_recipient_id' => $original->id,
                    'followup_recipient_id' => $copy->id,
                    'created_at' => now('UTC'),
                    'updated_at' => now('UTC'),
                ]);
            }

            // Usa exatamente o start() do sistema: gestor, consentimento prévio no plano,
            // validação HTTPS/SMTP e quotas mantidas. Nenhum e-mail é enviado nesta transação.
            app(CampaignManager::class)->start($user, $campaign, true);
            DB::table('email_campaign_sequences')->where('id', $sequenceId)->update([
                'last_campaign_id' => $campaign->id,
                'draft_campaign_id' => $campaign->id,
                'sent_rounds' => (int) $plan->sent_rounds + 1,
                'status' => ((int) $plan->sent_rounds + 1 >= (int) $plan->max_rounds) ? 'finished' : 'active',
                'updated_at' => now('UTC'),
            ]);

            return true;
        }, 3);
    }
}
