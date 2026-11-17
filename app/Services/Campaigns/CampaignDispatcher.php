<?php

namespace App\Services\Campaigns;

use App\Jobs\SendEmailCampaignRecipient;
use App\Models\EmailCampaign;
use App\Models\EmailCampaignRecipient;
use App\Models\EmailCampaignSuppression;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

final class CampaignDispatcher
{
    /**
     * Reserva destinatários sob uma trava GLOBAL no PostgreSQL, inclusive
     * quando duas chamadas do agendador são disparadas ao mesmo tempo.
     *
     * Cada reserva recebe um UUID. Jobs antigos não podem utilizar uma reserva
     * que tenha sido liberada por pausa, cancelamento ou alteração de estado.
     */
    public function tick(): int
    {
        if (! config('campaign_mail.enabled') || ! config('campaign_mail.live_enabled')) {
            return 0;
        }

        // CAMPANHAS_V70_LIMITES_DINAMICOS: lemos o ritmo DENTRO da trava global.
        // Assim mudanças em andamento e novas reservas não usam cotas defasadas.
        $policyService = app(CampaignSendPolicy::class);

        /** @var list<array{id: int, token: string}> $reserved */
        $reserved = DB::transaction(static function () use ($policyService): array {
            // Todas as execuções travam a MESMA linha, não apenas a campanha
            // ativa. Isso serializa a verificação das cotas entre campanhas.
            // A trava id=1 é independente das campanhas e criada pela migration V4.
            $gate = DB::table('email_campaign_dispatch_gates')
                ->where('id', 1)->lockForUpdate()->first();
            if ($gate === null) {
                throw new LogicException('Campaign dispatch quota gate is missing.');
            }

            $policy = $policyService->effective();
            $hour = max(1, min((int) config('campaign_mail.smtp_approved_per_hour', 100), $policy['hour']));
            $day = max(1, min((int) config('campaign_mail.smtp_approved_per_day', 500), $policy['day']));
            $tick = max(1, min((int) config('campaign_mail.smtp_approved_per_tick', 5), $policy['tick']));

            $utcNow = now('UTC');
            $hourCutoff = $utcNow->copy()->subHour();
            $dayCutoff = $utcNow->copy()->subDay();

            // Os contadores GLOBAIS protegem a capacidade SMTP de todas as campanhas.
            // Nunca aplique um limite individual ao uso global: a campanha seguinte
            // ficaria aguardando 60 minutos por um envio feito pela campanha anterior.
            $hourUsed = EmailCampaignRecipient::query()
                ->where(static function ($query) use ($hourCutoff): void {
                    $query->where('attempted_at', '>=', $hourCutoff)
                        ->orWhere(static function ($query) use ($hourCutoff): void {
                            $query->where('status', 'reserved')
                                ->where('reserved_at', '>=', $hourCutoff);
                        });
                })->count();
            $dayUsed = EmailCampaignRecipient::query()
                ->where(static function ($query) use ($dayCutoff): void {
                    $query->where('attempted_at', '>=', $dayCutoff)
                        ->orWhere(static function ($query) use ($dayCutoff): void {
                            $query->where('status', 'reserved')
                                ->where('reserved_at', '>=', $dayCutoff);
                        });
                })->count();

            $globallyAvailable = min($tick, max(0, $hour - $hourUsed), max(0, $day - $dayUsed));
            if ($globallyAvailable === 0) {
                return [];
            }

            // Não permitir que uma campanha sem capacidade individual / pendentes
            // bloqueie outras campanhas ativas. Mantém a ordem histórica por ID.
            $campaigns = EmailCampaign::query()
                ->where('status', 'running')
                ->whereHas('recipients', static fn ($query) => $query->where('status', 'pending'))
                ->orderBy('id')->lockForUpdate()->get(['id']);

            $result = [];
            foreach ($campaigns as $campaign) {
                $remaining = $globallyAvailable - count($result);
                if ($remaining <= 0) {
                    break;
                }

                // O ritmo desta campanha só considera destinatários DELA.
                // O teto global continua sendo aplicado à soma de todas as campanhas.
                $perCampaign = app(CampaignPacing::class)->limits((int) $campaign->id);
                $campaignHourLimit = $perCampaign['hour'] ?? $hour;
                $campaignTickLimit = $perCampaign['tick'] ?? $tick;
                $campaignHourUsed = EmailCampaignRecipient::query()
                    ->where('email_campaign_id', $campaign->id)
                    ->where(static function ($query) use ($hourCutoff): void {
                        $query->where('attempted_at', '>=', $hourCutoff)
                            ->orWhere(static function ($query) use ($hourCutoff): void {
                                $query->where('status', 'reserved')
                                    ->where('reserved_at', '>=', $hourCutoff);
                            });
                    })->count();
                $available = min(
                    $remaining,
                    $campaignTickLimit,
                    max(0, $campaignHourLimit - $campaignHourUsed),
                );
                if ($available <= 0) {
                    continue;
                }

                $ids = EmailCampaignRecipient::query()
                    ->where('email_campaign_id', $campaign->id)
                    ->where('status', 'pending')
                    ->orderBy('id')->limit($available)
                    ->lockForUpdate()->pluck('id')->all();

                foreach ($ids as $id) {
                    $token = (string) Str::uuid();
                    $updated = EmailCampaignRecipient::query()
                        ->whereKey((int) $id)->where('status', 'pending')
                        ->update([
                            'status' => 'reserved',
                            'reserved_at' => $utcNow,
                            'reservation_token' => $token,
                        ]);
                    if ($updated > 0) {
                        $result[] = ['id' => (int) $id, 'token' => $token];
                    }
                }
            }

            return $result;
        });

        // Uma falha ao inserir na fila deixa a reserva para auditoria humana;
        // nunca a libera automaticamente quando o broker pode tê-la aceitado.
        foreach ($reserved as $item) {
            SendEmailCampaignRecipient::dispatch($item['id'], $item['token'])
                ->onConnection('redis')
                ->onQueue((string) config('campaign_mail.queue', 'campaigns'));
        }

        // Não altera status de envios incertos e não reprograma destinatários.
        EmailCampaign::query()->where('status', 'running')->get()->each(
            static function (EmailCampaign $campaign): void {
                if (! EmailCampaignRecipient::query()
                    ->where('email_campaign_id', $campaign->id)
                    ->whereIn('status', ['pending', 'reserved', 'sending'])->exists()) {
                    EmailCampaign::query()->whereKey($campaign->id)->where('status', 'running')
                        ->update(['status' => 'completed', 'completed_at' => now('UTC')]);
                }
            }
        );

        return count($reserved);
    }

    public function markAsSuppressedIfNecessary(EmailCampaignRecipient $recipient): bool
    {
        $blocked = EmailCampaignSuppression::query()
            ->where('normalized_email', $recipient->normalized_email)->exists();
        if ($blocked) {
            $recipient->update(['status' => 'suppressed', 'reservation_token' => null]);
        }

        return $blocked;
    }
}
