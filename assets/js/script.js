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
        const resp = await fetch(path, { method: 'POST', body: attachCsrfToken(formData) });
        data = await readJson(resp);
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
        // Sem resposta em 15 s desiste deste ciclo (senão o sino para de atualizar)
        const signal = typeof AbortSignal.timeout === 'function' ? AbortSignal.timeout(15000) : undefined;
        const response = await fetch('api/get_notifications.php', { cache: 'no-store', signal });
        if (response.status === 401) {
            window.location.href = 'login';
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

// Abre o pedido do aviso na página dele
function openNotification(id, link) {
    if (id) markNotifRead(id, true);
    window.location.href = link;
}

// O aviso fala do que está aberto? Atualiza sem a pessoa precisar recarregar
function refreshForNotifications(fresh) {
    const links = fresh.map(n => String(n.link || ''));
    if (document.querySelector('[data-queue]') && typeof refreshAfterChange === 'function') {
        // Com pedidos marcados para resolver de uma vez, não redesenha a fila (perderia a seleção);
        // o sino já mostra o aviso e a fila atualiza depois da ação em lote
        if (!document.body.classList.contains('has-bulk')) refreshAfterChange();
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
            window.location.href = 'home';
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
        title: 'Escreva um título curto.',
        end: 'Escolha a data em que você precisa.',
        details: 'Explique o que você precisa.',
        file: 'Informe qual arquivo deve ser impresso.',
        qtd: 'Informe quantas cópias (número maior que zero).',
        model: 'Escolha uma opção.',
        outros_texto: 'Descreva qual é a outra opção.',
        urgent_reason: 'Conte por que é urgente.'
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

// Data de hoje + n dias, no formato do campo de data
function isoInDays(n) {
    const d = new Date();
    d.setDate(d.getDate() + n);
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

function bindFormRequests() {
    const form_requests = document.querySelector('.form_request');
    if (!form_requests) return;

    // Data mínima = hoje
    form_requests.querySelectorAll('input[type="date"]').forEach(input => { input.min = todayIso(); });

    // Atalhos de data: um toque preenche o campo
    form_requests.querySelectorAll('[data-date-quick]').forEach(box => {
        const input = box.closest('.form-group').querySelector('input[type="date"]');
        [['Amanhã', 1], ['Em 1 semana', 7], ['Em 2 semanas', 14]].forEach(([label, days]) => {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'chip-btn';
            btn.textContent = label;
            btn.addEventListener('click', () => {
                input.value = isoInDays(days);
                input.dispatchEvent(new Event('input', { bubbles: true }));
            });
            box.appendChild(btn);
        });
    });

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

    const hideLoading = showLoading();

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

        const data = await readJson(response);

        if (!data.success && data.redirect) {
            window.location.href = data.redirect;
            return;
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
        } else {
            showToast(data.message || 'Não foi possível concluir a ação.', 'error');
        }
    } catch (error) {
        console.error("Erro fatal", error);
        showToast(requestErrorMessage(error), "error");
    } finally {
        hideLoading();
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

            const data = await fetchAjax("api/approve_request.php", formData);
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
        const data = await readJson(response);
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
        document.querySelectorAll('[data-sync]').forEach(el => {
            const twin = doc.querySelector(`[data-sync="${el.dataset.sync}"]`);
            if (twin) el.replaceWith(twin);
        });
        document.body.classList.remove('has-bulk');
    } catch (err) {
        location.reload();
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
    const data = await postForm('api/approve_request.php', { action: 'post', id, table, value, reason });
    if (!data) return;
    showToast(data.message, data.success ? 'success' : 'error');
    if (data.success) refreshAfterChange({ advance: true, message: data.message });
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
    if (data.success) refreshAfterChange({ advance: action === 'refuse' || action === 'return', message: data.message });
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
// Escolher vários pedidos e resolver de uma vez (Aprovar, Começar, Concluir)
// ---------------------------------------------------------------------
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
    bar.querySelector('[data-bulk-count]').textContent = n === 1 ? '1 selecionado' : `${n} selecionados`;
    // Botões por grupo: "Começar atendimento (2)" só aparece quando há novos marcados
    bar.querySelectorAll('[data-bulk-group]').forEach(btn => {
        const k = bulkPicked(btn.dataset.bulkGroup).length;
        btn.hidden = k === 0;
        const label = btn.querySelector('[data-bulk-n]');
        if (label) label.textContent = `(${k})`;
    });
    picks.forEach(i => i.closest('.queue-row')?.classList.toggle('picked', i.checked));
    const all = document.querySelector('[data-queue] [data-pick-all]');
    if (all) {
        all.checked = n > 0 && n === picks.length;
        all.indeterminate = n > 0 && n < picks.length;
    }
    document.body.classList.toggle('has-bulk', n > 0);
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

    let reason = '';
    if (action === 'refuse') {
        reason = await showConfirm({
            title: `Recusar ${n === 1 ? '1 pedido' : n + ' pedidos'}`,
            message: 'Cada solicitante recebe o motivo abaixo.',
            type: 'error', confirmLabel: 'Recusar', isDanger: true,
            input: { label: 'Motivo da recusa *', placeholder: 'Ex.: pedido repetido, já foi atendido', required: true }
        });
        if (reason === false) return;
    } else {
        const texts = {
            approve: [`Aprovar ${plural}?`, 'Cada pedido vai para o setor responsável.', 'Aprovar', 'success'],
            start: [`Começar ${plural}?`, 'Os solicitantes são avisados que o atendimento começou.', 'Começar', 'info'],
            finish: [`Concluir ${plural}?`, 'Os solicitantes são avisados que o pedido foi atendido.', 'Concluir', 'success'],
        }[action];
        if (n > 1 || action === 'finish') {
            const ok = await showConfirm({ title: texts[0], message: texts[1], confirmLabel: texts[2], type: texts[3] });
            if (!ok) return;
        }
    }

    const bar = btn.closest('[data-bulk]');
    const count = bar.querySelector('[data-bulk-count]');
    bar.querySelectorAll('button').forEach(b => { b.disabled = true; });
    const verb = { approve: 'Aprovando', refuse: 'Recusando', start: 'Começando', finish: 'Concluindo' }[action];

    let ok = 0;
    const errors = [];
    for (const [i, item] of items.entries()) {
        count.textContent = `${verb} ${i + 1} de ${n}...`;
        let data;
        if (action === 'approve' || action === 'refuse') {
            data = await postForm('api/approve_request.php', { action: 'post', id: item.id, table: item.table, value: action === 'approve' ? 'Y' : 'N', reason });
        } else {
            data = await postForm('api/request_actions.php', { id: item.id, table: `ctd_${item.table}_frm`, action: action === 'start' ? 'start_progress' : 'finish' });
        }
        if (data && data.success) ok++;
        else errors.push(`nº ${item.id}: ${(data && data.message) || 'sem conexão'}`);
    }

    const done = { approve: ['aprovado', 'aprovados'], refuse: ['recusado', 'recusados'], start: ['em atendimento', 'em atendimento'], finish: ['concluído', 'concluídos'] }[action];
    if (!errors.length) {
        showToast(n === 1 ? `Pedido ${done[0]}.` : `${ok} pedidos ${done[1]}.`, 'success');
    } else {
        showToast(`${ok} de ${n} deram certo. Não foi possível: ${errors.join('; ')}`, 'error');
    }
    document.body.classList.remove('has-bulk');
    refreshAfterChange();
});
