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

// Lógica de Notificações
const notifBtn = document.getElementById('notif-btn');
const notifDropdown = document.getElementById('notif-dropdown');
const notifBadge = document.getElementById('notif-badge');
const notifBody = document.getElementById('notif-body');

if (notifBtn) {
    notifBtn.addEventListener('click', (e) => {
        e.stopPropagation();
        notifDropdown.classList.toggle('active');
        if (notifDropdown.classList.contains('active')) {
            updateNotifications();
        }
    });

    document.addEventListener('click', () => {
        notifDropdown.classList.remove('active');
    });

    notifDropdown.addEventListener('click', (e) => e.stopPropagation());

    // Polling inicial e a cada 60s
    updateNotifications();
    setInterval(updateNotifications, 60000);
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
    if (document.hidden) return;
    try {
        const response = await fetch('api/get_notifications.php');
        const data = await response.json();
        
        if (data.unreadCount > 0) {
            notifBadge.textContent = data.unreadCount;
            notifBadge.style.display = 'grid';
        } else {
            notifBadge.style.display = 'none';
        }

        if (data.notifications && data.notifications.length > 0) {
            notifBody.innerHTML = data.notifications.map(n => `
                <a class="notif-item ${n.is_read == 0 ? 'unread' : ''}" href="${escapeHtml(safeInternalLink(n.link))}">
                    <div class="notif-item-content">
                        <span class="notif-item-title">${escapeHtml(n.title)}</span>
                        <p class="notif-item-msg">${escapeHtml(n.message)}</p>
                        <span class="notif-item-time">${escapeHtml(formatDate(n.created_at))}</span>
                    </div>
                    ${n.is_read == 0 ? `
                        <button class="notif-mark-read" onclick="markSingleRead(event, ${parseInt(n.id, 10)})" title="Marcar como lida" aria-label="Marcar como lida">
                            <i class="fa-solid fa-circle"></i>
                        </button>
                    ` : ''}
                </a>
            `).join('');
        } else {
            notifBody.innerHTML = '<div class="notif-empty">Nenhuma notificação nova</div>';
        }
    } catch (err) {
        console.error('Erro ao carregar notificações:', err);
    }
}

async function markAllRead() {
    try {
        const formData = new URLSearchParams();
        const response = await fetch('api/mark_notifications_read.php', {
            method: 'POST',
            body: attachCsrfToken(formData)
        });
        const data = await response.json();
        if (data.success) {
            updateNotifications();
        }
    } catch (err) {
        console.error('Erro ao marcar como lidas:', err);
    }
}

async function markSingleRead(event, id) {
    event.preventDefault();
    event.stopPropagation();
    try {
        const formData = new URLSearchParams();
        formData.append('id', id);
        const response = await fetch('api/mark_notification_read_single.php', {
            method: 'POST',
            body: attachCsrfToken(formData)
        });
        const data = await response.json();
        if (data.success) {
            updateNotifications();
        }
    } catch (err) {
        console.error('Erro ao marcar como lida:', err);
    }
}

function formatDate(dateStr) {
    const date = new Date(dateStr);
    return date.toLocaleDateString('pt-BR', { 
        day: '2-digit', 
        month: '2-digit',
        hour: '2-digit',
        minute: '2-digit'
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
        'start_progress': 'Começar o atendimento agora? O solicitante será avisado.',
        'set_priority': 'Mudar a urgência desta requisição?',
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
async function refreshAfterChange() {
    const queue = document.querySelector('[data-queue]');
    if (!queue) {
        setTimeout(() => location.reload(), 700);
        return;
    }
    const selected = queue.querySelector('.row-item.selected');
    const key = selected ? selected.dataset.table + ':' + selected.dataset.id : null;
    try {
        const html = await (await fetch(location.href, { headers: { 'X-Requested-With': 'fetch' } })).text();
        const doc = new DOMParser().parseFromString(html, 'text/html');
        const fresh = doc.querySelector('[data-queue]');
        if (fresh) queue.replaceWith(fresh);
        document.querySelectorAll('[data-sync]').forEach(el => {
            const twin = doc.querySelector(`[data-sync="${el.dataset.sync}"]`);
            if (twin) el.replaceWith(twin);
        });
        bindQueue();
        if (key) {
            const [table, id] = key.split(':');
            const row = document.querySelector(`[data-queue] .row-item[data-table="${table}"][data-id="${id}"]`);
            openInPanel(table, id, row);
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
        initRequestView(panel);
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
