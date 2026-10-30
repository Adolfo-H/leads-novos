/* A24.14: camada de resiliência aos estilos legados com !important.
 * Aplica apenas contraste às classes reais no modo Escuro. Não altera eventos
 * Livewire, URLs, estados de filtros, envio de e-mails nem dados. */
(function () {
  'use strict';
  if (window.ProspectorDarkA2415) { window.ProspectorDarkA2415.refresh(); return; }
  const cache = new WeakMap();
  const active = new Set();
  let raf = 0;
  const P = {
    panel: {'background-color':'#181d22','background-image':'none','border-color':'#40505a','color':'#f2f7f9'},
    raised: {'background-color':'#22292f','background-image':'none','border-color':'#40505a','color':'#f2f7f9'},
    selection: {'background-color':'#193e35','background-image':'none','border-color':'#4b9681','color':'#e9fff8','-webkit-text-fill-color':'#e9fff8'},
    ink: {'color':'#f2f7f9','-webkit-text-fill-color':'#f2f7f9','opacity':'1','text-shadow':'none'},
    muted: {'color':'#c3d2da','-webkit-text-fill-color':'#c3d2da','opacity':'1'},
    mint: {'color':'#aef7e6','-webkit-text-fill-color':'#aef7e6','opacity':'1'},
    clear: {'background-color':'transparent','background-image':'none','color':'#aef7e6','-webkit-text-fill-color':'#aef7e6','border-color':'transparent','box-shadow':'none','opacity':'1'},
    filter: {'background-color':'#18392f','background-image':'none','color':'#e9fff8','-webkit-text-fill-color':'#e9fff8','border-color':'#4b9681'},
    filterButton: {'background-color':'#265c4b','background-image':'none','color':'#fafffd','-webkit-text-fill-color':'#fafffd','border-color':'#68b99f'},
    control: {'background-color':'#20272e','background-image':'none','color':'#f2f7f9','-webkit-text-fill-color':'#f2f7f9','border-color':'#52616b','color-scheme':'dark'},
    avatar: {'background-color':'#20594a','background-image':'none','color':'#e6fff7','-webkit-text-fill-color':'#e6fff7','border-color':'#5baa96'},
    activeTab: {'background-color':'#193e35','background-image':'none','color':'#e9fff8','-webkit-text-fill-color':'#e9fff8','border-color':'#509986'},
    neutralTab: {'background-color':'#242b31','background-image':'none','color':'#c3d2da','-webkit-text-fill-color':'#c3d2da','border-color':'#40505a'}
  };
  const groups = [
    {
      root: '.ec-page-shell.leads-rf.lf36-leads',
      entries: [
        ['.lv15-selected-filter', 'filter'],
        ['.lv15-selected-filter :is(span,strong,small,p)', 'ink'],
        ['.lv15-selected-filter button', 'filterButton']
      ]
    },
    {
      root: '.ecgm-page.ecgm-v2.ecgm61.ecgm62',
      entries: [
        ['.ecgm62-load-card a.ecgm62-person-link', 'clear'],
        ['.ecgm62-load-card a.ecgm62-person-link :is(span,strong)', 'mint']
      ]
    },
    {
      root: '.ecc-page.ecc3-page.ecc66-page',
      entries: [
        ['.c66-selected-actions, .ecc3-bulk.has-selection', 'selection'],
        ['.c66-selected-actions :is(span,strong,small), .ecc3-bulk.has-selection :is(span,strong,small)', 'ink'],
        ['aside.ecc3-filters.is-open, .ecc3-filter-scroll', 'panel'],
        ['aside.ecc3-filters.is-open .ecc3-filter-group, aside.ecc3-filters.is-open .ecc3-filter-group summary, aside.ecc3-filters.is-open .ecc3-filter-list', 'raised'],
        ['.ecc3-inspector, .ecc3-inspector-title, .ecc3-profile-card, .ecc3-inspector-tabs, .ecc3-inspector-scroll', 'panel'],
        ['.ecc3-inspector .ecc3-info-card, .ecc3-inspector .ecc3-receipt', 'raised'],
        ['.ecc3-inspector :is(h2,h3,strong,.ecc3-profile-name h3,.ecc3-card-title h3)', 'ink'],
        ['.ecc3-inspector :is(p,small,.ecc3-profile-name p,.ecc3-profile-name small,.ecc3-info-line)', 'muted'],
        ['.ecc3-inspector :is(.ecc3-avatar,.ecc3-avatar-large)', 'avatar'],
        ['.ecc3-inspector-tabs button', 'neutralTab'],
        ['.ecc3-inspector-tabs button.is-active', 'activeTab']
      ]
    },
    {
      root: '.ecm2.ec21-page, .ecm6-page.ec21-page',
      entries: [
        ['.ecm6-nav,.ecm7-nav,.ecm2-stat,.ecm2-list-panel,.ecm2-main,.ecm2-context-card,.ecm2-detail,.ecm2-progress-section,.ecm2-information,.ec22-card,.ec22-settings-intro,.ec21-panel,.ec21-inbox-items,.ec69-reply-filter-card,.ec77-reply-health,.ec73-recipients', 'panel'],
        ['.ecm2-campaign-list,.ecm2-campaign,.ecm2-table,.ecm2-detail-stats>div,.ec22-review-grid>div,.ec22-metric-grid>div,.ec22-contact-table,.ec22-contact-table :is(thead,tbody,tr,td,th),.ec73-recipient-row,.ec22-email-paper,.ec22-email-headers,.ec22-email-body,.ec21-inbox-item,.ec21-message-sheet,.ec21-history,.ec22-real-limits,.ec22-current-policy,.ec22-info-note,.ec22-warning,.ec22-success', 'raised'],
        ['.ecm2-stat strong,.ecm2-panel-header h2,.ecm2-detail-header h2,.ec22-card-head h2,.ec22-card-head h3,.ec22-settings-intro h2,.ec22-review-card h3,.ec22-metric-grid strong,.ec22-review-grid strong,.ec73-recipients-heading h3,.ec21-module-header h1,.ec21-panel-heading h3,.ec21-inbox-copy strong,.ec69-reply-filter-card h2', 'ink'],
        ['.ecm2-stat small,.ecm2-panel-header p,.ec22-card-head p,.ec22-review-grid span,.ec22-metric-grid span,.ec21-panel-heading p,.ec21-inbox-copy small,.ec22-settings-intro p', 'muted'],
        ['input:not([type="checkbox"]):not([type="radio"]),select,textarea', 'control'],
        ['.ecm6-nav a,.ecm7-nav a,.ec22-tabs button,.ec22-duration-grid button,.ecm2-tabs button,.ec69-reply-categories button', 'neutralTab'],
        ['.ecm6-nav a.is-active,.ecm7-nav a.is-active,.ec22-tabs button.is-active,.ec22-duration-grid button.is-active,.ecm2-tabs button.is-active,.ec69-reply-categories button.is-active', 'activeTab'],
        // A24.15: Home e detalhe Figma sao ec68-* / ec76-* (nao ecM2-stat).
        ['.ec68-stat,.ec68-card,.ec68-campaigns,.ec68-starter-grid > *,.ec68-table-scroll,.ec76-metric,.ec76-progress-card,.ec76-metadata,.ecm2-overview-body,.ec76-breakdown,.ecm2-state-grid > div', 'panel'],
        ['.ec68-table,.ec68-table :is(thead,tbody,tr,td,th),.ec68-search,.ec68-table-tools,.ec22-current-policy,.ec22-current-policy dl > div,.ec22-tip,.ec22-real-limits dl > div,.ec22-test-block,.ec22-pick,.ec22-contact-table,.ec22-contact-table :is(thead,tbody,tr,td,th),.ec21-history,.ec21-reader-heading,.ec76-breakdown > summary,.ec76-progress-heading', 'raised'],
        ['.ec68-stat :is(strong,h3),.ec68-card :is(h2,h3,strong),.ec68-table :is(th,td),.ec76-metric strong,.ec76-progress-card strong,.ecm2-overview-body h3,.ec22-current-policy strong,.ec22-current-policy dd,.ec22-tip strong,.ec22-review-card strong,.ec22-test-block strong,.ec21-history strong,.ec21-reader-heading :is(strong,h2,h3),.ec22-real-limits h3', 'ink'],
        ['.ec68-stat :is(span,small),.ec68-card :is(p,small),.ec76-metric small,.ec76-progress-card small,.ec22-current-policy :is(p,dt),.ec22-tip p,.ec22-review-card p,.ec21-history :is(p,small),.ec21-reader-heading p', 'muted']
      ]
    }
  ];

  function isDark() {
    const html = document.documentElement;
    return html.classList.contains('dark') &&
      html.dataset.ecAppearance !== 'light' && html.dataset.ecAppearance !== 'system';
  }
  function force(el, changes) {
    if (!el || !el.isConnected) return;
    let props = cache.get(el);
    if (!props) { props = new Map(); cache.set(el, props); }
    for (const [key, value] of Object.entries(changes)) {
      if (!props.has(key)) props.set(key, {
        value: el.style.getPropertyValue(key),
        priority: el.style.getPropertyPriority(key)
      });
      if (el.style.getPropertyValue(key) !== value || el.style.getPropertyPriority(key) !== 'important') {
        el.style.setProperty(key, value, 'important');
      }
    }
    active.add(el);
  }
  function restore() {
    for (const el of active) {
      if (!el.isConnected) { cache.delete(el); continue; }
      const original = cache.get(el);
      if (!original) continue;
      for (const [key, previous] of original) {
        if (previous.value) el.style.setProperty(key, previous.value, previous.priority);
        else el.style.removeProperty(key);
      }
      cache.delete(el);
    }
    active.clear();
  }
  function paint() {
    raf=0;
    if (!isDark()) { if (active.size) restore(); return; }
    for (const node of active) {
      if (!node.isConnected) { active.delete(node); cache.delete(node); }
    }
    for (const group of groups) {
      let roots=[];
      try { roots=document.querySelectorAll(group.root); } catch (e) { continue; }
      for (const root of roots) {
        for (const [selector,style] of group.entries) {
          let found=[];
          try { found=root.querySelectorAll(selector); } catch (e) { continue; }
          for (const node of found) force(node, P[style]);
        }
      }
    }
    // Preserva o estado visual dos destinatarios marcados apos o Livewire.
    for (const row of document.querySelectorAll('.ecm2.ec21-page .ec22-contact-table tbody tr.is-picked, .ecm2.ec21-page .ec22-contact-table tbody tr:has(input[type="checkbox"]:checked)')) {
      force(row, P.selection);
      for (const cell of row.querySelectorAll('td')) force(cell, {'background-color':'transparent','background-image':'none'});
    }
  }
  function refresh() { if (!raf) raf=requestAnimationFrame(paint); }
  const observer=new MutationObserver(refresh);
  function start() {
    observer.observe(document.documentElement, {
      subtree:true, childList:true, attributes:true,
      attributeFilter:['class','checked','aria-selected','aria-pressed','data-ec-appearance']
    });
    refresh();
  }
  document.addEventListener('livewire:navigated',refresh);
  document.addEventListener('livewire:updated',refresh);
  document.addEventListener('change',refresh,true);
  document.addEventListener('click',refresh,true);
  window.addEventListener('ec-appearance-changed',refresh);
  window.addEventListener('pageshow',refresh);
  document.addEventListener('DOMContentLoaded',refresh);
  window.ProspectorDarkA2415={refresh,version:'A24.15',check:()=>{paint();return{dark:isDark(),painted:active.size};}};
  start();
})();
