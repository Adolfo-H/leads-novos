{{-- EC_WA_VISUAL_V12: navegação local entre tarefas, sem novas rotas nem requisições. --}}
<nav class="wa12-tabs" aria-label="Áreas da verificação WhatsApp" role="tablist" data-test="wa12-tabs">
    <button type="button" role="tab" x-bind:aria-selected="tab==='numbers'" x-bind:class="tab==='numbers' ? 'is-active' : ''" x-on:click="tab='numbers'" aria-controls="wa12-numbers">
        <span class="wa12-tab-icon" aria-hidden="true">01</span>
        <span><strong>Números e contatos</strong><small>Consultar minha base</small></span>
    </button>
    <button type="button" role="tab" x-bind:aria-selected="tab==='company'" x-bind:class="tab==='company' ? 'is-active' : ''" x-on:click="tab='company'" aria-controls="wa12-company">
        <span class="wa12-tab-icon" aria-hidden="true">02</span>
        <span><strong>Pesquisar empresa</strong><small>Encontrar WhatsApps publicados</small></span>
    </button>
    <button type="button" role="tab" x-bind:aria-selected="tab==='operation'" x-bind:class="tab==='operation' ? 'is-active' : ''" x-on:click="tab='operation'" aria-controls="wa12-operation">
        <span class="wa12-tab-icon" aria-hidden="true">03</span>
        <span><strong>Verificações e histórico</strong><small>Contas, lotes e horários</small></span>
    </button>
</nav>
