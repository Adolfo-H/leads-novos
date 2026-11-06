<?php

use App\Http\Controllers\Access\AccessAdminController;
use App\Http\Controllers\Access\AccessInvitationController;
use App\Http\Controllers\Access\AccessRequestController;
use App\Http\Controllers\CampaignIntelligenceController;
use App\Http\Controllers\CampaignProductionReadinessController;
use App\Http\Controllers\CampaignUnsubscribeController;
use App\Http\Controllers\HubSpotWebhookController;
use Illuminate\Support\Facades\Route;

Route::post(
    '/webhooks/hubspot',
    HubSpotWebhookController::class
)
    ->middleware(
        'hubspot.signature'
    )
    ->name(
        'webhooks.hubspot'
    );

Route::get('/', static fn () => redirect()->route(auth()->check() ? 'dashboard' : 'login'))->name('home');

Route::middleware(['auth', 'verified'])->group(function () {

    Route::livewire(
        '/importacoes',
        'pages::imports.index'
    )
        ->middleware(
            'commercial.workspace'
        )
        ->name('imports.index');

    Route::view('dashboard', 'dashboard')->name('dashboard');
});

// Central de Contatos: apenas gestores autenticados.
Route::middleware(['auth', 'verified', 'commercial.workspace'])->group(function () {
    Route::livewire('/contatos', 'pages::contacts.index')
        ->name('contacts.index');
    /* CONTACTS_RECEITA_V4_ROUTE */
    Route::livewire('/contatos/receita', 'pages::contacts.receita')->name('contacts.receita');
});

/* EC_EMAIL_CAMPAIGNS_V1_ROUTES */
// Os dados comerciais e o envio são exclusivos de gestores autorizados.
Route::middleware(['auth', 'verified', 'commercial.workspace'])->group(function () {
    Route::livewire('/campanhas', 'pages::campaigns.index')->name('campaigns.index');
    // CAMPANHAS_V6 — paginas independentes, todas restritas aos gestores.
    Route::livewire('/campanhas/respostas', 'pages::campaigns.replies')->name('campaigns.replies');
    Route::livewire('/campanhas/fila', 'pages::campaigns.queue')->name('campaigns.queue');
    Route::livewire('/campanhas/configuracoes', 'pages::campaigns.settings')->name('campaigns.settings');
    // EC_CAMPAIGNS_UX_V22: tela exclusivamente de leitura para gestores.
    Route::livewire('/campanhas/avancado', 'pages::campaigns.advanced')->name('campaigns.advanced');
});

// Link individual e com HMAC; GET só exibe confirmação. POST realiza descadastro.
Route::get('/email/descadastro/{token}', [CampaignUnsubscribeController::class, 'show'])
    ->middleware('throttle:10,1')->name('campaigns.unsubscribe');
Route::post('/email/descadastro/{token}', [CampaignUnsubscribeController::class, 'store'])
    ->middleware('throttle:10,1')->name('campaigns.unsubscribe.store');
/* EC_EMAIL_CAMPAIGNS_V1_ROUTES_END */

// EC_ACCESS_V24 — fluxo público de solicitação sem auto cadastro.
Route::middleware(['guest', 'throttle:8,1'])->group(function (): void {
    Route::get('/acesso/solicitar', [AccessRequestController::class, 'create'])
        ->name('access.request.create');
    Route::post('/acesso/solicitar', [AccessRequestController::class, 'store'])
        ->name('access.request.store');
    Route::get('/acesso/convite/{token}', [AccessInvitationController::class, 'show'])
        ->where('token', '[a-f0-9]{64}')->name('access.invitation.show');
    Route::post('/acesso/convite/{token}', [AccessInvitationController::class, 'activate'])
        ->where('token', '[a-f0-9]{64}')->name('access.invitation.activate');
});
// A24.3: visualizacao de solicitacoes nao consome quota de acoes administrativas.
// Auth, verificacao de e-mail, perfil gestor e isApprover() permanecem ativos.
Route::middleware(['auth', 'verified', 'commercial.manager'])->group(function (): void {
    Route::get('/acessos/solicitacoes', [AccessAdminController::class, 'index'])
        ->name('access.requests.index');
});

// As acoes de escrita continuam limitadas a 20 tentativas por minuto.
Route::middleware(['auth', 'verified', 'commercial.manager', 'throttle:20,1'])->group(function (): void {
    Route::post('/acessos/solicitacoes/{accessRequest}/aprovar', [AccessAdminController::class, 'approve'])
        ->name('access.requests.approve');
    Route::post('/acessos/solicitacoes/{accessRequest}/recusar', [AccessAdminController::class, 'reject'])
        ->name('access.requests.reject');
    Route::post('/acessos/solicitacoes/{accessRequest}/reenviar', [AccessAdminController::class, 'resend'])
        ->name('access.requests.resend');
    Route::post('/acessos/solicitacoes/{accessRequest}/reenviar-aviso', [AccessAdminController::class, 'resendNotification'])
        ->name('access.requests.resend-notification');
    Route::post('/acessos/solicitacoes/{accessRequest}/revogar', [AccessAdminController::class, 'revoke'])
        ->name('access.requests.revoke');
});
// EC_ACCESS_V24_END

require __DIR__.'/settings.php';

/*
|--------------------------------------------------------------------------
| Prospector ExportControl - Empresas
|--------------------------------------------------------------------------
*/

Route::middleware(['auth', 'verified'])->group(function () {
    Route::livewire(
        '/prospeccao',
        'pages::prospecting.index'
    )
        ->middleware(
            'commercial.workspace'
        )
        ->name('prospecting.index');

    Route::livewire(
        '/prospeccao/rodadas/{batch:uuid}',
        'pages::prospecting.show'
    )
        ->middleware(
            'commercial.workspace'
        )
        ->name('prospecting.show');

    Route::livewire(
        '/leads',
        'pages::leads.index'
    )->name('leads.index');

    Route::livewire(
        '/leads/gestao',
        'pages::leads.management'
    )
        ->middleware(
            'commercial.manager'
        )
        ->name('leads.management');

    Route::livewire(
        '/empresas',
        'pages::companies.index'
    )
        ->middleware(
            'commercial.workspace'
        )
        ->name('companies.index');

    Route::livewire(
        '/empresas/nova',
        'pages::companies.create'
    )
        ->middleware(
            'commercial.manager'
        )
        ->name('companies.create');

    Route::livewire(
        '/empresas/{company:uuid}/editar',
        'pages::companies.edit'
    )
        ->middleware(
            'commercial.manager'
        )
        ->name('companies.edit');

    Route::livewire(
        '/empresas/{company:uuid}/filiais/nova',
        'pages::companies.branches.create'
    )
        ->middleware(
            'commercial.manager'
        )
        ->name('companies.branches.create');

    Route::livewire(
        '/empresas/{company:uuid}',
        'pages::companies.show'
    )
        ->middleware(
            'commercial.company-access'
        )
        ->name('companies.show');
});

/* EC_CAMPAIGN_RELEASE_V8: apenas gestores */
Route::middleware(['auth', 'verified', 'commercial.workspace'])->group(function () {
    Route::get('/campanhas/homologacao', [CampaignProductionReadinessController::class, 'index'])->name('campaigns.production.index');
    Route::post('/campanhas/homologacao', [CampaignProductionReadinessController::class, 'save'])->name('campaigns.production.save');
});

/* EC_CAMPAIGN_INTELLIGENCE_V9 — apenas gestores */
Route::middleware(['auth', 'verified', 'commercial.workspace'])->group(function () {
    Route::get('/campanhas/inteligencia', [CampaignIntelligenceController::class, 'index'])->name('campaigns.intelligence.index');
    Route::post('/campanhas/inteligencia/segmentos', [CampaignIntelligenceController::class, 'saveSegment'])->name('campaigns.intelligence.segments.save');
    Route::post('/campanhas/inteligencia/rascunhos', [CampaignIntelligenceController::class, 'draft'])->name('campaigns.intelligence.draft');
    Route::post('/campanhas/inteligencia/sequencias', [CampaignIntelligenceController::class, 'sequence'])->name('campaigns.intelligence.sequence');
    Route::post('/campanhas/inteligencia/sequencias/{sequence}/preparar', [CampaignIntelligenceController::class, 'prepare'])->whereNumber('sequence')->name('campaigns.intelligence.prepare');
    Route::post('/campanhas/inteligencia/responder', [CampaignIntelligenceController::class, 'sendReply'])->name('campaigns.intelligence.reply');
});
