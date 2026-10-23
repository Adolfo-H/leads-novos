<?php

namespace App\Http\Controllers\Access;

use App\Http\Controllers\Controller;
use App\Mail\AccessInvitation;
use App\Models\AccountAccessRequest;
use App\Models\User;
use App\Services\Access\AccessRequestNotifier;
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
        $status = (string) $request->query('status', 'all');
        if (! in_array($status, ['all', 'pending', 'approved', 'rejected', 'activated'], true)) {
            $status = 'all';
        }
        $records = AccountAccessRequest::query()
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->orderByRaw("CASE WHEN status = 'pending' THEN 0 ELSE 1 END")
            ->latest()->paginate(12)->withQueryString();
        $counts = AccountAccessRequest::query()->selectRaw('status, count(*) as total')
            ->groupBy('status')->pluck('total', 'status');

        return view('access.admin', compact('records', 'status', 'counts'));
    }

    public function approve(Request $request, AccountAccessRequest $accessRequest): RedirectResponse
    {
        $actor = $this->authorizeAdmin($request);
        if ($accessRequest->status !== 'pending') {
            return back()->with('access_error', 'Esta solicitação já recebeu uma decisão.');
        }
        $accessRequest->forceFill([
            'status' => 'approved',
            'reviewed_by' => $actor->id,
            'reviewed_at' => now(),
        ])->save();

        return $this->issueInvitation($accessRequest);
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
        if ($accessRequest->status !== 'pending') {
            return back()->with('access_error', 'Esta solicitação já recebeu uma decisão.');
        }
        $accessRequest->forceFill([
            'status' => 'rejected',
            'reviewed_by' => $actor->id,
            'reviewed_at' => now(),
            'review_note' => trim((string) ($data['review_note'] ?? '')) ?: null,
            'invitation_token_hash' => null,
            'invitation_expires_at' => null,
        ])->save();

        return back()->with('access_success', 'Solicitação recusada. Nenhum usuário foi criado.');
    }

    public function revoke(Request $request, AccountAccessRequest $accessRequest): RedirectResponse
    {
        $actor = $this->authorizeAdmin($request);
        if ($accessRequest->status !== 'approved') {
            return back()->with('access_error', 'Apenas convites aprovados ainda não utilizados podem ser revogados.');
        }
        $accessRequest->forceFill([
            'status' => 'rejected',
            'reviewed_by' => $actor->id,
            'reviewed_at' => now(),
            'review_note' => 'Convite revogado pelo aprovador.',
            'invitation_token_hash' => null,
            'invitation_expires_at' => null,
        ])->save();

        return back()->with('access_success', 'Convite revogado. O link anterior não poderá criar uma conta.');
    }

    public function resend(Request $request, AccountAccessRequest $accessRequest): RedirectResponse
    {
        $this->authorizeAdmin($request);
        if ($accessRequest->status !== 'approved') {
            return back()->with('access_error', 'Somente convites aprovados e não ativados podem ser reenviados.');
        }

        return $this->issueInvitation($accessRequest);
    }

    private function issueInvitation(AccountAccessRequest $record): RedirectResponse
    {
        // Cada reenvio invalida o link antigo. Só o hash fica no banco.
        $rawToken = bin2hex(random_bytes(32));
        $record->forceFill([
            'invitation_token_hash' => hash('sha256', $rawToken),
            'invitation_expires_at' => now()->addHours(max(1, min(168, (int) config('access_requests.invite_hours', 48)))),
            'invitation_status' => 'pending',
        ])->save();
        try {
            Mail::to($record->email)->send(new AccessInvitation(
                $record,
                route('access.invitation.show', ['token' => $rawToken]),
            ));
            $record->forceFill([
                'invitation_status' => 'accepted_by_mailer',
                'invitation_attempted_at' => now(),
            ])->save();

            return back()->with('access_success', 'Convite aprovado e aceito pelo serviço de e-mail. Confira a entrega efetiva na sua configuração SMTP.');
        } catch (Throwable $exception) {
            report($exception);
            $record->forceFill([
                'invitation_status' => 'failed',
                'invitation_attempted_at' => now(),
            ])->save();

            return back()->with('access_error', 'Aprovação salva, mas o envio do convite falhou. Configure o SMTP e use Reenviar convite.');
        }
    }
}
