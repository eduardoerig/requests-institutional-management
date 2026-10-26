/**
 * Página do pedido (UI 2.0): abas do celular (Conversa / Detalhes / Envolvidos),
 * altura da barra fixa de baixo e rolagem até a mensagem nova depois de enviar.
 *
 * O assets/js/script.js pode trocar o .request-view inteiro (reloadRequestView) ou só o
 * feed (reloadChat); por isso tudo aqui usa delegação e um MutationObserver que reaplica
 * a aba escolhida no HTML novo.
 */
(function () {
    'use strict';

    var TABS = ['chat', 'info', 'people'];
    var current = 'chat';
    var justSent = false;
    var page = document.querySelector('.detail-page');
    if (!page) return;

    function applyTab(view) {
        if (!view) return;
        if (view.getAttribute('data-tab') !== current) view.setAttribute('data-tab', current);
        view.querySelectorAll('[data-rv-tab]').forEach(function (btn) {
            var on = btn.getAttribute('data-rv-tab') === current;
            btn.setAttribute('aria-selected', on ? 'true' : 'false');
            btn.tabIndex = on ? 0 : -1;
        });
    }

    function applyAll() {
        page.querySelectorAll('.request-view').forEach(applyTab);
    }

    function select(tab, focus) {
        if (TABS.indexOf(tab) === -1) return;
        var changed = tab !== current;
        current = tab;
        applyAll();
        if (focus) {
            var btn = page.querySelector('[data-rv-tab="' + tab + '"]');
            if (btn) btn.focus();
        }
        if (changed) {
            var head = page.querySelector('.rv-tabs');
            // Ao trocar de aba, o topo das abas fica à vista (sem pular se já estiver)
            // (a barra superior do app é fixa: desconta a altura dela)
            var topbar = parseInt(getComputedStyle(document.documentElement).getPropertyValue('--topbar-h'), 10) || 60;
            var top = head ? head.getBoundingClientRect().top - topbar - 8 : 0;
            if (head && top < 0) window.scrollBy(0, top);
        }
    }

    // Altura real da barra fixa (ações + mensagem): reserva o espaço no fim da página
    var observedDock = null;
    var resizer = 'ResizeObserver' in window ? new ResizeObserver(function (entries) {
        entries.forEach(function (entry) {
            page.style.setProperty('--rv-dock-h', Math.ceil(entry.target.getBoundingClientRect().height) + 'px');
        });
    }) : null;

    function watchDock() {
        var dock = page.querySelector('.rv-dock');
        if (!resizer || dock === observedDock) return;
        if (observedDock) resizer.unobserve(observedDock);
        observedDock = dock;
        if (dock) resizer.observe(dock);
    }

    // "O que foi pedido" no topo: mostra "Ver tudo" só quando o texto passou das 3 linhas
    function markLongAsk() {
        page.querySelectorAll('[data-rv-ask]').forEach(function (box) {
            var body = box.querySelector('.rv-ask__body');
            var more = box.querySelector('[data-rv-ask-more]');
            if (body && more && !box.classList.contains('is-open')) more.hidden = body.scrollHeight <= body.clientHeight + 2;
        });
    }

    document.addEventListener('click', function (e) {
        var more = e.target.closest('[data-rv-ask-more]');
        if (more && page.contains(more)) {
            var box = more.closest('[data-rv-ask]');
            var open = box.classList.toggle('is-open');
            more.textContent = open ? 'Ver menos' : 'Ver tudo';
            return;
        }
        var btn = e.target.closest('[data-rv-tab]');
        if (!btn || !page.contains(btn)) return;
        select(btn.getAttribute('data-rv-tab'), false);
    });

    // Setas, Home e End entre as abas (padrão de abas WAI-ARIA)
    document.addEventListener('keydown', function (e) {
        var btn = e.target.closest('[data-rv-tab]');
        if (!btn || !page.contains(btn)) return;
        var i = TABS.indexOf(btn.getAttribute('data-rv-tab'));
        var next = null;
        if (e.key === 'ArrowRight') next = TABS[(i + 1) % TABS.length];
        else if (e.key === 'ArrowLeft') next = TABS[(i + TABS.length - 1) % TABS.length];
        else if (e.key === 'Home') next = TABS[0];
        else if (e.key === 'End') next = TABS[TABS.length - 1];
        if (!next) return;
        e.preventDefault();
        select(next, true);
    });

    // Quem escreve está na conversa: volta para a aba Conversa e, quando o feed
    // redesenhar com a mensagem nova, rola até ela
    document.addEventListener('submit', function (e) {
        if (!e.target.closest || !e.target.closest('[data-chat-form]') || !page.contains(e.target)) return;
        justSent = true;
        if (current !== 'chat') select('chat', false);
    }, true);

    new MutationObserver(function (mutations) {
        var viewChanged = false;
        var feedChanged = null;
        mutations.forEach(function (m) {
            m.addedNodes.forEach(function (node) {
                if (node.nodeType === 1 && node.classList.contains('request-view')) viewChanged = true;
            });
            if (m.target.nodeType === 1 && m.target.hasAttribute('data-chat-feed')) feedChanged = m.target;
        });
        if (viewChanged) {
            applyAll();
            watchDock();
            markLongAsk();
        }
        if (feedChanged && justSent) {
            justSent = false;
            var last = feedChanged.lastElementChild;
            if (last && last.classList.contains('feed-empty')) last = last.previousElementSibling;
            if (last) last.scrollIntoView({ block: 'end', behavior: 'smooth' });
        }
    }).observe(page, { childList: true, subtree: true });

    applyAll();
    watchDock();
    markLongAsk();
    window.addEventListener('resize', markLongAsk);
})();
