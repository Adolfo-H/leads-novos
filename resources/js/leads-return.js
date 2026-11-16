/*
 * DOSSIE_RETORNO_LEADS_V60
 * Retorna à URL exata da lista, com filtros de Livewire publicados na URL.
 * Não envia dados para terceiros nem armazena informações comerciais no servidor.
 */
const ORIGIN_KEY = 'ec.prospector.leads.return.v60';
const RESTORE_KEY = 'ec.prospector.leads.restore.v60';
const MAX_AGE_MS = 2 * 60 * 60 * 1000;

function isLeadsPage(pathname) {
    return pathname.replace(/\/$/, '') === '/leads';
}

function isCompanyDossier(pathname) {
    return /^\/empresas\/[^/]+\/?$/.test(pathname);
}

function validContext(context) {
    if (!context || typeof context !== 'object') return false;
    if (typeof context.url !== 'string' || !Number.isFinite(context.savedAt)) return false;
    if (Date.now() - context.savedAt > MAX_AGE_MS || context.savedAt > Date.now() + 60000) return false;
    try {
        const candidate = new URL(context.url);
        return candidate.origin === window.location.origin && isLeadsPage(candidate.pathname);
    } catch (_) {
        return false;
    }
}

function storedContext(key) {
    try {
        const value = window.sessionStorage.getItem(key);
        const data = value ? JSON.parse(value) : null;
        return validContext(data) ? data : null;
    } catch (_) {
        return null;
    }
}

function saveLeadsPosition(target) {
    if (!isLeadsPage(window.location.pathname) || !isCompanyDossier(target.pathname)) return;
    const scrollContainer = document.querySelector('.ec-main');
    const context = {
        url: window.location.href,
        savedAt: Date.now(),
        windowY: window.scrollY,
        containerY: scrollContainer ? scrollContainer.scrollTop : 0,
    };
    try {
        window.sessionStorage.setItem(ORIGIN_KEY, JSON.stringify(context));
    } catch (_) {
        // Storage indisponível: o link de voltar ainda funciona via rota /leads.
    }
}

function restoreScroll() {
    if (!isLeadsPage(window.location.pathname)) return;
    const context = storedContext(RESTORE_KEY);
    if (!context) return;
    const previous = new URL(context.url);
    const current = new URL(window.location.href);
    if (previous.pathname !== current.pathname || previous.search !== current.search) return;

    try { window.sessionStorage.removeItem(RESTORE_KEY); } catch (_) { /* noop */ }

    const restore = () => {
        window.scrollTo({ top: Math.max(0, Number(context.windowY) || 0), behavior: 'instant' });
        const scrollContainer = document.querySelector('.ec-main');
        if (scrollContainer) scrollContainer.scrollTop = Math.max(0, Number(context.containerY) || 0);
    };

    window.requestAnimationFrame(() => window.requestAnimationFrame(restore));
    window.setTimeout(restore, 240);
    window.setTimeout(restore, 700);
}

// Captura antes de o wire:navigate interceptar a navegação para a empresa.
document.addEventListener('click', (event) => {
    if (event.defaultPrevented || event.button !== 0 || event.ctrlKey || event.metaKey || event.altKey || event.shiftKey) return;
    const anchor = event.target instanceof Element ? event.target.closest('a[href]') : null;
    if (!anchor || anchor.target === '_blank') return;

    if (anchor.matches('[data-ec-return-leads]')) {
        const context = storedContext(ORIGIN_KEY);
        if (!context || !isCompanyDossier(window.location.pathname)) return; // href fallback
        event.preventDefault();
        try { window.sessionStorage.setItem(RESTORE_KEY, JSON.stringify(context)); } catch (_) { /* noop */ }
        window.location.assign(context.url);
        return;
    }

    try {
        const target = new URL(anchor.href, window.location.href);
        if (target.origin === window.location.origin) saveLeadsPosition(target);
    } catch (_) {
        // Não é um link interno navegável.
    }
}, true);

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', restoreScroll, { once: true });
} else {
    restoreScroll();
}
document.addEventListener('livewire:navigated', restoreScroll);
