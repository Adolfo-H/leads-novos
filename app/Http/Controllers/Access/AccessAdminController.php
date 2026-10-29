<?php

namespace App\Http\Controllers\Access;

use App\Http\Controllers\Controller;
use App\Mail\AccessInvitation;
use App\Models\AccountAccessRequest;
use App\Models\User;
use App\Services\Access\AccessRequestNotifier;
use App\Support\Access\AccessRequestPageData;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Throwable;

class AccessAdminController extends Controller
{
    public static function isApprover(?User $user): bool
    {
        return $user !== null
            && $user->isCommercialManager()
            && Str::lower(trim($user->email)) === Str::lower((string) config('access_requests.approver_email'));
    }

    private function authorizeAdmin(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User && self::isApprover($actor), 403);

        return $actor;
    }

    public function index(Request $request): View
    {
        $this->authorizeAdmin($request);

        // Antes o controller consultava os dados descartados pela view, que os consultava novamente.
        // Uma única fonte prepara filtros, totais e paginação preservando as ações existentes.
        return view('access.admin', [
            'accessPage' => AccessRequestPageData::load(),
        ]);
    }

    public function approve(Request $request, AccountAccessRequest $accessRequest): RedirectResponse
    {
        $actor = $this->authorizeAdmin($request);
        $rawToken = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $rawToken);

        // Atualizacao condicional atomica: duas decisoes concorrentes nao podem
        // aprovar ou recusar a mesma solicitacao com base em dados antigos.
        $changed = AccountAccessRequest::query()
            ->whereKey($accessRequest->getKey())
            ->where('status', 'pending')
            ->update([
                'status' => 'approved',
                'reviewed_by' => $actor->id,
                'reviewed_at' => now(),
                'invitation_token_hash' => $tokenHash,
                'invitation_expires_at' => now()->addHours(max(1, min(168, (int) config('access_requests.invite_hours', 48)))),
                'invitation_status' => 'pending',
            ]);

        if ($changed !== 1) {
            return back()->with('access_error', 'Esta solicitação já recebeu uma decisão.');
        }

        $record = $accessRequest->fresh();
        if (! $record instanceof AccountAccessRequest) {
            return back()->with('access_error', 'Solicitação indisponível após a aprovação. Confira o painel.');
        }

        // SMTP fora da transacao/atualizacao atomica: nunca manter lock durante rede.
        return $this->issueInvitation($record, $rawToken, $tokenHash);
    }

    /** Reenvia apenas o aviso ao aprovador; não aprova e não cria usuários. */
    public function resendNotification(
        Request $request,
        AccountAccessRequest $accessRequest,
        AccessRequestNotifier $notifier,
    ): RedirectResponse {
        $this->authorizeAdmin($request);

        if ($accessRequest->status !== 'pending') {
            return back()->with('access_error', 'Somente solicitações pendentes podem ter o aviso reenviado.');
        }

        $key = 'access-notification-resend:'.$accessRequest->id;
        if (RateLimiter::tooManyAttempts($key, 2)) {
            return back()->with('access_error', 'Limite de reenvios atingido. Aguarde antes de tentar novamente.');
        }
        RateLimiter::hit($key, 3600);

        $status = $notifier->send($accessRequest);

        if ($status === 'failed') {
            return back()->with('access_error', 'Falha no SMTP. O pedido continua salvo no painel. Consulte o diagnóstico do correio.');
        }

        if ($status === 'local_only') {
            return back()->with('access_error', 'E-mail capturado apenas localmente (Mailpit/log). Configure um SMTP externo e reenvie o aviso.');
        }

        return back()->with('access_success', 'Aviso aceito pelo serviço de e-mail. Confira o recebimento em adolfo@exportcontrol.com.br.');
    }

    public function reject(Request $request, AccountAccessRequest $accessRequest): RedirectResponse
    {
        $actor = $this->authorizeAdmin($request);
        $data = $request->validate(['review_note' => ['nullable', 'string', 'max:500']]);

        $changed = AccountAccessRequest::query()
            ->whereKey($accessRequest->getKey())
            ->where('status', 'pending')
            ->update([
                'status' => 'rejected',
                'reviewed_by' => $actor->id,
                'reviewed_at' => now(),
                'review_note' => trim((string) ($data['review_note'] ?? '')) ?: null,
                'invitation_token_hash' => null,
                'invitation_expires_at' => null,
            ]);

        if ($changed !== 1) {
            return back()->with('access_error', 'Esta solicitação já recebeu uma decisão.');
        }

        return back()->with('access_success', 'Solicitação recusada. Nenhum usuário foi criado.');
    }

    public function revoke(Request $request, AccountAccessRequest $accessRequest): RedirectResponse
    {
        $actor = $this->authorizeAdmin($request);

        // A ativacao ja bloqueia a linha dentro de uma transacao. Este UPDATE
        // disputa a mesma linha e nunca revoga uma conta que acabou de ativar.
        $changed = AccountAccessRequest::query()
            ->whereKey($accessRequest->getKey())
            ->where('status', 'approved')
            ->whereNull('activated_at')
            ->update([
                'status' => 'rejected',
                'reviewed_by' => $actor->id,
                'reviewed_at' => now(),
                'review_note' => 'Convite revogado pelo aprovador.',
                'invitation_token_hash' => null,
                'invitation_expires_at' => null,
            ]);

        if ($changed !== 1) {
            return back()->with('access_error', 'Apenas convites aprovados ainda não utilizados podem ser revogados.');
        }

        return back()->with('access_success', 'Convite revogado. O link anterior não poderá criar uma conta.');
    }

    public function resend(Request $request, AccountAccessRequest $accessRequest): RedirectResponse
    {
        $this->authorizeAdmin($request);
        $rawToken = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $rawToken);
        $priorHash = $accessRequest->invitation_token_hash;

        // Compare-and-swap: um reenvio antigo nao substitui o convite gerado
        // por outro operador, nem reabre um convite revogado ou ativado.
        $query = AccountAccessRequest::query()
            ->whereKey($accessRequest->getKey())
            ->where('status', 'approved')
            ->whereNull('activated_at');

        if (is_string($priorHash)) {
            $query->where('invitation_token_hash', $priorHash);
        } else {
            $query->whereNull('invitation_token_hash');
        }

        $changed = $query->update([
            'invitation_token_hash' => $tokenHash,
            'invitation_expires_at' => now()->addHours(max(1, min(168, (int) config('access_requests.invite_hours', 48)))),
            'invitation_status' => 'pending',
        ]);

        if ($changed !== 1) {
            return back()->with('access_error', 'O convite foi alterado ou utilizado. Atualize o painel antes de reenviar.');
        }

        $record = $accessRequest->fresh();
        if (! $record instanceof AccountAccessRequest) {
            return back()->with('access_error', 'Solicitação indisponível após o reenvio. Confira o painel.');
        }

        return $this->issueInvitation($record, $rawToken, $tokenHash);
    }

    private function issueInvitation(
        AccountAccessRequest $record,
        string $rawToken,
        string $tokenHash,
    ): RedirectResponse {
        // O token ja foi gravado de modo atomico antes de falar com o SMTP.
        // Nunca atualizar o resultado de um convite que foi revogado, ativado
        // ou substituido enquanto o servidor de e-mail respondia.
        try {
            Mail::to($record->email)->send(new AccessInvitation(
                $record,
                route('access.invitation.show', ['token' => $rawToken]),
            ));
            $changed = AccountAccessRequest::query()
                ->whereKey($record->getKey())
                ->where('status', 'approved')
                ->where('invitation_token_hash', $tokenHash)
                ->update([
                    'invitation_status' => 'accepted_by_mailer',
                    'invitation_attempted_at' => now(),
                ]);

            if ($changed !== 1) {
                return back()->with('access_error', 'O convite foi alterado durante o envio. Confira o status atual antes de reenviar.');
            }

            return back()->with('access_success', 'Convite aprovado e aceito pelo serviço de e-mail. Confira a entrega efetiva na sua configuração SMTP.');
        } catch (Throwable $exception) {
            report($exception);
            AccountAccessRequest::query()
                ->whereKey($record->getKey())
                ->where('status', 'approved')
                ->where('invitation_token_hash', $tokenHash)
                ->update([
                    'invitation_status' => 'failed',
                    'invitation_attempted_at' => now(),
                ]);

            return back()->with('access_error', 'Aprovação salva, mas o envio do convite falhou. Configure o SMTP e use Reenviar convite.');
        }
    }
}
