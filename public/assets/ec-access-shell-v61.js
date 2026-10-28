/*
 * Prospector ExportControl — complemento visual V61.
 * Não modifica solicitações, permissões, links, botões ou endpoints.
 * Identifica o campo de busca do header e o bloco publicitário da sidebar,
 * sem depender dos nomes das classes Tailwind do projeto.
 */
(() => {
    'use strict';

    const PAGE_ID = 'ec-access-v60';
    const KEY = 'inteligenciaqueconectaseunegocioaomundo';
    const SIDEBAR = '.ec-sidebar, [data-flux-sidebar], flux-sidebar';
    const HEADER = '.ec-topbar, header[data-flux-header]';

    function compact(text) {
        return String(text ?? '')
            .normalize('NFD')
            .replace(/[\u0300-\u036f]/g, '')
            .toLowerCase()
            .replace(/[^a-z0-9]/g, '');
    }

    function visibleText(element) {
        return String(element.innerText || element.textContent || '');
    }

    function hideSidebarAdvertisement() {
        const sidebar = document.querySelector(SIDEBAR);
        if (!sidebar) return;

        // Primeiro, nomes comuns do componente visual antigo.
        sidebar.querySelectorAll(
            '.ec-sidebar-promo, .ec-sidebar-slogan, .ec-sidebar-tagline, ' +
            '.ec-sidebar-world, .ec-sidebar-globe, .ec-sidebar-hero, ' +
            '.sidebar-promo, .sidebar-slogan, .sidebar-tagline, .sidebar-globe'
        ).forEach(node => node.setAttribute('data-ec-access-v61', 'promo'));

        // O layout pode mudar de classes. Localize o slogan pelo conteúdo;
        // suba apenas por contêineres que não contenham links de navegação.
        const elements = [...sidebar.querySelectorAll('aside, section, div, p, span, small, strong')];
        const matches = elements.filter(el => compact(visibleText(el)).includes(KEY));
        if (!matches.length) return;
        matches.sort((a, b) => compact(visibleText(a)).length - compact(visibleText(b)).length);
        let ad = matches[0];
        let parent = ad.parentElement;
        while (parent && parent !== sidebar) {
            if (parent.matches('nav, header, footer, [data-flux-sidebar-nav], [data-flux-sidebar-header]')) break;
            if (parent.querySelector('a[href], button, [role="navigation"], [data-flux-sidebar-item]')) break;
            if (compact(visibleText(parent)).length > 300) break;
            ad = parent;
            parent = ad.parentElement;
        }
        ad.setAttribute('data-ec-access-v61', 'promo');
    }

    function markGlobalSearch() {
        document.querySelectorAll(HEADER).forEach(header => {
            // Busca real: input acompanhado de um invólucro visual.
            header.querySelectorAll('input[placeholder]').forEach(input => {
                if (!compact(input.placeholder).includes('buscarempresas')) return;
                input.classList.add('ec-access-v61-search-input');
                const parent = input.parentElement;
                if (parent && parent !== header) {
                    parent.classList.add('ec-access-v61-global-search');
                }
            });

            // Busca disparada por botão, frequente no menu do Prospector.
            header.querySelectorAll('button, [role="button"], a').forEach(control => {
                const label = compact(
                    control.getAttribute('aria-label') ||
                    control.getAttribute('title') ||
                    visibleText(control)
                );
                if (label.includes('buscarempresas') && label.length < 250) {
                    control.classList.add('ec-access-v61-global-search');
                }
            });
        });
    }

    function apply() {
        // A alteração só deve afetar /acessos/solicitacoes e sua partial V60.
        if (!document.getElementById(PAGE_ID)) return;
        hideSidebarAdvertisement();
        markGlobalSearch();
    }

    apply();
    document.addEventListener('DOMContentLoaded', apply, { once: true });
    document.addEventListener('livewire:navigated', apply);
})();
