<?php

use App\Models\EcProposal;
use App\Models\User;
use App\Services\Proposals\ProposalPricing;
use App\Services\Proposals\ProposalWorkflow;
use Database\Seeders\EcProposalCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(EcProposalCatalogSeeder::class);
    Mail::fake();
    Queue::fake();
});

function ecpV1Manager(): User
{
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();

    return $user;
}

it('navega nas paginas, lista cinco solucoes e nao envia nada ao abrir', function (): void {
    $actor = ecpV1Manager();
    $this->actingAs($actor)->get(route('proposals.index'))->assertOk()->assertSee('Propostas Comerciais');
    $this->actingAs($actor)->get(route('proposals.create'))->assertOk()->assertSee('Nova proposta');
    $this->actingAs($actor)->get(route('proposals.templates'))->assertOk()->assertSee('NFS-e');
    $this->actingAs($actor)->get(route('proposals.pricing'))->assertOk()->assertSee('Numeração global');
    $this->actingAs($actor)->get(route('proposals.import.page'))->assertOk()->assertSee('PDF original');
    Mail::assertNothingSent();
    Queue::assertNothingPushed();
});

it('reproduz o calculo oficial de licenciamento com reajuste 6,5 por cento', function (): void {
    $quote = app(ProposalPricing::class)->quote([
        'service_codes' => ['LICENCIAMENTO'],
        'price_date' => '2026-11-23',
        'recurring_total' => 3265,
        'license_status' => 'NAO_ATIVO',
    ]);
    expect($quote['monthly_average'])->toBe(272)
        ->and($quote['monthly_final'])->toBe('617.65')
        ->and($quote['one_time_final'])->toBe('2470.58')
        ->and($quote['adjustment_audit']['reajustes_aplicados'])->toHaveCount(1);
});

it('cria rascunho sem consumir numero global nem enviar emails', function (): void {
    $actor = ecpV1Manager();
    $before = (int) DB::table('ec_proposal_numbering')->where('id', 1)->value('next_number');
    $response = $this->actingAs($actor)->post(route('proposals.store'), [
        'client_name' => 'Cliente demonstrativo',
        'service_codes' => ['LICENCIAMENTO'],
        'price_date' => '2026-11-23',
        'license_status' => 'NAO_ATIVO',
        'validity_days' => 30,
        'payment_days' => 10,
    ]);
    $response->assertRedirect();
    expect(EcProposal::query()->count())->toBe(1)
        ->and(EcProposal::query()->firstOrFail()->status)->toBe('draft')
        ->and(DB::table('ec_proposal_number_ledger')->count())->toBe(0)
        ->and((int) DB::table('ec_proposal_numbering')->where('id', 1)->value('next_number'))->toBe($before);
    Mail::assertNothingSent();
    Queue::assertNothingPushed();
});

it('nao deixa vendedor mudar precos nem conciliar numeracao', function (): void {
    $seller = User::factory()->create(['email_verified_at' => now()]);
    $seller->forceFill(['commercial_role' => User::ROLE_SELLER])->save();
    $this->actingAs($seller)->post(route('proposals.numbering'), [
        'last_number' => 1900, 'confirmed' => 1,
    ])->assertForbidden();
    $this->actingAs($seller)->post(route('proposals.adjustment'), [
        'event_key' => 'TESTE', 'effective_from' => '2030-01-01',
        'percentage' => 5, 'reason' => 'Apenas exemplo para validar permissão', 'approve' => 1,
    ])->assertForbidden();
});

it('exige reconciliacao explicita antes de liberar numeracao global', function (): void {
    $actor = ecpV1Manager();
    $workflow = app(ProposalWorkflow::class);
    expect(DB::table('ec_proposal_numbering')->where('id', 1)->value('reconciled_at'))->toBeNull();
    $workflow->reconcileNumbering($actor, 1525);
    expect((int) DB::table('ec_proposal_numbering')->where('id', 1)->value('next_number'))->toBe(1526);
    expect(fn () => $workflow->reconcileNumbering($actor, 1500))->toThrow(ValidationException::class);
});

it('nao permite emitir documento incompleto, e mantem o seed da serie', function (): void {
    $actor = ecpV1Manager();
    $proposal = app(ProposalWorkflow::class)->saveDraft($actor, [
        'client_name' => 'Cliente incompleto',
        'service_codes' => ['LICENCIAMENTO'],
        'price_date' => '2026-11-23',
        'license_status' => 'NAO_ATIVO',
    ]);
    expect(fn () => app(ProposalWorkflow::class)->issue($actor, $proposal))
        ->toThrow(ValidationException::class);
    expect(DB::table('ec_proposal_number_ledger')->count())->toBe(0);
});

it('mantem documentos oficiais protegidos por autenticacao', function (): void {
    $actor = ecpV1Manager();
    $this->get(route('proposals.index'))->assertRedirect();
    $this->actingAs($actor)->get(route('proposals.index'))->assertOk();
});
