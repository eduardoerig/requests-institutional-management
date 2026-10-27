/**
 * Caixa do setor (UI 2.0): ações rápidas nos cartões.
 * Usa as mesmas funções da página do pedido (script.js):
 *   requestAct('start_progress' | 'finish', id, table)  -> api/request_actions.php
 *   forwardAct('accept' | 'return', forwardId, mode)     -> api/request_forwards.php
 * Elas confirmam quando preciso, mostram o aviso e chamam refreshAfterChange(),
 * que redesenha [data-queue] e os [data-sync] (abas e números do topo) no lugar.
 * A seleção em lote ([data-pick], barra de lote) continua toda no script.js.
 */
(function () {
    'use strict';

    const QUICK = {
        start: (b) => requestAct('start_progress', b.dataset.id, b.dataset.table),
        finish: (b) => requestAct('finish', b.dataset.id, b.dataset.table),
        accept: (b) => forwardAct('accept', b.dataset.forward, 'help'),
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
})();
