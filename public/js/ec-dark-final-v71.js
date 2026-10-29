/*
 * A24.9 / V71 — reparacao deterministica de contraste apos Livewire e selecao.
 * Atualiza apenas aparencia dos elementos encontrados no proprio DOM.
 * O v69 era executado apenas em insercoes de nos; selecao normalmente muda
 * atributos / propriedades sem inserir nos. Este reparo cobre ambos os casos.
 */
(function () {
  'use strict';
  if (window.EcDarkFixV71 && window.EcDarkFixV71.version === 'A24.9') {
    window.EcDarkFixV71.refresh();
    return;
  }

  const S = {
    leads: '.ec-page-shell.leads-rf.lf36-leads',
    manager: '.ecgm-page.ecgm-v2.ecgm61.ecgm62',
  };
  const C = {
    panel: '#181b20', raised: '#22262c', alternate: '#1d2228',
    selected: '#193a32', input: '#20242a', line: '#3b4650',
    selectedLine: '#438b7a', ink: '#f2f5f7', muted: '#bfd0da',
    accent: '#9ce9da', chip: '#1c4038', chipInk: '#c3f9ec',
    chipLine: '#3e7d6e', success: '#d1fff2'
  };
  const cache = new WeakMap();
  const active = new Set();
  let frame = null;
  let runCount = 0;
  let lastReport = null;
  const rules = [];

  function make(bg, ink, border, extras) {
    return Object.assign({
      'background-color': bg,
      'background-image': 'none',
      'color': ink,
      '-webkit-text-fill-color': ink,
      'border-color': border
    }, extras || {});
  }
  const PANEL = make(C.panel, C.ink, C.line);
  const RAISED = make(C.raised, C.ink, C.line);
  const ALTERNATE = make(C.alternate, C.ink, C.line);
  const SELECTED = make(C.selected, C.ink, C.selectedLine);
  const CONTROL = make(C.input, C.ink, '#56646d', {'color-scheme': 'dark', 'opacity': '1'});
  const INK = {'color': C.ink, '-webkit-text-fill-color': C.ink, 'opacity': '1', 'text-shadow': 'none'};
  const MUTED = {'color': C.muted, '-webkit-text-fill-color': C.muted, 'opacity': '1', 'text-shadow': 'none'};
  const TRANSPARENT = {'background-color': 'transparent', 'background-image': 'none'};
  const CHIP = make(C.chip, C.chipInk, C.chipLine, {'opacity': '1'});
  const ACTION = make('#22302e', '#d8fff6', '#567970', {'opacity': '1'});
  const CLEAR = make('#19493c', '#f0fffb', '#4c967f', {'opacity': '1'});
  const TEAL_TEXT = {'color': C.accent, '-webkit-text-fill-color': C.accent, 'opacity': '1'};

  function rule(group, selector, styles) {
    rules.push([group, selector, styles]);
  }

  // Leads: topo, diagnostico do HubSpot e atalhos.
  rule('leads', '.lv14-hubspot-summary, .lv14-hubspot-popup, .rf-hubspot-health, .rf-hubspot-health-content, .rf-hubspot-health-checks, .rf-hubspot-health-main, .rf-hubspot-check', PANEL);
  rule('leads', '.lv14-hubspot-copy strong, .rf-hubspot-health-main strong, .rf-hubspot-check strong', INK);
  rule('leads', '.lv14-hubspot-copy small, .rf-hubspot-health-main span, .rf-hubspot-check span, .rf-hubspot-health-checks small', MUTED);
  rule('leads', '.lv11-health-more', TEAL_TEXT);
  rule('leads', '.lv15-summary .lv15-card :is(.lv15-card-label, .lv15-card-value)', INK);
  rule('leads', '.lv15-summary .lv15-card :is(small, .lv15-card-arrow)', MUTED);

  // Leads: caixa de filtros, campos e botao limpar.
  rule('leads', '.rf-panel.rf-filters, .rf-filters-head, .lf364-filter-header, .rf-filter-main, .rf-filter-secondary, .lf36-filter-field', PANEL);
  rule('leads', '.rf-filters .rf-input, .rf-filters .rf-select, .rf-filters input:not([type="checkbox"]):not([type="radio"]), .rf-filters select, .rf-filters textarea', CONTROL);
  rule('leads', '.rf-filters :is(label, strong), .rf-filters-head strong', INK);
  rule('leads', '.rf-filters :is(.lf364-filter-caption, .rf-filters-head span)', MUTED);
  rule('leads', '.rf-filters-clear.lf364-filter-clear', CLEAR);

  // Leads: etapa pessoal, inclusive linha de chips que ainda ficava branca.
  rule('leads', 'details.lf361-pipeline-access, details.lf361-pipeline-access .lf361-pipeline-summary, details.lf361-pipeline-access .rf-stage-strip, details.lf361-pipeline-access .lv18-pipeline-heading, details.lf361-pipeline-access .rf-stage-strip-list, details.lf361-pipeline-access .lv18-pipeline-clear-container, details.lf361-pipeline-access .lv18-pipeline-chips', PANEL);
  rule('leads', 'details.lf361-pipeline-access :is(.lv18-chip-label, .lv18-chip-count, .rf-stage-strip-heading strong, .lf37-personal-title)', INK);
  rule('leads', 'details.lf361-pipeline-access :is(.rf-stage-strip-heading span, .lf37-personal-meta)', MUTED);

  // Leads: painel principal, cabecalhos, paginacao e linha detalhada.
  rule('leads', '.rf-list-panel, .rf-list-panel .rf-list-toolbar, .rf-list-panel .rf-list-scroll, .rf-list-panel .rf-pagination', PANEL);
  rule('leads', '.rf-list-panel .rf-list-head, .rf-list-panel .lv13-list-head', RAISED);
  rule('leads', '.rf-list-panel .rf-list-toolbar :is(h2, strong), .rf-list-panel .lv13-company-name, .rf-list-panel .lv13-score strong, .rf-list-panel .lv13-next strong, .rf-list-panel .lv13-owner-name, .rf-list-panel .rf-list-head > div', INK);
  rule('leads', '.rf-list-panel :is(.lf364-list-hint, .lv13-company-body small, .lv13-status small, .lv13-stage small, .lv13-next small, .lv13-owner small, .lv13-list-head span, .rf-pagination-info)', MUTED);
  rule('leads', '.rf-list-panel .lv13-lead-line :is(.lv13-company, .lv13-score, .lv13-status, .lv13-stage, .lv13-next, .lv13-owner, .lv13-company-body)', TRANSPARENT);
  rule('leads', '.rf-list-panel :is(.lv13-status, .lv13-stage) > .lv13-pill, .rf-list-panel .lv13-icp', CHIP);
  rule('leads', '.rf-list-panel .lv23-actions :is(a,button)', ACTION);
  rule('leads', '.rf-list-panel .lv23-actions :is(a,button) :is(span,svg)', INK);
  rule('leads', '.rf-list-panel .lf364-selection-bar, .rf-list-panel .lf364-selection-message', SELECTED);
  rule('leads', '.rf-list-panel .lf364-selection-bar :is(strong,span,button)', INK);

  // Gestao Comercial: fundo dos paines, indicadores, tabelas e formularios.
  rule('manager', '.ecgm62-card, .ecgm62-load-card, .ecgm62-attention-details, .ecgm62-attention-content, .ecgm62-card-header, .ecgm62-card-footer, .ecgm62-attention-summary, .ecgm62-table-shell, .ecgm62-scroll, .ecgm62-role-body, .ecgm62-role-field, .ecgm62-people, .ecgm62-person, .ecgm62-dist-footer', PANEL);
  rule('manager', '.ecgm62-kpis .ecgm62-kpi, .ecgm62-dist-stats > div, .ecgm62-confirm-stats > div', PANEL);
  rule('manager', 'table.ecgm62-table, table.ecgm61-table, table.ecgm-management-table, table.ecgm62-table :is(thead,tbody,tr,th,td), table.ecgm61-table :is(thead,tbody,tr,th,td)', RAISED);
  rule('manager', '.ecgm62-kpi strong, .ecgm62-kpi h2, .ecgm62-card-header :is(h2,h3), .ecgm62-attention-summary > span:first-child, .ecgm62-person strong, .ecgm61-panel-heading h3, .ecgm61-preview-volume strong', INK);
  rule('manager', '.ecgm62-kpi :is(span,small), .ecgm62-person small, .ecgm62-card-header p, .ecgm62-attention-hint, .ecgm62-help, .ecgm61-panel-heading p, .ecgm61-preview-volume :is(span,small)', MUTED);
  rule('manager', '.ecgm62-role-field select, .ecgm62-dist-config input[type="number"]', CONTROL);
  rule('manager', '.ecgm62-distribution .ecgm-distribution-preview, .ecgm62-distribution .ecgm61-panel-heading, .ecgm62-distribution .ecgm61-table-wrap, .ecgm62-distribution .ecgm61-preview-stats, .ecgm62-distribution .ecgm-preview-note, .ecgm62-confirm-dialog', PANEL);
  rule('manager', '.ecgm62-dist-footer button.ecgm62-btn:not(.ecgm62-btn--primary)', RAISED);

  function isDark() {
    const html = document.documentElement;
    // Flux e V65 podem executar em ordens diferentes no wire:navigate.
    // Evita dependencia de um atributo que pode estar temporariamente ausente.
    return html.classList.contains('dark') &&
      html.getAttribute('data-ec-appearance') !== 'light' &&
      html.getAttribute('data-ec-appearance') !== 'system';
  }

  function applyTo(node, declarations) {
    if (!node || !node.isConnected) return;
    let props = cache.get(node);
    if (!props) {
      props = new Map();
      cache.set(node, props);
    }
    for (const [key, value] of Object.entries(declarations)) {
      if (!props.has(key)) {
        props.set(key, {
          value: node.style.getPropertyValue(key),
          priority: node.style.getPropertyPriority(key)
        });
      }
      if (node.style.getPropertyValue(key) !== value || node.style.getPropertyPriority(key) !== 'important') {
        node.style.setProperty(key, value, 'important');
      }
    }
    active.add(node);
  }

  function clearStyles() {
    for (const node of active) {
      const original = cache.get(node);
      if (!original) continue;
      for (const [key, before] of original) {
        if (before.value) node.style.setProperty(key, before.value, before.priority);
        else node.style.removeProperty(key);
      }
      cache.delete(node);
    }
    active.clear();
  }

  function paintSelected(root) {
    for (const node of root.querySelectorAll('.rf-list-panel .rf-lead-row')) {
      const inner = node.querySelector('.lv13-lead-line');
      const checkbox = node.querySelector('.lv13-check input[type="checkbox"]');
      const selected = Boolean((checkbox && checkbox.checked) || (inner && inner.classList.contains('is-selected')));
      applyTo(node, selected ? SELECTED : PANEL);
      if (inner) applyTo(inner, selected ? SELECTED : (Array.prototype.indexOf.call(node.parentElement ? node.parentElement.children : [], node) % 2 === 1 ? ALTERNATE : PANEL));
    }
    for (const node of root.querySelectorAll('details.lf361-pipeline-access button.rf-stage-strip-chip')) {
      const selected = node.getAttribute('aria-pressed') === 'true' || node.classList.contains('is-active');
      applyTo(node, selected ? SELECTED : RAISED);
    }
    for (const node of root.querySelectorAll('.lv15-summary .lv15-card')) {
      applyTo(node, node.classList.contains('is-active') ? SELECTED : PANEL);
    }
  }

  function paint() {
    frame = null;
    if (!isDark()) {
      if (active.size) clearStyles();
      lastReport = {mode: 'other', styled: 0, selected: 0, whiteSurfaces: 0};
      return;
    }
    for (const node of active) {
      if (!node.isConnected) { active.delete(node); cache.delete(node); }
    }
    const roots = {leads: document.querySelector(S.leads), manager: document.querySelector(S.manager)};
    for (const [group, selector, declaration] of rules) {
      const root = roots[group];
      if (!root) continue;
      let nodes;
      try { nodes = root.querySelectorAll(selector); } catch (err) { continue; }
      for (const node of nodes) applyTo(node, declaration);
    }
    if (roots.leads) paintSelected(roots.leads);
    runCount++;
    const white = [];
    const check = [
      '.leads-rf .lv15-card', '.leads-rf .rf-filters',
      '.leads-rf details.lf361-pipeline-access .lv18-pipeline-chip',
      '.leads-rf .rf-list-panel', '.leads-rf .rf-list-panel .lv13-lead-line',
      '.ecgm-page .ecgm62-kpi', '.ecgm-page .ecgm-distribution-preview'
    ];
    for (const selector of check) {
      const node = document.querySelector(selector);
      if (!node) continue;
      const rgb = getComputedStyle(node).backgroundColor;
      if (/^rgba?\((255|254|253|252),\s*(255|254|253|252),\s*(255|254|253|252)/.test(rgb)) {
        white.push(selector);
      }
    }
    lastReport = {
      mode: 'dark', styled: active.size, runs: runCount,
      selected: document.querySelectorAll('.leads-rf .lv13-lead-line.is-selected, .leads-rf .rf-lead-row:has(.lv13-check input:checked)').length,
      whiteSurfaces: white.length,
      whiteSelectors: white
    };
  }

  function schedule() {
    if (frame !== null) return;
    frame = requestAnimationFrame(paint);
  }
  const observer = new MutationObserver(schedule);
  observer.observe(document.documentElement, {
    subtree: true,
    childList: true,
    attributes: true,
    attributeFilter: ['class', 'style', 'data-ec-appearance', 'aria-pressed', 'aria-checked', 'checked']
  });
  // Atualizacao de checkboxes via x-model nem sempre modifica o atributo checked.
  document.addEventListener('change', schedule, true);
  document.addEventListener('input', schedule, true);
  document.addEventListener('click', schedule, true);
  document.addEventListener('livewire:navigated', schedule);
  document.addEventListener('livewire:updated', schedule);
  window.addEventListener('ec-appearance-changed', schedule);
  window.addEventListener('pageshow', schedule);
  document.addEventListener('DOMContentLoaded', schedule);
  window.EcDarkFixV71 = {
    version: 'A24.9', refresh: schedule,
    report: () => lastReport,
    check: () => {paint(); return lastReport;}
  };
  schedule();
})();
