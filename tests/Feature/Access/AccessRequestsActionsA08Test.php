<?php

use App\Models\AccountAccessRequest;
use App\Models\User;
use App\Support\Access\AccessRequestPageData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

function a08Approver(): User
{
    config()->set('access_requests.approver_email', 'gestor-a08@example.test');
    $user = User::factory()->create([
        'email' => 'gestor-a08@example.test',
        'email_verified_at' => now(),
    ]);
    $user->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();

    return $user;
}

function a08Request(string $suffix, string $status = 'pending', bool $activated = false): AccountAccessRequest
{
    return AccountAccessRequest::query()->create([
        'name' => 'Solicitante A08 '.$suffix,
        'email' => 'solicitante-a08-'.$suffix.'@example.test',
        'company' => 'Empresa A08',
        'status' => $status,
        'consent_at' => now(),
        'activated_at' => $activated ? now() : null,
        'invitation_token_hash' => $status === 'approved' ? hash('sha256', 'a08-'.$suffix) : null,
        'invitation_expires_at' => $status === 'approved' ? now()->addDay() : null,
    ]);
}

it('resolve cada acao por sua rota nomeada e sem confundir os reenvios', function (): void {
    $request = a08Request('rotas');
    $expected = [
        'approve' => 'access.requests.approve',
        'reject' => 'access.requests.reject',
        'resend-invitation' => 'access.requests.resend',
        'resend-notification' => 'access.requests.resend-notification',
        'revoke' => 'access.requests.revoke',
    ];
    foreach ($expected as $type => $routeName) {
        expect(AccessRequestPageData::action($request, $type))
            ->toBe(['url' => route($routeName, $request), 'method' => 'POST']);
    }
    expect(AccessRequestPageData::action($request, 'inexistente'))->toBeNull();
});

it('oferece somente as acoes compativeis com a situacao atual', function (): void {
    $pending = a08Request('pendente');
    $approved = a08Request('aprovado', 'approved');
    $activated = a08Request('ativado', 'approved', true);
    $rejected = a08Request('recusado', 'rejected');
    $legacy = a08Request('legado', 'invited');

    expect(array_keys(AccessRequestPageData::availableActions($pending)))
        ->toBe(['approve', 'reject', 'resend-notification'])
        ->and(array_keys(AccessRequestPageData::availableActions($approved)))
        ->toBe(['resend-invitation', 'revoke'])
        ->and(AccessRequestPageData::availableActions($activated))->toBe([])
        ->and(AccessRequestPageData::availableActions($rejected))->toBe([])
        ->and(array_keys(AccessRequestPageData::availableActions($legacy)))
        ->toBe(['resend-invitation']);
});

it('mostra revogacao apenas para convite aprovado ainda nao ativado', function (): void {
    $approver = a08Approver();
    $approved = a08Request('aprovado', 'approved');
    $activated = a08Request('ativado', 'approved', true);
    a08Request('pendente');
    Mail::fake();

    $response = $this->actingAs($approver)->get(route('access.requests.index'));
    $response->assertOk()->assertSee('Revogar convite');
    $html = (string) $response->getContent();
    expect(substr_count($html, 'action="'.route('access.requests.revoke', $approved).'"'))
        ->toBe(1)
        ->and($html)->not->toContain('action="'.route('access.requests.revoke', $activated).'"')
        ->and($html)->toContain('data-ec-access-confirm="revoke"');
    Mail::assertNothingSent();
});

it('revoga convite usando o endpoint existente e continua bloqueando usuarios nao autorizados', function (): void {
    $approver = a08Approver();
    $record = a08Request('para-revogar', 'approved');
    $outsider = User::factory()->create(['email_verified_at' => now()]);
    $outsider->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();
    Mail::fake();

    $this->actingAs($outsider)->post(route('access.requests.revoke', $record))->assertForbidden();
    expect($record->fresh()->status)->toBe('approved');
    $this->actingAs($approver)->post(route('access.requests.revoke', $record))->assertRedirect();
    expect($record->fresh()->status)->toBe('rejected')
        ->and($record->fresh()->invitation_token_hash)->toBeNull()
        ->and($record->fresh()->invitation_expires_at)->toBeNull();
    Mail::assertNothingSent();
});

it('mantem a confirmacao de revogacao no script carregado pela pagina', function (): void {
    $js = file_get_contents(public_path('assets/ec-access-modal-v62.js'));
    expect($js)->toContain("['approve', 'reject', 'revoke']")
        ->toContain('Revogar convite?');
    $view = file_get_contents(resource_path('views/access/partials/requests-v60.blade.php'));
    expect($view)->toContain('ec-access-modal-v62.js')
        ->toContain('v=62.1');
});
