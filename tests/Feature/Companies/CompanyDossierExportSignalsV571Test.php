<?php

use App\Models\Company;
use App\Models\User;

it('renders export findings, publication dates and only direct or indirect dimensions in the dossier', function () {
    $user = User::factory()->create([
        'email_verified_at' => now(),
    ]);

    $company = Company::query()->create([
        'cnpj_root' => '11223344',
        'corporate_name' => 'Empresa Sinais de Exportação',
    ]);

    $company->exportIntelligence()->create([
        'direct_status' => 'uncertain',
        'direct_confidence' => 50,
        'direct_confirmed' => false,
        'indirect_status' => 'uncertain',
        'indirect_confidence' => 49,
        'indirect_confirmed' => false,
        'trading_status' => 'yes',
        'trading_confidence' => 51,
        'trading_confirmed' => false,
        'research_status' => 'completed',
        'research_provider' => 'fake-web',
        'overall_summary' => 'A empresa cita soja, milho e comercialização via trading para Ásia.',
        'researched_at' => now(),
        'research_completed_at' => now(),
        'metadata' => [],
    ]);

    $company->exportEvidence()->create([
        'fingerprint' => hash('sha256', 'company-dossier-v57-1'),
        'dimension' => 'indirect',
        'signal' => 'positive',
        'source_type' => 'website',
        'source_name' => 'portal.exportacao.teste',
        'source_url' => 'https://example.com/exportacao',
        'title' => 'Trading atende exportação de soja para Ásia',
        'evidence_text' => 'Operação com soja e milho destinada ao mercado asiático.',
        'confidence' => 78,
        'is_confirmed' => false,
        'metadata' => [
            'published_at' => '2026-10-21',
        ],
    ]);

    $this
        ->actingAs($user)
        ->get(route('companies.show', $company))
        ->assertSuccessful()
        ->assertSee('Produtos citados')
        ->assertSee('soja')
        ->assertSee('Ásia')
        ->assertSee('21/10/2026')
        ->assertSee('Direta')
        ->assertSee('Indireta')
        ->assertDontSee('<td><strong>Trading</strong></td>', false);
});
