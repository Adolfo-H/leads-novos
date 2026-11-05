<?php

namespace App\Livewire;

use App\Models\EmailCampaign;
use App\Models\User;
use App\Services\Campaigns\CampaignFollowupScheduleViewV4;
use App\Services\Campaigns\CampaignFollowupSettingsV5;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Throwable;

/** Painel reativo por campanha. As edicoes salvam configuracao, NUNCA enviam SMTP. */
class CampaignFollowupEditor extends Component
{
    public int $campaignId;

    public ?int $planId = null;

    public string $name = '';

    public int $delayDays = 5;

    public int $maxRounds = 2;

    public string $subject = '';

    public string $body = '';

    public string $feedback = '';

    public bool $saved = false;

    public function boot(): void
    {
        $user = auth()->user();
        abort_unless($user instanceof User && ($user->isCommercialManager() || $user->isCommercialSeller()), 403);
    }

    public function mount(int $campaignId): void
    {
        $campaign = EmailCampaign::query()->findOrFail($campaignId);
        $this->campaignId = (int) ($campaign->followup_root_campaign_id ?: $campaign->id);
        $this->reloadFields();
    }

    public function canEdit(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->isCommercialManager();
    }

    public function reloadFields(): void
    {
        $root = EmailCampaign::query()->findOrFail($this->campaignId);
        $plans = DB::table('email_campaign_sequences')
            ->where('email_campaign_id', $root->id)->orderByDesc('id')->limit(30)->get();
        $plan = $plans->first(static fn (object $row): bool => (bool) ($row->automatic ?? false)
            && in_array((string) $row->status, ['active', 'paused'], true))
            ?? $plans->first(static fn (object $row): bool => (string) $row->status === 'planned')
            ?? $plans->first();

        $this->planId = $plan !== null ? (int) $plan->id : null;
        $this->name = $plan !== null ? (string) $plan->name : 'Retomada comercial';
        $this->delayDays = $plan !== null ? (int) $plan->delay_days : 5;
        $this->maxRounds = $plan !== null && (bool) ($plan->automatic ?? false)
            ? max(1, (int) $plan->max_rounds) : 2;
        $this->subject = $plan !== null ? (string) $plan->subject : 'RE: '.(string) $root->subject;
        $this->body = $plan !== null ? (string) $plan->body : '';
    }

    /** @return array<string, mixed>|null */
    public function schedule(): ?array
    {
        $root = EmailCampaign::query()->findOrFail($this->campaignId);

        return app(CampaignFollowupScheduleViewV4::class)->forCampaign($root);
    }

    public function saveChanges(CampaignFollowupSettingsV5 $settings): void
    {
        $actor = auth()->user();
        if (! $actor instanceof User || ! $actor->isCommercialManager()) {
            abort(403);
        }
        $this->feedback = '';
        $this->saved = false;
        try {
            $this->validate([
                'name' => ['required', 'string', 'min:3', 'max:150'],
                'delayDays' => ['required', 'integer', 'min:1', 'max:90'],
                'maxRounds' => ['required', 'integer', 'min:1', 'max:4'],
                'subject' => ['required', 'string', 'min:3', 'max:180'],
                'body' => ['required', 'string', 'min:10', 'max:12000'],
            ]);
            $this->planId = $settings->save($actor, $this->campaignId, [
                'name' => $this->name,
                'delay_days' => $this->delayDays,
                'max_rounds' => $this->maxRounds,
                'subject' => $this->subject,
                'body' => $this->body,
            ]);
            $this->saved = true;
            $this->feedback = 'Configuração salva. O sistema enviará no prazo se todas as condições estiverem atendidas.';
        } catch (ValidationException $e) {
            $this->setErrorBag($e->errors());
            $this->feedback = 'Confira os campos indicados. A programação anterior foi preservada.';
        } catch (Throwable $e) {
            report($e);
            $this->feedback = 'Não foi possível salvar. A programação anterior foi preservada.';
        }
    }

    public function togglePause(CampaignFollowupSettingsV5 $settings): void
    {
        $actor = auth()->user();
        if (! $actor instanceof User || ! $actor->isCommercialManager()) {
            abort(403);
        }
        $info = $this->schedule();
        if ($info === null || $this->planId === null || ! (bool) $info['automatic']) {
            return;
        }
        $paused = $info['status'] === 'active';
        $settings->changePause($actor, $this->campaignId, $this->planId, $paused);
        $this->feedback = $paused ? 'Próximas rodadas pausadas.' : 'Próximas rodadas retomadas.';
    }

    public function render(): View
    {
        return view('livewire.campaign-followup-editor', [
            'schedule' => $this->schedule(),
            'root' => EmailCampaign::query()->findOrFail($this->campaignId),
            'canEdit' => $this->canEdit(),
        ]);
    }
}
