// =====================================================================
// AJAX: todas as chamadas à API passam por aqui
// =====================================================================

// Põe o token CSRF no corpo (FormData ou URLSearchParams); outros tipos passam como estão
function attachCsrfToken(payload) {
    const token = window.CSRF_TOKEN;
    const canAppend = payload instanceof FormData || payload instanceof URLSearchParams;
    if (token && canAppend && !payload.has('csrf_token')) payload.append('csrf_token', token);
    return payload;
}

// Lê a resposta como JSON; erro do servidor sem JSON (HTML, corpo vazio) não é confundido com falta de conexão
async function readJson(response) {
    if (!(response.headers.get('content-type') || '').includes('json')) {
        const err = new Error('Resposta inesperada do servidor');
        err.serverStatus = response.status;
        throw err;
    }
    return response.json();
}

// Texto para quando a chamada nem chegou a dar resposta útil
function requestErrorMessage(err) {
    return err && err.serverStatus
        ? `Erro no servidor (código ${err.serverStatus}). Tente de novo em instantes.`
        : 'Sem conexão. Verifique a internet e tente de novo.';
}

// ---------------------------------------------------------------------
// Sessão expirada no meio do uso: janela "Entre de novo" (#reloginModal, index.php)
// A pessoa entra sem sair da página (o que digitou continua lá) e o envio que falhou é repetido.
// O servidor responde 401 com session_expired antes de gravar qualquer coisa, então repetir é seguro.
// ---------------------------------------------------------------------
let sessionPromise = null;

// Token novo da sessão atual (a página aberta há muito tempo ficou com um token velho)
async function refreshCsrfToken() {
    try {
        const data = await readJson(await fetch('api/csrf.php', { cache: 'no-store' }));
        if (data && data.csrf_token) { window.CSRF_TOKEN = data.csrf_token; return true; }
    } catch (e) { /* sem conexão: quem chamou mostra o erro */ }
    return false;
}

// Abre a janela uma vez só, mesmo que várias chamadas expirem juntas. Resolve true depois de entrar.
function ensureSession(message) {
    if (sessionPromise) return sessionPromise;
    const modal = document.getElementById('reloginModal');
    if (!modal) {
        window.location.href = 'login';
        return Promise.resolve(false);
    }
    const form = modal.querySelector('#reloginForm');
    const user = modal.querySelector('#reloginUser');
    const pass = modal.querySelector('#reloginPass');
    const error = modal.querySelector('#reloginError');
    const submit = modal.querySelector('#reloginSubmit');
    const msg = modal.querySelector('#reloginMessage');
    const me = window.SESSION_USER || null;

    sessionPromise = new Promise((resolve) => {
        const showError = (text) => { error.textContent = text; error.hidden = !text; };
        if (message && msg) msg.textContent = message + ' O que você estava fazendo continua nesta tela.';
        if (me && me.login) user.value = me.login;
        pass.value = '';
        showError('');
        modal.classList.add('active');
        setTimeout(() => (user.value ? pass : user).focus(), 50);

        async function onSubmit(e) {
            e.preventDefault();
            if (!user.value.trim() || !pass.value) { showError('Preencha usuário e senha.'); return; }
            submit.disabled = true;
            submit.setAttribute('aria-busy', 'true');
            try {
                if (!(await refreshCsrfToken())) throw new Error('csrf');
                const body = new URLSearchParams({ action: 'get', login: user.value.trim(), password: pass.value, csrf_token: window.CSRF_TOKEN });
                const data = await readJson(await fetch('api/login.php', { method: 'POST', body }));
                if (!data.success) { showError(data.message || 'Não foi possível entrar.'); return; }
                // Entrou outra pessoa: a página é de quem estava antes, recarrega com os dados certos
                if (me && data.user_id !== me.id) { window.location.reload(); return; }
                if (data.csrf_token) window.CSRF_TOKEN = data.csrf_token;
                form.removeEventListener('submit', onSubmit);
                modal.classList.remove('active');
                pass.value = '';
                resolve(true);
            } catch (err) {
                showError(requestErrorMessage(err));
            } finally {
                submit.disabled = false;
                submit.removeAttribute('aria-busy');
            }
        }
        form.addEventListener('submit', onSubmit);
        modal.querySelector('#reloginLeave').onclick = () => { window.location.href = 'login'; };
    }).finally(() => { sessionPromise = null; });
    return sessionPromise;
}

// Cópia do corpo sem o token antigo (cada tentativa leva o token atual)
function cloneBody(payload) {
    if (payload instanceof FormData) {
        const copy = new FormData();
        for (const [k, v] of payload.entries()) if (k !== 'csrf_token') copy.append(k, v);
        return copy;
    }
    if (payload instanceof URLSearchParams) {
        const copy = new URLSearchParams(payload);
        copy.delete('csrf_token');
        return copy;
    }
    return payload;
}

// POST à API com CSRF. Sessão expirada: pede para entrar de novo e repete uma vez.
// Token velho (página aberta há muito tempo): busca outro e repete uma vez. Devolve o JSON.
async function postWithSession(url, payload, init = {}) {
    let triedLogin = false;
    let triedCsrf = false;
    for (;;) {
        const resp = await fetch(url, { ...init, method: 'POST', body: attachCsrfToken(cloneBody(payload)) });
        const data = await readJson(resp);
        if (data && !data.success) {
            if (data.session_expired && !triedLogin) {
                triedLogin = true;
                if (await ensureSession(data.message)) continue;
            } else if (data.csrf_expired && !triedCsrf) {
                triedCsrf = true;
                if (await refreshCsrfToken()) continue;
            }
        }
        return data;
    }
}

/**
 * Envia um POST para a API e devolve o JSON se success = true; senão devolve false.
 * - Mostra o véu de carregamento enquanto espera.
 * - Sessão expirada: se a API mandar 'redirect', a página vai para lá.
 * - Erro (sem conexão, servidor ou data.message): avisa o usuário.
 * - opts.quiet: sem véu e sem aviso; quem chama mostra o próprio estado.
 */
async function fetchAjax(path, formData, { quiet = false } = {}) {
    const hideLoading = quiet ? null : showLoading();

    let data = null;
    let failure = null; // erro de rede ou resposta que não é JSON
    try {
        data = await postWithSession(path, formData);
    } catch (e) {
        failure = e;
    } finally {
        if (hideLoading) hideLoading(); // antes do aviso, para não prender a tela atrás dele
    }

    if (data && data.success) return data;
    if (data && data.redirect) {
        window.location.href = data.redirect;
        return false;
    }
    if (!quiet) {
        if (failure) await showAjaxError('Não foi possível concluir', requestErrorMessage(failure), 'error');
        else await showAjaxError('Atenção', (data && data.message) || 'Não foi possível concluir a operação.', 'warning');
    }
    return false;
}

// Último botão tocado: é nele que a espera aparece (giro no lugar do texto)
let lastPressed = null;
document.addEventListener('click', (e) => {
    const btn = e.target.closest('.btn');
    lastPressed = btn ? { btn, at: Date.now() } : null;
}, true);

/**
 * Liga o véu de carregamento e devolve a função que desliga.
 * O véu segura cliques na hora, mas o CSS só o mostra após .3s. Se a ação veio de um
 * botão ainda visível (tocado há pouco), o giro vai no botão e o véu fica invisível.
 */
function showLoading() {
    const overlay = document.querySelector('.loading-overlay');
    const recent = lastPressed && Date.now() - lastPressed.at < 1500 ? lastPressed.btn : null;
    const btn = recent && recent.isConnected && recent.offsetParent !== null ? recent : null;
    const ownsBusy = btn && btn.getAttribute('aria-busy') !== 'true'; // a tela pode já ter marcado
    if (ownsBusy) btn.setAttribute('aria-busy', 'true');
    if (overlay) {
        overlay.classList.toggle('is-quiet', !!btn);
        overlay.style.display = 'flex';
    }
    return () => {
        if (overlay) overlay.style.display = 'none';
        if (ownsBusy) btn.removeAttribute('aria-busy');
    };
}

// Janela de aviso (showAlert) quando existe; senão, aviso rápido no canto
async function showAjaxError(title, message, type) {
    if (typeof showAlert === 'function') await showAlert({ title, message, type });
    else showToast(message, 'error');
}

// =====================================================================
// Avisos (sino): chegam sozinhos, aparecem na tela e atualizam o que está aberto
// =====================================================================
const notifBtn = document.getElementById('notif-btn');
const notifDropdown = document.getElementById('notif-dropdown');
const notifBadge = document.getElementById('notif-badge');
const notifBody = document.getElementById('notif-body');

const NOTIF_POLL_MS = 20000;
const NOTIF_KINDS = {
    approve:  ['fa-regular fa-clipboard', 'kind-approve'],
    approved: ['fa-solid fa-check',       'kind-ok'],
    done:     ['fa-solid fa-check-double', 'kind-ok'],
    refused:  ['fa-solid fa-xmark',       'kind-bad'],
    transfer: ['fa-solid fa-share',       'kind-forward'],
    help:     ['fa-solid fa-handshake-angle', 'kind-forward'],
    returned: ['fa-solid fa-rotate-left', 'kind-returned'],
    message:  ['fa-regular fa-message',   'kind-message'],
    started:  ['fa-solid fa-wrench',      'kind-info'],
    priority: ['fa-solid fa-bolt',        'kind-warn'],
    info:     ['fa-regular fa-bell',      'kind-message'],
};
const notifState = { lastId: null, unread: 0, items: [], loading: false, baseTitle: document.title, pending: [], lastFetch: 0 };

if (notifBtn) {
    notifBtn.addEventListener('click', (e) => {
        if (e.target.closest('#notif-dropdown')) return;
        e.stopPropagation();
        const open = notifDropdown.classList.toggle('active');
        notifBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
        if (open) updateNotifications();
    });
    notifBtn.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); notifBtn.click(); }
    });
    document.addEventListener('click', () => closeNotifDropdown());
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape') closeNotifDropdown(); });
    notifDropdown.addEventListener('click', (e) => e.stopPropagation());

    updateNotifications();
    setInterval(updateNotifications, NOTIF_POLL_MS);
    // Voltou para a aba/app: confere na hora
    document.addEventListener('visibilitychange', () => {
        if (document.hidden) return;
        // Mostra o que chegou enquanto a pessoa estava em outra aba
        const waiting = notifState.pending.splice(0);
        waiting.slice(-3).forEach(n => showNotifPop(n));
        if (waiting.length) refreshForNotifications(waiting);
        updateNotifications();
    });
    window.addEventListener('focus', () => updateNotifications());
}

function closeNotifDropdown() {
    if (!notifDropdown || !notifDropdown.classList.contains('active')) return;
    notifDropdown.classList.remove('active');
    notifBtn.setAttribute('aria-expanded', 'false');
}

function escapeHtml(value) {
    const div = document.createElement('div');
    div.textContent = value == null ? '' : String(value);
    return div.innerHTML;
}

// Aceita só links internos relativos (bloqueia javascript:, http://outro-site etc.)
function safeInternalLink(link) {
    const value = String(link || '');
    return /^[a-zA-Z0-9_\-\/?=&.%]+$/.test(value) && !/^\/\//.test(value) ? value : '#';
}

async function updateNotifications() {
    if (!notifBadge || notifState.loading) return;
    // Em segundo plano confere a cada minuto (atualiza o número no título da aba)
    if (document.hidden && notifState.lastId !== null && Date.now() - notifState.lastFetch < 55000) return;
    notifState.loading = true;
    notifState.lastFetch = Date.now();
    try {
        // Sem resposta em 15 s desiste deste ciclo (senão o sino para de atualizar)
        const signal = typeof AbortSignal.timeout === 'function' ? AbortSignal.timeout(15000) : undefined;
        const response = await fetch('api/get_notifications.php', { cache: 'no-store', signal });
        if (response.status === 401) {
            // Sessão caiu: janela para entrar de novo, sem tirar a pessoa da página
            const data = await response.json().catch(() => ({}));
            ensureSession(data.message);
            return;
        }
        if (!response.ok) return;
        const data = await response.json();
        const items = data.notifications || [];
        const fresh = notifState.lastId === null ? [] : items.filter(n => n.id > notifState.lastId && !n.is_read);

        notifState.items = items;
        notifState.lastId = Math.max(notifState.lastId || 0, data.latestId || 0);
        setNotifCount(data.unreadCount || 0, fresh.length > 0);
        renderNotifList();

        if (fresh.length && document.hidden) {
            notifState.pending.push(...fresh.slice().reverse());
            fresh.slice(0, 3).forEach(n => showBrowserNotif(n));
        } else if (fresh.length) {
            fresh.slice(0, 3).reverse().forEach(n => showNotifPop(n));
            if (fresh.length > 3) showNotifPop({ id: 0, kind: 'info', title: `Mais ${fresh.length - 3} avisos`, message: 'Abra o sino para ver todos.', link: '' });
            refreshForNotifications(fresh);
        }
    } catch (err) {
        /* sem conexão: tenta no próximo ciclo */
    } finally {
        notifState.loading = false;
    }
}

function setNotifCount(count, bump) {
    notifState.unread = count;
    notifBadge.textContent = count > 99 ? '99+' : count;
    notifBadge.hidden = !(count > 0);
    notifBtn.setAttribute('aria-label', count > 0 ? `Avisos: ${count} não lidos` : 'Avisos');
    document.title = (count > 0 ? `(${count}) ` : '') + notifState.baseTitle;
    const markAll = document.getElementById('notif-mark-all');
    if (markAll) markAll.disabled = count === 0;
    const unreadText = document.getElementById('notif-unread');
    if (unreadText) unreadText.textContent = count > 0 ? ` · ${count > 99 ? '99+' : count} ${count === 1 ? 'não lido' : 'não lidos'}` : '';
    if (bump) {
        notifBtn.classList.remove('ring');
        void notifBtn.offsetWidth; // reinicia a animação
        notifBtn.classList.add('ring');
    }
}

function notifRelativeTime(dateStr) {
    const date = new Date(String(dateStr).replace(' ', 'T'));
    const diff = Math.round((Date.now() - date.getTime()) / 60000);
    if (diff < 1) return 'agora';
    if (diff < 60) return `há ${diff} min`;
    if (diff < 60 * 24 && date.getDate() === new Date().getDate()) return `há ${Math.floor(diff / 60)} h`;
    const hour = String(date.getHours()).padStart(2, '0') + 'h' + String(date.getMinutes()).padStart(2, '0');
    if (notifGroup(dateStr) === 'Ontem') return `ontem, ${hour}`;
    return date.toLocaleDateString('pt-BR', { day: '2-digit', month: '2-digit' }) + ', ' + hour;
}

function notifGroup(dateStr) {
    const d = new Date(String(dateStr).replace(' ', 'T'));
    const today = new Date(); today.setHours(0, 0, 0, 0);
    const day = new Date(d); day.setHours(0, 0, 0, 0);
    const diff = Math.round((today - day) / 86400000);
    return diff === 0 ? 'Hoje' : diff === 1 ? 'Ontem' : 'Antes';
}

function notifIcon(kind) {
    const [icon, cls] = NOTIF_KINDS[kind] || NOTIF_KINDS.info;
    return `<span class="notif-icon ${cls}" aria-hidden="true"><i class="${icon}"></i></span>`;
}

function renderNotifList() {
    if (!notifBody) return;
    const items = notifState.items;
    if (!items.length) {
        notifBody.innerHTML = '<div class="notif-empty"><i class="fa-regular fa-bell" aria-hidden="true"></i><p>Nenhum aviso ainda.</p><small>Quando algo acontecer nos seus pedidos, aparece aqui.</small></div>';
    } else {
        let html = '';
        let group = '';
        items.forEach(n => {
            const g = notifGroup(n.created_at);
            if (g !== group) { html += `<p class="notif-group">${g}</p>`; group = g; }
            html += `
                <a class="notif-item ${n.is_read ? '' : 'unread'}" href="${escapeHtml(safeInternalLink(n.link))}" data-id="${n.id}">
                    ${notifIcon(n.kind)}
                    <span class="notif-item-content">
                        <span class="notif-item-title">${escapeHtml(n.title)}</span>
                        <span class="notif-item-msg">${escapeHtml(n.message)}</span>
                        <span class="notif-item-time">${escapeHtml(notifRelativeTime(n.created_at))}</span>
                    </span>
                    ${n.is_read ? '' : `<button type="button" class="notif-mark-read" data-read="${n.id}" title="Marcar como lido" aria-label="Marcar como lido"><span></span></button>`}
                </a>`;
        });
        notifBody.innerHTML = html;
    }
    renderNotifPermission();
}

// Oferece aviso do navegador (aparece mesmo com o SisReq em outra aba)
function renderNotifPermission() {
    const foot = document.getElementById('notif-foot');
    if (!foot) return;
    const can = 'Notification' in window && window.isSecureContext !== false;
    if (!can || Notification.permission !== 'default') { foot.hidden = true; return; }
    foot.hidden = false;
}

async function enableBrowserNotifs() {
    if (!('Notification' in window)) return;
    const result = await Notification.requestPermission();
    renderNotifPermission();
    showToast(result === 'granted' ? 'Pronto: você será avisado mesmo com o SisReq em outra aba.' : 'Tudo bem, os avisos continuam aparecendo aqui no SisReq.', result === 'granted' ? 'success' : 'info');
}

function showBrowserNotif(n) {
    if (!('Notification' in window) || Notification.permission !== 'granted') return;
    try {
        const notif = new Notification(n.title, { body: n.message, icon: 'assets/img/LogoFavConSisreq.png', tag: 'sisreq-' + n.id });
        notif.onclick = () => {
            window.focus();
            const link = safeInternalLink(n.link);
            if (link !== '#') openNotification(n.id, link);
            notif.close();
        };
    } catch (err) { /* alguns celulares só permitem pelo service worker */ }
}

// Cartão que aparece na tela quando chega um aviso novo
function showNotifPop(n) {
    let stack = document.getElementById('notif-pops');
    if (!stack) {
        stack = document.createElement('div');
        stack.id = 'notif-pops';
        stack.className = 'notif-pops';
        stack.setAttribute('aria-live', 'polite');
        document.body.appendChild(stack);
    }
    const link = safeInternalLink(n.link);
    const pop = document.createElement('div');
    pop.className = 'notif-pop';
    pop.setAttribute('role', 'status');
    pop.innerHTML = `
        ${notifIcon(n.kind)}
        <div class="notif-pop__text"><strong></strong><p></p></div>
        <div class="notif-pop__actions">
            ${link !== '#' && link !== '' ? '<button type="button" class="notif-pop__open">Abrir</button>' : ''}
            <button type="button" class="notif-pop__close" aria-label="Fechar aviso"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
        </div>`;
    pop.querySelector('strong').textContent = n.title;
    pop.querySelector('p').textContent = n.message;
    stack.appendChild(pop);
    while (stack.children.length > 3) stack.firstElementChild.remove();
    setTimeout(() => pop.classList.add('show'), 20);

    let timer;
    const dismiss = () => {
        clearTimeout(timer);
        pop.classList.remove('show');
        setTimeout(() => pop.remove(), 250);
    };
    const arm = () => { clearTimeout(timer); timer = setTimeout(dismiss, 8000); };
    arm();
    pop.addEventListener('mouseenter', () => clearTimeout(timer));
    pop.addEventListener('mouseleave', arm);
    pop.querySelector('.notif-pop__close').addEventListener('click', dismiss);
    const open = pop.querySelector('.notif-pop__open');
    if (open) open.addEventListener('click', () => { dismiss(); openNotification(n.id, link); });
}

// Abre o pedido do aviso na página dele
function openNotification(id, link) {
    if (id) markNotifRead(id, true);
    window.location.href = link;
}

// O aviso fala do que está aberto? Atualiza sem a pessoa precisar recarregar
function refreshForNotifications(fresh) {
    const links = fresh.map(n => String(n.link || ''));
    if (document.querySelector('[data-queue]') && typeof refreshAfterChange === 'function') {
        // Com pedidos marcados para resolver de uma vez, não redesenha a fila (perderia a seleção):
        // os avisos ficam em espera e entram quando a seleção esvazia (updateBulkBar) ou depois da ação em lote.
        // queue-hold: a página segura o redesenho (Aprovar, com a janela de setores aberta) e chama
        // refreshAfterChange() quando solta, se queuePending > 0
        if (document.body.classList.contains('has-bulk') || document.body.classList.contains('queue-hold')) {
            queuePending += fresh.length || 1;
            showQueuePending();
        } else {
            refreshAfterChange();
        }
        return;
    }
    document.querySelectorAll('.request-view').forEach(view => {
        const link = `request_detail?id=${view.dataset.id}&table=${view.dataset.table}`;
        if (!links.includes(link)) return;
        const typing = [...view.querySelectorAll('[data-chat-form] textarea')].some(t => t.value.trim());
        const onlyMessages = fresh.every(n => n.kind === 'message' || n.link !== link);
        if (onlyMessages || typing) {
            reloadChat(view);
        } else {
            reloadRequestView(view);
        }
    });
}

async function reloadRequestView(view) {
    try {
        const html = await (await fetch(`api/request_view.php?id=${view.dataset.id}&table=${view.dataset.table}`)).text();
        const box = document.createElement('div');
        box.innerHTML = html;
        const fresh = box.querySelector('.request-view');
        if (!fresh) return;
        view.replaceWith(fresh);
        const thread = chatThreadPicked.get(fresh.dataset.id + ':' + fresh.dataset.table);
        if (thread) selectChatThread(fresh, thread);
        fresh.classList.add('just-updated');
    } catch (err) { /* mantém a versão atual */ }
}

async function markAllRead() {
    const data = await postForm('api/mark_notifications_read.php', {});
    if (data && data.success) {
        notifState.items.forEach(n => { n.is_read = 1; });
        setNotifCount(0, false);
        renderNotifList();
    }
}

async function markNotifRead(id, keepalive = false) {
    const item = notifState.items.find(n => n.id === id);
    if (item && !item.is_read) {
        item.is_read = 1;
        setNotifCount(Math.max(0, notifState.unread - 1), false);
        renderNotifList();
    }
    const body = attachCsrfToken(new URLSearchParams({ id }));
    try {
        await fetch('api/mark_notification_read_single.php', { method: 'POST', body, keepalive });
    } catch (err) { /* o próximo ciclo corrige */ }
}

if (notifBody) {
    notifBody.addEventListener('click', (e) => {
        const mark = e.target.closest('[data-read]');
        if (mark) {
            e.preventDefault();
            markNotifRead(parseInt(mark.dataset.read, 10));
            return;
        }
        const item = e.target.closest('.notif-item');
        if (!item) return;
        e.preventDefault();
        const id = parseInt(item.dataset.id, 10);
        const link = item.getAttribute('href');
        if (link === '#') { markNotifRead(id); return; }
        closeNotifDropdown();
        openNotification(item.classList.contains('unread') ? id : 0, link);
    });
}

function showToast(message, type = 'success') {
    const toast = document.querySelector(".toast");
    if (!toast) return;
    const icons = { success: 'fa-circle-check', error: 'fa-circle-exclamation', info: 'fa-circle-info' };
    toast.innerHTML = `<i class="fa-solid ${icons[type] || icons.success}" aria-hidden="true"></i><span></span>`;
    toast.querySelector('span').textContent = message;
    toast.className = 'toast ' + type + ' show';
    clearTimeout(window.__toastTimer);
    window.__toastTimer = setTimeout(() => toast.classList.remove('show'), 3500);
}

// Sidebar toggle logic for mobile (with backdrop)
const menuBtn = document.getElementById('mobile-menu-btn');
const sidebar = document.getElementById('main-sidebar');
const sidebarBackdrop = document.getElementById('sidebar-backdrop');

function openSidebar() {
    if (sidebar) sidebar.classList.add('open');
    if (sidebarBackdrop) sidebarBackdrop.classList.add('active');
    document.body.style.overflow = 'hidden';
}

function closeSidebar() {
    if (sidebar) sidebar.classList.remove('open');
    if (sidebarBackdrop) sidebarBackdrop.classList.remove('active');
    document.body.style.overflow = '';
}

if(menuBtn && sidebar) {
    menuBtn.addEventListener('click', () => {
        if (sidebar.classList.contains('open')) {
            closeSidebar();
        } else {
            openSidebar();
        }
    });
}

// Fechar sidebar ao clicar no backdrop
if (sidebarBackdrop) {
    sidebarBackdrop.addEventListener('click', closeSidebar);
}

// Fechar sidebar ao clicar em um link da nav (mobile)
if (sidebar) {
    sidebar.querySelectorAll('a').forEach(link => {
        link.addEventListener('click', () => {
            if (window.innerWidth < 900) {
                closeSidebar();
            }
        });
    });
}

let btn_login = document.querySelector('#btn_login');

let isSubmittingLogin = false;
if (btn_login) {
    btn_login.addEventListener('click', async (e) => {
        e.preventDefault();
        
        if (isSubmittingLogin) return;
        isSubmittingLogin = true;
        
        const originalText = btn_login.innerHTML;
        btn_login.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Entrando...';
        btn_login.disabled = true;

        let formData = new FormData();
        formData.append('action', 'get');
        formData.append('login', document.querySelector('#user').value);
        formData.append('password', document.querySelector('#password').value);

        const success = await fetchAjax("api/login.php", formData);
        if (success) {
            // A tela de entrada aparece no próprio endereço pedido (index.php): recarregar abre essa página
            const onLoginUrl = /\/login\/?$/.test(window.location.pathname);
            if (onLoginUrl) window.location.href = 'home';
            else window.location.reload();
        } else {
            isSubmittingLogin = false;
            btn_login.innerHTML = originalText;
            btn_login.disabled = false;
        }
    });
}
// Sair (menu lateral e Minha conta): POST com o token CSRF, nunca por link GET
document.addEventListener('click', async (e) => {
    const btn = e.target.closest('[data-logout]');
    if (!btn) return;
    e.preventDefault();

    const success = await fetchAjax("api/logout.php", new FormData());
    if (success) {
        window.location.href = './';
    }
});

function todayIso() {
    const d = new Date();
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

// Mostra a mensagem de erro logo abaixo do campo e leva o usuário até ele
function showFieldError(field, message) {
    const group = field.closest('.form-group') || field.parentElement;
    let msg = group.querySelector('.field-error');
    if (!msg) {
        msg = document.createElement('p');
        msg.className = 'field-error';
        msg.setAttribute('role', 'alert');
        group.appendChild(msg);
    }
    msg.textContent = message;
    group.classList.add('has-error');
}

function clearFieldErrors(form) {
    form.querySelectorAll('.field-error').forEach(el => el.remove());
    form.querySelectorAll('.has-error').forEach(el => el.classList.remove('has-error'));
}

function validateRequestForm(form) {
    clearFieldErrors(form);
    const messages = {
        subdivision_id: 'Escolha de qual área é este pedido.',
        title: 'Escreva um título curto.',
        end: 'Escolha a data em que você precisa.',
        details: 'Explique o que você precisa.',
        file: 'Informe qual arquivo deve ser impresso.',
        qtd: 'Informe quantas cópias (número maior que zero).',
        model: 'Escolha uma opção.',
        outros_texto: 'Descreva qual é a outra opção.',
        urgent_reason: 'Conte por que é urgente.',
        obs_choice: 'Responda sim ou não.',
        partner_choice: 'Responda sim ou não.',
        obs: 'Escreva a observação ou marque Não.',
        partner_area_id: 'Escolha o setor ou marque Não.',
        'model[]': 'Escolha pelo menos um lugar.',
        kind: 'Escolha o que você precisa.',
        size: 'Escolha o tamanho.'
    };
    let firstInvalid = null;
    const checkedGroups = new Set();

    // data-required-group: grupo de caixas de marcar em que pelo menos uma precisa estar marcada
    form.querySelectorAll('[required], [data-required-group]').forEach(field => {
        if (field.disabled) return; // parte fechada do formulário (Não, outro tipo): não vai no envio
        if (field.type === 'radio' || field.hasAttribute('data-required-group')) {
            if (checkedGroups.has(field.name)) return;
            checkedGroups.add(field.name);
            if (!form.querySelector(`input[name="${CSS.escape(field.name)}"]:checked`)) {
                showFieldError(field, field.dataset.message || messages[field.name] || 'Escolha uma opção.');
                firstInvalid = firstInvalid || field;
            }
            return;
        }
        const value = field.value.trim();
        let invalid = value === '';
        if (!invalid && field.type === 'date' && value <= todayIso()) {
            showFieldError(field, 'Escolha uma data a partir de amanhã.');
            firstInvalid = firstInvalid || field;
            return;
        }
        // Número: a mensagem diz o que está errado (decimal, zero, acima do máximo), como o servidor
        if (field.type === 'number' && ((field.validity && field.validity.badInput) || value !== '')) {
            const max = parseInt(field.max, 10);
            let problem = '';
            if ((field.validity && field.validity.badInput) || !/^\d+$/.test(value)) problem = 'Use um número inteiro, sem vírgula nem ponto (ex.: 30).';
            else if (parseInt(value, 10) < 1) problem = 'O número precisa ser maior que zero.';
            else if (max > 0 && parseInt(value, 10) > max) problem = `O máximo é ${max.toLocaleString('pt-BR')}.`;
            if (problem) {
                showFieldError(field, problem);
                firstInvalid = firstInvalid || field;
                return;
            }
        }
        if (invalid) {
            showFieldError(field, field.dataset.message || messages[field.name] || 'Preencha este campo.');
            firstInvalid = firstInvalid || field;
        }
    });

    if (firstInvalid) {
        firstInvalid.scrollIntoView({ behavior: 'smooth', block: 'center' });
        firstInvalid.focus({ preventScroll: true });
        return false;
    }
    return true;
}

// Mostra (e liga) ou esconde (e desliga) uma parte do formulário. Campo desligado não vai no envio
// nem é conferido; data-yesno-required vira obrigatório só enquanto a parte está aberta.
function setFormPartOpen(part, open) {
    part.hidden = !open;
    part.querySelectorAll('input, select, textarea').forEach(field => {
        field.disabled = !open;
        if (field.hasAttribute('data-yesno-required')) field.required = open;
    });
}

// Perguntas Sim/Não (api/get_form.php, yesNoField): Sim abre o painel; Não fecha e mostra a frase em vermelho
function bindYesNo(form) {
    form.querySelectorAll('[data-yesno]').forEach(box => {
        const yes = box.querySelector('[data-yesno-yes]');
        const no = box.querySelector('[data-yesno-no]');
        const panel = box.querySelector('[data-yesno-panel]');
        const note = box.querySelector('[data-yesno-note]');
        const apply = (focus) => {
            setFormPartOpen(panel, yes.checked);
            note.hidden = !no.checked;
            if (focus && yes.checked) panel.querySelector('input:not([type="hidden"]), select, textarea')?.focus();
        };
        yes.addEventListener('change', () => apply(true));
        no.addEventListener('change', () => apply(false));
        apply(false); // resposta que voltou ao trocar de setor
    });
}

// Tipo do pedido escolhido no topo do formulário (Reprografia: cópia colorida | plastificação):
// só a parte do tipo escolhido fica aberta; data-kind-part="*" abre com qualquer tipo
function bindKindChoice(form) {
    const box = form.querySelector('[data-kind-choice]');
    if (!box) return;
    const apply = (focus) => {
        const kind = box.querySelector('input:checked')?.value || '';
        form.querySelectorAll('[data-kind-part]').forEach(part => {
            const want = part.dataset.kindPart;
            setFormPartOpen(part, kind !== '' && (want === '*' || want === kind));
        });
        if (focus) form.querySelector(`[data-kind-part="${CSS.escape(kind)}"] input:not([type="hidden"])`)?.focus();
    };
    box.addEventListener('change', () => apply(true));
    apply(false);
}

// Lista de linhas (itens de Compras, links do Marketing): "+ Adicionar" copia o <template>; cada
// campo vira items[n][nome]. Linha começada exige o campo principal (data-key). Com
// data-repeater-details, o campo Detalhes só é obrigatório enquanto não há linha preenchida.
function bindRepeater(form) {
    form.querySelectorAll('[data-repeater]').forEach(box => {
        const rows = box.querySelector('[data-repeater-rows]');
        const tpl = box.querySelector('template[data-repeater-template]');
        const add = box.querySelector('[data-repeater-add]');
        const max = parseInt(box.dataset.max || '30', 10);
        const details = box.dataset.repeaterDetails ? form.querySelector(`[name="${box.dataset.repeaterDetails}"]`) : null;
        const detailsReq = details ? form.querySelector('[data-details-req]') : null;
        if (!rows || !tpl || !add) return;

        const sync = () => {
            const list = Array.from(rows.querySelectorAll('[data-repeater-row]'));
            let filled = 0;
            list.forEach((row, i) => {
                const fields = Array.from(row.querySelectorAll('[data-field]'));
                const started = fields.some(f => f.value.trim() !== '');
                if (started) filled++;
                fields.forEach(f => {
                    f.name = `items[${i}][${f.dataset.field}]`;
                    if (f.hasAttribute('data-key')) f.required = started;
                });
            });
            add.hidden = list.length >= max;
            if (details) {
                details.required = filled === 0;
                if (detailsReq) detailsReq.hidden = filled > 0;
            }
        };

        add.addEventListener('click', () => {
            rows.appendChild(tpl.content.cloneNode(true));
            sync();
            rows.lastElementChild?.querySelector('[data-field]')?.focus();
        });
        rows.addEventListener('click', (e) => {
            const btn = e.target.closest('[data-repeater-remove]');
            if (!btn) return;
            btn.closest('[data-repeater-row]')?.remove();
            sync();
            add.focus();
        });
        rows.addEventListener('input', sync);
        sync();
    });
}

// Data de hoje + n dias, no formato do campo de data
function isoInDays(n) {
    const d = new Date();
    d.setDate(d.getDate() + n);
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

function bindFormRequests() {
    const form_requests = document.querySelector('.form_request');
    if (!form_requests) return;

    // Prazo só pelo calendário, a partir de amanhã (hoje não dá: feedback da CTAP)
    form_requests.querySelectorAll('input[type="date"]').forEach(input => { input.min = isoInDays(1); });

    bindYesNo(form_requests);
    bindKindChoice(form_requests);
    bindRepeater(form_requests);

    // "É urgente" pede o motivo
    const urgent = form_requests.querySelector('[data-urgent]');
    const urgentReason = form_requests.querySelector('[data-urgent-reason]');
    if (urgent && urgentReason) {
        urgent.addEventListener('change', () => {
            const reason = urgentReason.querySelector('input');
            urgentReason.hidden = !urgent.checked;
            reason.required = urgent.checked;
            if (urgent.checked) reason.focus(); else reason.value = '';
        });
    }

    // Campo "Outro" do marketing
    const radioOutros = document.getElementById('radio_outros');
    const inputOutros = document.getElementById('input_outros');
    if (radioOutros && inputOutros) {
        form_requests.querySelectorAll('.model_radius').forEach(radio => {
            radio.addEventListener('change', () => {
                const isOther = radioOutros.checked;
                inputOutros.hidden = !isOther;
                inputOutros.required = isOther;
                if (isOther) inputOutros.focus(); else inputOutros.value = '';
            });
        });
    }

    // Some com a mensagem de erro assim que o usuário corrige o campo
    form_requests.addEventListener('input', (e) => {
        const group = e.target.closest('.form-group');
        if (group) {
            group.classList.remove('has-error');
            group.querySelector('.field-error')?.remove();
        }
    });

    let isSubmitting = false;
    form_requests.addEventListener('submit', async (e) => {
        e.preventDefault();
        if (isSubmitting) return;
        if (!validateRequestForm(form_requests)) return;

        isSubmitting = true;
        const submitBtn = form_requests.querySelector('button[type="submit"]');
        const originalBtnText = submitBtn ? submitBtn.innerHTML : '';
        if (submitBtn) {
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Enviando...';
        }

        const formData = new FormData(form_requests);
        formData.append('action', 'post');

        const data = await fetchAjax("api/add_request.php", formData);
        if (data && data.success) {
            flashToast(`Pedido nº ${parseInt(data.id, 10)} enviado. Você recebe um aviso quando a coordenação aprovar.`);
            window.location.href = safeInternalLink(data.link);
            return;
        }

        isSubmitting = false;
        if (submitBtn) {
            submitBtn.disabled = false;
            submitBtn.innerHTML = originalBtnText;
        }
    });
}

// Compatibilidade: confirmação antiga redirecionada ao modal global
function customConfirm(message, title = 'Confirmação') {
    if (typeof message === 'object' && message !== null) {
        return window.showConfirm(message);
    }
    return window.showConfirm({ title, message, type: 'warning', confirmLabel: 'Sim, continuar' });
}

// Funções Globais de Ação de Requisição
// Devolve true quando o servidor confirmou a ação (changePriority usa para desfazer o campo se falhar)
async function globalRequestAction(action, id, table, value = '') {
    // Configurações de mensagens de confirmação
    const confirmations = {
        'reset_status': 'Reabrir este pedido? Ele volta para "Em atendimento".',
        'finish': 'Marcar como concluído? Quem pediu será avisado que o pedido foi atendido.',
        'approve': 'Aprovar este pedido?',
        'reject': 'Recusar este pedido?',
        'delete': 'Cancelar este pedido? Ele será apagado e não poderá ser recuperado.'
    };

    // Se houver uma mensagem definida para a ação, pede confirmação via Custom Modal
    if (confirmations[action]) {
        const confirmed = await customConfirm(confirmations[action]);
        if (!confirmed) return false;
    }

    if (!id || !table) {
        showToast("Dados do pedido não encontrados.", "error");
        return false;
    }

    let fullTable = table;
    if (!table.startsWith('ctd_')) fullTable = 'ctd_' + table + '_frm';

    const hideLoading = showLoading();

    try {
        const formData = new URLSearchParams();
        formData.append('id', id);
        formData.append('table', fullTable);
        formData.append('action', action);
        formData.append('value', value);

        const data = await postWithSession('api/request_actions.php', formData);

        if (!data.success && data.redirect) {
            window.location.href = data.redirect;
            return false;
        }

        if (data.success) {
            const finishedHere = action === 'finish' && document.querySelector('.detail-page');
            if (!finishedHere) showToast(data.message, 'success');
            // Após cancelar, a requisição não existe mais: volta para a lista
            if (action === 'delete') {
                setTimeout(() => { window.location.href = 'myRequests'; }, 900);
            } else if (finishedHere) {
                showFinishedOverlay(id); // a pessoa decide: voltar para a lista ou abrir o próximo
            } else {
                refreshAfterChange({ advance: action === 'finish', message: data.message });
            }
            return true;
        }
        showToast(data.message || 'Não foi possível concluir a ação.', 'error');
        return false;
    } catch (error) {
        console.error("Erro fatal", error);
        showToast(requestErrorMessage(error), "error");
        return false;
    } finally {
        hideLoading();
    }
}



// =====================================================================
// Pedido: ações a partir da visão única (página ou painel da fila)
// =====================================================================

async function postForm(url, fields) {
    const body = new URLSearchParams();
    Object.entries(fields).forEach(([k, v]) => body.append(k, v ?? ''));
    try {
        const data = await postWithSession(url, body);
        if (!data.success && data.redirect) {
            window.location.href = data.redirect;
            return null;
        }
        return data;
    } catch (err) {
        showToast(requestErrorMessage(err), 'error');
        return null;
    }
}

// Pedido: mostra ou esconde as mudanças automáticas no meio da conversa
document.addEventListener('click', (e) => {
    const btn = e.target.closest('[data-history-toggle]');
    if (!btn) return;
    const feeds = btn.closest('.rv-talk')?.querySelectorAll('[data-chat-feed]') || [];
    if (!feeds.length) return;
    const on = !feeds[0].classList.contains('show-all');
    feeds.forEach(feed => feed.classList.toggle('show-all', on));
    btn.setAttribute('aria-pressed', on ? 'true' : 'false');
    btn.textContent = on ? 'Esconder histórico' : 'Mostrar histórico completo';
});

// Avisos que sobrevivem à troca de página (ex.: "Pedido concluído" ao abrir o próximo da fila)
function flashToast(message) {
    try { sessionStorage.setItem('sisreq-flash', message); } catch (e) { /* sem armazenamento: só não mostra */ }
}

// Boas-vindas do primeiro acesso (components/welcome_card.php): aparece até a pessoa tocar em "Entendi"
document.addEventListener('DOMContentLoaded', () => {
    const card = document.querySelector('[data-welcome]');
    if (!card) return;
    const key = 'sisreq-welcome-' + card.dataset.welcome;
    let seen = false;
    try { seen = localStorage.getItem(key) === '1'; } catch (e) { /* sem armazenamento: mostra sempre */ }
    if (seen) return;
    card.hidden = false;
    card.querySelector('[data-welcome-close]')?.addEventListener('click', () => {
        try { localStorage.setItem(key, '1'); } catch (e) { /* só não lembra */ }
        card.remove();
    });
});

document.addEventListener('DOMContentLoaded', () => {
    let message = null;
    try {
        message = sessionStorage.getItem('sisreq-flash');
        sessionStorage.removeItem('sisreq-flash');
    } catch (e) { /* nada a mostrar */ }
    if (message) showToast(message, 'success');
});

/**
 * Depois de uma ação.
 * Na lista (Caixa, Aprovar): recarrega a lista e as abas no lugar.
 * Na página do pedido: com opts.advance e uma fila de origem, abre o próximo pedido
 * (ou volta para a lista quando a fila acabou); sem isso, recarrega a página.
 */
async function refreshAfterChange(opts = {}) {
    const queue = document.querySelector('[data-queue]');
    if (!queue) {
        const page = document.querySelector('.detail-page[data-back]');
        if (opts.advance && page) {
            const next = page.dataset.next;
            const done = opts.message ? opts.message.trim() + ' ' : '';
            flashToast(done + (next ? 'Este é o próximo da fila.' : 'Esse era o último da fila.'));
            setTimeout(() => { window.location.href = next || page.dataset.back; }, 300);
            return;
        }
        setTimeout(() => location.reload(), 700);
        return;
    }
    try {
        const html = await (await fetch(queue.dataset.url || location.href, { headers: { 'X-Requested-With': 'fetch' } })).text();
        const doc = new DOMParser().parseFromString(html, 'text/html');
        const fresh = doc.querySelector('[data-queue]');
        if (fresh) queue.replaceWith(fresh);
        queuePending = 0; // a lista nova já traz o que estava em espera
        document.querySelectorAll('[data-sync]').forEach(el => {
            const twin = doc.querySelector(`[data-sync="${el.dataset.sync}"]`);
            if (twin) el.replaceWith(twin);
        });
        document.body.classList.remove('has-bulk');
        return true;
    } catch (err) {
        // Sem resposta (sem internet): recarregar levaria à página de erro do navegador e apagaria
        // o que está na tela. Fica a lista atual, com o aviso (sem cobrir um erro mais importante)
        if (!document.querySelector('.toast.error.show')) {
            showToast('Não deu para atualizar a lista. Confira a internet; o que está na tela pode estar desatualizado.', 'error');
        }
        return false;
    }
}

/**
 * Pedido concluído na página do pedido: confirmação em tela cheia (check verde) em vez de pular
 * sozinho para o próximo. Botões: voltar para a lista de origem e, se houver, o próximo da fila.
 * Fechar (Esc ou fora do cartão) fica no pedido, recarregado já como concluído.
 */
function showFinishedOverlay(id) {
    const page = document.querySelector('.detail-page');
    const back = page.dataset.back || page.dataset.home || 'inbox';
    const next = page.dataset.next || '';
    const title = document.querySelector('.request-view')?.dataset.title || '';
    const backLabel = back.startsWith('approve') ? 'Voltar para Aprovar' : back.startsWith('home') ? 'Voltar ao Painel' : back.startsWith('myRequests') ? 'Voltar aos meus pedidos' : 'Voltar para a Caixa';

    const wrap = document.createElement('div');
    wrap.className = 'done-overlay';
    wrap.setAttribute('role', 'dialog');
    wrap.setAttribute('aria-modal', 'true');
    wrap.setAttribute('aria-labelledby', 'doneTitle');
    wrap.innerHTML = '<div class="done-card">'
        + '<span class="done-check" aria-hidden="true"><i class="fa-solid fa-check"></i></span>'
        + '<h3 id="doneTitle">Pedido concluído</h3>'
        + '<p class="done-text">Nº ' + escapeHtml(String(id)) + (title ? ' · ' + escapeHtml(title) : '') + '</p>'
        + '<p class="done-sub">Quem pediu já foi avisado.' + (page.dataset.back && !next ? ' Esse era o último da fila.' : '') + '</p>'
        + '<div class="done-actions">'
        +   '<a class="btn" href="' + escapeHtml(back) + '"><i class="fa-solid fa-arrow-left" aria-hidden="true"></i> ' + backLabel + '</a>'
        +   (next ? '<a class="btn btn--primary" href="' + escapeHtml(next) + '">Próximo pedido <i class="fa-solid fa-arrow-right" aria-hidden="true"></i></a>' : '')
        + '</div></div>';
    document.body.appendChild(wrap);
    requestAnimationFrame(() => wrap.classList.add('show'));
    (wrap.querySelector('.btn--primary') || wrap.querySelector('.btn')).focus();

    const stay = () => { wrap.remove(); document.removeEventListener('keydown', onKey); location.reload(); };
    const onKey = (e) => { if (e.key === 'Escape') stay(); };
    document.addEventListener('keydown', onKey);
    wrap.addEventListener('click', (e) => { if (e.target === wrap) stay(); });
}

function requestAct(action, id, table, value = '') {
    return globalRequestAction(action, id, table, value);
}

// Urgência na página do pedido: confirma antes de gravar; cancelar volta a opção anterior
async function changePriority(select, id, table) {
    const before = select.querySelector(`option[value="${select.dataset.current}"]`);
    const after = select.selectedOptions[0];
    const ok = await showConfirm({
        title: 'Mudar a urgência?',
        message: `De "${before ? before.textContent : '—'}" para "${after.textContent}". Quem atende vê a nova urgência na Caixa.`,
        type: 'warning',
        confirmLabel: 'Mudar urgência'
    });
    if (!ok) {
        select.value = select.dataset.current;
        return;
    }
    const chosen = select.value;
    const ok2 = await globalRequestAction('set_priority', id, table, chosen);
    // Falhou: o campo volta para o que está gravado (antes ficava mostrando o valor novo)
    if (ok2) select.dataset.current = chosen;
    else select.value = select.dataset.current;
    return ok2;
}

// Imprimir sem sair da tela: carrega a folha num iframe oculto e abre o diálogo de impressão
function printRequest(event, url) {
    if (event.ctrlKey || event.metaKey || event.shiftKey || event.button === 1) return true; // nova aba, se a pessoa quiser
    event.preventDefault();
    document.getElementById('printFrame')?.remove();
    const frame = document.createElement('iframe');
    frame.id = 'printFrame';
    frame.title = 'Impressão';
    frame.style.cssText = 'position:fixed;right:0;bottom:0;width:0;height:0;border:0;visibility:hidden;';
    // Com embed=1 a própria folha chama print() depois que as fontes carregam
    frame.src = url + (url.includes('?') ? '&' : '?') + 'embed=1';
    document.body.appendChild(frame);
    return false;
}

// Uma ação de pedido por vez (decidir, aceitar, recusar, devolver): o 2º clique enquanto a 1ª
// ainda está na tela ou indo para o servidor é ignorado, em vez de mandar de novo
let pedidoBusy = false;
async function runPedidoAction(fn) {
    if (pedidoBusy) return;
    pedidoBusy = true;
    try {
        await fn();
    } finally {
        pedidoBusy = false;
    }
}

// Envia com o véu de carregamento e o botão tocado marcado como ocupado (showLoading)
async function postPedido(url, fields) {
    const hideLoading = showLoading();
    try {
        return await postForm(url, fields);
    } finally {
        hideLoading();
    }
}

function decideRequest(value, id, table) {
    return runPedidoAction(() => decideRequestNow(value, id, table));
}

async function decideRequestNow(value, id, table) {
    const partnerRoot = document.querySelector(`.request-view[data-id="${id}"][data-table="${table}"]`);
    let reason = '';
    if (value === 'N') {
        reason = await showConfirm({
            title: 'Recusar pedido',
            message: 'Quem pediu vai receber o motivo abaixo.',
            type: 'error', confirmLabel: 'Recusar', isDanger: true,
            input: { label: 'Motivo da recusa *', placeholder: 'Ex.: falta informar a quantidade', required: true }
        });
        if (reason === false) return;
    } else {
        const names = partnerNames(partnerRoot);
        const ok = await showConfirm({ title: 'Aprovar pedido', message: 'O pedido vai para o setor responsável começar o atendimento.' + (names ? ` Setor parceiro: ${names}.` : ''), type: 'success', confirmLabel: 'Aprovar' });
        if (!ok) return;
    }
    const data = await postPedido('api/approve_request.php', { action: 'post', id, table, value, reason, ...(value === 'Y' ? partnerFields(partnerRoot) : {}) });
    if (!data) return;
    showToast(data.message, data.success ? 'success' : 'error');
    if (data.success) await refreshAfterChange({ advance: true, message: data.message });
}

// ---------------------------------------------------------------------
// Setor parceiro (components/partner_picker.php)
// ---------------------------------------------------------------------
/** Campos do seletor para enviar. Sem seletor: nada (o servidor usa a sugestão). Sem setor: tira o parceiro. */
function partnerFields(root) {
    const box = root?.querySelector('[data-partner-picker]');
    if (!box) return {};
    const fields = {};
    [...box.querySelectorAll('[data-partner-slot]')].forEach((slot, i) => {
        if (slot.hidden) return;
        const prefix = i === 0 ? 'partner' : 'partner2';
        const area = slot.querySelector('[data-partner-area]').value;
        if (!area) {
            if (i === 0) fields.partner_area_id = '0';
            return;
        }
        fields[`${prefix}_area_id`] = area;
        fields[`${prefix}_due`] = slot.querySelector('[data-partner-due]').value;
        fields[`${prefix}_note`] = slot.querySelector('[data-partner-note]').value.trim();
    });
    return fields;
}

/** Nomes dos setores escolhidos: "Compras" ou "Compras e Marketing" */
function partnerNames(root) {
    const selects = [...(root?.querySelectorAll('[data-partner-slot]:not([hidden]) [data-partner-area]') || [])];
    return selects.filter(s => s.value).map(s => s.selectedOptions[0]?.textContent).join(' e ');
}

/** Liga o seletor ao que foi escolhido: prazo e recado, "Adicionar outro setor" e sem repetir o setor */
function syncPartnerPicker(box) {
    const slots = [...box.querySelectorAll('[data-partner-slot]')];
    const selects = slots.map(s => s.querySelector('[data-partner-area]'));
    slots.forEach((slot, i) => {
        const more = slot.querySelector('[data-partner-more]');
        if (more) more.hidden = !selects[i].value;
    });
    selects.forEach(s => [...s.options].forEach(o => {
        o.disabled = !!o.value && selects.some(other => other !== s && other.value === o.value);
    }));
    const add = box.querySelector('[data-partner-add-slot]');
    if (add) add.hidden = !selects[0].value || !slots[1].hidden;
}

document.addEventListener('change', (e) => {
    const box = e.target.closest('[data-partner-area]')?.closest('[data-partner-picker]');
    if (box) syncPartnerPicker(box);
    // Formulário de novo pedido: o segundo setor aparece depois do primeiro e não repete o mesmo
    const first = e.target.closest('[name="partner_area_id"]');
    const form = first?.closest('form');
    const field = form?.querySelector('[data-partner2-field]');
    if (!field) return;
    const second = field.querySelector('select');
    field.hidden = !first.value;
    if (!first.value || second.value === first.value) second.value = '';
    [...second.options].forEach(o => { o.disabled = !!o.value && o.value === first.value; });
});

document.addEventListener('click', (e) => {
    const btn = e.target.closest('[data-partner-add-slot]');
    if (!btn) return;
    const box = btn.closest('[data-partner-picker]');
    const second = box.querySelectorAll('[data-partner-slot]')[1];
    second.hidden = false;
    syncPartnerPicker(box);
    second.querySelector('[data-partner-area]').focus();
});

// "Tirar este setor": o segundo some; tirar o primeiro com o segundo escolhido passa o segundo para o
// lugar dele (partnerFields() manda partner_area_id=0 quando o primeiro está vazio e o segundo se perderia)
document.addEventListener('click', (e) => {
    const btn = e.target.closest('[data-partner-remove]');
    if (!btn) return;
    const box = btn.closest('[data-partner-picker]');
    const slots = [...box.querySelectorAll('[data-partner-slot]')];
    const slot = btn.closest('[data-partner-slot]');
    const field = (s, name) => s.querySelector(`[data-partner-${name}]`);
    const clear = (s) => { field(s, 'area').value = ''; field(s, 'note').value = ''; };
    const second = slots[1];
    if (slot === slots[0] && second && !second.hidden && field(second, 'area').value) {
        ['area', 'due', 'note'].forEach(name => { field(slots[0], name).value = field(second, name).value; });
        clear(second);
        second.hidden = true;
    } else {
        clear(slot);
        if (slot === second) slot.hidden = true;
    }
    syncPartnerPicker(box);
    field(slots[0], 'area').focus();
});

function addPartner(id, table, button) {
    return runPedidoAction(() => addPartnerNow(id, table, button));
}

async function addPartnerNow(id, table, button) {
    const root = button.closest('[data-partner-section]');
    const fields = partnerFields(root);
    if (!fields.partner_area_id || fields.partner_area_id === '0') {
        showToast('Escolha o setor parceiro.', 'error');
        root?.querySelector('[data-partner-area]')?.focus();
        return;
    }
    const name = root.querySelector('[data-partner-area]').selectedOptions[0]?.textContent || 'O setor';
    const ok = await showConfirm({ title: 'Incluir setor parceiro', message: `${name} recebe um aviso e precisa aceitar a parte. O setor responsável só conclui depois que ${name} devolver.`, type: 'info', confirmLabel: 'Incluir' });
    if (!ok) return;
    const data = await postPedido('api/request_forwards.php', { action: 'add_partner', id, table, ...fields });
    if (!data) return;
    showToast(data.message, data.success ? 'success' : 'error');
    if (data.success) await refreshAfterChange({ message: data.message });
}

function forwardAct(action, forwardId, mode = 'help', toLabel = '') {
    return runPedidoAction(() => forwardActNow(action, forwardId, mode, toLabel));
}

async function forwardActNow(action, forwardId, mode = 'help', toLabel = '') {
    const fields = { action, forward_id: forwardId };
    if (action === 'accept') {
        const ok = await showConfirm(mode === 'partner'
            ? { title: 'Aceitar a parte', message: 'Seu setor faz esta parte e depois devolve com um retorno. O pedido continua com o setor responsável.', type: 'info', confirmLabel: 'Aceitar a parte' }
            : mode === 'transfer'
            ? { title: 'Assumir o pedido', message: 'Seu setor passa a cuidar do pedido inteiro e a falar com quem pediu. O setor que enviou só acompanha.', type: 'info', confirmLabel: 'Aceitar e assumir' }
            : { title: 'Aceitar ajuda', message: 'Seu setor faz esta parte e depois devolve com um retorno. O pedido continua com o setor que pediu ajuda.', type: 'info', confirmLabel: 'Aceitar ajuda' });
        if (!ok) return;
    } else if (action === 'refuse') {
        const reason = await showConfirm({
            title: mode === 'partner' ? 'Recusar a parte' : 'Recusar encaminhamento',
            message: mode === 'partner' ? 'A coordenação e o setor responsável vão receber o motivo.' : 'Quem enviou vai receber o motivo.', type: 'error', confirmLabel: 'Recusar', isDanger: true,
            input: { label: 'Por que não é com vocês? *', placeholder: 'Ex.: isso é com a Manutenção, não com Compras', required: true, minLength: 5 }
        });
        if (reason === false) return;
        fields.reason = reason;
    } else if (action === 'return') {
        const note = await showConfirm({
            title: 'Devolver ajuda', message: 'O setor responsável recebe o que você escrever.', type: 'success', confirmLabel: 'Devolver',
            input: { label: 'O que foi feito? *', placeholder: 'Ex.: compramos 2 cabos HDMI, já estão na secretaria', required: true, minLength: 5 }
        });
        if (note === false) return;
        fields.note = note;
    } else if (action === 'cancel' && mode === 'help_accepted') {
        // Ajuda já aceita que não volta: encerrar libera Concluir; o outro setor recebe o motivo
        const who = toLabel || 'O outro setor';
        const reason = await showConfirm({
            title: 'Encerrar a ajuda?',
            message: `${who} deixa de ajudar neste pedido e recebe o motivo. Depois disso, o pedido pode ser concluído sem esperar a devolução.`,
            type: 'warning', confirmLabel: 'Encerrar a ajuda', cancelLabel: 'Voltar', isDanger: true,
            input: { label: 'Por que encerrar a ajuda? *', placeholder: 'Ex.: resolvemos por conta própria', required: true, minLength: 5 }
        });
        if (reason === false) return;
        fields.reason = reason;
    } else if (action === 'cancel') {
        const ok = await showConfirm({ title: mode === 'partner' ? 'Tirar setor parceiro' : 'Cancelar envio', message: mode === 'partner' ? 'Este setor deixa de ver o pedido, e o setor responsável não precisa mais esperar a parte dele.' : 'O outro setor deixa de ver este encaminhamento.', type: 'warning', confirmLabel: mode === 'partner' ? 'Tirar parceiro' : 'Cancelar envio', cancelLabel: 'Voltar' });
        if (!ok) return;
    }
    const data = await postPedido('api/request_forwards.php', fields);
    if (!data) return;
    showToast(data.message, data.success ? 'success' : 'error');
    // Recusou: o pedido some para este setor, segue para o próximo. Devolveu: fica no pedido (agora só
    // consulta) para ver o que foi feito; não pula para outro com o botão de ação já pronto
    if (data.success) await refreshAfterChange({ advance: action === 'refuse', message: data.message });
}

// ---------------------------------------------------------------------
// Atividade do pedido: o pedido, os eventos e a conversa numa linha do tempo só
// ---------------------------------------------------------------------
document.addEventListener('submit', async (e) => {
    const form = e.target.closest('[data-chat-form]');
    if (!form) return;
    e.preventDefault();
    // Enter repetido enquanto a mensagem ainda está indo não envia de novo
    if (form.dataset.busy) return;
    const textarea = form.querySelector('textarea');
    const text = textarea.value.trim();
    if (!text) { textarea.focus(); return; }
    const btn = form.querySelector('button');
    form.dataset.busy = '1';
    btn.disabled = true;
    // Conversa da aba aberta (o servidor confere se a pessoa pode escrever nela)
    const channel = form.elements.channel?.value || 'public';
    const area = form.elements.area?.value || '';
    const data = await postForm('api/add_comment.php', { id: form.dataset.id, table: form.dataset.table, comment: text, channel, area });
    btn.disabled = false;
    delete form.dataset.busy;
    if (data && data.success) {
        textarea.value = ''; // continua na mesma conversa para a próxima mensagem
        textarea.style.height = '';
        await reloadChat(form.closest('.request-view'));
        form.scrollIntoView({ block: 'nearest' });
    } else if (data) {
        showToast(data.message || 'Não foi possível enviar a mensagem.', 'error');
    }
});

document.addEventListener('keydown', (e) => {
    const area = e.target.closest('[data-chat-form] textarea');
    if (area && e.key === 'Enter' && !e.shiftKey && window.innerWidth >= 900) {
        e.preventDefault();
        area.form.requestSubmit();
    }
});

document.addEventListener('input', (e) => {
    const area = e.target.closest('[data-chat-form] textarea');
    if (area) { area.style.height = ''; area.style.height = area.scrollHeight + 'px'; }
});

// Conversas separadas (abas do components/request_view.php): cada aba tem a sua linha do tempo e o
// seu campo de mensagem (ou o aviso de só leitura), todos com data-thread; a aba mostra os dela.
// Fica lembrada por pedido, para valer também depois que a visão é redesenhada (reloadRequestView)
const chatThreadPicked = new Map();

function selectChatThread(view, key, focus = false) {
    const tab = [...view.querySelectorAll('[data-chat-thread]')].find(t => t.dataset.chatThread === key);
    if (!tab) return;
    chatThreadPicked.set(view.dataset.id + ':' + view.dataset.table, key);
    view.querySelectorAll('[data-chat-thread]').forEach(t => {
        t.setAttribute('aria-selected', t === tab ? 'true' : 'false');
        t.tabIndex = t === tab ? 0 : -1;
    });
    tab.classList.remove('has-new'); // viu a conversa; volta a marcar se chegar outra pelo redesenho
    view.querySelectorAll('[data-chat-feed], [data-chat-form], .chat-readonly').forEach(el => { el.hidden = el.dataset.thread !== key; });
    if (focus) tab.focus();
}

document.addEventListener('click', (e) => {
    const tab = e.target.closest('[data-chat-thread]');
    if (!tab) return;
    const view = tab.closest('.request-view');
    selectChatThread(view, tab.dataset.chatThread);
    reloadChat(view);
});

// Celular com botões de ação: o botão de conversa troca as ações pelo campo de mensagem e o × volta
document.addEventListener('click', (e) => {
    const toggle = e.target.closest('[data-write-toggle]');
    if (!toggle) return;
    const dock = toggle.closest('.rv-dock');
    const writing = dock.classList.toggle('is-writing');
    const openBtn = dock.querySelector('.rv-write-btn');
    openBtn?.setAttribute('aria-expanded', writing ? 'true' : 'false');
    if (writing) dock.querySelector('[data-chat-form]:not([hidden]) textarea')?.focus();
    else openBtn?.focus();
});

// Setas, Home e End entre as abas de conversa (padrão de abas WAI-ARIA)
document.addEventListener('keydown', (e) => {
    const tab = e.target.closest('[data-chat-thread]');
    if (!tab) return;
    const tabs = [...tab.parentElement.querySelectorAll('[data-chat-thread]')];
    const i = tabs.indexOf(tab);
    const next = { ArrowRight: tabs[(i + 1) % tabs.length], ArrowLeft: tabs[(i + tabs.length - 1) % tabs.length], Home: tabs[0], End: tabs[tabs.length - 1] }[e.key];
    if (!next) return;
    e.preventDefault();
    const view = tab.closest('.request-view');
    selectChatThread(view, next.dataset.chatThread, true);
    reloadChat(view);
});

// Atualiza a conversa aberta sem recarregar a página (só redesenha se algo mudou)
async function reloadChat(view) {
    const feed = view && view.querySelector('[data-chat-feed]:not([hidden])');
    // Uma atualização por vez: com o banco lento, os ciclos não se acumulam
    if (!feed || document.hidden || feed.dataset.loading) return;
    feed.dataset.loading = '1';
    try {
        const response = await fetch(`api/get_comments.php?id=${feed.dataset.id}&table=${feed.dataset.table}&thread=${encodeURIComponent(feed.dataset.thread || '')}`);
        if (!response.ok) return;
        const box = document.createElement('div');
        box.innerHTML = await response.text();
        if (box.innerHTML !== feed.innerHTML) feed.innerHTML = box.innerHTML;
    } catch (err) { /* tenta de novo no próximo ciclo */ } finally {
        delete feed.dataset.loading;
    }
}

setInterval(() => document.querySelectorAll('.request-view').forEach(reloadChat), 15000);

// ---------------------------------------------------------------------
// Escolher vários pedidos e resolver de uma vez (Caixa: Começar, Concluir).
// A tela Aprovar não tem lote: cada pedido é decidido sozinho.
// ---------------------------------------------------------------------
// Avisos que chegaram com pedidos marcados: a fila só redesenha quando a seleção esvaziar
var queuePending = 0; // var: usada também por funções declaradas antes daqui
function showQueuePending() {
    const el = document.querySelector('[data-queue] [data-bulk-pending]');
    if (!el) return;
    el.hidden = queuePending === 0;
    el.textContent = queuePending === 1 ? ' · 1 atualização em espera' : ` · ${queuePending} atualizações em espera`;
}

function bulkPicked(group = '') {
    return [...document.querySelectorAll('[data-queue] [data-pick]:checked')]
        .filter(i => !group || i.dataset.group === group)
        .map(i => {
            const [table, id] = i.value.split(':');
            return { table, id };
        });
}

function updateBulkBar() {
    const bar = document.querySelector('[data-queue] [data-bulk]');
    if (!bar) return;
    const picks = document.querySelectorAll('[data-queue] [data-pick]');
    const n = bulkPicked().length;
    bar.hidden = n === 0;
    bar.querySelector('[data-bulk-count]').textContent = n === 1 ? '1 pedido selecionado' : `${n} pedidos selecionados`;
    // Botões por grupo: "Começar atendimento (2)" age só nos novos marcados; sem nenhum, fica desativado
    bar.querySelectorAll('[data-bulk-group]').forEach(btn => {
        const k = bulkPicked(btn.dataset.bulkGroup).length;
        btn.disabled = k === 0;
        const label = btn.querySelector('[data-bulk-n]');
        if (label) label.textContent = `(${k})`;
    });
    picks.forEach(i => i.closest('.queue-row, .inbox-row')?.classList.toggle('picked', i.checked));
    const all = document.querySelector('[data-queue] [data-pick-all]');
    if (all) {
        all.checked = n > 0 && n === picks.length;
        all.indeterminate = n > 0 && n < picks.length;
    }
    document.body.classList.toggle('has-bulk', n > 0);
    showQueuePending();
    // Seleção esvaziou com avisos em espera: agora a fila pode redesenhar
    if (n === 0 && queuePending > 0) refreshAfterChange();
}

document.addEventListener('change', (e) => {
    if (e.target.matches('[data-pick-all]')) {
        document.querySelectorAll('[data-queue] [data-pick]').forEach(i => { i.checked = e.target.checked; });
    }
    if (e.target.matches('[data-pick], [data-pick-all]')) updateBulkBar();
});

document.addEventListener('click', async (e) => {
    if (e.target.closest('[data-bulk-clear]')) {
        document.querySelectorAll('[data-queue] [data-pick]').forEach(i => { i.checked = false; });
        updateBulkBar();
        return;
    }
    const btn = e.target.closest('[data-bulk-action]');
    if (!btn) return;
    const items = bulkPicked(btn.dataset.bulkGroup || '');
    if (!items.length) return;
    const action = btn.dataset.bulkAction;
    const n = items.length;
    const plural = n === 1 ? 'o pedido' : `os ${n} pedidos`;

    const texts = {
        start: [`Começar ${plural}?`, 'Quem pediu é avisado que o atendimento começou.', 'Começar', 'info'],
        finish: [`Concluir ${plural}?`, 'Quem pediu é avisado que o pedido foi atendido.', 'Concluir', 'success'],
    }[action];
    if (!texts) return;
    if (n > 1 || action === 'finish') {
        const confirmed = await showConfirm({ title: texts[0], message: texts[1], confirmLabel: texts[2], type: texts[3] });
        if (!confirmed) return;
    }

    const bar = btn.closest('[data-bulk]');
    const count = bar.querySelector('[data-bulk-count]');
    bar.querySelectorAll('button').forEach(b => { b.disabled = true; });
    const verb = { start: 'Começando', finish: 'Concluindo' }[action];

    let ok = 0;
    const errors = [];
    for (const [i, item] of items.entries()) {
        count.textContent = `${verb} ${i + 1} de ${n}...`;
        const data = await postForm('api/request_actions.php', { id: item.id, table: `ctd_${item.table}_frm`, action: action === 'start' ? 'start_progress' : 'finish' });
        if (data && data.success) ok++;
        else errors.push(`nº ${item.id}: ${(data && data.message) || 'sem conexão'}`);
    }

    const done = { start: ['em atendimento', 'em atendimento'], finish: ['concluído', 'concluídos'] }[action];
    if (!errors.length) {
        showToast(n === 1 ? `Pedido ${done[0]}.` : `${ok} pedidos ${done[1]}.`, 'success');
    } else {
        showToast(`${ok} de ${n} deram certo. Não foi possível: ${errors.join('; ')}`, 'error');
    }
    document.body.classList.remove('has-bulk');
    refreshAfterChange();
});
