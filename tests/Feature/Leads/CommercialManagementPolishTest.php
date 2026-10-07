<?php

use App\Models\User;
use Livewire\Livewire;

it('contains the responsive commercial management workspace structure', function () {
    $view =
        file_get_contents(
            resource_path(
                'views/pages/leads/⚡management.blade.php'
            )
        );

    expect(
        $view
    )
        ->toContain(
            'ecgm-page-loading'
        )
        ->toContain(
            'wire:loading.delay'
        )
        ->toContain(
            'ecgm-management-table'
        )
        ->toContain(
            'ecgm-empty-state'
        )
        ->toContain(
            'partials.commercial-attention-queue'
        )
        ->toContain(
            'partials.commercial-distribution-preview'
        );
});

it('contains responsive and reduced motion styles for management', function () {
    $css =
        file_get_contents(
            resource_path(
                'css/commercial-management.css'
            )
        );

    expect(
        $css
    )
        ->toContain(
            'COMMERCIAL_MANAGEMENT_POLISH_START'
        )
        ->toContain(
            'ecgm-management-table'
        )
        ->toContain(
            'content:'
        )
        ->toContain(
            'attr(data-label)'
        )
        ->toContain(
            'max-width: 860px'
        )
        ->toContain(
            'max-width: 620px'
        )
        ->toContain(
            'max-width: 390px'
        )
        ->toContain(
            'prefers-reduced-motion: reduce'
        );
});

it('renders the polished management workspace for a manager', function () {
    $manager =
        User::factory()
            ->create([
                'commercial_role' => User::ROLE_MANAGER,

                'email_verified_at' => now(),
            ]);

    Livewire::actingAs(
        $manager
    )
        ->test(
            'pages::leads.management'
        )
        ->assertSee(
            'Gestão Comercial'
        )
        ->assertSee(
            'Atualizando painel comercial'
        )
        ->assertSee(
            'Empresas que precisam de atenção'
        )
        ->assertSee(
            'Nenhuma pendência crítica agora'
        )
        ->assertSee(
            'Nenhuma carteira atribuída'
        )
        ->assertSee(
            'Balancear novos leads'
        );
});
