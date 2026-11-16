/*
 * A24.7 — reparo de contraste nas telas reais Leads e Gestão Comercial.
 * Por que JS? O bundle antigo contém muitos seletores com !important que
 * venceram as tentativas anteriores de CSS. Declarações inline !important
 * vencem essa disputa, sem reescrever Livewire ou alterar outros temas.
 * Restaura o estilo inline original ao sair do modo Escuro.
 */
(function () {
  'use strict';

  if (window.EcDarkRepairV69 && window.EcDarkRepairV69.version === 'A24.7') {
    window.EcDarkRepairV69.refresh();
    return;
  }

  const ROOT_LEADS = '.ec-page-shell.leads-rf.lf36-leads';
  const ROOT_MANAGER = '.ecgm-page.ecgm-v2.ecgm61.ecgm62';
  const C = Object.freeze({
    card: '#181b20',
    raised: '#22262c',
    input: '#20242a',
    border: '#38414a',
    inputBorder: '#46515b',
    ink: '#f2f5f7',
    muted: '#bbc7d1',
    teal: '#8de9d7',
    tealBg: '#17382f'
  });

  function props(background, color, border) {
    return {
      'background-color': background,
      'background-image': 'none',
      'color': color,
      '-webkit-text-fill-color': color,
      'border-color': border
    };
  }
  const PANEL = props(C.card, C.ink, C.border);
  const RAISED = props(C.raised, C.ink, C.border);
  const INPUT = Object.assign(props(C.input, C.ink, C.inputBorder), { 'color-scheme': 'dark', 'opacity': '1' });
  const INK = { color: C.ink, '-webkit-text-fill-color': C.ink, opacity: '1' };
  const MUTED = { color: C.muted, '-webkit-text-fill-color': C.muted, opacity: '1' };
  const TEAL = { color: C.teal, '-webkit-text-fill-color': C.teal };
  const TRANSPARENT = { 'background-color': 'transparent', 'background-image': 'none' };

  // Cada seletor foi conferido nas views Blade do Prospector. Somente as duas
  // áreas solicitadas são alteradas; badges coloridas e ações ficam intactas.
  const rules = [
    // LEADS: painel dos filtros e campos.
    [ROOT_LEADS, 'section.rf-panel.rf-filters, section.rf-panel.rf-filters > .rf-filters-head, section.rf-panel.rf-filters .rf-filter-main, section.rf-panel.rf-filters .rf-filter-secondary, section.rf-panel.rf-filters .lf36-filter-field, section.rf-panel.rf-filters .lf364-filter-header', PANEL],
    [ROOT_LEADS, 'section.rf-panel.rf-filters input:not([type="checkbox"]):not([type="radio"]), section.rf-panel.rf-filters select, section.rf-panel.rf-filters textarea', INPUT],
    [ROOT_LEADS, 'section.rf-panel.rf-filters label, section.rf-panel.rf-filters .lf364-filter-header strong, section.rf-panel.rf-filters .lf36-filter-field label, section.rf-panel.rf-filters .rf-filters-head strong', INK],
    [ROOT_LEADS, 'section.rf-panel.rf-filters .lf364-filter-caption, section.rf-panel.rf-filters .lf364-filter-header span', MUTED],

    // LEADS: pipeline pessoal, inclusive chips com classes de tons diferentes.
    [ROOT_LEADS, 'details.lf361-pipeline-access, details.lf361-pipeline-access > summary, details.lf361-pipeline-access section.rf-stage-strip, details.lf361-pipeline-access .rf-stage-strip-heading, details.lf361-pipeline-access .rf-stage-strip-list, details.lf361-pipeline-access .lv18-pipeline-clear-container', PANEL],
    [ROOT_LEADS, 'details.lf361-pipeline-access button.rf-stage-strip-chip, details.lf361-pipeline-access button.lv18-pipeline-chip', RAISED],
    [ROOT_LEADS, 'details.lf361-pipeline-access .lv18-chip-label, details.lf361-pipeline-access .lv18-chip-count, details.lf361-pipeline-access .rf-stage-strip-heading strong, details.lf361-pipeline-access .lf37-personal-title', INK],
    [ROOT_LEADS, 'details.lf361-pipeline-access .rf-stage-strip-heading span, details.lf361-pipeline-access .lf37-personal-meta', MUTED],

    // LEADS: toolbar, cabeçalho, linhas e subelementos do quadro de empresas.
    [ROOT_LEADS, 'section.rf-panel.rf-list-panel, section.rf-panel.rf-list-panel .rf-list-toolbar, section.rf-panel.rf-list-panel .rf-list-scroll, section.rf-panel.rf-list-panel .rf-pagination, section.rf-panel.rf-list-panel .rf-lead-row, section.rf-panel.rf-list-panel .lv13-lead-line, section.rf-panel.rf-list-panel .rf-list-head, section.rf-panel.rf-list-panel .lv13-list-head', PANEL],
    [ROOT_LEADS, 'section.rf-panel.rf-list-panel .rf-list-head, section.rf-panel.rf-list-panel .lv13-list-head', RAISED],
    [ROOT_LEADS, 'section.rf-panel.rf-list-panel .lf36-list-title, section.rf-panel.rf-list-panel .lf364-list-heading h2, section.rf-panel.rf-list-panel .lv13-company-name, section.rf-panel.rf-list-panel .lv13-company-body strong, section.rf-panel.rf-list-panel .lv13-score strong, section.rf-panel.rf-list-panel .lv13-owner-name, section.rf-panel.rf-list-panel .rf-list-toolbar strong, section.rf-panel.rf-list-panel .rf-list-head strong', INK],
    [ROOT_LEADS, 'section.rf-panel.rf-list-panel .lf364-list-hint, section.rf-panel.rf-list-panel .lv13-company-body small, section.rf-panel.rf-list-panel .lv13-score small, section.rf-panel.rf-list-panel .lv13-next small, section.rf-panel.rf-list-panel .lv13-owner small, section.rf-panel.rf-list-panel .rf-pagination-info, section.rf-panel.rf-list-panel .rf-list-head span, section.rf-panel.rf-list-panel .lv13-list-head span', MUTED],
    [ROOT_LEADS, 'section.rf-panel.rf-list-panel .lv13-lead-line .lv13-company-body, section.rf-panel.rf-list-panel .lv13-lead-line .lv13-score, section.rf-panel.rf-list-panel .lv13-lead-line .lv13-next, section.rf-panel.rf-list-panel .lv13-lead-line .lv13-owner', TRANSPARENT],

    // GESTÃO: KPIs (não só as bordas, também o número e as legendas).
    [ROOT_MANAGER, 'section.ecgm-metrics.ecgm62-kpis .ecgm-metric.ecgm62-kpi, section.ecgm-metrics.ecgm62-kpis a.ecgm-metric.ecgm62-kpi', PANEL],
    [ROOT_MANAGER, 'section.ecgm-metrics.ecgm62-kpis .ecgm62-kpi strong', INK],
    [ROOT_MANAGER, 'section.ecgm-metrics.ecgm62-kpis .ecgm62-kpi span, section.ecgm-metrics.ecgm62-kpis .ecgm62-kpi small', MUTED],

    // GESTÃO: blocos, cabeçalhos, rodapés e tabelas.
    [ROOT_MANAGER, '.ecgm62-card, .ecgm62-load-card, details.ecgm62-attention-details, .ecgm62-attention-content, .ecgm62-card-header, .ecgm62-card-footer, .ecgm62-attention-summary, .ecgm62-table-shell, .ecgm62-scroll, .ecgm62-role-body, .ecgm62-role-field, .ecgm62-role-card, .ecgm62-people, .ecgm62-person', PANEL],
    [ROOT_MANAGER, 'table.ecgm62-table, table.ecgm62-attention-table, table.ecgm-management-table, table.ecgm62-table thead, table.ecgm62-table tbody, table.ecgm62-table tbody tr, table.ecgm62-table thead tr, table.ecgm62-table td, table.ecgm62-table th, table.ecgm62-attention-table td, table.ecgm62-attention-table th', RAISED],
    [ROOT_MANAGER, '.ecgm62-card-header h2, .ecgm62-card-header h3, .ecgm62-attention-summary > span:first-child, .ecgm62-table td, .ecgm62-table th, .ecgm62-kpi strong, .ecgm62-person strong, .ecgm62-role-field label', INK],
    [ROOT_MANAGER, '.ecgm62-attention-hint, .ecgm62-card-header p, .ecgm62-card-footer, .ecgm62-person small, .ecgm62-help, .ecgm62-role-field small, .ecgm62-attention-footer', MUTED],
    [ROOT_MANAGER, '.ecgm62-role-field select, .ecgm62-dist-config input[type="number"]', INPUT],

    // GESTÃO: distribuição automática / área inferior delimitada.
    [ROOT_MANAGER, '.ecgm62-distribution .ecgm62-dist-stats > div, .ecgm62-distribution .ecgm62-card, .ecgm62-distribution .ecgm62-card-header, .ecgm62-distribution .ecgm62-dist-footer, .ecgm62-distribution .ecgm62-person, .ecgm62-distribution .ecgm62-confirm-dialog, .ecgm62-distribution .ecgm62-confirm-stats > div', PANEL],
    [ROOT_MANAGER, '.ecgm62-distribution .ecgm62-dist-stats strong, .ecgm62-distribution .ecgm62-card-header h2, .ecgm62-distribution .ecgm62-card-header h3, .ecgm62-distribution .ecgm62-confirm-dialog h2, .ecgm62-distribution .ecgm62-person strong', INK],
    [ROOT_MANAGER, '.ecgm62-distribution .ecgm62-dist-stats span, .ecgm62-distribution .ecgm62-dist-config label, .ecgm62-distribution .ecgm62-dist-config small, .ecgm62-distribution .ecgm62-help, .ecgm62-distribution .ecgm62-confirm-dialog p', MUTED],
    [ROOT_MANAGER, '.ecgm62-distribution .ecgm62-dist-footer button.ecgm62-btn:not(.ecgm62-btn--primary)', RAISED],
    [ROOT_MANAGER, '.ecgm62-distribution .ecgm62-link-btn', TEAL],
  ];

  const original = new WeakMap();
  const active = new Set();
  let scheduled = false;

  function setStyles(el, declarations) {
    if (!el || !el.isConnected) return;
    let record = original.get(el);
    if (!record) {
      record = new Map();
      original.set(el, record);
    }
    for (const [property, value] of Object.entries(declarations)) {
      if (!record.has(property)) {
        record.set(property, {
          value: el.style.getPropertyValue(property),
          priority: el.style.getPropertyPriority(property)
        });
      }
      // A mesma propriedade pode ser estilizada por regras de grupos diferentes.
      if (el.style.getPropertyValue(property) !== value || el.style.getPropertyPriority(property) !== 'important') {
        el.style.setProperty(property, value, 'important');
      }
    }
    active.add(el);
  }

  function restore() {
    for (const el of active) {
      const record = original.get(el);
      if (!record) continue;
      for (const [property, old] of record) {
        if (old.value) el.style.setProperty(property, old.value, old.priority);
        else el.style.removeProperty(property);
      }
    }
    active.clear();
  }

  function isDark() {
    const html = document.documentElement;
    return html.classList.contains('dark') && html.getAttribute('data-ec-appearance') === 'dark';
  }

  function refresh() {
    scheduled = false;
    if (!isDark()) {
      if (active.size) restore();
      return;
    }
    // Não manter referências fortes a elementos removidos nas trocas do Livewire.
    for (const el of active) {
      if (!el.isConnected) active.delete(el);
    }
    for (const [parentSelector, selector, styles] of rules) {
      for (const host of document.querySelectorAll(parentSelector)) {
        for (const node of host.querySelectorAll(selector)) setStyles(node, styles);
      }
    }
    // Certifica que o modal ativo, se estiver fora do root por portal, não fique branco.
    for (const dialog of document.querySelectorAll('.ecgm62-confirm-dialog')) {
      if (document.querySelector(ROOT_MANAGER)) setStyles(dialog, PANEL);
    }
  }

  function schedule() {
    if (scheduled) return;
    scheduled = true;
    requestAnimationFrame(refresh);
  }

  window.EcDarkRepairV69 = { version: 'A24.7', refresh: schedule, count: () => active.size };
  const htmlObserver = new MutationObserver(schedule);
  htmlObserver.observe(document.documentElement, {
    attributes: true,
    attributeFilter: ['class', 'data-ec-appearance']
  });
  const livewireObserver = new MutationObserver(schedule);
  livewireObserver.observe(document.documentElement, { childList: true, subtree: true });
  document.addEventListener('DOMContentLoaded', schedule);
  document.addEventListener('livewire:navigated', schedule);
  document.addEventListener('livewire:updated', schedule);
  window.addEventListener('ec-appearance-changed', schedule);
  window.addEventListener('pageshow', schedule);
  schedule();
})();
