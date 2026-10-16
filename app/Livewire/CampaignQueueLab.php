<?php

namespace App\Livewire;

use App\Models\User;
use App\Services\Campaigns\CampaignQueueSimulator;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Component;

/** Laboratório somente de leitura, disponível apenas para gestor autenticado. */
class CampaignQueueLab extends Component
{
    public int $recipients = 50;

    public function boot(): void
    {
        $user = auth()->user();
        abort_unless($user instanceof User && $user->isCommercialManager(), 403);
    }

    public function updatedRecipients(): void
    {
        if (! in_array($this->recipients, [10, 50, 100, 500], true)) {
            $this->recipients = 50;
        }
        unset($this->scenario);
    }

    public function refresh(): void
    {
        unset($this->scenario);
    }

    /** @return array<string, mixed> */
    #[Computed]
    public function scenario(): array
    {
        return app(CampaignQueueSimulator::class)->report($this->recipients);
    }

    public function render(): View
    {
        return view('livewire.campaign-queue-lab');
    }
}
