/**
 * Aprovar pedidos (UI 2.0, para quem aprova sem prática com o sistema)
 *
 * Lista + painel (pages/approve.php, components/approve_item.php):
 * - O painel mostra um pedido por vez ([data-detail]); a lista ([data-row]) mostra todos. No computador
 *   ficam lado a lado; no celular o painel é a tela principal e "Ver lista" troca para a lista.
 * - Clicar numa linha abre o pedido no painel (Ctrl/Cmd/Shift/botão do meio continuam abrindo a página
 *   do pedido); ‹ › e ↑/↓ (na lista) andam entre os pedidos ainda não decididos.
 *
 * Decidir: Aprovar vai direto; Recusar pede o motivo na janela de confirmação (showConfirm, index.php).
 * A decisão NÃO vai na hora: espera UNDO_MS com o aviso "Pedido nº X aprovado · Desfazer" e, na lista,
 * o pedido fica apagado com o selo e o próprio Desfazer. O painel passa sozinho para o próximo.
 * Se a pessoa sair da página antes (troca de página, fecha a aba, o celular põe o navegador em segundo
 * plano), as decisões pendentes são enviadas na hora com navigator.sendBeacon no 'pagehide' e no
 * 'visibilitychange' (hidden), o último evento confiável no celular; sem sendBeacon, fetch com keepalive.
 * Nenhuma decisão tomada se perde; o preço é que o Desfazer deixa de valer quando a página sai de vista.
 * O que vai por sendBeacon não tem resposta: fica em "beaconed" (e no sessionStorage) até a lista ser
 * recarregada; se o pedido ainda estiver na fila, a decisão não chegou e a pessoa é avisada.
 *
 * Setores que também participam: a linha do painel mostra a escolha; "Alterar" abre a janela <dialog>
 * do próprio painel com o seletor (components/partner_picker.php). Cancelar (ou Esc, ou tocar fora)
 * volta ao que estava; Pronto atualiza a linha e a frase "Aprovar envia...". Ao aprovar,
 * partnerFields(painel) do script.js manda a escolha. Com a janela aberta, avisos novos não redesenham
 * a lista (body.queue-hold, ver refreshForNotifications no script.js).
 *
 * refreshAfterChange (script.js) troca o [data-queue] inteiro (avisos novos, depois de enviar): a seleção,
 * as decisões esperando o Desfazer e a escolha de setores ainda não enviada voltam (MutationObserver).
 */
(function () {
    'use strict';

    const URL_DECIDE = 'api/approve_request.php';
    const UNDO_MS = 5000;
    const BEACON_KEY = 'sisreq-approve-beacon';
    const BEACON_SETTLE_MS = 1500; // espera o envio de saída chegar antes de conferir a lista
    // Depois de decidir, o próximo pedido aparece no mesmo lugar: um toque duplo não pode decidi-lo sem ser visto
    const LOCK_MS = 800;
    const mobile = window.matchMedia('(max-width: 899.98px)');

    const pending = new Map();       // "tabela:id" -> { fields, kind, id, timer }
    const sent = new Set();          // já enviados (somem mesmo antes da lista recarregar)
    const beaconed = new Map();      // enviados por sendBeacon (sem resposta): "tabela:id" -> nº do pedido
    const partnerDrafts = new Map(); // escolha de setores feita na janela e ainda não enviada: "tabela:id" -> slots
    let selected = '';
    let listOpen = false;            // celular: a lista à vista no lugar do painel
    let lastKey = null;
    let undoTimer = null;
    let lockUntil = 0;
    let flushedWhileHidden = false;
    let editing = null;              // janela de setores aberta: { panel, key, snapshot }

    const $ = (sel, root = document) => root.querySelector(sel);
    const queue = () => $('[data-queue]');
    const rows = () => { const q = queue(); return q ? [...q.querySelectorAll('[data-row]')] : []; };
    const panels = () => { const q = queue(); return q ? [...q.querySelectorAll('[data-detail]')] : []; };
    const panelFor = (key) => panels().find(p => p.dataset.detail === key) || null;
    const rowFor = (key) => rows().find(r => r.dataset.row === key) || null;
    const isGone = (key) => sent.has(key) || beaconed.has(key);
    const isOpen = (key) => !!key && !pending.has(key) && !isGone(key);
    const openKeys = () => rows().map(r => r.dataset.row).filter(isOpen);
    const plural = (n, one, many) => (n === 1 ? `1 ${one}` : `${n} ${many}`);
    const secsLeft = (p) => Math.max(1, Math.ceil((p.until - Date.now()) / 1000));
    let ticker = null;

    // Contagem regressiva das decisões esperando o Desfazer (linha da lista e aviso de baixo)
    function tick() {
        if (!pending.size) { clearInterval(ticker); ticker = null; }
        render();
    }

    // ---------- Desenho ----------
    function render() {
        const q = queue();
        if (!q) return;
        const open = openKeys();
        if (!open.includes(selected)) selected = open[0] || '';

        rows().forEach(r => {
            const key = r.dataset.row;
            const p = pending.get(key);
            r.hidden = isGone(key);
            r.classList.toggle('is-selected', key === selected);
            r.classList.toggle('is-decided', !!p);
            r.classList.toggle('is-decided--refuse', !!p && p.kind === 'refuse');
            const link = $('[data-select]', r);
            if (link) {
                if (key === selected) link.setAttribute('aria-current', 'true');
                else link.removeAttribute('aria-current');
            }
            const box = $('[data-row-pending]', r);
            if (box) {
                box.hidden = !p;
                const stamp = $('[data-row-stamp]', box);
                if (stamp) stamp.textContent = p ? (p.kind === 'approve' ? 'Aprovado' : 'Recusado') : '';
                const secs = $('[data-row-secs]', box);
                if (secs) secs.textContent = p ? `${secsLeft(p)} s` : '';
            }
        });
        // Aviso de baixo: contagem da última decisão
        const last = lastKey && pending.get(lastKey);
        const toastSecs = $('[data-undo-secs]');
        if (toastSecs) toastSecs.textContent = last ? ` Enviando em ${secsLeft(last)} s` : '';
        // Grupo sem nenhum pedido à vista some; o número conta os que ainda esperam
        q.querySelectorAll('.ap-group').forEach(g => {
            const list = [...g.querySelectorAll('[data-row]')];
            g.hidden = list.every(r => r.hidden);
            const n = $('.ap-group__title span', g);
            if (n) n.textContent = list.filter(r => isOpen(r.dataset.row)).length;
        });

        const at = open.indexOf(selected);
        panels().forEach(p => {
            const on = p.dataset.detail === selected;
            p.hidden = !on;
            if (!on) return;
            const pos = $('[data-pos]', p);
            if (pos) pos.textContent = `${at + 1} de ${open.length}`;
            const prev = $('[data-nav="prev"]', p);
            const next = $('[data-nav="next"]', p);
            if (prev) prev.disabled = at <= 0;
            if (next) next.disabled = at >= open.length - 1;
        });

        // Fila vazia nesta visita: enquanto há decisão esperando o Desfazer, o pedido ainda pode voltar
        const wait = $('[data-approve-wait]', q);
        const done = $('[data-approve-done]', q);
        if (wait) wait.hidden = open.length > 0 || pending.size === 0;
        if (done) done.hidden = open.length > 0 || pending.size > 0;

        // Celular: lista ou painel
        const showList = listOpen && mobile.matches;
        q.classList.toggle('is-list', showList);
        // Celular: busca e filtro só junto da lista (no pedido aberto, o pedido vem primeiro)
        document.body.classList.toggle('ap-list-open', showList);

        // Linha do topo: "N pedidos esperando sua decisão · 1 urgente · 2 enviando"
        const summary = $('[data-approve-summary]');
        if (summary) {
            const decidedRows = rows().filter(r => !isOpen(r.dataset.row));
            const waiting = Math.max(0, (parseInt(summary.dataset.total, 10) || 0) - decidedRows.length);
            const urgent = Math.max(0, (parseInt(summary.dataset.urgent, 10) || 0) - decidedRows.filter(r => r.classList.contains('is-urgent')).length);
            const w = $('[data-summary-waiting]', summary);
            if (w) w.textContent = plural(waiting, 'pedido esperando sua decisão', 'pedidos esperando sua decisão');
            const u = $('[data-summary-urgent]', summary);
            if (u) { u.hidden = !urgent; u.textContent = urgent ? ` · ${plural(urgent, 'urgente', 'urgentes')}` : ''; }
            const s = $('[data-summary-sending]', summary);
            if (s) { s.hidden = !pending.size; s.textContent = pending.size ? ` · ${pending.size} enviando` : ''; }
        }

        // O aviso do Desfazer fica acima dos botões presos embaixo (celular, painel à vista)
        const toast = $('[data-undo-toast]');
        if (toast) toast.classList.toggle('is-low', !(mobile.matches && !showList && selected));
    }

    function focusPanel(key, scroll) {
        const p = panelFor(key);
        if (!p) return;
        if (scroll && mobile.matches) p.scrollIntoView({ block: 'start' });
        $('.ap-detail__title', p)?.focus({ preventScroll: !scroll || mobile.matches });
    }

    function select(key, opts = {}) {
        if (!isOpen(key)) return;
        selected = key;
        render();
        if (opts.focus) focusPanel(key, true);
        if (opts.scrollRow && !mobile.matches) rowFor(key)?.scrollIntoView({ block: 'nearest' });
    }

    function step(dir) {
        const open = openKeys();
        const target = open[open.indexOf(selected) + dir];
        if (target) select(target, { scrollRow: true, focus: mobile.matches });
    }

    // ---------- Decidir, desfazer, enviar ----------
    async function decide(key, kind) {
        const panel = panelFor(key);
        if (!panel || !isOpen(key)) return;
        let reason = '';
        let partner = {};
        if (kind === 'refuse') {
            const who = panel.dataset.who || 'Quem pediu';
            const text = await showConfirm({
                title: 'Recusar este pedido?',
                message: `${who} vai receber a sua explicação.`,
                type: 'error', confirmLabel: 'Recusar pedido', cancelLabel: 'Voltar', isDanger: true,
                input: { label: 'Por que o pedido foi recusado? *', placeholder: 'Ex.: já temos esse material no almoxarifado', required: true },
            });
            if (text === false || !isOpen(key)) return;
            reason = text;
        } else {
            partner = partnerFields(panel);
        }

        const open = openKeys();
        const at = open.indexOf(key);
        const next = open[at + 1] || open[at - 1] || '';
        const fields = { action: 'post', id: panel.dataset.id, table: panel.dataset.table, value: kind === 'approve' ? 'Y' : 'N', reason, ...partner };
        pending.set(key, { fields, kind, id: panel.dataset.id, timer: setTimeout(() => commit(key), UNDO_MS), until: Date.now() + UNDO_MS });
        lastKey = key;
        if (!ticker) ticker = setInterval(tick, 250);
        lockUntil = Date.now() + LOCK_MS;
        if (next) selected = next;
        render();
        showUndo(panel.dataset.id, kind);
        if (next) focusPanel(next, true);
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
            partnerDrafts.delete(key);
        } else if (data) {
            // Não foi: o pedido volta para a fila
            showToast(`Pedido nº ${p.id}: ${data.message || 'não foi possível decidir.'}`, 'error');
        }
        render();
        maybeRefresh();
    }

    // Sem chave: desfaz a última (aviso de baixo)
    function undo(key = lastKey) {
        const p = key && pending.get(key);
        if (!p) { hideUndo(); return; }
        clearTimeout(p.timer);
        pending.delete(key);
        if (key === lastKey) { lastKey = null; hideUndo(); }
        selected = key;
        render();
        if (!(listOpen && mobile.matches)) focusPanel(key, true);
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
        const keys = new Set(rows().map(r => r.dataset.row));
        const lost = list.filter(([key]) => keys.has(key)).map(([, id]) => id);
        if (lost.length) {
            showToast(lost.length === 1
                ? `A decisão sobre o pedido nº ${lost[0]} não chegou. Ele continua esperando.`
                : `As decisões sobre os pedidos nº ${lost.join(', ')} não chegaram. Eles continuam esperando.`, 'error');
        }
    }

    // Depois de enviar tudo, atualiza lista, números e menu (sem fechar a janela de setores)
    function maybeRefresh() {
        if (pending.size || editing) return;
        if (typeof refreshAfterChange === 'function') refreshAfterChange();
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

    // ---------- Setores que também participam ----------
    const slotsOf = (panel) => [...panel.querySelectorAll('[data-partner-slot]')];
    const readSlots = (panel) => slotsOf(panel).map(slot => ({
        area: $('[data-partner-area]', slot).value,
        due: $('[data-partner-due]', slot).value,
        note: $('[data-partner-note]', slot).value,
        on: !slot.hidden,
    }));

    function writeSlots(panel, state) {
        slotsOf(panel).forEach((slot, i) => {
            const s = state[i];
            if (!s) return;
            slot.hidden = !s.on;
            $('[data-partner-area]', slot).value = s.area;
            $('[data-partner-due]', slot).value = s.due;
            $('[data-partner-note]', slot).value = s.note;
        });
        const box = $('[data-partner-picker]', panel);
        if (box) syncPartnerPicker(box);
    }

    // Linha "Também participam" e a frase "Aprovar envia..." conforme a escolha atual
    function paintPartners(panel) {
        const line = $('[data-partner-line]', panel);
        if (!line) return;
        const names = partnerNames(panel);
        const chosen = slotsOf(panel).filter(s => !s.hidden).map(s => $('[data-partner-area]', s).value).filter(Boolean);
        const suggested = (line.dataset.suggested || '').split(',').filter(Boolean);
        const asSuggested = chosen.length > 0 && chosen.length === suggested.length && chosen.every(v => suggested.includes(v));
        const label = $('[data-partner-label]', line);
        if (label) label.textContent = names ? 'Também participam' : 'Outros setores';
        const namesEl = $('[data-partner-names]', line);
        if (namesEl) { namesEl.textContent = names; namesEl.hidden = !names; }
        const origin = $('[data-partner-origin]', line);
        if (origin) origin.hidden = !asSuggested;
        const edit = $('[data-partner-edit]', line);
        if (edit) edit.innerHTML = names ? 'Alterar' : '<i class="fa-solid fa-plus" aria-hidden="true"></i> Chamar outro setor para ajudar';
        const hint = $('[data-approve-hint]', panel);
        if (hint) hint.textContent = `Aprovar envia o pedido para ${panel.dataset.sectorTo || 'o setor'} começar${names ? ` e avisa ${names}` : ''}.`;
    }

    // Prazo antes de hoje o servidor recusa: avisa ainda na janela
    function partnerProblem(panel) {
        for (const slot of slotsOf(panel)) {
            if (slot.hidden || !$('[data-partner-area]', slot).value) continue;
            const due = $('[data-partner-due]', slot);
            if (due.value && due.min && due.value < due.min) {
                due.focus();
                return 'O prazo de cada setor não pode ser antes de hoje.';
            }
        }
        return '';
    }

    function releaseHold() {
        document.body.classList.remove('queue-hold');
        // Avisos que chegaram com a janela aberta: agora a lista pode redesenhar
        if (typeof queuePending !== 'undefined' && queuePending > 0 && !pending.size) refreshAfterChange();
    }

    function openPartners(panel) {
        const dialog = panel && $('[data-partner-dialog]', panel);
        if (!dialog || dialog.open) return;
        editing = { panel, key: panel.dataset.detail, snapshot: readSlots(panel) };
        const err = $('[data-partner-error]', dialog);
        if (err) err.hidden = true;
        document.body.classList.add('queue-hold');
        if (typeof dialog.showModal === 'function') dialog.showModal();
        else dialog.setAttribute('open', '');
        $('[data-partner-area]', dialog)?.focus();
    }

    function closePartners(save) {
        if (!editing) return;
        const { panel, key, snapshot } = editing;
        const dialog = $('[data-partner-dialog]', panel);
        if (!panel.isConnected) { editing = null; releaseHold(); return; }
        if (save) {
            const problem = partnerProblem(panel);
            const err = dialog && $('[data-partner-error]', dialog);
            if (problem) {
                if (err) { err.textContent = problem; err.hidden = false; }
                return;
            }
            partnerDrafts.set(key, readSlots(panel));
        } else {
            writeSlots(panel, snapshot);
        }
        editing = null;
        if (dialog) {
            if (typeof dialog.close === 'function' && dialog.open) dialog.close();
            else dialog.removeAttribute('open');
        }
        paintPartners(panel);
        $('[data-partner-edit]', panel)?.focus();
        releaseHold();
    }

    // Lista redesenhada: a escolha de setores ainda não enviada volta ao painel
    function restorePartners() {
        partnerDrafts.forEach((state, key) => {
            const panel = panelFor(key);
            if (!panel) return;
            writeSlots(panel, state);
            paintPartners(panel);
        });
    }

    // ---------- Eventos ----------
    document.addEventListener('click', (e) => {
        const t = e.target;
        // Janela de setores: tocar fora do cartão, Cancelar ou Pronto
        if (t.matches && t.matches('[data-partner-dialog]')) { closePartners(false); return; }
        if (t.closest('[data-partner-cancel]')) { closePartners(false); return; }
        if (t.closest('[data-partner-done]')) { closePartners(true); return; }
        const edit = t.closest('[data-queue] [data-partner-edit]');
        if (edit) { openPartners(edit.closest('[data-detail]')); return; }

        const link = t.closest('[data-queue] [data-select]');
        if (link) {
            if (e.ctrlKey || e.metaKey || e.shiftKey || e.altKey || e.button !== 0) return;
            e.preventDefault();
            const key = link.dataset.select;
            if (!isOpen(key)) return; // decidido: a linha só oferece o Desfazer
            listOpen = false;
            select(key, { focus: true });
            return;
        }
        const nav = t.closest('[data-queue] [data-nav]');
        if (nav) { step(nav.dataset.nav === 'next' ? 1 : -1); return; }
        if (t.closest('[data-queue] [data-show-list]')) {
            listOpen = true;
            render();
            // Topo da página: a busca aparece junto da lista
            window.scrollTo(0, 0);
            rowFor(selected)?.querySelector('[data-select]')?.focus({ preventScroll: true });
            return;
        }
        if (t.closest('[data-queue] [data-show-panel]')) {
            listOpen = false;
            render();
            focusPanel(selected, true);
            return;
        }
        const decideBtn = t.closest('[data-queue] [data-decide]');
        if (decideBtn) {
            if (Date.now() < lockUntil) return;
            const panel = decideBtn.closest('[data-detail]');
            if (panel) decide(panel.dataset.detail, decideBtn.dataset.decide);
            return;
        }
        if (t.closest('[data-undo]')) { undo(); return; }
        const rowUndo = t.closest('[data-row-undo]');
        if (rowUndo) { undo(rowUndo.closest('[data-row]').dataset.row); }
    });

    // Esc na janela de setores = Cancelar ('cancel' não sobe; a captura no document pega)
    document.addEventListener('cancel', (e) => {
        if (e.target.matches && e.target.matches('[data-partner-dialog]')) {
            e.preventDefault();
            closePartners(false);
        }
    }, true);

    document.addEventListener('keydown', (e) => {
        // Celular: Esc na lista volta ao pedido
        if (e.key === 'Escape' && listOpen && mobile.matches && !editing) {
            listOpen = false;
            render();
            focusPanel(selected, true);
            return;
        }
        // ↑/↓ na lista trocam o pedido aberto
        if (e.key !== 'ArrowDown' && e.key !== 'ArrowUp') return;
        if (!e.target.closest || !e.target.closest('[data-queue] [data-list]')) return;
        e.preventDefault();
        step(e.key === 'ArrowDown' ? 1 : -1);
        rowFor(selected)?.querySelector('[data-select]')?.focus();
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

    // A lista é redesenhada por refreshAfterChange: reaplica seleção, decisões e escolhas
    function bind() {
        const q = queue();
        if (!q || q.dataset.bound) return;
        q.dataset.bound = '1';
        restorePartners();
        render();
    }
    const host = queue()?.parentNode;
    if (host) new MutationObserver(bind).observe(host, { childList: true });
    if (mobile.addEventListener) mobile.addEventListener('change', render);
    else if (mobile.addListener) mobile.addListener(render);

    bind();
    // Decisões mandadas na saída da última visita: confere se chegaram
    setTimeout(checkBeaconed, BEACON_SETTLE_MS);
})();
