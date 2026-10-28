<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('carrega uma folha CSS de acesso e preserva os dois scripts de interacao', function (): void {
    config()->set('access_requests.approver_email', 'approver-a09@example.test');
    $admin = User::factory()->create([
        'email' => 'approver-a09@example.test',
        'email_verified_at' => now(),
    ]);
    $admin->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();

    $response = $this->actingAs($admin)->get(route('access.requests.index'));
    $response->assertOk()
        ->assertSee('ec-access-panel-a09.css')
        ->assertSee('ec-access-shell-v61.js')
        ->assertSee('ec-access-modal-v62.js')
        ->assertDontSee('ec-access-requests-v60.css')
        ->assertDontSee('ec-access-shell-v61.css')
        ->assertDontSee('ec-access-modal-v62.css');

    $html = $response->getContent();
    expect(substr_count((string) $html, 'ec-access-panel-a09.css'))->toBe(1);
});

it('consolida as tres folhas sem carregar CSS legado e respeita o tema Flux', function (): void {
    $css = file_get_contents(public_path('assets/ec-access-panel-a09.css'));
    expect($css)->not->toBeFalse()
        ->toContain('.ec-access-v60__table-card')
        ->toContain('.ec-access-v60__action-popover')
        ->toContain('.ec-access-v62-modal::backdrop')
        ->toContain('body:has(#ec-access-v60)')
        ->toContain('html.dark .ec-access-v60')
        ->toContain('html.dark .ec-access-v62-modal')
        ->toContain('var(--v64-page)')
        ->toContain('var(--ec-topbar)')
        ->not->toContain('@import');
    foreach ([
        'ec-access-requests-v60.css',
        'ec-access-shell-v61.css',
        'ec-access-modal-v62.css',
    ] as $legacy) {
        expect(file_exists(public_path('assets/'.$legacy)))->toBeFalse();
    }
    $view = file_get_contents(resource_path('views/access/partials/requests-v60.blade.php'));
    expect($view)->toContain('data-ec-access-confirm="revoke"')
        ->toContain('ec-access-shell-v61.js')
        ->toContain('ec-access-modal-v62.js');
});
