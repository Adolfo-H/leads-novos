<?php

declare(strict_types=1);

it('keeps the Figma V67 visual stylesheet with safe campaign workflow sources', function (): void {
    $appCss = file_get_contents(resource_path('css/app.css'));
    $figma = file_get_contents(resource_path('css/campaigns-figma-v67.css'));
    $index = file_get_contents(resource_path('views/pages/campaigns/⚡index.blade.php'));

    expect($appCss)->not->toBeFalse()
        ->and($figma)->not->toBeFalse()
        ->and($index)->not->toBeFalse()
        ->and(substr_count((string) $appCss, "@import './campaigns-figma-v67.css';"))->toBe(1)
        ->and($figma)->toContain('CAMPANHAS_FIGMA_V67')
        ->and($figma)->toContain('background:#fff!important')
        ->and($index)->toContain('queuePilot')
        ->and($index)->toContain('saveDraft')
        ->and($index)->toContain('saveAndStart')
        ->and($index)->toContain('lawfulBasisConfirmed')
        ->and($index)->toContain('pauseCampaign')
        ->and($index)->toContain('cancelCampaign');
});
