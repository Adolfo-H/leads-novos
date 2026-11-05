<?php

namespace App\Services\Campaigns;

use App\Models\EmailCampaign;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Controle de continuidade comercial: salva configuracoes, nao envia mensagens.
 * O SMTP permanece no worker existente e o agendamento no CampaignAutomaticFollowups.
 */
final class CampaignFollowupSettingsV5
{
    /**
     * @param  array{name:string,delay_days:int|string,max_rounds:int|string,subject:string,body:string}  $fields
     */
    public function save(User $actor, int $campaignId, array $fields, ?int $preferredPlanId = null): int
    {
        abort_unless($actor->isCommercialManager(), 403);

        /** @var array{name:string,delay_days:int,max_rounds:int,subject:string,body:string} $valid */
        $valid = Validator::make($fields, [
            'name' => ['required', 'string', 'min:3', 'max:150'],
            'delay_days' => ['required', 'integer', 'min:1', 'max:90'],
            'max_rounds' => ['required', 'integer', 'min:1', 'max:4'],
            'subject' => ['required', 'string', 'min:3', 'max:180'],
            'body' => ['required', 'string', 'min:10', 'max:12000'],
        ])->validate();

        return DB::transaction(function () use ($actor, $campaignId, $valid, $preferredPlanId): int {
            // Ordem global de locks igual ao despachante; nunca manter lock durante SMTP.
            $gate = DB::table('email_campaign_dispatch_gates')->where('id', 1)->lockForUpdate()->first();
            if ($gate === null) {
                throw ValidationException::withMessages(['flow' => 'Trava global de envio não encontrada.']);
            }
            $root = EmailCampaign::query()->whereKey($campaignId)->lockForUpdate()->firstOrFail();
            if ($root->followup_root_campaign_id !== null || $root->status !== 'completed') {
                throw ValidationException::withMessages(['flow' => 'A continuidade exige uma campanha inicial concluída.']);
            }
            if (! $root->recipients()->where('status', 'sent')->exists()) {
                throw ValidationException::withMessages(['flow' => 'A campanha não possui mensagens aceitas pelo SMTP.']);
            }

            $plans = DB::table('email_campaign_sequences')
                ->where('email_campaign_id', $root->id)->orderByDesc('id')->lockForUpdate()->get();
            $automatic = $plans->first(static fn (object $row): bool => (bool) ($row->automatic ?? false)
                && in_array((string) $row->status, ['active', 'paused'], true));
            $hasFinished = $plans->contains(static fn (object $row): bool => (bool) ($row->automatic ?? false) && (string) $row->status === 'finished');
            if ($automatic === null && $hasFinished) {
                throw ValidationException::withMessages(['flow' => 'Esta sequência já terminou. Consulte o histórico antes de criar outra.']);
            }
            if ($preferredPlanId !== null && $automatic !== null && (int) $automatic->id !== $preferredPlanId) {
                throw ValidationException::withMessages(['flow' => 'Já existe uma sequência automática ativa para esta campanha.']);
            }
            $plan = $preferredPlanId !== null
                ? $plans->first(static fn (object $row): bool => (int) $row->id === $preferredPlanId)
                : ($automatic ?? $plans->first(static fn (object $row): bool => (string) $row->status === 'planned'
                    && ! (bool) ($row->automatic ?? false)));
            if ($preferredPlanId !== null && ($plan === null || (string) $plan->status !== 'planned')) {
                throw ValidationException::withMessages(['flow' => 'O planejamento solicitado não está mais disponível.']);
            }

            $attrs = [
                'name' => trim($valid['name']),
                'subject' => trim($valid['subject']),
                'body' => trim($valid['body']),
                'delay_days' => (int) $valid['delay_days'],
                'max_rounds' => (int) $valid['max_rounds'],
                'updated_at' => now('UTC'),
            ];

            if ($plan !== null) {
                if ((int) $valid['max_rounds'] < (int) $plan->sent_rounds) {
                    throw ValidationException::withMessages(['max_rounds' => 'O total não pode ser menor que as rodadas já iniciadas.']);
                }
                $attrs += [
                    'automatic' => true,
                    'status' => (string) $plan->status === 'paused' ? 'paused' : 'active',
                    'last_campaign_id' => (int) ($plan->last_campaign_id ?: $root->id),
                    'automatic_authorized_at' => $plan->automatic_authorized_at ?: now('UTC'),
                ];
                DB::table('email_campaign_sequences')->where('id', (int) $plan->id)->update($attrs);

                return (int) $plan->id;
            }

            // Um draft manual existente nao deve ser convertido silenciosamente em envio real.
            $hasPrepared = $plans->contains(static fn (object $row): bool => (string) $row->status === 'prepared');
            if ($hasPrepared) {
                throw ValidationException::withMessages(['flow' => 'Há um follow-up manual já preparado. Revise esse rascunho antes de configurar a automação.']);
            }

            return (int) DB::table('email_campaign_sequences')->insertGetId($attrs + [
                'email_campaign_id' => $root->id,
                'draft_campaign_id' => null,
                'status' => 'active',
                'automatic' => true,
                'sent_rounds' => 0,
                'last_campaign_id' => $root->id,
                'automatic_authorized_at' => now('UTC'),
                'created_by' => $actor->id,
                'created_at' => now('UTC'),
            ]);
        }, 3);
    }

    /**
     * Ativa somente um plano manual existente, escolhido explicitamente por campanha e nome.
     * Nao cria novos destinatarios nem executa o dispatcher.
     */
    public function activateExisting(User $actor, int $rootId, int $planId, int $rounds = 2): int
    {
        abort_unless($actor->isCommercialManager(), 403);
        $row = DB::table('email_campaign_sequences')->where('id', $planId)
            ->where('email_campaign_id', $rootId)->first();
        if ($row === null) {
            throw ValidationException::withMessages(['flow' => 'Plano não encontrado para a campanha.']);
        }

        if ((bool) ($row->automatic ?? false) && in_array((string) $row->status, ['active', 'paused'], true)) {
            return $planId;
        }
        if ((string) $row->status !== 'planned' || (bool) ($row->automatic ?? false)) {
            throw ValidationException::withMessages(['flow' => 'Só é possível automatizar um planejamento ainda não preparado.']);
        }

        return $this->save($actor, $rootId, [
            'name' => (string) $row->name,
            'delay_days' => (int) $row->delay_days,
            'max_rounds' => max(1, min(4, $rounds)),
            'subject' => (string) $row->subject,
            'body' => (string) $row->body,
        ], $planId);
    }

    public function changePause(User $actor, int $rootId, int $planId, bool $paused): void
    {
        abort_unless($actor->isCommercialManager(), 403);
        DB::transaction(function () use ($rootId, $planId, $paused): void {
            $gate = DB::table('email_campaign_dispatch_gates')->where('id', 1)->lockForUpdate()->first();
            if ($gate === null) {
                throw ValidationException::withMessages(['flow' => 'Trava global de envio não encontrada.']);
            }
            $sourceStatus = $paused ? 'active' : 'paused';
            DB::table('email_campaign_sequences')->where('id', $planId)
                ->where('email_campaign_id', $rootId)->where('automatic', true)
                ->where('status', $sourceStatus)
                ->update(['status' => $paused ? 'paused' : 'active', 'updated_at' => now('UTC')]);
        }, 3);
        // Pausar apenas novas rodadas; mensagens ja reservadas seguem politica do worker.
    }
}
