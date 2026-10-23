<?php

namespace App\Http\Controllers\Access;

use App\Http\Controllers\Controller;
use App\Models\AccountAccessRequest;
use App\Models\User;
use App\Services\Access\AccessRequestNotifier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\View\View;

class AccessRequestController extends Controller
{
    public function create(): View
    {
        return view('access.request');
    }

    public function store(Request $request, AccessRequestNotifier $notifier): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:160'],
            'email' => ['required', 'string', 'email:rfc', 'max:254'],
            'company' => ['required', 'string', 'min:2', 'max:180'],
            'phone' => ['nullable', 'string', 'max:40'],
            'job_title' => ['nullable', 'string', 'max:120'],
            'consent' => ['accepted'],
            'website' => ['nullable', 'string', 'max:0'],
        ]);

        $email = Str::lower(trim($data['email']));
        $limitKey = 'access-request-email:'.hash('sha256', $email);
        if (RateLimiter::tooManyAttempts($limitKey, 3)) {
            return back()->with('access_message', 'Solicitação recebida. Se necessário, aguarde a análise do responsável.');
        }
        RateLimiter::hit($limitKey, 3600);

        // Não vaza se o e-mail já possui conta ou solicitação.
        if (User::query()->whereRaw('LOWER(email) = ?', [$email])->exists()) {
            return back()->with('access_message', 'Solicitação recebida. Se necessário, aguarde a análise do responsável.');
        }

        $new = false;
        $record = AccountAccessRequest::query()->firstOrCreate(
            ['email' => $email],
            [
                'name' => trim($data['name']),
                'company' => trim($data['company']),
                'phone' => trim((string) ($data['phone'] ?? '')) ?: null,
                'job_title' => trim((string) ($data['job_title'] ?? '')) ?: null,
                'status' => 'pending',
                'consent_at' => now(),
            ],
        );
        $new = $record->wasRecentlyCreated;

        if ($new) {
            // Solicitação já gravada; entrega externa depende do mailer configurado.
            $notifier->send($record);
        }

        return back()->with('access_message', 'Solicitação recebida. Se necessário, aguarde a análise do responsável.');
    }
}
