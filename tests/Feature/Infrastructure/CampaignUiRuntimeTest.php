<?php

it('carrega Livewire explicitamente e mantem Flux nos layouts', function (): void {
    $head = file_get_contents(resource_path('views/partials/head.blade.php'));
    $layout = file_get_contents(resource_path('views/layouts/app/sidebar.blade.php'));
    expect((string) $head)->toContain('@livewireStyles');
    expect((string) $layout)->toContain('@livewireScripts')->toContain('@fluxScripts');
});

it('mostra configuracoes SMTP sem accordion oculto', function (): void {
    $source = file_get_contents(resource_path('views/pages/campaigns/⚡settings.blade.php'));
    expect((string) $source)->toContain('Configuração técnica do SMTP')
        ->toContain('Limites SMTP globais')
        ->not->toContain('<details class="ec90-server-advanced"');
});

it('mantem busca, filtros e caixas de selecao do assistente de campanhas', function (): void {
    $source = file_get_contents(resource_path('views/pages/campaigns/⚡index.blade.php'));
    expect((string) $source)
        ->toContain('wire:model.live.debounce.400ms="audienceSearch"')
        ->toContain('wire:model.live="audienceKeys"')
        ->toContain('wire:click="toggleAudiencePage"')
        ->toContain('wire:click="addAllFiltered"')
        ->toContain('campaign_transfer_expected_v92')
        ->toContain('CampaignContactTransfer');
});

it('mantem selecao dos contatos vinculada ao POST seguro', function (): void {
    $source = file_get_contents(resource_path('views/pages/contacts/⚡index.blade.php'));
    expect((string) $source)
        ->toContain('id="ec-v92-contact-campaign-form"')
        ->toContain('name="campaign_transfer_token"')
        ->toContain('name="campaign_contacts_v92[]"')
        ->toContain('name="campaign_emails_v92[{{ $transferKey }}]"')
        ->toContain('wire:model.live="checkedContactKeys"')
        ->toContain("route('campaigns.contacts-transfer-v92')");
});
