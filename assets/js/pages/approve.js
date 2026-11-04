/**
 * Aprovar pedidos (UI 2.0)
 *
 * Celular: um pedido por vez (o primeiro ainda não decidido), progresso "X de Y decididos"
 * e a barra fixa Recusar/Aprovar. "Ver todos" troca para a lista (onde fica o lote).
 * Computador: grade; cada cartão tem seus botões.
 *
 * Desfazer: a decisão NÃO vai na hora; só depois de UNDO_MS ela é enviada para
 * api/approve_request.php (postForm do script.js). Enquanto isso, no computador (e na lista do
 * celular) o próprio cartão fica apagado com o selo "Aprovado"/"Recusado", a contagem
 * "Enviando para ... em N s" e o botão Desfazer; no celular, um por vez, o cartão sai e aparece
 * o aviso fixo "Pedido nº X aprovado · Desfazer". A linha do topo conta quantos estão enviando.
 * Se a pessoa sair da página antes (troca de página, fecha a aba, o celular põe o navegador em
 * segundo plano), as decisões pendentes são enviadas na hora com navigator.sendBeacon — feito para
 * sobreviver ao fechamento da página — no 'pagehide' e no 'visibilitychange' (hidden), que é o
 * último evento confiável no celular; sem sendBeacon, fetch com keepalive. Assim nenhuma decisão
 * tomada se perde; o preço é que o Desfazer deixa de valer quando a página sai de vista.
 *
 * O que vai por sendBeacon não tem resposta: fica em "beaconed" (e no sessionStorage) até a lista ser
 * recarregada; se o pedido ainda estiver na fila, a decisão não chegou e a pessoa é avisada.
 *
 * Recusar exige motivo (mesma regra do servidor). O motivo que está sendo escrito sobrevive quando a
 * lista é redesenhada (outra decisão enviada, aviso novo): fica em "refusing"/"drafts" e volta ao cartão.
 * O lote (data-pick, barra de lote) continua no script.js.
 */
(function () {
    'use strict';

    const URL_DECIDE = 'api/approve_request.php';
    const UNDO_MS = 5000;
    const LIST_KEY = 'sisreq-approve-list';
    const mq = window.matchMedia('(max-width: 899.98px)');

    const pending = new Map();   // "tabela:id" -> { fields, kind, id, timer, until }
    const sent = new Set();      // já enviados (some da lista mesmo antes da lista recarregar)
    const beaconed = new Map();  // enviados por sendBeacon (sem resposta): "tabela:id" -> nº do pedido
    const refusing = new Set();  // cartões com o campo do motivo aberto
    const drafts = new Map();    // motivo de recusa sendo escrito: "tabela:id" -> texto
    const BEACON_KEY = 'sisreq-approve-beacon';
    const BEACON_SETTLE_MS = 1500; // espera o envio de saída chegar antes de conferir a lista
    let decided = 0;             // decisões tomadas nesta visita (inclui as pendentes)
    let lastKey = null;
    let undoTimer = null;
    let flushedWhileHidden = false;
    let ticker = null;
    const DOCK_LOCK_MS = 800;    // depois de decidir pela barra fixa, ignora toques por este tempo
    let dockLockUntil = 0;

    let listMode = false;
    try { listMode = localStorage.getItem(LIST_KEY) === '1'; } catch (e) { /* sem armazenamento */ }

    const $ = (sel, root = document) => root.querySelector(sel);
    const deck = () => $('[data-approve-deck]');
    const cards = () => { const d = deck(); return d ? [...d.querySelectorAll('[data-approve-card]')] : []; };
    const keyOf = (card) => card.dataset.key;
    const isGone = (key) => sent.has(key) || beaconed.has(key);
    const openCards = () => cards().filter(c => !pending.has(keyOf(c)) && !isGone(keyOf(c)));
    const isDeck = () => mq.matches && !listMode;
    const current = () => (isDeck() ? openCards()[0] : null) || null;

    // ---------- Desenho ----------
    function render() {
        const d = deck();
        if (!d) return;
        const all = cards();
        const deckOn = isDeck();
        // Um por vez (celular): o decidido sai e o Desfazer vai no aviso fixo; senão fica no próprio cartão
        all.forEach(c => {
            const p = pending.get(keyOf(c));
            c.hidden = isGone(keyOf(c)) || (!!p && deckOn);
            paintPending(c, deckOn ? null : p);
        });
        const open = openCards();
        d.classList.toggle('is-deck', deckOn);
        const cur = deckOn ? open[0] : null;
        all.forEach(c => c.classList.toggle('is-current', c === cur));

        // Linha do topo: "N aguardando decisão · X enviando"
        const summary = $('[data-approve-summary]');
        if (summary) {
            const waiting = Math.max(0, (parseInt(summary.dataset.total, 10) || 0) - (all.length - open.length));
            const w = $('[data-summary-waiting]', summary);
            if (w) w.textContent = `${waiting} aguardando decisão`;
            const s = $('[data-summary-sending]', summary);
            if (s) { s.hidden = !pending.size; s.textContent = pending.size ? ` · ${pending.size} enviando` : ''; }
        }

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
            dock.dataset.key = cur ? keyOf(cur) : '';
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
        if (toast) {
            toast.classList.toggle('is-low', !cur);
            if (!deckOn) hideUndo();
        }
    }

    const secsLeft = (p) => Math.max(1, Math.ceil((p.until - Date.now()) / 1000));

    // Cartão decidido, esperando o Desfazer: apagado, selo, contagem e botão (p = null volta ao normal)
    function paintPending(card, p) {
        const box = $('[data-card-undo]', card);
        const was = card.classList.contains('is-pending');
        card.classList.toggle('is-pending', !!p);
        card.classList.toggle('is-pending--refuse', !!p && p.kind === 'refuse');
        card.querySelectorAll('.approve-card__body, .approve-card__actions, .approve-card__urgent').forEach(el => { el.inert = !!p; });
        if (!box) return;
        box.hidden = !p;
        if (!p) return;
        const stamp = $('[data-card-stamp]', box);
        if (stamp) stamp.textContent = p.kind === 'approve' ? 'Aprovado' : 'Recusado';
        const dest = $('[data-card-dest]', box);
        if (dest) dest.textContent = p.kind === 'approve' ? `Enviando para ${card.dataset.sectorTo || 'o setor'}` : 'Avisando quem pediu';
        const secs = $('[data-card-secs]', box);
        if (secs) secs.textContent = `${secsLeft(p)} s`;
        if (!was) $('[data-card-undo-btn]', box)?.focus({ preventScroll: true });
    }

    // Contagem regressiva dos cartões decididos
    function tick() {
        if (!pending.size) { clearInterval(ticker); ticker = null; return; }
        if (isDeck()) return;
        for (const [key, p] of pending) {
            const card = cards().find(c => keyOf(c) === key);
            const secs = card && $('[data-card-secs]', card);
            if (secs) secs.textContent = `${secsLeft(p)} s`;
        }
    }

    function setRefusing(card, on) {
        card.classList.toggle('is-refusing', on);
        if (on) refusing.add(keyOf(card));
        else { refusing.delete(keyOf(card)); drafts.delete(keyOf(card)); }
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
        if (pending.has(key) || isGone(key)) return;

        // Sai do lote, se estava marcado
        const pick = $('[data-pick]', card);
        if (pick && pick.checked) {
            pick.checked = false;
            if (typeof updateBulkBar === 'function') updateBulkBar();
        }

        const fields = { action: 'post', id: card.dataset.id, table: card.dataset.table, value: kind === 'approve' ? 'Y' : 'N', reason };
        pending.set(key, { fields, kind, id: card.dataset.id, timer: setTimeout(() => commit(key), UNDO_MS), until: Date.now() + UNDO_MS });
        decided++;
        lastKey = key;
        if (pick) pick.disabled = true;
        if (card.classList.contains('is-refusing')) setRefusing(card, false);
        if (!ticker) ticker = setInterval(tick, 250);
        render();
        if (isDeck()) {
            showUndo(card.dataset.id, kind);
            focusNext();
        }
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

    // Sem chave: desfaz a última (aviso fixo do celular)
    function undo(key = lastKey) {
        const p = key && pending.get(key);
        if (!p) { hideUndo(); return; }
        clearTimeout(p.timer);
        pending.delete(key);
        decided = Math.max(0, decided - 1);
        if (key === lastKey) { lastKey = null; hideUndo(); }
        const card = cards().find(c => keyOf(c) === key);
        const pick = card && $('[data-pick]', card);
        if (pick) pick.disabled = false;
        render();
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
            // Sem resposta: só se sabe se chegou quando a lista for recarregada
            beaconed.set(key, p.id);
        }
        pending.clear();
        saveBeaconed();
        hideUndo();
        render();
    }

    function saveBeaconed() {
        try { sessionStorage.setItem(BEACON_KEY, JSON.stringify([...beaconed])); } catch (e) { /* sem armazenamento */ }
    }

    // Recarrega a lista e avisa dos pedidos enviados na saída que continuam esperando (o servidor recusou:
    // outra pessoa já decidiu, a sessão caiu...). Vale ao voltar para a aba e ao abrir a tela de novo.
    async function checkBeaconed() {
        let saved = [];
        try { saved = JSON.parse(sessionStorage.getItem(BEACON_KEY) || '[]'); } catch (e) { saved = []; }
        saved.forEach(([key, id]) => { if (!beaconed.has(key)) beaconed.set(key, id); });
        if (!beaconed.size) return;
        const list = [...beaconed];
        beaconed.clear();
        try { sessionStorage.removeItem(BEACON_KEY); } catch (e) { /* sem armazenamento */ }
        if (typeof refreshAfterChange === 'function') await refreshAfterChange();
        render();
        const keys = new Set(cards().map(keyOf));
        const lost = list.filter(([key]) => keys.has(key)).map(([, id]) => id);
        if (lost.length) {
            decided = Math.max(0, decided - lost.length);
            render();
            showToast(lost.length === 1
                ? `A decisão sobre o pedido nº ${lost[0]} não chegou. Ele continua esperando.`
                : `As decisões sobre os pedidos nº ${lost.join(', ')} não chegaram. Eles continuam esperando.`, 'error');
        }
    }

    // Lista redesenhada: o motivo de recusa que estava sendo escrito volta ao cartão
    function restoreRefusing() {
        cards().forEach(card => {
            const key = keyOf(card);
            if (!refusing.has(key) || pending.has(key) || isGone(key) || card.classList.contains('is-refusing')) return;
            const text = drafts.get(key) || '';
            setRefusing(card, true);
            drafts.set(key, text);
            const input = $('[data-refuse-reason]', card);
            if (input) input.value = text;
        });
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
            // Um por vez: o próximo cartão entra no mesmo lugar, então vale a mesma trava da barra fixa
            if (isDeck() && Date.now() < dockLockUntil) return;
            const card = decideBtn.closest('[data-approve-card]');
            const refusing = card.classList.contains('is-refusing');
            if (decideBtn.dataset.decide === 'refuse') decide(card, 'refuse');
            else if (refusing) setRefusing(card, false);   // "Voltar"
            else decide(card, 'approve');
            if (isDeck() && pending.has(keyOf(card))) dockLockUntil = Date.now() + DOCK_LOCK_MS;
            return;
        }
        const dockBtn = e.target.closest('[data-dock]');
        if (dockBtn) {
            // Toque duplo: o 1º toque decide e a barra passa a valer para o próximo pedido; o 2º toque
            // não pode decidir esse próximo sem a pessoa ter visto. Trava curta + a barra só age no
            // pedido que ela mostrava quando foi desenhada.
            if (Date.now() < dockLockUntil) return;
            const dock = dockBtn.closest('[data-approve-dock]');
            const card = current();
            if (!card || (dock && dock.dataset.key && dock.dataset.key !== keyOf(card))) return;
            if (dockBtn.dataset.dock === 'refuse') decide(card, 'refuse');
            else if (card.classList.contains('is-refusing')) setRefusing(card, false);
            else decide(card, 'approve');
            if (pending.has(keyOf(card))) dockLockUntil = Date.now() + DOCK_LOCK_MS;
            return;
        }
        if (e.target.closest('[data-undo]')) { undo(); return; }
        const cardUndo = e.target.closest('[data-card-undo-btn]');
        if (cardUndo) { undo(keyOf(cardUndo.closest('[data-approve-card]'))); return; }
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

    // "Selecionar todos" (script.js) não deve marcar os que já foram decididos
    document.addEventListener('change', (e) => {
        if (!e.target.matches('[data-pick-all]')) return;
        let changed = false;
        cards().forEach(c => {
            const pick = $('[data-pick]', c);
            if (pick && pick.checked && (pending.has(keyOf(c)) || isGone(keyOf(c)))) { pick.checked = false; changed = true; }
        });
        if (changed && typeof updateBulkBar === 'function') updateBulkBar();
    });

    document.addEventListener('input', (e) => {
        const input = e.target.closest('[data-refuse-reason]');
        const owner = input && input.closest('[data-approve-card]');
        if (owner) drafts.set(keyOf(owner), input.value);
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
            setTimeout(checkBeaconed, BEACON_SETTLE_MS);
        }
    });

    // A lista é redesenhada por refreshAfterChange (lote, avisos novos): reaplica o estado
    const main = deck()?.parentNode;
    if (main) {
        new MutationObserver(() => {
            const d = deck();
            if (d && !d.dataset.bound) { d.dataset.bound = '1'; render(); restoreRefusing(); }
        }).observe(main, { childList: true });
    }
    if (mq.addEventListener) mq.addEventListener('change', render);
    else if (mq.addListener) mq.addListener(render);

    const first = deck();
    if (first) first.dataset.bound = '1';
    render();
    // Decisões mandadas na saída da última visita: confere se chegaram
    setTimeout(checkBeaconed, BEACON_SETTLE_MS);
})();
