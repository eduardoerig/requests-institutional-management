/**
 * Caixa do setor (tela 2b): seleção da lista, painel de detalhes e ações.
 *
 * - Clicar numa linha seleciona o pedido e mostra o painel ([data-detail]) em vez de abrir a página
 *   (Ctrl/Cmd/Shift/botão do meio continuam abrindo o link). Sem nada selecionado, ou se o selecionado
 *   saiu da lista, o primeiro é selecionado. ↑/↓ mudam de pedido; Anterior/Próximo seguem a ordem da lista.
 * - Celular (< 900px): o painel vira tela cheia ao selecionar; "Voltar" fecha.
 * - Ações usam as funções da página do pedido (script.js):
 *     requestAct('start_progress' | 'finish', id, table)  -> api/request_actions.php
 *     forwardAct('accept' | 'return', forwardId, mode)     -> api/request_forwards.php
 *   Elas confirmam quando preciso, mostram o aviso e chamam refreshAfterChange(), que troca o
 *   [data-queue] e os [data-sync] no lugar; aqui a seleção é reaplicada na lista nova.
 * - O lote ([data-pick], barra de lote, avisos em espera) fica no script.js.
 */
(function () {
    'use strict';

    const mobile = window.matchMedia('(max-width: 899.98px)');
    let selected = '';

    const queue = () => document.querySelector('[data-queue]');
    const links = () => [...document.querySelectorAll('[data-queue] [data-select]')];

    function select(key, opts = {}) {
        const q = queue();
        if (!q) return;
        const all = links();
        if (!all.length) { selected = ''; return; }
        let link = all.find(a => a.dataset.select === key);
        if (!link) link = all[0];
        selected = link.dataset.select;

        all.forEach(a => {
            const on = a === link;
            a.closest('.inbox-row').classList.toggle('is-selected', on);
            if (on) a.setAttribute('aria-current', 'true'); else a.removeAttribute('aria-current');
        });
        const i = all.indexOf(link);
        q.querySelectorAll('[data-detail]').forEach(d => {
            const on = d.dataset.detail === selected;
            d.hidden = !on;
            if (!on) return;
            d.querySelector('[data-nav="prev"]').disabled = i <= 0;
            d.querySelector('[data-nav="next"]').disabled = i >= all.length - 1;
        });

        if (opts.open && mobile.matches) {
            q.classList.add('is-detail-open');
            document.body.classList.add('inbox-detail-open');
        }
        if (opts.focus) link.focus();
        if (opts.scroll) link.closest('.inbox-row').scrollIntoView({ block: 'nearest' });
    }

    function step(dir, opts = {}) {
        const all = links();
        const i = all.findIndex(a => a.dataset.select === selected);
        const target = all[i + dir];
        if (target) select(target.dataset.select, { scroll: true, ...opts });
    }

    function closeDetail() {
        const q = queue();
        if (q) q.classList.remove('is-detail-open');
        document.body.classList.remove('inbox-detail-open');
    }

    document.addEventListener('click', (e) => {
        const link = e.target.closest('[data-queue] [data-select]');
        if (link) {
            if (e.ctrlKey || e.metaKey || e.shiftKey || e.altKey || e.button !== 0) return;
            e.preventDefault();
            select(link.dataset.select, { open: true });
            return;
        }
        const nav = e.target.closest('[data-queue] [data-nav]');
        if (nav) {
            step(nav.dataset.nav === 'next' ? 1 : -1);
            return;
        }
        if (e.target.closest('[data-queue] [data-panel-close]')) closeDetail();
    });

    // ↑/↓ na lista trocam o pedido selecionado
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && document.body.classList.contains('inbox-detail-open')) { closeDetail(); return; }
        if (e.key !== 'ArrowDown' && e.key !== 'ArrowUp') return;
        if (!e.target.closest || !e.target.closest('[data-queue] [data-list]')) return;
        e.preventDefault();
        step(e.key === 'ArrowDown' ? 1 : -1, { focus: true });
    });

    // Ações do painel
    const QUICK = {
        start: (b) => requestAct('start_progress', b.dataset.id, b.dataset.table),
        finish: (b) => requestAct('finish', b.dataset.id, b.dataset.table),
        accept: (b) => forwardAct('accept', b.dataset.forward, 'help'),
        partner: (b) => forwardAct('accept', b.dataset.forward, 'partner'),
        assume: (b) => forwardAct('accept', b.dataset.forward, 'transfer'),
        return: (b) => forwardAct('return', b.dataset.forward),
    };

    document.addEventListener('click', async (e) => {
        const btn = e.target.closest('[data-queue] [data-quick]');
        if (!btn) return;
        e.preventDefault();
        const run = QUICK[btn.dataset.quick];
        if (!run || btn.disabled) return;
        if (['accept', 'assume', 'return'].includes(btn.dataset.quick) && !btn.dataset.forward) return;
        btn.disabled = true;
        btn.setAttribute('aria-busy', 'true');
        try {
            await run(btn);
        } finally {
            // Se a lista foi redesenhada o botão já saiu da página; senão, libera de novo
            if (btn.isConnected) {
                btn.disabled = false;
                btn.removeAttribute('aria-busy');
            }
        }
    });

    // A lista é redesenhada por refreshAfterChange (ação, avisos novos): reaplica a seleção
    function bind() {
        const q = queue();
        if (!q || q.dataset.bound) return;
        q.dataset.bound = '1';
        const wasOpen = document.body.classList.contains('inbox-detail-open');
        const stillThere = links().some(a => a.dataset.select === selected);
        select(selected);
        // No celular, se o pedido aberto saiu da lista (foi para outra aba), volta para a lista
        if (wasOpen && mobile.matches) {
            if (stillThere) q.classList.add('is-detail-open'); else closeDetail();
        }
    }
    const host = queue()?.parentNode;
    if (host) new MutationObserver(bind).observe(host, { childList: true });
    const onResize = () => { if (!mobile.matches) closeDetail(); };
    if (mobile.addEventListener) mobile.addEventListener('change', onResize);
    bind();
})();
