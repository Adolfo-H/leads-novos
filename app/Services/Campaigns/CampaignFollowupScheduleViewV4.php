<?php

namespace App\Services\Campaigns;

use App\Jobs\QueueWorkerHeartbeat;
use App\Models\EmailCampaign;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Visualização somente leitura das rodadas automáticas já autorizadas.
 * Não reserva destinatários, não altera campanhas e nunca envia e-mail.
 */
final class CampaignFollowupScheduleViewV4
{
    /** @return array<string, mixed>|null */
    public function forCampaign(EmailCampaign $campaign): ?array
    {
        $rootId = (int) ($campaign->followup_root_campaign_id ?: $campaign->getKey());
        // A V3 ignorava todas as sequencias manuais (automatic=false),
        // fazendo o painel desaparecer para o status "planned".
        $plans = DB::table('email_campaign_sequences')
            ->where('email_campaign_id', $rootId)
            ->orderByDesc('id')->limit(40)->get();

        $plan = $plans->first(static fn (object $row): bool => (bool) ($row->automatic ?? false) && in_array((string) $row->status, ['active', 'paused'], true)
        ) ?? $plans->first(static fn (object $row): bool => in_array((string) $row->status, ['planned', 'prepared'], true)
        ) ?? $plans->first();

        return $plan === null ? null : $this->describe($plan);
    }

    /** @return list<array<string, mixed>> */
    public function home(int $limit = 4): array
    {
        $plans = DB::table('email_campaign_sequences')
            ->whereIn('status', ['active', 'paused', 'planned', 'prepared', 'finished'])
            ->orderByDesc('id')->limit(100)->get();

        // Uma sequencia automatica ativa tem precedencia sobre um
        // planejamento manual antigo da mesma campanha. Nunca duplicar cards.
        $selected = [];
        foreach ($plans as $plan) {
            $rootId = (int) $plan->email_campaign_id;
            if (! isset($selected[$rootId])) {
                $selected[$rootId] = $plan;

                continue;
            }
            $current = $selected[$rootId];
            $newPriority = (bool) ($plan->automatic ?? false)
                && in_array((string) $plan->status, ['active', 'paused'], true);
            $oldPriority = (bool) ($current->automatic ?? false)
                && in_array((string) $current->status, ['active', 'paused'], true);
            if ($newPriority && ! $oldPriority) {
                $selected[$rootId] = $plan;
            }
        }
        usort($selected, static fn (object $a, object $b): int => (int) $b->id <=> (int) $a->id);

        return array_map(fn (object $row): array => $this->describe($row),
            array_slice($selected, 0, max(1, min(8, $limit))));
    }

    /** @return array<string, mixed> */
    private function describe(\stdClass $plan): array
    {
        $rootId = (int) $plan->email_campaign_id;
        $previousId = (int) ($plan->last_campaign_id ?: $rootId);
        $root = EmailCampaign::query()->find($rootId);
        $previous = $previousId === $rootId
            ? $root
            : EmailCampaign::query()->find($previousId);
        $days = max(1, (int) $plan->delay_days);
        $sent = max(0, (int) ($plan->sent_rounds ?? 0));
        $max = max(1, (int) ($plan->max_rounds ?? 1));
        $remaining = max(0, $max - $sent);
        $status = (string) $plan->status;
        $automatic = (bool) ($plan->automatic ?? false);
        $previousCompleted = $previous !== null
            && $previous->status === 'completed'
            && $previous->completed_at !== null;

        // Tela e executor usam o MESMO cálculo. Nenhuma data meramente visual.
        $scheduledTimeValue = data_get($plan, 'send_time_local');
        $scheduledDateValue = $sent === 0 ? data_get($plan, 'first_send_date') : null;
        $scheduledTime = is_string($scheduledTimeValue) ? $scheduledTimeValue : null;
        $scheduledDate = is_string($scheduledDateValue) ? $scheduledDateValue : null;
        $dueAt = $previous !== null
            ? app(CampaignFollowupScheduleClock::class)->dueAt($previous, $days, $scheduledTime, $scheduledDate)
            : null;
        $displayTz = $scheduledTime !== null ? 'America/Sao_Paulo' : $this->timezone();

        // Resultado real da última rodada iniciada ou, antes dela, da campanha original.
        $tracked = $sent > 0 ? $previous : $root;
        $report = null;
        $reportBounces = 0;
        $reportReplies = 0;
        if ($tracked !== null) {
            $report = DB::table('email_campaign_recipients')
                ->where('email_campaign_id', $tracked->id)
                ->selectRaw("COUNT(*) as total, SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) as accepted, SUM(CASE WHEN status = 'uncertain' THEN 1 ELSE 0 END) as uncertain, SUM(CASE WHEN status IN ('pending', 'reserved', 'sending') THEN 1 ELSE 0 END) as pending")
                ->first();
            $reportBounces = DB::table('email_campaign_bounces')
                ->where('email_campaign_id', $tracked->id)
                ->whereNotNull('email_campaign_recipient_id')->count();
            $reportReplies = DB::table('email_campaign_replies')
                ->where('email_campaign_id', $tracked->id)->count();
        }

        // Resultado confirmado PELOS REGISTROS LOCAIS do SMTP, nao por abertura.
        // O plano "finished" significa que todas as rodadas foram INICIADAS;
        // os destinatarios podem continuar processando quando isso ocorre.
        $latestTotal = (int) data_get($report, 'total', 0);
        $latestAccepted = (int) data_get($report, 'accepted', 0);
        $latestPending = (int) data_get($report, 'pending', 0);
        $latestUncertain = (int) data_get($report, 'uncertain', 0);
        $lastRoundNumber = $sent > 0 ? $sent + 1 : 1;
        $latestSmtpAt = null;
        if ($tracked !== null && $latestAccepted > 0) {
            $lastSmtpValue = DB::table('email_campaign_recipients')
                ->where('email_campaign_id', $tracked->id)
                ->where('status', 'sent')
                ->max('sent_at');
            if ($lastSmtpValue !== null) {
                try {
                    $latestSmtpAt = CarbonImmutable::parse((string) $lastSmtpValue, 'UTC')
                        ->setTimezone('America/Sao_Paulo')
                        ->format('d/m/Y H:i');
                } catch (\Throwable) {
                    // Um valor invalido nao deve impedir a leitura da campanha.
                }
            }
        }

        $eligible = ($previousCompleted && $remaining > 0)
            ? $this->eligibleRecipientCount($rootId, (int) $previous->id)
            : null;

        $mailEnabled = (bool) config('campaign_mail.enabled')
            && (bool) config('campaign_mail.live_enabled');
        $autoEnabled = (bool) config('campaign_mail.auto_followups_enabled');
        $imapState = ($status === 'active' && $mailEnabled && $autoEnabled)
            ? (string) app(CampaignImapHealth::class)->snapshot()['state']
            : 'not_checked';
        $publicUnsubscribe = $status === 'active' && $mailEnabled && $autoEnabled
            ? app(CampaignUnsubscribeLink::class)->isReady()
            : false;

        // Sinais reais do scheduler e do worker; planejamento sozinho nao envia.
        $schedulerHeartbeat = Cache::get('ops:scheduler:last-heartbeat');
        $schedulerHealthy = is_string($schedulerHeartbeat) && $schedulerHeartbeat !== ''
            && CarbonImmutable::parse($schedulerHeartbeat)->greaterThan(CarbonImmutable::now('UTC')->subMinutes(3));
        $campaignQueue = (string) config('campaign_mail.queue', 'campaigns');
        $workerHeartbeat = Cache::get(QueueWorkerHeartbeat::cacheKey($campaignQueue));
        $workerHealthy = is_string($workerHeartbeat) && $workerHeartbeat !== ''
            && CarbonImmutable::parse($workerHeartbeat)->greaterThan(CarbonImmutable::now('UTC')->subMinutes(3));

        if ($remaining <= 0 || $status === 'finished') {
            $state = 'finished';
            if ($sent === 0 || $tracked === null || $latestTotal === 0) {
                $stateLabel = 'Sequencia finalizada — consulte as rodadas';
                $stateReason = 'Nao existem novas rodadas previstas. Confira o historico.';
            } elseif ($latestPending > 0) {
                $stateLabel = $lastRoundNumber.'ª rodada — envio em andamento';
                $stateReason = $latestAccepted.' de '.$latestTotal.' aceitos pelo SMTP; '
                    .$latestPending.' ainda aguardam processamento.';
            } elseif ($latestUncertain > 0) {
                $stateLabel = $lastRoundNumber.'ª rodada — requer revisao';
                $stateReason = $latestAccepted.' aceitos pelo SMTP; '
                    .$latestUncertain.' envio(s) com resultado incerto. Nao reenvie automaticamente.';
            } elseif ($latestAccepted === $latestTotal) {
                $stateLabel = $lastRoundNumber.'ª rodada — aceita pelo SMTP';
                $stateReason = 'Rodada executada: '.$latestAccepted.' de '.$latestTotal
                    .' aceitos pelo SMTP'.($reportBounces > 0 ? ', com '.$reportBounces.' devolucao(oes) registrada(s).' : '.')
                    .' Aceitacao SMTP nao comprova entrega na caixa de entrada.';
            } else {
                $stateLabel = $lastRoundNumber.'ª rodada — consultar resultados';
                $stateReason = $latestAccepted.' de '.$latestTotal
                    .' aceitos pelo SMTP. Confira os demais destinatarios e estados da campanha.';
            }
        } elseif (! $automatic) {
            // Planejado manual nao equivale a uma rodada automatica agendada.
            $state = 'manual';
            $stateLabel = $status === 'prepared'
                ? 'Rascunho criado — envio depende de aprovacao'
                : 'Apenas planejado — envio nao automatico';
            $stateReason = $status === 'prepared'
                ? 'Este follow-up virou rascunho e nao sera enviado sem a aprovacao do gestor.'
                : 'O intervalo e a mensagem estao salvos, mas nao existe autorizacao de disparo automatico. Abra "Configurar envios automaticos apos a campanha" para criar uma automacao.';
        } elseif ($status === 'paused') {
            $state = 'paused';
            $stateLabel = 'Próxima rodada pausada';
            $stateReason = 'O gestor precisa retomar a sequência para permitir novos envios.';
        } elseif ($root === null || $previous === null || $previous->status === 'canceled' || $root->status === 'canceled') {
            $state = 'blocked';
            $stateLabel = 'Revisar sequência';
            $stateReason = 'A campanha original ou a rodada anterior não está disponível para continuar.';
        } elseif (! $previousCompleted) {
            $state = 'waiting';
            $stateLabel = 'Aguardando rodada anterior';
            $stateReason = 'A contagem de dias começa após a conclusão e a última aceitação SMTP da rodada anterior.';
        } elseif ($eligible === 0) {
            $state = 'no_recipients';
            $stateLabel = 'Sem contatos elegíveis';
            $stateReason = 'Não há contatos confirmados para esta próxima rodada; nenhuma mensagem será enviada.';
        } elseif (! $autoEnabled || ! $mailEnabled) {
            $state = 'blocked';
            $stateLabel = 'Automação ainda não liberada';
            $stateReason = 'A sequência está configurada, mas o envio automático/SMTP não está habilitado no ambiente.';
        } elseif ($imapState !== 'healthy') {
            $state = 'blocked';
            $stateLabel = 'Aguardando sincronização IMAP';
            $stateReason = 'É preciso confirmar as respostas recebidas antes de iniciar outro contato.';
        } elseif (! $publicUnsubscribe) {
            $state = 'blocked';
            $stateLabel = 'Descadastro HTTPS pendente';
            $stateReason = 'O sistema exige um endereço público seguro para descadastro.';
        } elseif ($dueAt === null) {
            $state = 'blocked';
            $stateLabel = 'Data ou horario invalidos';
            $stateReason = 'Revise a programacao antes de iniciar um envio.';
        } elseif (! $schedulerHealthy) {
            $state = 'blocked';
            $stateLabel = 'Agendador sem atividade recente';
            $stateReason = 'O cronograma esta salvo, mas o scheduler nao registra atividade recente. Confira schedule:work ou o cron do Laravel.';
        } elseif (! $workerHealthy) {
            $state = 'blocked';
            $stateLabel = 'Fila de campanhas sem worker';
            $stateReason = 'O cronograma esta salvo, mas a fila de campanhas nao possui heartbeat recente. Verifique o worker Redis da fila de campanhas.';
        } elseif ($dueAt->greaterThan(CarbonImmutable::now('UTC'))) {
            $state = 'scheduled';
            $stateLabel = 'Próxima rodada programada';
            $stateReason = 'O sistema aguardará o prazo configurado e as condições de envio.';
        } else {
            $state = 'ready';
            $stateLabel = 'Prazo atingido — aguardando agendador';
            $stateReason = 'O agendador poderá iniciar a próxima rodada, respeitando a fila e as cotas SMTP.';
        }

        $rounds = [];
        for ($index = 1; $index <= $max; $index++) {
            $number = $index + 1;
            $stage = $index <= $sent ? 'started' : ($index === $sent + 1 ? 'next' : 'future');
            $rounds[] = [
                'number' => $number,
                'label' => $number.'ª rodada',
                'stage' => $stage,
                'date' => $stage === 'next' && $dueAt !== null
                    ? $dueAt->setTimezone($displayTz)->format('d/m/Y')
                    : null,
            ];
        }

        return [
            'id' => (int) $plan->id,
            'root_id' => $rootId,
            'root_name' => (string) ($root->name ?? 'Campanha original'),
            'name' => (string) $plan->name,
            'status' => $status,
            'automatic' => $automatic,
            'state' => $state,
            'state_label' => $stateLabel,
            'state_reason' => $stateReason,
            'next_round' => $sent + 2,
            'days' => $days,
            'max_followups' => $max,
            'sent_followups' => $sent,
            'remaining_followups' => $remaining,
            'previous_completed' => $previousCompleted,
            'due_at' => $dueAt,
            'due_date' => $dueAt?->setTimezone($displayTz)->format('d/m/Y'),
            'due_datetime' => $dueAt?->setTimezone($displayTz)->format('d/m/Y H:i'),
            'send_time_local' => $scheduledTime,
            'first_send_date' => $scheduledDate,
            'scheduler_healthy' => $schedulerHealthy,
            'campaign_worker_healthy' => $workerHealthy,
            'latest_campaign_id' => $tracked?->id,
            'latest_campaign_name' => $tracked?->name,
            'latest_campaign_status' => $tracked?->status,
            'latest_total_count' => $latestTotal,
            'last_round_number' => $lastRoundNumber,
            'latest_smtp_at' => $latestSmtpAt,
            'accepted_smtp_count' => (int) data_get($report, 'accepted', 0),
            'uncertain_count' => (int) data_get($report, 'uncertain', 0),
            'pending_count' => (int) data_get($report, 'pending', 0),
            'bounces_count' => $reportBounces,
            'replies_count' => $reportReplies,
            'eligible_count' => $eligible,
            'subject' => (string) $plan->subject,
            'body' => (string) $plan->body,
            'from_address' => (string) ($root->from_address ?? ''),
            'rounds' => $rounds,
        ];
    }

    /**
     * Mesma regra principal de elegibilidade do motor V2: histórico de todas
     * as rodadas, respostas, DSNs vinculadas, descadastro e envio incerto.
     * O resultado é estimativa de leitura, nunca uma reserva de SMTP.
     */
    private function eligibleRecipientCount(int $rootId, int $previousId): int
    {
        $flowCampaignIds = EmailCampaign::query()
            ->where(static function ($query) use ($rootId): void {
                $query->whereKey($rootId)
                    ->orWhere('followup_root_campaign_id', $rootId);
            })
            ->pluck('id')->all();

        return (int) DB::table('email_campaign_recipients as original')
            ->where('original.email_campaign_id', $previousId)
            ->where('original.status', 'sent')
            ->whereNotNull('original.sent_at')
            ->whereNull('original.replied_at')
            ->whereNull('original.excluded_from_sequences_at')
            ->whereNotExists(static function (Builder $q): void {
                $q->selectRaw('1')
                    ->from('email_campaign_suppressions as suppression')
                    ->whereColumn('suppression.normalized_email', 'original.normalized_email');
            })
            ->whereNotExists(static function (Builder $q) use ($flowCampaignIds): void {
                $q->selectRaw('1')
                    ->from('email_campaign_recipients as history')
                    ->whereIn('history.email_campaign_id', $flowCampaignIds)
                    ->whereColumn('history.normalized_email', 'original.normalized_email')
                    ->where(static function (Builder $blocked): void {
                        $blocked->whereNotNull('history.replied_at')
                            ->orWhereNotNull('history.excluded_from_sequences_at')
                            ->orWhereIn('history.status', ['uncertain', 'suppressed'])
                            ->orWhereExists(static function (Builder $reply): void {
                                $reply->selectRaw('1')
                                    ->from('email_campaign_replies as reply')
                                    ->whereColumn('reply.email_campaign_recipient_id', 'history.id');
                            })
                            ->orWhereExists(static function (Builder $bounce): void {
                                $bounce->selectRaw('1')
                                    ->from('email_campaign_bounces as bounce')
                                    ->whereColumn('bounce.email_campaign_recipient_id', 'history.id');
                            });
                    });
            })
            // Mesmas exclusoes globais aplicadas na criacao da rodada.
            ->whereNotExists(static function (Builder $q): void {
                $q->selectRaw('1')
                    ->from('email_campaign_recipients as any_history')
                    ->whereColumn('any_history.normalized_email', 'original.normalized_email')
                    ->where(static function (Builder $blocked): void {
                        $blocked->whereNotNull('any_history.replied_at')
                            ->orWhere('any_history.status', 'uncertain')
                            ->orWhereExists(static function (Builder $reply): void {
                                $reply->selectRaw('1')->from('email_campaign_replies as any_reply')
                                    ->whereColumn('any_reply.email_campaign_recipient_id', 'any_history.id');
                            })
                            ->orWhereExists(static function (Builder $bounce): void {
                                $bounce->selectRaw('1')->from('email_campaign_bounces as any_bounce')
                                    ->whereColumn('any_bounce.email_campaign_recipient_id', 'any_history.id');
                            });
                    });
            })
            ->whereNotExists(static function (Builder $q): void {
                $q->selectRaw('1')->from('email_campaign_bounces as any_bounce_email')
                    ->whereColumn('any_bounce_email.final_recipient', 'original.normalized_email');
            })
            ->distinct()
            ->count('original.normalized_email');
    }

    private function timezone(): string
    {
        return (string) config('app.timezone', 'America/Sao_Paulo');
    }
}
