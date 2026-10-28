/* Prospector ExportControl — V62.0
 * Confirmação acessível sem window.confirm(), preservando o POST original,
 * CSRF, rota do backend, middleware e validação review_note (<=500 caracteres).
 */
(() => {
    'use strict';
    if (window.__ecAccessV62Attached) return;
    window.__ecAccessV62Attached = true;

    const approvedForms = new WeakSet();
    let pendingForm = null;
    let pendingAction = null;
    let dialog = null;
    let lastTrigger = null;

    function getDialog() {
        const root = document.getElementById('ec-access-v60');
        if (!root) return null;
        const current = document.getElementById('ec-access-v62-modal');
        if (!current) return null;
        // Fora da tabela, para impedir que overflow:hidden recorte o modal.
        if (current.parentElement !== document.body) document.body.appendChild(current);
        if (dialog !== current) {
            dialog = current;
            dialog.addEventListener('close', () => {
                pendingForm = null;
                pendingAction = null;
                if (lastTrigger?.isConnected) lastTrigger.focus({ preventScroll: true });
                lastTrigger = null;
            });
            dialog.addEventListener('click', (event) => {
                if (event.target === dialog) dialog.close('cancel');
            });
        }
        return dialog;
    }

    document.addEventListener('submit', (event) => {
        const form = event.target;
        if (!(form instanceof HTMLFormElement) || !form.matches('#ec-access-v60 form[data-ec-access-confirm]')) return;
        if (approvedForms.has(form)) {
            approvedForms.delete(form);
            return;
        }
        const current = getDialog();
        if (!current || typeof current.showModal !== 'function') return;
        const action = form.dataset.ecAccessConfirm;
        if (!['approve', 'reject'].includes(action)) return;
        event.preventDefault();
        event.stopImmediatePropagation();
        pendingForm = form;
        pendingAction = action;
        lastTrigger = event.submitter || form.querySelector('button[type="submit"]');

        const name = (form.dataset.ecRequester || 'esta pessoa').trim().slice(0, 150);
        const isReject = action === 'reject';
        current.classList.toggle('is-rejection', isReject);
        current.querySelector('#ec-access-v62-icon').textContent = isReject ? '×' : '✓';
        current.querySelector('#ec-access-v62-title').textContent = isReject ? 'Recusar solicitação?' : 'Aprovar solicitação?';
        current.querySelector('#ec-access-v62-description').textContent = isReject
            ? `Você está prestes a recusar a solicitação de ${name}. Nenhum novo usuário será criado.`
            : `Ao confirmar, você aprovará o pedido de ${name} e o sistema emitirá um convite individual por e-mail.`;
        const reasonWrap = current.querySelector('#ec-access-v62-reason-wrap');
        const reason = current.querySelector('#ec-access-v62-reason');
        reasonWrap.hidden = !isReject;
        if (isReject) reason.value = form.querySelector('input[name="review_note"]')?.value || '';
        current.querySelector('#ec-access-v62-confirm').textContent = isReject ? 'Confirmar recusa' : 'Aprovar e enviar convite';
        current.querySelector('#ec-access-v62-confirm').disabled = false;
        current.showModal();
        (isReject ? reason : current.querySelector('[data-ec-modal-cancel]')).focus();
    }, true);

    document.addEventListener('click', (event) => {
        const cancel = event.target.closest?.('#ec-access-v62-modal [data-ec-modal-cancel]');
        if (cancel) {
            const d = getDialog();
            if (d?.open) d.close('cancel');
            return;
        }
        const ok = event.target.closest?.('#ec-access-v62-confirm');
        if (!ok || !pendingForm) return;
        const form = pendingForm;
        const action = pendingAction;
        if (action === 'reject') {
            let note = form.querySelector('input[name="review_note"]');
            if (!note) {
                note = document.createElement('input');
                note.type = 'hidden';
                note.name = 'review_note';
                form.appendChild(note);
            }
            note.value = (document.getElementById('ec-access-v62-reason')?.value || '').trim().slice(0, 500);
        }
        ok.disabled = true;
        approvedForms.add(form);
        // Fecha antes do POST, mantendo a mesma rota, verbo e CSRF.
        const d = getDialog();
        if (d?.open) d.close('confirmed');
        if (form.isConnected) {
            if (typeof form.requestSubmit === 'function') form.requestSubmit();
            else HTMLFormElement.prototype.submit.call(form);
        }
    });

    document.addEventListener('livewire:navigated', () => {
        if (!document.getElementById('ec-access-v60')) {
            pendingForm = null;
            pendingAction = null;
            if (dialog?.open) dialog.close('cancel');
            dialog?.remove();
            dialog = null;
        }
    });
})();
