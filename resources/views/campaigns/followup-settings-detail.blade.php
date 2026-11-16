{{-- EC_FOLLOWUP_AUTOMATION_V5 — painel Livewire de leitura + edicao automatica. --}}
<livewire:campaign-followup-editor :campaign-id="(int) $selected->id" :key="'ec-followup-editor-'.$selected->id" />
