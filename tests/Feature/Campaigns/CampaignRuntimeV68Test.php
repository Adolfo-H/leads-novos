<?php

declare(strict_types=1);

use App\Console\Commands\RecoverCampaignRepliesV77;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

// EC_CI_RUNTIME_V68: prevenção de regressões de permissões e colisão de comandos.
uses(RefreshDatabase::class);

it('permite abrir campanhas ao vendedor sem montar o simulador reservado à gestão', function (): void {
    $seller = User::factory()->create(['email_verified_at' => now()]);
    $seller->forceFill(['commercial_role' => User::ROLE_SELLER])->save();

    $this->actingAs($seller)
        ->get(route('campaigns.index'))
        ->assertOk()
        ->assertDontSee('data-ec-queue-lab', false);
});

it('preserva o simulador de fila para gestores', function (): void {
    $manager = User::factory()->create(['email_verified_at' => now()]);
    $manager->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();

    $this->actingAs($manager)
        ->get(route('campaigns.index'))
        ->assertOk()
        ->assertSee('data-ec-queue-lab', false);
});

it('registra o comando de recuperação V77 com limites obrigatórios de janela e lote', function (): void {
    $commands = Artisan::all();
    expect($commands)->toHaveKey('campaigns:recover-replies');
    $command = $commands['campaigns:recover-replies'];
    expect($command)->toBeInstanceOf(RecoverCampaignRepliesV77::class)
        ->and($command->getDefinition()->hasOption('hours'))->toBeTrue()
        ->and($command->getDefinition()->hasOption('limit'))->toBeTrue()
        ->and($command->getDefinition()->hasOption('apply'))->toBeTrue();
});
