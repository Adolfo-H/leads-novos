<?php

it('keeps the clear filters button at the beginning of the real filters header', function (): void {
    $view = file_get_contents(resource_path('views/pages/leads/⚡index.blade.php'));
    $css = file_get_contents(resource_path('css/app.css'));

    expect($view)->toContain('lf364-filter-header-actions')
        ->toContain('rf-filters-clear lf364-filter-clear')
        ->and($css)->toContain('EC_PROSPECTOR_V49_START')
        ->toContain('.lf364-filter-header-actions')
        ->toContain('order: -1 !important');
});

it('renders document and external link icons in both global and personal HubSpot stages', function (): void {
    $view = file_get_contents(resource_path('views/pages/leads/⚡index.blade.php'));
    $partial = file_get_contents(resource_path('views/partials/leads-global-stage-list-v45.blade.php'));

    expect($view)->toContain('personalHubSpotStageDeals')
        ->toContain("@include('partials.leads-global-stage-list-v45')")
        ->and($partial)->toContain('lf49-icon-action lf49-action-dossier')
        ->toContain('lf49-icon-action lf49-action-hubspot')
        ->toContain('<span>Dossiê</span>')
        ->toContain('<span>HubSpot</span>')
        ->not->toContain('Dossiê ↗</a>')
        ->not->toContain('HubSpot ↗</a>');
});

it('shows fiscal pagination only without either HubSpot-stage filter to avoid double footers', function (): void {
    $view = file_get_contents(resource_path('views/pages/leads/⚡index.blade.php'));
    $partial = file_get_contents(resource_path('views/partials/leads-global-stage-list-v45.blade.php'));
    $tasks = file_get_contents(resource_path('views/partials/leads-due-tasks-v54.blade.php'));

    expect($tasks)->toContain('Paginação das tarefas HubSpot')
        ->toContain("nextPage('dueTaskPage')")
        ->and($view)->toContain("@if (! \$dueActionOnly && \$globalHubSpotStage === '' && \$personalHubSpotStage === '' && \$this->leads->hasPages())")
        ->and($partial)->toContain("nextPage('globalStageDealsPage')")
        ->toContain('Paginação dos negócios desta etapa')
        ->not->toContain('$lf45Deals->links()');
});
