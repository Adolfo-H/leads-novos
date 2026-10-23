<?php

namespace App\Http\Controllers\Access;

use App\Http\Controllers\Controller;
use App\Models\AccountAccessRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class AccessInvitationController extends Controller
{
    private function findValid(string $token): ?AccountAccessRequest
    {
        if (! preg_match('/^[a-f0-9]{64}$/D', $token)) {
            return null;
        }

        return AccountAccessRequest::query()
            ->where('invitation_token_hash', hash('sha256', $token))
            ->where('status', 'approved')
            ->where('invitation_expires_at', '>', now())
            ->first();
    }

    public function show(string $token): View
    {
        return view('access.invitation', ['record' => $this->findValid($token), 'token' => $token]);
    }

    public function activate(Request $request, string $token): RedirectResponse
    {
        $data = $request->validate([
            'password' => ['required', 'string', Password::defaults(), 'confirmed'],
        ]);
        if (! preg_match('/^[a-f0-9]{64}$/D', $token)) {
            return back()->withErrors(['invitation' => 'Este convite não é válido.']);
        }
        $created = DB::transaction(function () use ($token, $data): bool {
            $record = AccountAccessRequest::query()
                ->where('invitation_token_hash', hash('sha256', $token))
                ->lockForUpdate()->first();
            if ($record === null || $record->status !== 'approved'
                || $record->invitation_expires_at === null
                || $record->invitation_expires_at->isPast()) {
                return false;
            }
            if (User::query()->whereRaw('LOWER(email) = ?', [$record->email])->exists()) {
                return false;
            }
            $user = User::query()->create([
                'name' => $record->name,
                'email' => $record->email,
                'password' => $data['password'],
            ]);
            $user->forceFill([
                'commercial_role' => User::ROLE_SELLER,
                // O token de uso único entregue ao e-mail comprova o domínio da caixa.
                'email_verified_at' => now(),
            ])->save();
            $record->forceFill([
                'status' => 'activated',
                'activated_at' => now(),
                'invitation_token_hash' => null,
                'invitation_expires_at' => null,
            ])->save();

            return true;
        }, 3);
        if (! $created) {
            return back()->withErrors(['invitation' => 'Convite inválido, expirado ou já utilizado. Solicite outro convite.']);
        }

        // Não autentica automaticamente; o usuário entra pelo Fortify e, se habilitado, 2FA.
        return redirect()->route('login')->with('access_activated', 'Conta ativada. Agora você pode entrar com seu e-mail e sua senha.');
    }
}
