<?php

use App\Mail\AccessInvitation;
use App\Mail\AccessRequestSubmitted;
use App\Models\AccountAccessRequest;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;

function accessPackage24Approver(): User
{
    $user = User::factory()->create([
        'email' => 'adolfo@exportcontrol.com',
        'email_verified_at' => now(),
    ]);
    $user->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();

    return $user;
}

function accessPackage24Request(string $email = 'candidato24@example.org'): array
{
    return [
        'name' => 'Pessoa Solicitante',
        'email' => $email,
        'company' => 'Empresa de Teste',
        'phone' => '11912345678',
        'job_title' => 'Analista Comercial',
        'consent' => 1,
        'website' => '',
    ];
}

beforeEach(function (): void {
    config([
        'access_requests.approver_email' => 'adolfo@exportcontrol.com',
        'access_requests.notification_email' => 'adolfo@exportcontrol.com',
        'access_requests.invite_hours' => 48,
    ]);
    Mail::fake();
    RateLimiter::clear('access-request-email:'.hash('sha256', 'candidato24@example.org'));
});

it('shows login and public access request without enabling fortify public registration', function (): void {
    $this->get('/login')->assertOk()
        ->assertSee('Solicitar acesso')
        ->assertSee('Esqueci minha senha');
    $this->get('/acesso/solicitar')->assertOk()->assertSee('PRIMEIRO ACESSO');
    $this->get('/register')->assertNotFound();
});

it('stores requests and notifies only the configured mailbox without creating users', function (): void {
    $this->post('/acesso/solicitar', accessPackage24Request())->assertRedirect();
    $record = AccountAccessRequest::query()->sole();
    expect($record->status)->toBe('pending')
        ->and($record->email)->toBe('candidato24@example.org')
        ->and(User::query()->where('email', $record->email)->exists())->toBeFalse();
    Mail::assertSent(AccessRequestSubmitted::class, function (AccessRequestSubmitted $mail): bool {
        return $mail->hasTo('adolfo@exportcontrol.com');
    });
    Mail::assertSent(AccessRequestSubmitted::class, 1);

    $this->post('/acesso/solicitar', accessPackage24Request())->assertRedirect();
    expect(AccountAccessRequest::query()->count())->toBe(1);
    Mail::assertSent(AccessRequestSubmitted::class, 1);
});

it('rejects incomplete access requests and spambots', function (): void {
    $this->post('/acesso/solicitar', [
        ...accessPackage24Request(),
        'name' => '',
        'consent' => 0,
    ])->assertSessionHasErrors(['name', 'consent']);

    $this->post('/acesso/solicitar', [
        ...accessPackage24Request(),
        'website' => 'https://spam.example',
    ])->assertSessionHasErrors(['website']);
    expect(AccountAccessRequest::query()->count())->toBe(0);
    Mail::assertNothingSent();
});

it('blocks other managers sellers and guests from the approval dashboard', function (): void {
    $this->get('/acessos/solicitacoes')->assertRedirect('/login');
    $manager = User::factory()->create(['email' => 'outro-gestor@example.org']);
    $manager->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();
    $this->actingAs($manager)->get('/acessos/solicitacoes')->assertForbidden();

    $seller = User::factory()->create(['email' => 'adolfo@exportcontrol.com']);
    $seller->forceFill(['commercial_role' => User::ROLE_SELLER])->save();
    $this->actingAs($seller)->get('/acessos/solicitacoes')->assertForbidden();
});

it('approves a request sends a unique invitation and creates only a seller on activation', function (): void {
    $this->post('/acesso/solicitar', accessPackage24Request())->assertRedirect();
    $record = AccountAccessRequest::query()->sole();
    $admin = accessPackage24Approver();

    $this->actingAs($admin)->get('/acessos/solicitacoes')->assertOk()->assertSee('Pessoa Solicitante');
    $this->actingAs($admin)->post(route('access.requests.approve', $record))->assertRedirect();
    $record->refresh();
    expect($record->status)->toBe('approved')
        ->and($record->invitation_token_hash)->not->toBeNull();
    Mail::assertSent(AccessInvitation::class, 1);
    $invitation = Mail::sent(AccessInvitation::class)->first();
    $token = basename(parse_url($invitation->invitationUrl, PHP_URL_PATH));
    expect($token)->toMatch('/^[a-f0-9]{64}$/')
        ->and($record->invitation_token_hash)->toBe(hash('sha256', $token));

    auth()->logout();
    $this->get(route('access.invitation.show', ['token' => $token]))->assertOk()->assertSee('Ative sua conta');
    $this->post(route('access.invitation.activate', ['token' => $token]), [
        'password' => 'UmaSenhaSuperSegura!2026',
        'password_confirmation' => 'UmaSenhaSuperSegura!2026',
    ])->assertRedirect(route('login'));

    $user = User::query()->where('email', 'candidato24@example.org')->sole();
    expect($user->isCommercialSeller())->toBeTrue()
        ->and($user->isCommercialManager())->toBeFalse()
        ->and($user->hasVerifiedEmail())->toBeTrue()
        ->and($record->fresh()->status)->toBe('activated');

    $this->post(route('access.invitation.activate', ['token' => $token]), [
        'password' => 'UmaSenhaSuperSegura!2026',
        'password_confirmation' => 'UmaSenhaSuperSegura!2026',
    ])->assertSessionHasErrors('invitation');
    expect(User::query()->where('email', 'candidato24@example.org')->count())->toBe(1);
});

it('lets approver reject without creating an account', function (): void {
    $this->post('/acesso/solicitar', accessPackage24Request())->assertRedirect();
    $record = AccountAccessRequest::query()->sole();
    $admin = accessPackage24Approver();
    $this->actingAs($admin)->post(route('access.requests.reject', $record), [
        'review_note' => 'Fora do escopo autorizado',
    ])->assertRedirect();
    expect($record->fresh()->status)->toBe('rejected')
        ->and(User::query()->where('email', $record->email)->exists())->toBeFalse();
    Mail::assertNotSent(AccessInvitation::class);
});

it('does not create a second user through a preexisting account email', function (): void {
    User::factory()->create(['email' => 'candidato24@example.org']);
    $this->post('/acesso/solicitar', accessPackage24Request())->assertRedirect();
    expect(AccountAccessRequest::query()->count())->toBe(0);
    Mail::assertNotSent(AccessRequestSubmitted::class);
});

it('does not allow expired invitations to create users', function (): void {
    $this->post('/acesso/solicitar', accessPackage24Request())->assertRedirect();
    $record = AccountAccessRequest::query()->sole();
    $this->actingAs(accessPackage24Approver())
        ->post(route('access.requests.approve', $record))->assertRedirect();
    $invitation = Mail::sent(AccessInvitation::class)->first();
    $token = basename(parse_url($invitation->invitationUrl, PHP_URL_PATH));
    $record->forceFill(['invitation_expires_at' => now()->subMinute()])->save();
    auth()->logout();
    $this->get(route('access.invitation.show', ['token' => $token]))
        ->assertOk()->assertSee('Este link não está disponível');
    $this->post(route('access.invitation.activate', ['token' => $token]), [
        'password' => 'UmaSenhaSuperSegura!2026',
        'password_confirmation' => 'UmaSenhaSuperSegura!2026',
    ])->assertSessionHasErrors('invitation');
    expect(User::query()->where('email', 'candidato24@example.org')->exists())->toBeFalse();
});

it('allows the approver to revoke an unused invitation', function (): void {
    $this->post('/acesso/solicitar', accessPackage24Request())->assertRedirect();
    $record = AccountAccessRequest::query()->sole();
    $this->actingAs(accessPackage24Approver())->post(route('access.requests.approve', $record))->assertRedirect();
    $invitation = Mail::sent(AccessInvitation::class)->first();
    $token = basename(parse_url($invitation->invitationUrl, PHP_URL_PATH));
    $this->post(route('access.requests.revoke', $record))->assertRedirect();
    expect($record->fresh()->status)->toBe('rejected')
        ->and($record->fresh()->invitation_token_hash)->toBeNull();
    auth()->logout();
    $this->post(route('access.invitation.activate', ['token' => $token]), [
        'password' => 'UmaSenhaSuperSegura!2026',
        'password_confirmation' => 'UmaSenhaSuperSegura!2026',
    ])->assertSessionHasErrors('invitation');
    expect(User::query()->where('email', 'candidato24@example.org')->exists())->toBeFalse();
});
