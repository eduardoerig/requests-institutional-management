/**
 * Gaveta do pedido (side peek) — peça comum do padrão clean. Markup em components/request_peek.php
 * (lá está a lista de data-* que a linha precisa ter); carregado pelas telas de $peekPages no index.php.
 *
 * - Clicar num link [data-peek] abre a gaveta (.peek) com os dados que a própria
 *   linha carrega nos data-*; o link da linha continua valendo (Ctrl/Cmd/Shift/botão do meio abrem
 *   o pedido completo noutra aba).
 * - A conversa do pedido vem de api/get_comments.php (mesma linha do tempo da tela do pedido) e a
 *   resposta vai por api/add_comment.php. O canal é o padrão do servidor (conversa com quem pediu);
 *   as conversas internas e entre setores ficam na tela do pedido.
 * - Fecha por [data-peek-close] (X e scrim) ou Esc. Ao abrir, o foco vai para o X;
 *   ao fechar, volta para a linha de origem. Tab circula dentro da gaveta (aria-modal).
 */
(function () {
    'use strict';

    const peek = document.getElementById('peek');
    if (!peek) return;

    const panel = peek.querySelector('.peek__panel');
    const closeBtn = peek.querySelector('.peek__x');
    const feed = document.getElementById('peek-feed');
    const form = document.getElementById('peek-form');
    const msg = document.getElementById('peek-msg');
    const el = (id) => document.getElementById(id);
    let trigger = null;
    let current = null; // { id, table }

    function initials(name) {
        const parts = String(name || '').trim().split(/\s+/).filter(Boolean);
        if (!parts.length || parts[0] === '—') return '·';
        const first = parts[0].charAt(0);
        const last = parts.length > 1 ? parts[parts.length - 1].charAt(0) : '';
        return (first + last).toUpperCase();
    }

    function fill(row) {
        const d = row.dataset;

        const crumb = el('peek-crumb');
        if (crumb) crumb.textContent = '#' + d.id + ' · ' + (d.sector || 'Setor');

        const title = el('peek-title');
        if (title) title.textContent = d.title || 'Sem título';

        // Prazo: o texto já vem pronto; a cor segue o mesmo critério da lista
        const prazo = el('peek-prazo');
        if (prazo) {
            const txt = prazo.querySelector('span');
            if (txt) txt.textContent = d.prazo || 'Sem prazo';
            prazo.classList.toggle('is-bad', d.prazoKind === 'bad');
            prazo.classList.toggle('is-warn', d.prazoKind === 'warn');
        }

        const prio = el('peek-prio');
        if (prio) {
            prio.textContent = d.prio || 'Normal';
            prio.classList.toggle('is-bad', d.urgent === '1');
        }

        const parado = el('peek-parado');
        if (parado) {
            parado.textContent = d.parado || 'Sem encaminhamento';
            parado.classList.toggle('is-bad', d.paradoLate === '1');
        }

        const desc = el('peek-desc');
        if (desc) {
            desc.textContent = d.desc || 'Sem descrição registrada.';
            desc.classList.toggle('is-empty', !d.desc);
        }

        const who = el('peek-who');
        if (who) who.textContent = d.who || '—';
        const sector = el('peek-sector');
        if (sector) sector.textContent = d.sector || '—';
        const av = el('peek-av');
        if (av) av.textContent = initials(d.who);

        const status = el('peek-status');
        if (status) {
            status.className = 'status-pill' + (d.statusCode ? ' status-pill--' + d.statusCode : '');
            status.textContent = d.status || '—';
        }

        const open = el('peek-open');
        if (open) open.href = d.href || '#';
    }

    // ------------------------------------------------------------------
    // Conversa
    // ------------------------------------------------------------------
    function hint(text) {
        feed.innerHTML = '';
        const p = document.createElement('p');
        p.className = 'peek-talk__hint';
        p.textContent = text;
        feed.appendChild(p);
    }

    async function loadTalk() {
        if (!current) return;
        hint('Carregando conversa…');
        try {
            const resp = await fetch(
                'api/get_comments.php?id=' + encodeURIComponent(current.id) +
                '&table=' + encodeURIComponent(current.table),
                { headers: { 'X-Requested-With': 'fetch' } }
            );
            if (!resp.ok) throw new Error('status ' + resp.status);
            feed.innerHTML = await resp.text();
            scrollTalk();
        } catch (err) {
            hint('Não foi possível carregar a conversa.');
        }
    }

    function scrollTalk() {
        feed.scrollTop = feed.scrollHeight;
    }

    if (form) {
        form.addEventListener('submit', async (e) => {
            e.preventDefault();
            if (!current || form.dataset.busy) return;
            const text = msg.value.trim();
            if (!text) {
                msg.focus();
                return;
            }
            const btn = form.querySelector('button');
            form.dataset.busy = '1';
            btn.disabled = true;
            try {
                const data = await window.postForm('api/add_comment.php', {
                    id: current.id,
                    table: current.table,
                    comment: text,
                });
                if (data && data.success) {
                    msg.value = '';
                    msg.style.height = '';
                    await loadTalk();
                } else if (data && data.message) {
                    window.showToast(data.message, 'error');
                }
            } finally {
                delete form.dataset.busy;
                btn.disabled = false;
            }
        });

        // Enter envia; Shift+Enter quebra a linha (mesma regra da tela do pedido)
        msg.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' && !e.shiftKey && window.innerWidth >= 900) {
                e.preventDefault();
                form.requestSubmit();
            }
        });

        // O campo cresce com o texto até um limite
        msg.addEventListener('input', () => {
            msg.style.height = '';
            msg.style.height = msg.scrollHeight + 'px';
        });
    }

    // ------------------------------------------------------------------
    // Abrir e fechar
    // ------------------------------------------------------------------
    function open(row) {
        trigger = row; // ao fechar, o foco volta para esta linha
        fill(row);
        current = { id: row.dataset.id, table: row.dataset.table };
        peek.classList.add('is-open');
        peek.setAttribute('aria-hidden', 'false');
        document.body.classList.add('peek-open');
        loadTalk();
        if (closeBtn) closeBtn.focus();
    }

    function close() {
        if (!peek.classList.contains('is-open')) return;
        peek.classList.remove('is-open');
        peek.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('peek-open');
        current = null;
        if (msg) {
            msg.value = '';
            msg.style.height = '';
        }
        hint('Abra um pedido para ver a conversa.');
        if (trigger && trigger.isConnected) trigger.focus();
        trigger = null;
    }

    document.addEventListener('click', (e) => {
        const row = e.target.closest('a[data-peek]');
        if (row) {
            if (e.ctrlKey || e.metaKey || e.shiftKey || e.altKey || e.button !== 0) return;
            e.preventDefault();
            open(row);
            return;
        }
        if (e.target.closest('[data-peek-close]')) {
            e.preventDefault();
            close();
        }
    });

    document.addEventListener('keydown', (e) => {
        if (!peek.classList.contains('is-open')) return;

        if (e.key === 'Escape') {
            e.preventDefault();
            close();
            return;
        }
        if (e.key !== 'Tab') return;

        // Tab não escapa da gaveta
        const focusable = [...panel.querySelectorAll('a[href], button:not([disabled]), textarea, [tabindex]:not([tabindex="-1"])')];
        if (!focusable.length) return;
        const first = focusable[0];
        const last = focusable[focusable.length - 1];
        if (e.shiftKey && document.activeElement === first) {
            e.preventDefault();
            last.focus();
        } else if (!e.shiftKey && document.activeElement === last) {
            e.preventDefault();
            first.focus();
        }
    });
})();
