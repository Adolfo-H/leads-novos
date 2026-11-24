<?php

use App\Models\EcProposal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('exibe o responsável de assinatura abreviada na lista e no detalhe sem modificar o histórico', function (): void {
    $manager = User::factory()->create(['email_verified_at' => now()]);
    $manager->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();
    $p = EcProposal::query()->create([
        'public_id' => (string) Str::uuid(), 'origin' => 'imported', 'status' => 'imported',
        'client_name' => 'Empresa exemplo', 'client_contact' => 'Pessoa Cliente',
        'client_email' => 'cliente@example.org', 'sales_responsible' => 'Adolfo Heerdt',
        'service_codes' => ['ACOMPANHAMENTO'], 'recurring_total' => 960,
        'monthly_final' => '636.00', 'created_by' => $manager->id, 'updated_by' => $manager->id,
    ]);
    DB::table('ec_proposal_history_metadata')->insert([
        'proposal_id' => $p->id, 'document_sha256' => str_repeat('c', 64),
        'title' => 'Proposta moderna exemplo', 'document_name' => 'exemplo.pdf',
        'services' => '["ACOMPANHAMENTO"]', 'commercial_status' => 'sent_history',
        'extraction_status' => 'extracted', 'crm_match' => 'company_only',
        'responsible_name' => 'Adolfo Heerdt', 'responsible_email' => 'adolfo@exportcontrol.com.br',
        'recipient_email' => 'cliente@example.org', 'issued_on' => '2026-11-11',
        'monthly_amount' => '636.00', 'one_time_amount' => null,
        'evidence' => json_encode([
            'identity_v3' => ['client_legal_name' => 'EMPRESA EXEMPLO LTDA', 'recipient_name' => 'Pessoa Cliente'],
            'finance_v2' => ['monthly_state' => 'identified', 'base_quantity' => '80', 'annual_quantity' => '960', 'conditions' => ['dependencia' => 'Licenciamento ativo durante toda a vigência.']],
            'responsible_v8' => ['version' => 8, 'signature' => ['signature_name' => 'Adolfo']],
        ], JSON_THROW_ON_ERROR), 'updated_at' => now(),
    ]);
    $this->actingAs($manager)->get(route('proposals.show', $p))->assertOk()
        ->assertSee('Pessoa Cliente')->assertSee('cliente@example.org')->assertSee('Adolfo Heerdt')
        ->assertSee('adolfo@exportcontrol.com.br')->assertSee('960')->assertSee('Documentos em 12 meses')
        ->assertSee('R$ 636,00')->assertSee('11/11/2026')->assertSee('Licenciamento ativo durante toda a vigência.')
        ->assertSee('Sem valor de implantação identificado no documento');
    $this->get(route('proposals.index'))->assertOk()->assertSee('Pessoa Cliente')->assertSee('Adolfo Heerdt')->assertSee('adolfo@exportcontrol.com.br');
    expect($p->refresh()->status)->toBe('imported')->and($p->accepted_at)->toBeNull()->and($p->one_time_final)->toBeNull();
});
