<?php

use App\Http\Controllers\Access\AccessAdminController;
use App\Mail\AccessInvitation;
use App\Models\AccountAccessRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

function a16Approver(): User
{
    config()->set('access_requests.approver_email', 'approver-a16@example.test');
    $user = User::factory()->create([
        'email' => 'approver-a16@example.test',
        'email_verified_at' => now(),
    ]);
    $user->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();

    return $user;
}

function a16Request(string $suffix, string $status = 'pending'): AccountAccessRequest
{
    return AccountAccessRequest::query()->create([
        'name' => 'Solicitante A16 '.$suffix,
        'email' => 'request-a16-'.$suffix.'@example.test',
        'company' => 'Empresa teste A16',
        'status' => $status,
        'consent_at' => now(),
        'invitation_token_hash' => $status === 'approved' ? hash('sha256', 'original-'.$suffix) : null,
        'invitation_expires_at' => $status === 'approved' ? now()->addDay() : null,
    ]);
}

function a16AdminHttpRequest(User $user): Request
{
    $request = Request::create('/acessos/solicitacoes', 'POST');
    $request->setUserResolver(static fn (): User => $user);

    return $request;
}

beforeEach(function (): void {
    Mail::fake();
});

it('nao aprova registro recusado depois da leitura inicial', function (): void {
    $user = a16Approver();
    $record = a16Request('stale-approve');
    $stale = AccountAccessRequest::query()->findOrFail($record->id);
    AccountAccessRequest::query()->whereKey($record->id)->update(['status' => 'rejected']);

    app(AccessAdminController::class)->approve(a16AdminHttpRequest($user), $stale);

    expect($record->fresh()->status)->toBe('rejected')
        ->and($record->fresh()->invitation_token_hash)->toBeNull();
    Mail::assertNothingSent();
});

it('nao recusa um registro aprovado depois da leitura inicial', function (): void {
    $user = a16Approver();
    $record = a16Request('stale-reject');
    $stale = AccountAccessRequest::query()->findOrFail($record->id);
    $tokenHash = hash('sha256', 'token-a16');
    AccountAccessRequest::query()->whereKey($record->id)->update([
        'status' => 'approved',
        'invitation_token_hash' => $tokenHash,
        'invitation_expires_at' => now()->addDay(),
    ]);

    app(AccessAdminController::class)->reject(a16AdminHttpRequest($user), $stale);

    expect($record->fresh()->status)->toBe('approved')
        ->and($record->fresh()->invitation_token_hash)->toBe($tokenHash);
    Mail::assertNothingSent();
});

it('nao revoga um convite que foi ativado apos o carregamento', function (): void {
    $user = a16Approver();
    $record = a16Request('stale-revoke', 'approved');
    $stale = AccountAccessRequest::query()->findOrFail($record->id);
    AccountAccessRequest::query()->whereKey($record->id)->update([
        'status' => 'activated',
        'activated_at' => now(),
        'invitation_token_hash' => null,
    ]);

    app(AccessAdminController::class)->revoke(a16AdminHttpRequest($user), $stale);

    expect($record->fresh()->status)->toBe('activated')
        ->and($record->fresh()->activated_at)->not->toBeNull();
    Mail::assertNothingSent();
});

it('nao recria convite revogado depois da leitura inicial', function (): void {
    $user = a16Approver();
    $record = a16Request('stale-resend', 'approved');
    $stale = AccountAccessRequest::query()->findOrFail($record->id);
    AccountAccessRequest::query()->whereKey($record->id)->update([
        'status' => 'rejected',
        'invitation_token_hash' => null,
        'invitation_expires_at' => null,
    ]);

    app(AccessAdminController::class)->resend(a16AdminHttpRequest($user), $stale);

    expect($record->fresh()->status)->toBe('rejected')
        ->and($record->fresh()->invitation_token_hash)->toBeNull();
    Mail::assertNothingSent();
});

it('impede que um reenvio antigo substitua o token mais recente', function (): void {
    $user = a16Approver();
    $record = a16Request('stale-token', 'approved');
    $stale = AccountAccessRequest::query()->findOrFail($record->id);

    $this->actingAs($user)->post(route('access.requests.resend', $record))->assertRedirect();
    $newTokenHash = $record->fresh()->invitation_token_hash;
    expect($newTokenHash)->not->toBeNull()
        ->not->toBe($stale->invitation_token_hash);
    Mail::assertSent(AccessInvitation::class, 1);

    app(AccessAdminController::class)->resend(a16AdminHttpRequest($user), $stale);

    expect($record->fresh()->invitation_token_hash)->toBe($newTokenHash);
    Mail::assertSent(AccessInvitation::class, 1);
});

it('aprova uma unica vez e nao gera dois convites em repeticao', function (): void {
    $user = a16Approver();
    $record = a16Request('double-approve');

    $this->actingAs($user)->post(route('access.requests.approve', $record))->assertRedirect();
    $hash = $record->fresh()->invitation_token_hash;
    $this->post(route('access.requests.approve', $record))->assertRedirect();

    expect($record->fresh()->status)->toBe('approved')
        ->and($record->fresh()->invitation_token_hash)->toBe($hash);
    Mail::assertSent(AccessInvitation::class, 1);
});

it('mantem o convite revogado apos tentativa de reenvio', function (): void {
    $user = a16Approver();
    $record = a16Request('revoke-then-resend', 'approved');

    $this->actingAs($user)->post(route('access.requests.revoke', $record))->assertRedirect();
    $this->post(route('access.requests.resend', $record))->assertRedirect();

    expect($record->fresh()->status)->toBe('rejected')
        ->and($record->fresh()->invitation_token_hash)->toBeNull();
    Mail::assertNothingSent();
});
