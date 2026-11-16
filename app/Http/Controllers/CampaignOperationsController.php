<?php

namespace App\Http\Controllers;

use App\Models\EmailCampaign;
use App\Models\User;
use App\Services\Campaigns\CampaignManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\ValidationException;
use Throwable;

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

    /**
     * Teste HTTP exclusivo para a caixa autorizada. Nunca usa os destinatários da campanha.
     * Funciona sem JavaScript e não depende do worker de campanhas.
     */
    public function test(Request $request, EmailCampaign $campaign, CampaignManager $manager): RedirectResponse
    {
        $user = $this->manager($request);
        $tab = (string) $request->input('aba', 'recipients');
        if (! in_array($tab, ['overview', 'recipients', 'bounces', 'message', 'replies'], true)) {
            $tab = 'recipients';
        }

        $back = route('campaigns.index', [
            'campanha' => $campaign->getKey(),
            'aba' => $tab,
        ], false).'#email-teste';

        // Evita falso sucesso com transporte de simulação/Mailpit.
        // A confirmação real depende do servidor SMTP configurado.
        $mailer = (string) config('campaign_mail.mailer', 'smtp');
        $transport = (string) config('mail.mailers.'.$mailer.'.transport', '');
        $host = strtolower(trim((string) config('mail.mailers.'.$mailer.'.host', '')));

        if (in_array($transport, ['log', 'array'], true) || in_array($mailer, ['log', 'array'], true)) {
            return redirect()->to($back)->with('campaigns_test_error',
                'O transporte de e-mail está em modo de simulação. Configure um SMTP real para receber o teste.');
        }

        if (in_array($host, ['mailpit', 'mailhog'], true)) {
            return redirect()->to($back)->with('campaigns_test_error',
                'O SMTP está apontando para Mailpit/Mailhog. Essa caixa de testes não entrega mensagens ao seu e-mail externo.');
        }

        try {
            $manager->sendTest($user, (string) $campaign->subject, (string) $campaign->body);

            return redirect()->to($back)->with('campaigns_test_ok',
                'Teste aceito pelo servidor SMTP para '.config('campaign_mail.test_to').'. Confira a caixa de entrada e o spam. A aceitação não comprova entrega final.');
        } catch (ValidationException $exception) {
            $errors = $exception->errors();
            $message = collect($errors)->flatten()->first() ?: 'O teste foi bloqueado pelas regras de envio.';

            return redirect()->to($back)->with('campaigns_test_error', $message);
        } catch (Throwable $exception) {
            report($exception);

            return redirect()->to($back)->with('campaigns_test_error',
                'O SMTP não confirmou o teste. Confira MAIL_HOST, MAIL_PORT, credenciais e logs. Resultado incerto: não clique repetidamente.');
        }
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
