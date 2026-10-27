<?php

use App\Models\User;
use App\Models\EmailCampaignBounce;
use App\Services\Campaigns\CampaignQueueHealth;
use App\Services\Campaigns\CampaignQueueSimulator;
use App\Services\Campaigns\CampaignSendPolicy;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    public int $scenario = 500;

    public string $customScenario = '500';

    public function mount(): void
    {
        $this->ensureManager();
    }

    private function ensureManager(): void
    {
        $user = auth()->user();
        abort_unless($user instanceof User && $user->isCommercialManager(), 403);
    }

    #[Computed]
    public function health(): array
    {
        $this->ensureManager();
        return app(CampaignQueueHealth::class)->snapshot();
    }

    #[Computed]
    public function simulation(): array
    {
        $this->ensureManager();
        return app(CampaignQueueSimulator::class)->report($this->scenario);
    }

    #[Computed]
    public function effective(): array
    {
        $this->ensureManager();
        return app(CampaignSendPolicy::class)->effective();
    }

    // EC_CAMPAIGNS_V7_BOUNCES: somente leitura; nenhum e-mail ou estado é alterado.
    #[Computed]
    public function bounceSummary(): array
    {
        $this->ensureManager();
        return [
            'invalid' => EmailCampaignBounce::query()->where('classification', 'invalid')->count(),
            'temporary' => EmailCampaignBounce::query()->where('classification', 'temporary')->count(),
            'permanent_other' => EmailCampaignBounce::query()->where('classification', 'permanent_other')->count(),
        ];
    }

    #[Computed]
    public function recentBounces(): \Illuminate\Database\Eloquent\Collection
    {
        $this->ensureManager();
        return EmailCampaignBounce::query()->with('campaign')->orderByDesc('id')->limit(15)->get();
    }

    public function selectScenario(int $scenario): void
    {
        $this->ensureManager();
        abort_unless(in_array($scenario, [10, 50, 100, 500], true), 422);
        $this->scenario = $scenario;
        $this->customScenario = (string) $scenario;
        unset($this->simulation);
    }

    /** Simulação local: nunca coloca mensagens na fila nem inicia campanhas. */
    public function simulateCustom(): void
    {
        $this->ensureManager();
        $amount = filter_var($this->customScenario, FILTER_VALIDATE_INT);
        if (! is_int($amount) || $amount < 1 || $amount > 100000) {
            $this->addError('customScenario', 'Informe de 1 a 100.000 contatos para a simulação.');

            return;
        }
        $this->resetValidation('customScenario');
        $this->scenario = $amount;
        unset($this->simulation);
    }

    public function refreshQueue(): void
    {
        $this->ensureManager();
        unset($this->health, $this->simulation, $this->effective, $this->bounceSummary, $this->recentBounces);
    }
};
?>
@include('campaigns.fila-v70')
