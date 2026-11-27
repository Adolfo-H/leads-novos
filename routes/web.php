<?php

use App\Http\Controllers\Access\AccessAdminController;
use App\Http\Controllers\Access\AccessInvitationController;
use App\Http\Controllers\Access\AccessRequestController;
use App\Http\Controllers\CampaignContactsSelectionController;
use App\Http\Controllers\CampaignContactsTransferController;
use App\Http\Controllers\CampaignFollowupCalendarController;
use App\Http\Controllers\CampaignFollowupCancelNextController;
use App\Http\Controllers\CampaignFollowupExtensionController;
use App\Http\Controllers\CampaignImportController;
use App\Http\Controllers\CampaignIntelligenceController;
use App\Http\Controllers\CampaignOperationsController;
use App\Http\Controllers\CampaignProductionReadinessController;
use App\Http\Controllers\CampaignProspectingController;
use App\Http\Controllers\CampaignUnsubscribeController;
use App\Http\Controllers\CampaignWizardController;
use App\Http\Controllers\EcProposalsController;
use App\Http\Controllers\HistoricalProposalCrmController;
use App\Http\Controllers\HubSpotWebhookController;
use App\Http\Controllers\ProposalExtrasV28Controller;
use App\Http\Controllers\WhatsappOperationsHttpController;
use App\Http\Controllers\WhatsappPairHttpController;
use App\Http\Controllers\WhatsappVerificationController;
use App\Http\Middleware\RedirectLegacyCampaignWizard;
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
    // EC_CONTACT_CAMPAIGN_EXACT_ROUTE_V91: seleção HTTP nativa, sem disparar SMTP.
    Route::post('/contatos/criar-campanha', CampaignContactsSelectionController::class)
        ->middleware(['commercial.manager', 'throttle:15,1'])->name('campaigns.from-contacts');
    /* CONTACTS_RECEITA_V4_ROUTE */
    Route::livewire('/contatos/receita', 'pages::contacts.receita')->name('contacts.receita');
    /* EC_WHATSAPP_REGISTRY_V1_ROUTE */
    Route::livewire('/contatos/verificacao-whatsapp', 'pages::contacts.whatsapp')
        ->middleware('commercial.manager')
        ->name('contacts.whatsapp');
    // EC_WA_OPERATIONS_HTTP_V1: consultas, cancelamento e revisao sem Livewire.
    Route::post('/contatos/verificacao-whatsapp/operacoes-http-v1', WhatsappOperationsHttpController::class)
        ->middleware(['commercial.manager', 'throttle:20,1'])
        ->name('contacts.whatsapp.operations-http-v1');
    // EC_WA_PAIR_HTTP_V1: fallback POST para o conector Ubuntu quando Livewire nao responde.
    Route::post('/contatos/verificacao-whatsapp/parear-v1', WhatsappPairHttpController::class)
        ->middleware(['commercial.manager', 'throttle:30,1'])
        ->name('contacts.whatsapp.pair-http-v1');
});

/* EC_EMAIL_CAMPAIGNS_V1_ROUTES */
// Os dados comerciais e o envio são exclusivos de gestores autorizados.
Route::middleware(['auth', 'verified', 'commercial.workspace'])->group(function () {
    Route::livewire('/campanhas', 'pages::campaigns.index')
        ->middleware(RedirectLegacyCampaignWizard::class)->name('campaigns.index');
    // CAMPANHAS_V6 — paginas independentes, todas restritas aos gestores.
    Route::livewire('/campanhas/respostas', 'pages::campaigns.replies')->name('campaigns.replies');
    Route::livewire('/campanhas/fila', 'pages::campaigns.queue')->name('campaigns.queue');
    Route::livewire('/campanhas/configuracoes', 'pages::campaigns.settings')->name('campaigns.settings');
    /* EC_CAMPAIGN_PROSPECTING_V80_BEGIN */
    Route::get('/campanhas/prospeccao-inteligente', [CampaignProspectingController::class, 'index'])
        ->middleware('commercial.manager')->name('campaigns.prospecting.index');
    Route::post('/campanhas/prospeccao-inteligente/rascunho', [CampaignProspectingController::class, 'draft'])
        ->middleware(['commercial.manager', 'throttle:5,1'])->name('campaigns.prospecting.draft');
    Route::get('/campanhas/prospeccao-inteligente/exportar', [CampaignProspectingController::class, 'export'])
        ->middleware(['commercial.manager', 'throttle:5,1'])->name('campaigns.prospecting.export');
    // EC_PROSPECTING_EXCLUDE_V83: mudanças somente na seleção individual.
    Route::post('/campanhas/prospeccao-inteligente/excluir', [CampaignProspectingController::class, 'exclude'])
        ->middleware(['commercial.manager', 'throttle:30,1'])->name('campaigns.prospecting.exclude');
    Route::post('/campanhas/prospeccao-inteligente/restaurar', [CampaignProspectingController::class, 'restore'])
        ->middleware(['commercial.manager', 'throttle:30,1'])->name('campaigns.prospecting.restore');
    /* EC_CAMPAIGN_PROSPECTING_V80_END */
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
    Route::post('/campanhas/inteligencia/sequencias/{sequence}/pausar', [CampaignIntelligenceController::class, 'pauseSequence'])
        ->whereNumber('sequence')->name('campaigns.intelligence.sequence.pause');
    Route::post('/campanhas/inteligencia/responder', [CampaignIntelligenceController::class, 'sendReply'])->name('campaigns.intelligence.reply');
});

/* EC92: selecao HTTP nativa de Contatos; sem despacho de mensagens. */
Route::middleware(['auth', 'verified', 'commercial.workspace', 'commercial.manager'])
    ->post('/campanhas/contatos-selecionados-v92',
        [CampaignContactsTransferController::class, 'store'])
    ->name('campaigns.contacts-transfer-v92');

/* EC_CAMPAIGNS_V93: nova criação HTTP independente de Livewire, sem SMTP automático. */
Route::middleware(['auth', 'verified', 'commercial.workspace', 'commercial.manager'])->group(function (): void {
    Route::get('/campanhas/novo-v93', [CampaignWizardController::class, 'index'])->name('campaigns.wizard-v93');
    // EC_CAMPAIGN_IMPORT_V27 — private preview, manager-only, no send routes.
    Route::get('/campanhas/importar-destinatarios', [CampaignImportController::class, 'show'])->name('campaigns.import-v27.show');
    Route::post('/campanhas/importar-destinatarios/arquivo', [CampaignImportController::class, 'upload'])->middleware('throttle:10,1')->name('campaigns.import-v27.upload');
    Route::post('/campanhas/importar-destinatarios/mapear', [CampaignImportController::class, 'mapping'])->name('campaigns.import-v27.mapping');
    Route::post('/campanhas/importar-destinatarios/adicionar', [CampaignImportController::class, 'add'])->name('campaigns.import-v27.add');
    Route::post('/campanhas/importar-destinatarios/descartar', [CampaignImportController::class, 'discard'])->name('campaigns.import-v27.discard');
    Route::post('/campanhas/novo-v93/selecionar', [CampaignWizardController::class, 'selection'])->name('campaigns.wizard-v93.selection');
    Route::post('/campanhas/novo-v93/adicionar-filtrados', [CampaignWizardController::class, 'addFiltered'])->name('campaigns.wizard-v93.filtered');
    Route::post('/campanhas/novo-v93/remover', [CampaignWizardController::class, 'remove'])->name('campaigns.wizard-v93.remove');
    Route::post('/campanhas/novo-v93/mensagem', [CampaignWizardController::class, 'message'])->name('campaigns.wizard-v93.message');
    Route::post('/campanhas/novo-v93/escolher-modelo', [CampaignWizardController::class, 'chooseTemplate'])->name('campaigns.wizard-v93.choose-template');
    Route::post('/campanhas/novo-v93/ritmo', [CampaignWizardController::class, 'pace'])->name('campaigns.wizard-v93.pace');
    Route::post('/campanhas/novo-v93/salvar-rascunho', [CampaignWizardController::class, 'save'])->name('campaigns.wizard-v93.save');
});

// EC_CALENDAR_FOLLOWUP_V1: agendamento explícito e pausa por HTTP, sem SMTP direto.
Route::middleware(['auth', 'verified', 'commercial.workspace', 'commercial.manager', 'throttle:20,1'])
    ->group(function (): void {
        Route::post('/campanhas/{campaign}/programar-rodadas', [CampaignFollowupCalendarController::class, 'save'])
            ->whereNumber('campaign')->name('campaigns.followup.calendar');
        Route::post('/campanhas/{campaign}/pausar-rodadas', [CampaignFollowupCalendarController::class, 'pause'])
            ->whereNumber('campaign')->name('campaigns.followup.pause');
        // EC_FOLLOWUP_EXTEND_V2: somente uma nova rodada explicitamente autorizada.
        Route::post('/campanhas/{campaign}/proxima-rodada', [CampaignFollowupExtensionController::class, 'store'])
            ->whereNumber('campaign')->name('campaigns.followup.extend');
        // EC_FOLLOWUP_CANCEL_NEXT_V3: cancela somente rodadas ainda não criadas.
        Route::post('/campanhas/{campaign}/cancelar-proximas-rodadas', [CampaignFollowupCancelNextController::class, 'store'])
            ->whereNumber('campaign')->name('campaigns.followup.cancel-next');
    });

/* EC94: ações HTTP autenticadas, reaproveitando o motor original de envios. */
Route::middleware(['auth', 'verified', 'commercial.workspace', 'commercial.manager'])->group(function (): void {
    Route::post('/campanhas/{campaign}/iniciar-v94', [CampaignOperationsController::class, 'start'])->whereNumber('campaign')->name('campaigns.ops-v94.start');
    Route::post('/campanhas/{campaign}/cancelar-v94', [CampaignOperationsController::class, 'cancel'])->whereNumber('campaign')->name('campaigns.ops-v94.cancel');
    Route::post('/campanhas/{campaign}/pausar-v94', [CampaignOperationsController::class, 'pause'])->whereNumber('campaign')->name('campaigns.ops-v94.pause');
    Route::post('/campanhas/{campaign}/retomar-v94', [CampaignOperationsController::class, 'resume'])->whereNumber('campaign')->name('campaigns.ops-v94.resume');
    Route::post('/campanhas/{campaign}/email-de-teste', [CampaignOperationsController::class, 'test'])->whereNumber('campaign')->name('campaigns.email-test');
});

/* EC941: confirmacao manual WhatsApp via POST, mantendo o registro original/auditoria. */
Route::middleware(['auth', 'verified', 'commercial.manager', 'throttle:30,1'])
    ->post('/contatos/verificacao-whatsapp/{phone}/registrar-v941',
        [WhatsappVerificationController::class, 'store'])
    ->whereNumber('phone')
    ->name('contacts.whatsapp.verify-v941');

/* EC_PROPOSALS_MODULE_V1: HTTP CRUD; nunca envia SMTP nem usa callback Livewire. */
Route::middleware(['auth', 'verified', 'commercial.workspace'])->group(function (): void {
    Route::get('/propostas', [EcProposalsController::class, 'index'])->name('proposals.index');
    Route::get('/propostas/nova', [EcProposalsController::class, 'create'])->name('proposals.create');
    Route::post('/propostas', [EcProposalsController::class, 'store'])->name('proposals.store');
    Route::get('/propostas/modelos', [EcProposalsController::class, 'templates'])->name('proposals.templates');
    // EC_PROPOSALS_V28: private previews and local CRM reconciliation.
    Route::get('/propostas/modelos/{service}/pdf', [ProposalExtrasV28Controller::class, 'modelPdf'])->name('proposals.model-pdf-v28');
    Route::get('/propostas/cobertura-hubspot', [ProposalExtrasV28Controller::class, 'coverage'])->name('proposals.crm-coverage-v28');
    Route::post('/propostas/cobertura-hubspot/conciliar', [ProposalExtrasV28Controller::class, 'reconcile'])->middleware('throttle:5,1')->name('proposals.crm-reconcile-v28');
    Route::get('/propostas/precos', [EcProposalsController::class, 'pricing'])->name('proposals.pricing');
    Route::get('/propostas/importar', [EcProposalsController::class, 'importPage'])->name('proposals.import.page');
    Route::post('/propostas/importar', [EcProposalsController::class, 'import'])->middleware('throttle:15,1')->name('proposals.import.store');
    Route::post('/propostas/ajustes', [EcProposalsController::class, 'addAdjustment'])->name('proposals.adjustment');
    Route::post('/propostas/tributos', [EcProposalsController::class, 'addTaxProfile'])->name('proposals.tax-profile');
    Route::post('/propostas/tabela', [EcProposalsController::class, 'addPriceVersion'])->name('proposals.price-version');
    Route::post('/propostas/modelos', [EcProposalsController::class, 'updateTemplate'])->name('proposals.template-update');
    Route::post('/propostas/numeracao', [EcProposalsController::class, 'reconcile'])->name('proposals.numbering');
    Route::get('/propostas/{proposal}', [EcProposalsController::class, 'show'])->whereNumber('proposal')->name('proposals.show');
    Route::post('/propostas/{proposal}/conciliar-historico', [HistoricalProposalCrmController::class, 'confirm'])->whereNumber('proposal')->middleware('throttle:30,1')->name('proposals.history-crm.confirm');
    Route::get('/propostas/{proposal}/editar', [EcProposalsController::class, 'edit'])->whereNumber('proposal')->name('proposals.edit');
    Route::put('/propostas/{proposal}', [EcProposalsController::class, 'update'])->whereNumber('proposal')->name('proposals.update');
    Route::post('/propostas/{proposal}/emitir', [EcProposalsController::class, 'issue'])->whereNumber('proposal')->middleware('throttle:5,1')->name('proposals.issue');
    Route::post('/propostas/{proposal}/duplicar', [EcProposalsController::class, 'duplicate'])->whereNumber('proposal')->name('proposals.duplicate');
    Route::post('/propostas/{proposal}/revisar', [EcProposalsController::class, 'revise'])->whereNumber('proposal')->name('proposals.revise');
    Route::post('/propostas/{proposal}/situacao', [EcProposalsController::class, 'changeStatus'])->whereNumber('proposal')->name('proposals.status');
    Route::get('/propostas/{proposal}/documentos/{document}', [EcProposalsController::class, 'download'])->whereNumber('proposal')->whereNumber('document')->name('proposals.document');
});
