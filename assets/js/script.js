function attachCsrfToken(payload) {
    const token = window.CSRF_TOKEN || '';
    if (!token) return payload;
    if (payload instanceof FormData) {
        if (!payload.has('csrf_token')) payload.append('csrf_token', token);
        return payload;
    }
    if (payload instanceof URLSearchParams) {
        if (!payload.has('csrf_token')) payload.append('csrf_token', token);
        return payload;
    }
    return payload;
}

async function fetchAjax(path, formData) {
    const overlay = document.querySelector('.loading-overlay');
    if (overlay) overlay.style.display = "flex";
    try {
        const resp = await fetch(path, {
            method: "POST",
            body: attachCsrfToken(formData)
        });
        const data = await resp.json();

        if (!data.success && data.redirect) {
            window.location.href = data.redirect;
            return false;
        }

        if (data.success) {
            return data;
        } else {
            // Se showToast for preferido globalmente ou showAlert não existir
            if (typeof showAlert === 'function') {
                await showAlert({ title: 'Atenção', message: data.message || 'Não foi possível concluir a operação.', type: 'warning' });
            } else {
                showToast(data.message || 'Não foi possível concluir a operação.', 'error');
            }
            return false;
        }
    } catch (e) {
        if (typeof showAlert === 'function') {
            await showAlert({ title: 'Erro de Comunicação', message: 'Não foi possível completar a requisição. Verifique sua conexão.', type: 'error' });
        } else {
            showToast('Erro de comunicação. Verifique sua conexão.', 'error');
        }
        return false;
    } finally {
        if (overlay) overlay.style.display = "none";
    }
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
    approve:  ['fa-inbox',             'kind-approve'],
    approved: ['fa-circle-check',      'kind-ok'],
    done:     ['fa-check-double',      'kind-ok'],
    refused:  ['fa-circle-xmark',      'kind-bad'],
    forward:  ['fa-share-from-square', 'kind-forward'],
    message:  ['fa-comment-dots',      'kind-message'],
    started:  ['fa-gears',             'kind-info'],
    priority: ['fa-bolt',              'kind-warn'],
    info:     ['fa-bell',              'kind-info'],
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
        const response = await fetch('api/get_notifications.php', { cache: 'no-store' });
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
    notifBadge.style.display = count > 0 ? 'grid' : 'none';
    notifBtn.setAttribute('aria-label', count > 0 ? `Avisos: ${count} não lidos` : 'Avisos');
    document.title = (count > 0 ? `(${count}) ` : '') + notifState.baseTitle;
    const markAll = document.getElementById('notif-mark-all');
    if (markAll) markAll.disabled = count === 0;
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
    return date.toLocaleDateString('pt-BR', { day: '2-digit', month: '2-digit' }) + ' às ' + date.toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' });
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
    return `<span class="notif-icon ${cls}" aria-hidden="true"><i class="fa-solid ${icon}"></i></span>`;
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

// Abre o pedido do aviso: no painel da fila quando ele está na lista, senão a página
function openNotification(id, link) {
    if (id) markNotifRead(id, true);
    const m = /request_detail\?id=(\d+)&table=([a-z]+)/.exec(link);
    if (m && window.innerWidth >= 900 && document.getElementById('requestPanel')) {
        const row = document.querySelector(`[data-queue] .row-item[data-table="${m[2]}"][data-id="${m[1]}"]`);
        if (row) { openInPanel(m[2], m[1], row); closeNotifDropdown(); return; }
    }
    window.location.href = link;
}

// O aviso fala do que está aberto? Atualiza sem a pessoa precisar recarregar
function refreshForNotifications(fresh) {
    const links = fresh.map(n => String(n.link || ''));
    if (document.querySelector('[data-queue]') && typeof refreshAfterChange === 'function') {
        const panelView = document.querySelector('#requestPanel .request-view');
        const typing = panelView?.querySelector('[data-chat-form] textarea')?.value.trim();
        refreshAfterChange({ keepPanel: !!typing });
        if (typing) reloadChat(panelView);
        return;
    }
    document.querySelectorAll('.request-view').forEach(view => {
        const link = `request_detail?id=${view.dataset.id}&table=${view.dataset.table}`;
        if (!links.includes(link)) return;
        const typing = view.querySelector('[data-chat-form] textarea')?.value.trim();
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
        const html = await (await fetch(`api/request_view.php?id=${view.dataset.id}&table=${view.dataset.table}&context=page`)).text();
        const box = document.createElement('div');
        box.innerHTML = html;
        const fresh = box.querySelector('.request-view');
        if (!fresh) return;
        view.replaceWith(fresh);
        initRequestView(fresh.parentElement);
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

        const success = await fetchAjax("config/login.php", formData);
        if (success) {
            window.location.href = 'home';
        } else {
            isSubmittingLogin = false;
            btn_login.innerHTML = originalText;
            btn_login.disabled = false;
        }
    });
}
let btn_logout = document.querySelector('#btn_logout');

if (btn_logout) {
    btn_logout.addEventListener('click', async (e) => {
        e.preventDefault();

        let formData = new FormData();

        const success = await fetchAjax("config/logout.php", formData);
        if (success) {
            window.location.reload()
        }
    });
}

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
        title: 'Escreva um título curto.',
        end: 'Escolha a data em que você precisa.',
        details: 'Explique o que você precisa.',
        file: 'Informe qual arquivo deve ser impresso.',
        qtd: 'Informe quantas cópias (número maior que zero).',
        model: 'Escolha uma opção.',
        outros_texto: 'Descreva qual é a outra opção.'
    };
    let firstInvalid = null;
    const checkedGroups = new Set();

    form.querySelectorAll('[required]').forEach(field => {
        if (field.type === 'radio') {
            if (checkedGroups.has(field.name)) return;
            checkedGroups.add(field.name);
            if (!form.querySelector(`input[name="${field.name}"]:checked`)) {
                showFieldError(field, messages[field.name] || 'Escolha uma opção.');
                firstInvalid = firstInvalid || field;
            }
            return;
        }
        const value = field.value.trim();
        let invalid = value === '';
        if (!invalid && field.type === 'date' && value < todayIso()) {
            showFieldError(field, 'A data não pode ser no passado.');
            firstInvalid = firstInvalid || field;
            return;
        }
        if (!invalid && field.type === 'number' && !(parseInt(value, 10) > 0)) invalid = true;
        if (invalid) {
            showFieldError(field, messages[field.name] || 'Preencha este campo.');
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

function showRequestSuccess(container, data) {
    const id = parseInt(data.id, 10);
    const title = document.querySelector('.form_request #title')?.value || '';
    container.innerHTML = `
        <div class="request-success" role="status">
            <h2><i class="fa-solid fa-circle-check" style="color:var(--ok)" aria-hidden="true"></i> Pedido enviado</h2>
            <div class="ficha ficha--hero" data-sector="${escapeHtml(data.table)}">
                <span class="ficha__stub"><span class="ficha__num"><small>Nº</small>${id}</span><span class="ficha__sector-bar" aria-hidden="true"></span></span>
                <span class="ficha__body">
                    <span class="ficha__title">${escapeHtml(title)}</span>
                    <span><span class="status-pill status-pill--P">Aguardando aprovação</span></span>
                </span>
            </div>
            <p>Anote o número <strong>${id}</strong>. Você recebe um aviso no sino <i class="fa-solid fa-bell" aria-hidden="true"></i> quando a situação mudar.</p>
            <div class="request-success__actions">
                <a class="btn btn--primary" href="${escapeHtml(safeInternalLink(data.link))}"><i class="fa-solid fa-eye" aria-hidden="true"></i> Ver meu pedido</a>
                <a class="btn" href="new_request"><i class="fa-solid fa-plus" aria-hidden="true"></i> Fazer outro</a>
                <a class="btn" href="myRequests"><i class="fa-solid fa-list" aria-hidden="true"></i> Meus pedidos</a>
            </div>
        </div>`;
    container.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

function bindFormRequests() {
    const form_requests = document.querySelector('.form_request');
    if (!form_requests) return;

    // Data mínima = hoje
    form_requests.querySelectorAll('input[type="date"]').forEach(input => { input.min = todayIso(); });

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

        const data = await fetchAjax("config/addRequest.php", formData);
        if (data && data.success) {
            showRequestSuccess(document.getElementById('dynamicForm') || form_requests.parentElement, data);
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
async function globalRequestAction(action, id, table, value = '') {
    // Configurações de mensagens de confirmação
    const confirmations = {
        'reset_status': 'Reabrir esta requisição? Ela volta para "Em atendimento".',
        'finish': 'Marcar como concluída? O solicitante será avisado que o pedido foi atendido.',
        'approve': 'Deseja APROVAR esta requisição?',
        'reject': 'Deseja RECUSAR esta requisição?',
        'delete': 'Deseja cancelar esta requisição? Ela será apagada e não poderá ser recuperada.'
    };

    // Se houver uma mensagem definida para a ação, pede confirmação via Custom Modal
    if (confirmations[action]) {
        const confirmed = await customConfirm(confirmations[action]);
        if (!confirmed) return;
    }

    if (!id || !table) {
        showToast("Dados da requisição não encontrados.", "error");
        return;
    }

    let fullTable = table;
    if (!table.startsWith('ctd_')) fullTable = 'ctd_' + table + '_frm';

    const overlay = document.querySelector('.loading-overlay');
    if (overlay) overlay.style.display = "flex";

    try {
        const formData = new URLSearchParams();
        formData.append('id', id);
        formData.append('table', fullTable);
        formData.append('action', action);
        formData.append('value', value);

        const response = await fetch('api/request_actions.php', {
            method: 'POST',
            body: attachCsrfToken(formData)
        });
        
        const data = await response.json();

        if (!data.success && data.redirect) {
            window.location.href = data.redirect;
            return;
        }

        if (data.success) {
            showToast(data.message, 'success');
            // Após cancelar, a requisição não existe mais: volta para a lista
            if (action === 'delete') {
                setTimeout(() => { window.location.href = 'myRequests'; }, 900);
            } else {
                refreshAfterChange();
            }
        } else {
            showToast(data.message || 'Não foi possível concluir a ação.', 'error');
        }
    } catch (error) {
        console.error("Erro fatal", error);
        showToast("Falha na comunicação com o servidor.", "error");
    } finally {
        if (overlay) overlay.style.display = "none";
    }
}

const formCard = document.getElementById("modalReqCard");

if (formCard) {
    let lastClickedButton = null;
    formCard.addEventListener("click", (e) => {
        const btn = e.target.closest("button");
        if (btn) lastClickedButton = btn;
    });

    formCard.addEventListener("submit", async (e) => {
        e.preventDefault();
        
        const btn = e.submitter || lastClickedButton || document.activeElement;
        const actionValue = btn ? btn.value : null;
        const actionName = btn ? btn.getAttribute('name') : null;
        
        const id = formCard.querySelector(".Rid")?.value;
        const table = formCard.querySelector(".Rtype")?.value;

        if (!id || !table) {
            showToast("Selecione uma requisição primeiro.", "info");
            return;
        }

        if (actionName === 'action_value' && (actionValue === 'start_progress' || actionValue === 'finish' || actionValue === 'delete')) {
            await globalRequestAction(actionValue, id, table);
        } 
        else if (actionValue === 'Y' || actionValue === 'N') {
            const val = actionValue;
            let reason = '';
            if (val === 'N') {
                reason = await showConfirm({
                    title: 'Recusar requisição',
                    message: 'O solicitante vai receber o motivo abaixo.',
                    type: 'error',
                    confirmLabel: 'Recusar',
                    isDanger: true,
                    input: { label: 'Motivo da recusa *', placeholder: 'Ex.: falta informar a quantidade', required: true }
                });
                if (reason === false) return;
            } else {
                const ok = await showConfirm({
                    title: 'Aprovar requisição',
                    message: 'A requisição será enviada ao setor responsável para atendimento.',
                    type: 'success',
                    confirmLabel: 'Aprovar'
                });
                if (!ok) return;
            }

            let formData = new FormData(formCard);
            formData.append('action', 'post');
            formData.append('id', id);
            formData.append('table', table);
            formData.append('value', val);
            formData.append('reason', reason);

            const data = await fetchAjax("config/aprovRequest.php", formData);
            if (data && data.success) {
                showToast(data.message, 'success');
                setTimeout(() => location.reload(), 1000);
            } else if (data) {
                showToast(data.message, 'error');
            }
        }
    });
}
// Filtros de setor/situação: transforma as bolinhas em botões com o nome escrito
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('input.radioType, input.statusRadio').forEach(input => {
        if (input.closest('label.chip-radio')) return;
        const text = input.dataset.tooltip === 'Todas req' ? 'Todos' : (input.dataset.tooltip || input.value);
        const label = document.createElement('label');
        label.className = 'chip-radio';
        input.parentNode.insertBefore(label, input);
        label.appendChild(input);
        const span = document.createElement('span');
        span.textContent = text;
        label.appendChild(span);
    });
});


// =====================================================================
// Pedido: ações a partir da visão única (página ou painel da fila)
// =====================================================================

async function postForm(url, fields) {
    const body = new URLSearchParams();
    Object.entries(fields).forEach(([k, v]) => body.append(k, v ?? ''));
    try {
        const response = await fetch(url, { method: 'POST', body: attachCsrfToken(body) });
        const data = await response.json();
        if (!data.success && data.redirect) {
            window.location.href = data.redirect;
            return null;
        }
        return data;
    } catch (err) {
        showToast('Sem conexão. Tente de novo.', 'error');
        return null;
    }
}

// Depois de uma ação: no painel da fila, recarrega lista + painel sem sair da tela; na página, recarrega a página
async function refreshAfterChange(opts = {}) {
    const queue = document.querySelector('[data-queue]');
    if (!queue) {
        setTimeout(() => location.reload(), 700);
        return;
    }
    const panel = document.getElementById('requestPanel');
    const key = panel ? panel.dataset.key : null;
    const advance = queue.hasAttribute('data-advance');
    const rows = [...queue.querySelectorAll('.row-item')];
    const oldIndex = key ? rows.findIndex(r => r.dataset.table + ':' + r.dataset.id === key) : -1;
    try {
        const html = await (await fetch(queue.dataset.url || location.href, { headers: { 'X-Requested-With': 'fetch' } })).text();
        const doc = new DOMParser().parseFromString(html, 'text/html');
        const fresh = doc.querySelector('[data-queue]');
        if (fresh) {
            // o painel continua o mesmo elemento (evita piscar)
            const freshPanel = fresh.querySelector('#requestPanel');
            if (freshPanel && panel) freshPanel.replaceWith(panel);
            queue.replaceWith(fresh);
        }
        document.querySelectorAll('[data-sync]').forEach(el => {
            const twin = doc.querySelector(`[data-sync="${el.dataset.sync}"]`);
            if (twin) el.replaceWith(twin);
        });
        bindQueue();
        if (!key) return;
        if (opts.keepPanel) {
            const current = document.querySelector(`[data-queue] .row-item[data-table="${key.split(':')[0]}"][data-id="${key.split(':')[1]}"]`);
            if (current) current.classList.add('selected');
            return;
        }
        const [table, id] = key.split(':');
        const row = document.querySelector(`[data-queue] .row-item[data-table="${table}"][data-id="${id}"]`);
        if (row || !advance) {
            openInPanel(table, id, row);
            return;
        }
        // Aprovar pedidos: o pedido decidido sai da lista, abre o próximo
        const next = document.querySelectorAll('[data-queue] .row-item')[Math.max(0, oldIndex)] || document.querySelector('[data-queue] .row-item');
        if (next) {
            openInPanel(next.dataset.table, next.dataset.id, next);
        } else if (panel) {
            panel.innerHTML = '<div class="empty-state"><i class="fa-regular fa-circle-check" aria-hidden="true"></i><p>Tudo em dia: nenhum pedido aguardando.</p></div>';
            delete panel.dataset.key;
        }
    } catch (err) {
        location.reload();
    }
}

function requestAct(action, id, table, value = '') {
    return globalRequestAction(action, id, table, value);
}

async function decideRequest(value, id, table) {
    let reason = '';
    if (value === 'N') {
        reason = await showConfirm({
            title: 'Recusar pedido',
            message: 'O solicitante vai receber o motivo abaixo.',
            type: 'error', confirmLabel: 'Recusar', isDanger: true,
            input: { label: 'Motivo da recusa *', placeholder: 'Ex.: falta informar a quantidade', required: true }
        });
        if (reason === false) return;
    } else {
        const ok = await showConfirm({ title: 'Aprovar pedido', message: 'O pedido vai para o setor responsável começar o atendimento.', type: 'success', confirmLabel: 'Aprovar' });
        if (!ok) return;
    }
    const data = await postForm('config/aprovRequest.php', { action: 'post', id, table, value, reason });
    if (!data) return;
    showToast(data.message, data.success ? 'success' : 'error');
    if (data.success) refreshAfterChange();
}

async function forwardAct(action, forwardId) {
    const fields = { action, forward_id: forwardId };
    if (action === 'accept') {
        const ok = await showConfirm({ title: 'Aceitar encaminhamento', message: 'Seu setor passa a cuidar desta parte do pedido.', type: 'info', confirmLabel: 'Aceitar' });
        if (!ok) return;
    } else if (action === 'refuse') {
        const reason = await showConfirm({
            title: 'Recusar encaminhamento', message: 'Quem enviou vai receber o motivo.', type: 'error', confirmLabel: 'Recusar', isDanger: true,
            input: { label: 'Por que não é com vocês? *', placeholder: 'Ex.: isso é com a Manutenção, não com Compras', required: true }
        });
        if (reason === false) return;
        fields.reason = reason;
    } else if (action === 'return') {
        const note = await showConfirm({
            title: 'Devolver ajuda', message: 'O setor responsável recebe o que você escrever.', type: 'success', confirmLabel: 'Devolver',
            input: { label: 'O que foi feito? *', placeholder: 'Ex.: compramos 2 cabos HDMI, já estão na secretaria', required: true }
        });
        if (note === false) return;
        fields.note = note;
    } else if (action === 'cancel') {
        const ok = await showConfirm({ title: 'Cancelar envio', message: 'O outro setor deixa de ver este encaminhamento.', type: 'warning', confirmLabel: 'Cancelar envio', cancelLabel: 'Voltar' });
        if (!ok) return;
    }
    const data = await postForm('api/request_forwards.php', fields);
    if (!data) return;
    showToast(data.message, data.success ? 'success' : 'error');
    if (data.success) refreshAfterChange();
}

// ---------------------------------------------------------------------
// Conversa (funciona em qualquer visão do pedido, inclusive no painel)
// ---------------------------------------------------------------------
document.addEventListener('submit', async (e) => {
    const form = e.target.closest('[data-chat-form]');
    if (!form) return;
    e.preventDefault();
    const textarea = form.querySelector('textarea');
    const text = textarea.value.trim();
    if (!text) { textarea.focus(); return; }
    const btn = form.querySelector('button');
    btn.disabled = true;
    const data = await postForm('api/add_comment.php', { id: form.dataset.id, table: form.dataset.table, comment: text });
    btn.disabled = false;
    if (data && data.success) {
        form.reset();
        textarea.style.height = '';
        reloadChat(form.closest('.request-view'));
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

async function reloadChat(view) {
    const feed = view && view.querySelector('[data-chat-feed]');
    if (!feed || document.hidden) return;
    try {
        const html = await (await fetch(`api/get_comments.php?id=${feed.dataset.id}&table=${feed.dataset.table}`)).text();
        if (feed.innerHTML.trim() === html.trim()) return;
        const atBottom = feed.scrollHeight - feed.scrollTop <= feed.clientHeight + 150;
        feed.innerHTML = html;
        if (atBottom) feed.scrollTop = feed.scrollHeight;
    } catch (err) { /* tenta de novo no próximo ciclo */ }
}

function initRequestView(root = document) {
    root.querySelectorAll('[data-chat-feed]').forEach(feed => { feed.scrollTop = feed.scrollHeight; });
    if (window.innerWidth >= 900) {
        root.querySelectorAll('.rv-timeline[data-open-desktop]').forEach(d => d.setAttribute('open', ''));
    }
}

setInterval(() => document.querySelectorAll('.request-view').forEach(reloadChat), 15000);
document.addEventListener('DOMContentLoaded', () => initRequestView());

// ---------------------------------------------------------------------
// Filas (Caixa do setor, Aprovar pedidos): lista à esquerda, pedido à direita
// ---------------------------------------------------------------------
async function openInPanel(table, id, row) {
    const panel = document.getElementById('requestPanel');
    if (!panel) return false;
    document.querySelectorAll('[data-queue] .row-item').forEach(r => r.classList.remove('selected'));
    if (row) row.classList.add('selected');
    panel.setAttribute('aria-busy', 'true');
    try {
        const response = await fetch(`api/request_view.php?id=${encodeURIComponent(id)}&table=${encodeURIComponent(table)}`);
        panel.innerHTML = await response.text();
        panel.dataset.key = table + ':' + id;
        initRequestView(panel);
        if (typeof updateNotifications === 'function') updateNotifications(); // abrir o pedido marcou os avisos dele como lidos
        const url = new URL(location.href);
        url.searchParams.set('sel', table + ':' + id);
        history.replaceState(null, '', url);
    } catch (err) {
        panel.innerHTML = '<div class="empty-state"><p>Não foi possível abrir o pedido. Tente de novo.</p></div>';
    }
    panel.removeAttribute('aria-busy');
    return true;
}

function bindQueue() {
    document.querySelectorAll('[data-queue] .row-item').forEach(row => {
        row.addEventListener('click', (e) => {
            if (window.innerWidth < 900 || !document.getElementById('requestPanel')) return; // celular: abre a página do pedido
            e.preventDefault();
            openInPanel(row.dataset.table, row.dataset.id, row);
        });
    });
}

document.addEventListener('DOMContentLoaded', () => {
    bindQueue();
    if (window.innerWidth < 900) return;
    const sel = new URL(location.href).searchParams.get('sel');
    const first = sel
        ? document.querySelector(`[data-queue] .row-item[data-table="${sel.split(':')[0]}"][data-id="${sel.split(':')[1]}"]`)
        : document.querySelector('[data-queue] .row-item');
    if (first) openInPanel(first.dataset.table, first.dataset.id, first);
});
