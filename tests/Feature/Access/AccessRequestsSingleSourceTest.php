<?php

use App\Models\AccountAccessRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

function a07AuthorizedApprover(): User
{
    config()->set('access_requests.approver_email', 'gestor-a07@example.test');
    $manager = User::factory()->create([
        'email' => 'gestor-a07@example.test',
        'email_verified_at' => now(),
    ]);
    $manager->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();

    return $manager;
}

function a07AccessRequest(int $i, string $status = 'pending', bool $activated = false): AccountAccessRequest
{
    return AccountAccessRequest::query()->create([
        'name' => sprintf('Solicitante A07 %02d', $i),
        'email' => sprintf('a07-%02d@example.test', $i),
        'company' => 'Empresa de teste A07',
        'status' => $status,
        'consent_at' => now(),
        'activated_at' => $activated ? now() : null,
    ]);
}

it('consulta a fonte unica no painel, sem repetir a listagem e o total', function (): void {
    $approver = a07AuthorizedApprover();
    foreach (range(1, 12) as $i) {
        a07AccessRequest($i);
    }
    a07AccessRequest(13, 'approved', true);

    Mail::fake();
    DB::enableQueryLog();
    DB::flushQueryLog();
    try {
        $response = $this->actingAs($approver)->get(route('access.requests.index'));
        $statements = collect(DB::getQueryLog())
            ->pluck('query')
            ->filter(static fn (string $sql): bool => preg_match('/^\s*select\b/i', $sql) === 1
                && str_contains(strtolower($sql), 'account_access_requests'));
    } finally {
        DB::disableQueryLog();
        DB::flushQueryLog();
    }

    $response->assertOk()
        ->assertSee('Solicitações de acesso')
        ->assertSee('Solicitante A07 12')
        ->assertDontSee('Solicitante A07 01')
        ->assertSee('Mostrando 1–10 de 13');
    // Só contam SELECTs cujo FROM é a tabela de solicitações.
    // PRAGMA/SQLite e metadados do schema não são leituras da listagem.
    // Assim, o teste identifica duplicação real, não overhead de introspecção.
    $sourceSelects = $statements->filter(
        static fn (string $sql): bool => preg_match(
            '/\bfrom\s+["`]?account_access_requests["`]?(?=\s|$)/i',
            $sql,
        ) === 1,
    );
    $statusGroups = $sourceSelects->filter(
        static fn (string $sql): bool => str_contains(strtolower($sql), 'group by'),
    );
    $pageRows = $sourceSelects->filter(
        static fn (string $sql): bool => str_contains(strtolower($sql), 'order by')
            && preg_match('/\blimit\s+[0-9?]+/i', $sql) === 1,
    );

    // Consulta de status (1), ajuste de ativados (1), COUNT da paginação (1)
    // e SELECT dos 10 itens (1). Se a página carregar os dados duas vezes,
    // estes asserts falham, sem depender do número de queries de metadados.
    expect($statusGroups->count())->toBe(1)
        ->and($pageRows->count())->toBe(1)
        ->and($sourceSelects->count())->toBeLessThanOrEqual(4);
    Mail::assertNothingSent();
});

it('mantem filtros, ordenacao e paginação de dez registros', function (): void {
    $approver = a07AuthorizedApprover();
    foreach (range(1, 12) as $i) {
        a07AccessRequest($i);
    }
    $this->actingAs($approver)
        ->get(route('access.requests.index', ['status' => 'pending', 'page' => 2]))
        ->assertOk()
        ->assertSee('Solicitante A07 01')
        ->assertDontSee('Solicitante A07 12')
        ->assertSee('Mostrando 11–12 de 12');
    $this->get(route('access.requests.index', ['q' => 'Solicitante A07 07', 'sort' => 'oldest']))
        ->assertOk()
        ->assertSee('Solicitante A07 07')
        ->assertDontSee('Solicitante A07 08');
});

it('mantem separadas aprovacoes ainda pendentes e ativacoes', function (): void {
    $approver = a07AuthorizedApprover();
    a07AccessRequest(1, 'approved', false);
    a07AccessRequest(2, 'approved', true);
    a07AccessRequest(3, 'activated', true);

    $this->actingAs($approver)
        ->get(route('access.requests.index', ['status' => 'activated']))
        ->assertOk()
        ->assertSee('Solicitante A07 02')
        ->assertSee('Solicitante A07 03')
        ->assertDontSee('Solicitante A07 01');
    $this->get(route('access.requests.index', ['status' => 'approved']))
        ->assertOk()
        ->assertSee('Solicitante A07 01')
        ->assertDontSee('Solicitante A07 02');
});

it('nao altera a autorizacao exclusiva do gestor aprovador', function (): void {
    a07AuthorizedApprover();
    $other = User::factory()->create(['email_verified_at' => now()]);
    $other->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();
    $this->actingAs($other)->get(route('access.requests.index'))->assertForbidden();
    $seller = User::factory()->create([
        'email' => 'seller-a07@example.test',
        'email_verified_at' => now(),
    ]);
    $seller->forceFill(['commercial_role' => User::ROLE_SELLER])->save();
    $this->actingAs($seller)->get(route('access.requests.index'))->assertForbidden();
});
