<?php

use App\Models\EcProposal;
use App\Models\User;
use App\Services\Proposals\ProposalPricing;
use App\Services\Proposals\ProposalWorkflow;
use Carbon\CarbonImmutable;
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

function v27Manager(): User
{
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();

    return $user;
}

it('cadastra cinco tabelas e modelos ativos sem duplicar reajuste', function (): void {
    $this->seed(EcProposalCatalogSeeder::class);
    expect(DB::table('ec_proposal_templates')->where('ready_to_issue', true)->count())->toBe(5)
        ->and(DB::table('ec_proposal_price_versions')->where('approved', true)->count())->toBe(5)
        ->and(DB::table('ec_proposal_adjustments')->where('event_key', 'EC-REAJUSTE-2026-11-01-01')->count())->toBe(1);
});

it('reproduz as duas propostas e mantém reajuste e tributos separados', function (): void {
    $pricing = app(ProposalPricing::class);
    $quote = $pricing->quote(['service_codes' => ['DFE', 'NFSE'], 'price_date' => '2026-11-23', 'dfe_cnpj_count' => 3, 'dfe_total' => 790, 'nfse_cnpj_count' => 3, 'nfse_total' => 123]);
    expect($quote['items']['DFE']['net'])->toBe('175.73')
        ->and($quote['items']['DFE']['final'])->toBe('198.01')
        ->and($quote['items']['NFSE']['net'])->toBe('159.75')
        ->and($quote['items']['NFSE']['final'])->toBe('180.00')
        ->and($quote['monthly_final'])->toBe('378.01')
        ->and($quote['one_time_final'])->toBe('0.00');
    $tables = $pricing->tables('2026-11-27');
    expect($tables['effective']['LICENCIAMENTO']['faixas'][0]['fixed'])->toBe('362.72')
        ->and($tables['minimums']['LICENCIAMENTO']['final'])->toBe('408.70')
        ->and($tables['minimums']['LICENCIAMENTO']['implementation'])->toBe('1634.79')
        ->and($tables['effective']['DFE']['faixas'][1]['fixed'])->toBe('292.88');
});

it('não confunde faixas fixas DFE com marginais NFSE', function (): void {
    $pricing = app(ProposalPricing::class);
    $quote = $pricing->quote(['service_codes' => ['DFE', 'NFSE'], 'price_date' => '2026-11-23', 'dfe_cnpj_count' => 11, 'dfe_total' => 99999, 'nfse_cnpj_count' => 7, 'nfse_total' => 1812]);
    expect($quote['items']['DFE']['net'])->toBe('292.88')
        ->and($quote['items']['NFSE']['net'])->toBe('160.12');
    expect(fn () => $pricing->quote(['service_codes' => ['DFE'], 'price_date' => '2026-11-23', 'dfe_cnpj_count' => 351, 'dfe_total' => 1]))->toThrow(ValidationException::class);
    expect(fn () => $pricing->quote(['service_codes' => ['NFSE'], 'price_date' => '2026-11-23', 'nfse_cnpj_count' => 1, 'nfse_total' => 1]))->toThrow(ValidationException::class);
});

it('salva e duplica volumes novos sem consumir numeração', function (): void {
    $actor = v27Manager();
    $workflow = app(ProposalWorkflow::class);
    $draft = $workflow->saveDraft($actor, ['client_name' => 'Cliente teste', 'service_codes' => ['DFE', 'NFSE'], 'price_date' => '2026-11-23', 'dfe_cnpj_count' => 3, 'dfe_total' => 790, 'nfse_cnpj_count' => 3, 'nfse_total' => 123]);
    expect((int) $draft->getAttribute('dfe_cnpj_count'))->toBe(3)
        ->and((int) $draft->getAttribute('nfse_total'))->toBe(123)
        ->and($draft->monthly_final)->toBe('378.01');
    $this->actingAs($actor)->post(route('proposals.duplicate', $draft))->assertRedirect();
    $copy = EcProposal::query()->orderByDesc('id')->firstOrFail();
    expect((int) $copy->getAttribute('dfe_total'))->toBe(790)
        ->and((int) $copy->getAttribute('nfse_total'))->toBe(123)
        ->and(DB::table('ec_proposal_number_ledger')->count())->toBe(0);
    Mail::assertNothingSent();
    Queue::assertNothingPushed();
});

it('oculta aviso de numeração apenas quando reconciliada e mostra tabelas efetivas', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-27 12:00:00', 'America/Sao_Paulo'));
    $actor = v27Manager();
    $this->actingAs($actor)->get(route('proposals.import.page'))->assertOk()->assertSee('Não conflitar numeração');
    app(ProposalWorkflow::class)->reconcileNumbering($actor, 1058);
    $this->actingAs($actor)->get(route('proposals.import.page'))->assertOk()->assertDontSee('Não conflitar numeração');
    $this->actingAs($actor)->get(route('proposals.create'))->assertOk()->assertSee('1059')->assertSee('dfe_cnpj_count')->assertSee('nfse_total');
    $this->actingAs($actor)->get(route('proposals.pricing'))->assertOk()->assertSee('362,72')->assertSee('175,73')->assertSee('159,75')->assertSee('11,25%');
});
