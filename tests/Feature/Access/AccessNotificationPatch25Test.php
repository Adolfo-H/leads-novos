<?php

use App\Mail\AccessRequestSubmitted;
use App\Models\AccountAccessRequest;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;

beforeEach(function (): void {
    config([
        'access_requests.approver_email' => 'adolfo@exportcontrol.com',
        'access_requests.notification_email' => 'adolfo@exportcontrol.com',
        'mail.default' => 'log',
    ]);
    Mail::fake();
});

it('keeps the approved login structure and three scalable svg features', function (): void {
    $this->get(route('login'))->assertOk()->assertSee('Solicitar acesso');
    $layout = file_get_contents(resource_path('views/layouts/auth/simple.blade.php'));
    expect($layout)->toContain('ec24-brand-layer')
        ->toContain('ec24-feature-symbol')
        ->not->toContain('>▥<')
        ->not->toContain('>◎<')
        ->not->toContain('>↗<');
    expect(file_exists(public_path('images/brand/login-background-v25.png')))->toBeTrue();
});

it('records local-only delivery instead of pretending notification reached external mailbox', function (): void {
    $data = [
        'name' => 'Contato Exemplo',
        'email' => 'contato25@example.org',
        'company' => 'Empresa Exemplo',
        'consent' => '1',
        'website' => '',
    ];
    RateLimiter::clear('access-request-email:'.hash('sha256', $data['email']));
    $this->post(route('access.request.store'), $data)->assertRedirect();
    $record = AccountAccessRequest::query()->where('email', $data['email'])->sole();
    expect($record->notification_status)->toBe('local_only')
        ->and($record->status)->toBe('pending');
    Mail::assertSent(AccessRequestSubmitted::class, 1);
});

it('allows only the designated manager to resend notices without approving accounts', function (): void {
    $record = AccountAccessRequest::query()->create([
        'name' => 'Solicitante 25',
        'email' => 'solicitante25@example.org',
        'company' => 'Exemplo de Empresa',
        'status' => 'pending',
        'consent_at' => now(),
    ]);
    $manager = User::factory()->create([
        'email' => 'adolfo@exportcontrol.com',
        'email_verified_at' => now(),
    ]);
    $manager->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();
    RateLimiter::clear('access-notification-resend:'.$record->id);

    $this->actingAs($manager)
        ->post(route('access.requests.resend-notification', $record))
        ->assertRedirect();

    expect($record->fresh()->status)->toBe('pending')
        ->and($record->fresh()->notification_status)->toBe('local_only');
    Mail::assertSent(AccessRequestSubmitted::class, 1);
});

it('never allows another manager to resend a notification', function (): void {
    $record = AccountAccessRequest::query()->create([
        'name' => 'Solicitante 25',
        'email' => 'solicitante-sem-permissao25@example.org',
        'company' => 'Exemplo de Empresa',
        'status' => 'pending',
        'consent_at' => now(),
    ]);
    $otherManager = User::factory()->create(['email' => 'outro-gestor25@example.org', 'email_verified_at' => now()]);
    $otherManager->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();
    $this->actingAs($otherManager)
        ->post(route('access.requests.resend-notification', $record))
        ->assertForbidden();
    Mail::assertNothingSent();
});
