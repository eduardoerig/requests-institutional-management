/**
 * Aprovar pedidos (UI 2.0)
 *
 * Celular: um pedido por vez (o primeiro ainda não decidido), progresso "X de Y decididos"
 * e a barra fixa Recusar/Aprovar. "Ver todos" troca para a lista (onde fica o lote).
 * Computador: grade; cada cartão tem seus botões.
 *
 * Desfazer: a decisão NÃO vai na hora. O cartão some, aparece "Pedido nº X aprovado · Desfazer"
 * e só depois de UNDO_MS a decisão é enviada para api/approve_request.php (postForm do script.js).
 * Se a pessoa sair da página antes (troca de página, fecha a aba, o celular põe o navegador em
 * segundo plano), as decisões pendentes são enviadas na hora com navigator.sendBeacon — feito para
 * sobreviver ao fechamento da página — no 'pagehide' e no 'visibilitychange' (hidden), que é o
 * último evento confiável no celular; sem sendBeacon, fetch com keepalive. Assim nenhuma decisão
 * tomada se perde; o preço é que o Desfazer deixa de valer quando a página sai de vista.
 *
 * Recusar exige motivo (mesma regra do servidor). O lote (data-pick, barra de lote) continua no script.js.
 */
(function () {
    'use strict';

    const URL_DECIDE = 'api/approve_request.php';
    const UNDO_MS = 5000;
    const LIST_KEY = 'sisreq-approve-list';
    const mq = window.matchMedia('(max-width: 899.98px)');

    const pending = new Map();   // "tabela:id" -> { fields, kind, id, timer }
    const sent = new Set();      // já enviados (some da lista mesmo antes da lista recarregar)
    let decided = 0;             // decisões tomadas nesta visita (inclui as pendentes)
    let lastKey = null;
    let undoTimer = null;
    let flushedWhileHidden = false;

    let listMode = false;
    try { listMode = localStorage.getItem(LIST_KEY) === '1'; } catch (e) { /* sem armazenamento */ }

    const $ = (sel, root = document) => root.querySelector(sel);
    const deck = () => $('[data-approve-deck]');
    const cards = () => { const d = deck(); return d ? [...d.querySelectorAll('[data-approve-card]')] : []; };
    const keyOf = (card) => card.dataset.key;
    const openCards = () => cards().filter(c => !pending.has(keyOf(c)) && !sent.has(keyOf(c)));
    const isDeck = () => mq.matches && !listMode;
    const current = () => (isDeck() ? openCards()[0] : null) || null;

    // ---------- Desenho ----------
    function render() {
        const d = deck();
        if (!d) return;
        const all = cards();
        all.forEach(c => { c.hidden = pending.has(keyOf(c)) || sent.has(keyOf(c)); });
        const open = openCards();
        const deckOn = isDeck();
        d.classList.toggle('is-deck', deckOn);
        const cur = deckOn ? open[0] : null;
        all.forEach(c => c.classList.toggle('is-current', c === cur));

        // Progresso
        const total = decided + open.length;
        const text = $('[data-progress-text]');
        if (text) text.textContent = `${decided} de ${total} decididos`;
        const bar = $('[data-progress-bar]');
        if (bar) bar.style.width = total ? Math.round((decided / total) * 100) + '%' : '0%';
        const toggle = $('[data-deck-toggle]');
        if (toggle) {
            toggle.textContent = listMode ? 'Um por vez' : 'Ver todos';
            toggle.setAttribute('aria-pressed', listMode ? 'true' : 'false');
            toggle.hidden = open.length < 2 && !listMode;
        }

        // Próximo / fim da fila
        const hint = $('[data-next-hint]', d);
        if (hint) hint.textContent = deckOn && open.length ? (open.length > 1 ? `Depois deste: mais ${open.length - 1}` : 'Este é o último') : '';
        const done = $('[data-approve-done]', d);
        if (done) done.hidden = open.length > 0 || all.length === 0;
        const pickAll = $('.approve-pickall', d);
        if (pickAll) pickAll.hidden = open.length === 0;

        // Barra fixa (celular, um por vez)
        const dock = $('[data-approve-dock]');
        if (dock) {
            dock.hidden = !cur;
            const refusing = !!(cur && cur.classList.contains('is-refusing'));
            dock.classList.toggle('is-refusing', refusing);
            const r = $('[data-dock="refuse"]', dock);
            const a = $('[data-dock="approve"]', dock);
            if (r) r.textContent = refusing ? 'Confirmar recusa' : 'Recusar';
            if (a) a.textContent = refusing ? 'Voltar' : 'Aprovar';
            if (cur) {
                r?.setAttribute('aria-label', (refusing ? 'Confirmar recusa do pedido nº ' : 'Recusar pedido nº ') + cur.dataset.id);
                a?.setAttribute('aria-label', refusing ? 'Voltar sem recusar' : 'Aprovar pedido nº ' + cur.dataset.id);
            }
        }
        const toast = $('[data-undo-toast]');
        if (toast) toast.classList.toggle('is-low', !cur);
    }

    function setRefusing(card, on) {
        card.classList.toggle('is-refusing', on);
        const box = $('[data-refuse-box]', card);
        if (box) box.hidden = !on;
        const err = $('[data-refuse-error]', card);
        if (err) err.hidden = true;
        const r = $('[data-decide="refuse"]', card);
        const a = $('[data-decide="approve"]', card);
        if (r) {
            r.textContent = on ? 'Confirmar recusa' : 'Recusar';
            r.setAttribute('aria-label', (on ? 'Confirmar recusa do pedido nº ' : 'Recusar pedido nº ') + card.dataset.id);
        }
        if (a) {
            a.textContent = on ? 'Voltar' : 'Aprovar';
            a.setAttribute('aria-label', on ? 'Voltar sem recusar' : 'Aprovar pedido nº ' + card.dataset.id);
        }
        if (on) $('[data-refuse-reason]', card)?.focus();
        render();
    }

    // ---------- Decidir, desfazer, enviar ----------
    function decide(card, kind) {
        let reason = '';
        if (kind === 'refuse') {
            if (!card.classList.contains('is-refusing')) { setRefusing(card, true); return; }
            const input = $('[data-refuse-reason]', card);
            reason = (input?.value || '').trim();
            if (!reason) {
                const err = $('[data-refuse-error]', card);
                if (err) err.hidden = false;
                input?.focus();
                return;
            }
        }
        const key = keyOf(card);
        if (pending.has(key) || sent.has(key)) return;

        // Sai do lote, se estava marcado
        const pick = $('[data-pick]', card);
        if (pick && pick.checked) {
            pick.checked = false;
            if (typeof updateBulkBar === 'function') updateBulkBar();
        }

        const fields = { action: 'post', id: card.dataset.id, table: card.dataset.table, value: kind === 'approve' ? 'Y' : 'N', reason };
        pending.set(key, { fields, kind, id: card.dataset.id, timer: setTimeout(() => commit(key), UNDO_MS) });
        decided++;
        lastKey = key;
        if (card.classList.contains('is-refusing')) setRefusing(card, false);
        render();
        showUndo(card.dataset.id, kind);
        focusNext();
    }

    async function commit(key) {
        const p = pending.get(key);
        if (!p) return;
        clearTimeout(p.timer);
        pending.delete(key);
        if (key === lastKey) hideUndo();
        const data = await postForm(URL_DECIDE, p.fields);
        if (data && data.success) {
            sent.add(key);
        } else {
            // Não foi: o pedido volta para a fila
            decided = Math.max(0, decided - 1);
            if (data) showToast(`Pedido nº ${p.id}: ${data.message || 'não foi possível decidir.'}`, 'error');
        }
        render();
        maybeRefresh();
    }

    function undo() {
        const key = lastKey;
        const p = key && pending.get(key);
        if (!p) { hideUndo(); return; }
        clearTimeout(p.timer);
        pending.delete(key);
        decided = Math.max(0, decided - 1);
        lastKey = null;
        hideUndo();
        render();
        const card = cards().find(c => keyOf(c) === key);
        if (card) {
            card.scrollIntoView({ block: 'nearest' });
            $('.approve-card__title', card)?.focus();
        }
        showToast(`Decisão desfeita. O pedido nº ${p.id} continua esperando.`, 'info');
    }

    // Envia tudo que está pendente agora, de um jeito que sobrevive à saída da página
    function flushAll() {
        if (!pending.size) return;
        for (const [key, p] of pending) {
            clearTimeout(p.timer);
            const body = new URLSearchParams(p.fields);
            if (window.CSRF_TOKEN) body.append('csrf_token', window.CSRF_TOKEN);
            let queued = false;
            try { queued = !!(navigator.sendBeacon && navigator.sendBeacon(URL_DECIDE, body)); } catch (e) { queued = false; }
            if (!queued) {
                try { fetch(URL_DECIDE, { method: 'POST', body, keepalive: true, credentials: 'same-origin' }); } catch (e) { /* nada mais a fazer */ }
            }
            sent.add(key);
        }
        pending.clear();
        hideUndo();
        render();
    }

    // Depois de enviar tudo, atualiza lista, contadores e menu (sem atrapalhar uma seleção em lote)
    function maybeRefresh() {
        if (pending.size || document.body.classList.contains('has-bulk')) return;
        if (typeof refreshAfterChange === 'function') refreshAfterChange();
    }

    function focusNext() {
        const next = isDeck() ? current() : null;
        if (next) {
            next.scrollIntoView({ block: 'nearest' });
            $('.approve-card__title', next)?.focus({ preventScroll: true });
        }
    }

    // ---------- Aviso com Desfazer ----------
    function showUndo(id, kind) {
        const toast = $('[data-undo-toast]');
        if (!toast) return;
        $('[data-undo-text]', toast).textContent = `Pedido nº ${id} ${kind === 'approve' ? 'aprovado' : 'recusado'}.`;
        toast.hidden = false;
        clearTimeout(undoTimer);
        undoTimer = setTimeout(hideUndo, UNDO_MS);
    }

    function hideUndo() {
        clearTimeout(undoTimer);
        const toast = $('[data-undo-toast]');
        if (toast) toast.hidden = true;
    }

    // ---------- Eventos ----------
    document.addEventListener('click', (e) => {
        const decideBtn = e.target.closest('[data-approve-card] [data-decide]');
        if (decideBtn) {
            const card = decideBtn.closest('[data-approve-card]');
            const refusing = card.classList.contains('is-refusing');
            if (decideBtn.dataset.decide === 'refuse') decide(card, 'refuse');
            else if (refusing) setRefusing(card, false);   // "Voltar"
            else decide(card, 'approve');
            return;
        }
        const dockBtn = e.target.closest('[data-dock]');
        if (dockBtn) {
            const card = current();
            if (!card) return;
            if (dockBtn.dataset.dock === 'refuse') decide(card, 'refuse');
            else if (card.classList.contains('is-refusing')) setRefusing(card, false);
            else decide(card, 'approve');
            return;
        }
        if (e.target.closest('[data-undo]')) { undo(); return; }
        if (e.target.closest('[data-deck-toggle]')) {
            listMode = !listMode;
            try { localStorage.setItem(LIST_KEY, listMode ? '1' : '0'); } catch (err) { /* só não lembra */ }
            render();
        }
    });

    document.addEventListener('keydown', (e) => {
        const input = e.target.closest('[data-refuse-reason]');
        if (!input) return;
        const card = input.closest('[data-approve-card]');
        if (e.key === 'Enter') { e.preventDefault(); decide(card, 'refuse'); }
        else if (e.key === 'Escape') { e.preventDefault(); setRefusing(card, false); }
    });

    document.addEventListener('input', (e) => {
        const input = e.target.closest('[data-refuse-reason]');
        if (input && input.value.trim()) {
            const err = $('[data-refuse-error]', input.closest('[data-approve-card]'));
            if (err) err.hidden = true;
        }
    });

    // Saída da página: envia o que está esperando o Desfazer
    window.addEventListener('pagehide', flushAll);
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'hidden') {
            if (pending.size) { flushAll(); flushedWhileHidden = true; }
        } else if (flushedWhileHidden) {
            flushedWhileHidden = false;
            maybeRefresh();
        }
    });

    // A lista é redesenhada por refreshAfterChange (lote, avisos novos): reaplica o estado
    const main = deck()?.parentNode;
    if (main) {
        new MutationObserver(() => {
            const d = deck();
            if (d && !d.dataset.bound) { d.dataset.bound = '1'; render(); }
        }).observe(main, { childList: true });
    }
    if (mq.addEventListener) mq.addEventListener('change', render);
    else if (mq.addListener) mq.addListener(render);

    const first = deck();
    if (first) first.dataset.bound = '1';
    render();
})();
