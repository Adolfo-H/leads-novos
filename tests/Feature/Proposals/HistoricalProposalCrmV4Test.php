<?php

use App\Models\EcProposal;
use App\Models\HubSpotCompany;
use App\Models\HubSpotDeal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/** @return array{0:User,1:EcProposal,2:HubSpotCompany,3:HubSpotDeal} */
function historyCrmV4Fixture(): array
{
    $manager = User::factory()->create(['email_verified_at' => now()]);
    $manager->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();
    $p = EcProposal::query()->create(['public_id' => (string) Str::uuid(), 'origin' => 'imported', 'status' => 'imported', 'client_name' => 'Empresa Exemplo', 'service_codes' => ['LICENCIAMENTO'], 'created_by' => $manager->id, 'updated_by' => $manager->id]);
    DB::table('ec_proposal_history_metadata')->insert([
        'proposal_id' => $p->id, 'document_sha256' => str_repeat('a', 64), 'title' => 'Proposta exemplo', 'document_name' => 'exemplo.pdf', 'services' => '["LICENCIAMENTO"]', 'commercial_status' => 'sent_history', 'extraction_status' => 'extracted', 'crm_match' => 'company_only', 'evidence' => '{}', 'updated_at' => now(),
    ]);
    $company = HubSpotCompany::query()->create(['hubspot_id' => '993001', 'name' => 'Empresa Exemplo']);
    $deal = HubSpotDeal::query()->create(['hubspot_id' => '993002', 'name' => 'Empresa Exemplo - Licenciamento', 'stage_label' => 'Negócio fechado']);

    return [$manager, $p, $company, $deal];
}

it('confirma empresa e negócio sem emitir número ou registrar aceite formal', function (): void {
    [$manager, $p, $company, $deal] = historyCrmV4Fixture();
    DB::table('hubspot_company_deal')->insert(['hubspot_company_id' => $company->id, 'hubspot_deal_id' => $deal->id, 'is_primary' => true]);
    $this->actingAs($manager)->post(route('proposals.history-crm.confirm', $p), ['company_external_id' => '993001', 'deal_external_id' => '993002', 'confirm_crm' => '1'])->assertRedirect(route('proposals.show', $p));
    $p->refresh();
    expect((int) $p->hubspot_company_id)->toBe((int) $company->id)->and((int) $p->hubspot_deal_id)->toBe((int) $deal->id)->and($p->status)->toBe('imported')->and($p->accepted_at)->toBeNull()->and($p->proposal_numbers)->toBeNull();
    $this->assertDatabaseHas('ec_proposal_history_metadata', ['proposal_id' => $p->id, 'crm_match' => 'manual_confirmed', 'commercial_status' => 'accepted_crm']);
    $this->assertDatabaseHas('ec_proposal_events', ['proposal_id' => $p->id, 'event' => 'historical_crm_link_confirmed']);
});

it('recusa empresa não associada ao negócio', function (): void {
    [$manager, $p] = historyCrmV4Fixture();
    $this->actingAs($manager)->from(route('proposals.show', $p))->post(route('proposals.history-crm.confirm', $p), ['company_external_id' => '993001', 'deal_external_id' => '993002', 'confirm_crm' => '1'])->assertSessionHasErrors('deal_external_id');
    expect($p->refresh()->hubspot_deal_id)->toBeNull();
});

it('não permite conciliação a visitante', function (): void {
    [, $p] = historyCrmV4Fixture();
    $this->post(route('proposals.history-crm.confirm', $p), ['company_external_id' => '993001', 'deal_external_id' => '993002', 'confirm_crm' => '1'])->assertRedirect(route('login'));
    expect($p->refresh()->hubspot_deal_id)->toBeNull();
});
