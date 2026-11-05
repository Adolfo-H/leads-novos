<?php

namespace App\Services\Campaigns;

use App\Models\EmailCampaign;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Visualização somente leitura das rodadas automáticas já autorizadas.
 * Não reserva destinatários, não altera campanhas e nunca envia e-mail.
 */
final class CampaignFollowupScheduleView
{
    /** @return array<string, mixed>|null */
    public function forCampaign(EmailCampaign $campaign): ?array
    {
        $rootId = (int) ($campaign->followup_root_campaign_id ?: $campaign->getKey());
        $plan = DB::table('email_campaign_sequences')
            ->where('email_campaign_id', $rootId)
            ->where('automatic', true)
            ->orderByDesc('id')
            ->first();

        if ($plan === null) {
            return null;
        }

        return $this->describe($plan);
    }

    /** @return list<array<string, mixed>> */
    public function home(int $limit = 4): array
    {
        $rows = DB::table('email_campaign_sequences')
            ->where('automatic', true)
            ->whereIn('status', ['active', 'paused'])
            ->orderByDesc('id')
            ->limit(max(1, min(8, $limit)))
            ->get();

        $result = [];
        foreach ($rows as $row) {
            $result[] = $this->describe($row);
        }

        return $result;
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
        $previousCompleted = $previous !== null
            && $previous->status === 'completed'
            && $previous->completed_at !== null;

        // O executor real exige tanto a conclusão quanto o prazo de todos
        // os destinatários aceitos pelo SMTP da rodada anterior.
        $dueAt = null;
        if ($previousCompleted) {
            $completed = $this->asUtcDate($previous->completed_at);
            $lastAccepted = DB::table('email_campaign_recipients')
                ->where('email_campaign_id', $previous->id)
                ->where('status', 'sent')
                ->max('sent_at');
            $lastSent = $this->asUtcDate($lastAccepted);
            if ($completed !== null) {
                $reference = $lastSent !== null && $lastSent->greaterThan($completed)
                    ? $lastSent : $completed;
                $dueAt = $reference->addDays($days);
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

        if ($remaining <= 0 || $status === 'finished') {
            $state = 'finished';
            $stateLabel = 'Rodadas programadas iniciadas';
            $stateReason = 'Veja os resultados individuais no histórico de campanhas.';
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
        } elseif ($dueAt !== null && $dueAt->greaterThan(CarbonImmutable::now('UTC'))) {
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
                    ? $dueAt->setTimezone($this->timezone())->format('d/m/Y')
                    : null,
            ];
        }

        return [
            'id' => (int) $plan->id,
            'root_id' => $rootId,
            'root_name' => (string) ($root->name ?? 'Campanha original'),
            'name' => (string) $plan->name,
            'status' => $status,
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
            'due_date' => $dueAt?->setTimezone($this->timezone())->format('d/m/Y'),
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
            ->distinct()
            ->count('original.normalized_email');
    }

    private function asUtcDate(mixed $value): ?CarbonImmutable
    {
        if ($value instanceof DateTimeInterface) {
            return CarbonImmutable::instance($value)->setTimezone('UTC');
        }
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value, 'UTC');
        } catch (\Throwable) {
            return null;
        }
    }

    private function timezone(): string
    {
        return (string) config('app.timezone', 'America/Sao_Paulo');
    }
}
