<?php

namespace App\Http\Controllers;

use App\Models\EmailCampaign;
use App\Models\User;
use App\Services\Campaigns\CampaignManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Ações explícitas via POST HTTP. Reusa o CampaignManager original e suas
 * travas de status, SMTP, opt-out, locks e prevenção de envios duplicados.
 * Nenhuma ação de GET envia, inicia, pausa ou cancela uma campanha.
 */
final class CampaignOperationsController extends Controller
{
    private function manager(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->isCommercialManager(), 403);

        return $user;
    }

    public function start(Request $request, EmailCampaign $campaign, CampaignManager $manager): RedirectResponse
    {
        $user = $this->manager($request);
        $request->validate(['lawful_basis' => ['required', 'accepted']]);
        // Validações de URL pública HTTPS, SMTP e status seguem no serviço existente.
        $manager->start($user, $campaign, true);

        return redirect()->route('campaigns.index', ['campanha' => $campaign->getKey()])
            ->with('campaigns_operation_v94', 'Campanha aprovada. A fila respeitará os limites configurados; acompanhe o status abaixo.');
    }

    public function cancel(Request $request, EmailCampaign $campaign, CampaignManager $manager): RedirectResponse
    {
        $user = $this->manager($request);
        $manager->cancel($user, $campaign);

        return redirect()->route('campaigns.index', ['campanha' => $campaign->getKey()])
            ->with('campaigns_operation_v94', 'Campanha cancelada. Mensagens já aceitas pelo SMTP não podem ser desfeitas.');
    }

    public function pause(Request $request, EmailCampaign $campaign, CampaignManager $manager): RedirectResponse
    {
        $user = $this->manager($request);
        $manager->pause($user, $campaign);

        return redirect()->route('campaigns.index', ['campanha' => $campaign->getKey()])
            ->with('campaigns_operation_v94', 'Novos envios pausados. Mensagens já iniciadas podem concluir.');
    }

    public function resume(Request $request, EmailCampaign $campaign, CampaignManager $manager): RedirectResponse
    {
        $user = $this->manager($request);
        $manager->resume($user, $campaign);

        return redirect()->route('campaigns.index', ['campanha' => $campaign->getKey()])
            ->with('campaigns_operation_v94', 'Campanha retomada sob os limites do servidor.');
    }
}
