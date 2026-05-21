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

async function updateNotifications() {
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
                <div class="notif-item ${n.is_read == 0 ? 'unread' : ''}" onclick="window.location.href='${n.link || '#'}'">
                    <div class="notif-item-content">
                        <span class="notif-item-title">${n.title}</span>
                        <p class="notif-item-msg">${n.message}</p>
                        <span class="notif-item-time">${formatDate(n.created_at)}</span>
                    </div>
                    ${n.is_read == 0 ? `
                        <button class="notif-mark-read" onclick="markSingleRead(event, ${n.id})" title="Marcar como lida">
                            <i class="fa-solid fa-circle"></i>
                        </button>
                    ` : ''}
                </div>
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
    
    let icon = '<i class="fa-solid fa-check-circle"></i>';
    if(type === 'error') icon = '<i class="fa-solid fa-circle-exclamation"></i>';
    if(type === 'info') icon = '<i class="fa-solid fa-circle-info"></i>';

    toast.innerHTML = icon + ' ' + message;
    
    toast.className = 'toast'; // Reset classes
    toast.classList.add(type);
    toast.classList.add("show");

    setTimeout(() => {
        toast.classList.remove("show");
    }, 3000);
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
            if (window.innerWidth <= 768) {
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

function bindFormRequests() {
    let form_requests = document.querySelector('.form_request');

    // Aparição do descrição da aplicação
    const radioOutros = document.getElementById('radio_outros');
    const inputOutros = document.getElementById('input_outros');
    const radios = document.querySelectorAll('.model_radius');

    radios.forEach(radio => {
        radio.addEventListener('change', function () {
            if (radioOutros.checked) {
                inputOutros.style.display = 'block';
                inputOutros.required = true;
            } else {
                inputOutros.style.display = 'none';
                inputOutros.required = false;
                inputOutros.value = "";
            }
        });
    });

    // prepara para o envio
    let isSubmitting = false;
    form_requests.addEventListener('submit', async (e) => {
        e.preventDefault();
        
        if (isSubmitting) return;

        // Fallback to customConfirm if showConfirm is not globally defined yet
        const confirmationFn = typeof showConfirm === 'function' ? showConfirm : customConfirm;
        const confirm = await confirmationFn({
            title: 'Enviar Requisição',
            message: 'Deseja realmente enviar esta requisição agora?',
            type: 'info',
            confirmLabel: 'Sim, Enviar'
        });
        
        // customConfirm returns boolean, showConfirm might return boolean
        if(!confirm) return;

        isSubmitting = true;
        const submitBtn = form_requests.querySelector('button[type="submit"]');
        const originalBtnText = submitBtn ? submitBtn.innerHTML : '';
        if (submitBtn) {
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Enviando...';
        }

        let formData = new FormData(form_requests);
        formData.append('action', 'post');

        const data = await fetchAjax("config/addRequest.php", formData);
        if (data && data.success) {
            // Se showToast não existir, usa showAlert
            if (typeof showAlert === 'function') {
                showAlert({ title: 'Sucesso', message: 'Sua requisição foi enviada com sucesso!', type: 'success' });
            } else {
                showToast('Sua requisição foi enviada com sucesso!', 'success');
            }
            setTimeout(() => location.reload(), 500);
        } else {
            isSubmitting = false;
            if (submitBtn) {
                submitBtn.disabled = false;
                submitBtn.innerHTML = originalBtnText;
            }
            if (data) {
                if (typeof showAlert === 'function') {
                    showAlert({ title: 'Atenção', message: data.message, type: 'warning' });
                } else {
                    showToast(data.message, 'error');
                }
            }
        }
    });
}

// Função de Confirmação Customizada (Modal Premium)
function customConfirm(message, title = 'Confirmação') {
    return new Promise((resolve) => {
        // Injetar HTML do modal se não existir
        let overlay = document.getElementById('confirm-overlay');
        if (!overlay) {
            overlay = document.createElement('div');
            overlay.id = 'confirm-overlay';
            overlay.className = 'confirm-overlay';
            overlay.innerHTML = `
                <div class="confirm-modal">
                    <div class="confirm-icon"><i class="fa-solid fa-circle-question"></i></div>
                    <h3 id="confirm-title">${title}</h3>
                    <p id="confirm-message">${message}</p>
                    <div class="confirm-buttons">
                        <button class="confirm-btn confirm-btn-ok" id="confirm-btn-ok">Sim, Continuar</button>
                        <button class="confirm-btn confirm-btn-cancel" id="confirm-btn-cancel">Cancelar</button>
                    </div>
                </div>
            `;
            document.body.appendChild(overlay);
        } else {
            document.getElementById('confirm-title').innerText = title;
            document.getElementById('confirm-message').innerText = message;
        }

        const btnOk = document.getElementById('confirm-btn-ok');
        const btnCancel = document.getElementById('confirm-btn-cancel');

        const close = (result) => {
            overlay.classList.remove('show');
            setTimeout(() => {
                overlay.style.display = 'none';
                resolve(result);
            }, 300);
        };

        btnOk.onclick = () => close(true);
        btnCancel.onclick = () => close(false);
        overlay.onclick = (e) => { if (e.target === overlay) close(false); };

        overlay.style.display = 'flex';
        setTimeout(() => overlay.classList.add('show'), 10);
    });
}

// Funções Globais de Ação de Requisição
async function globalRequestAction(action, id, table, value = '') {
    // Configurações de mensagens de confirmação
    const confirmations = {
        'reset_status': 'Deseja realmente REABRIR esta requisição? Ela voltará para o estado em andamento.',
        'finish': 'Deseja marcar esta requisição como CONCLUÍDA? Isso encerrará o atendimento.',
        'start_progress': 'Deseja INICIAR o atendimento desta requisição agora?',
        'set_priority': 'Deseja ALTERAR a PRIORIDADE desta requisição?',
        'approve': 'Deseja APROVAR esta requisição?',
        'reject': 'Deseja RECUSAR esta requisição?',
        'delete': 'Deseja realmente EXCLUIR esta requisição? Esta ação não pode ser desfeita.'
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
        
        if (!response.ok) {
            throw new Error("Erro no servidor: " + response.status);
        }

        const data = await response.json();

        if (data.success) {
            showToast(data.message, 'success');
            setTimeout(() => location.reload(), 1000);
        } else {
            showToast(data.message || 'Erro ao processar ação.', 'error');
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

        console.log("Submit detectado:", { actionName, actionValue, id, table });

        if (!id || !table) {
            showToast("Selecione uma requisição primeiro.", "info");
            return;
        }

        if (actionName === 'action_value' && (actionValue === 'start_progress' || actionValue === 'finish' || actionValue === 'delete')) {
            console.log("DEBUG: Iniciando fluxo de ação direta...");
            await globalRequestAction(actionValue, id, table);
        } 
        else {
            const val = actionValue || btn.innerText.trim();
            let formData = new FormData(formCard);
            formData.append('action', 'post');
            formData.append('id', id);
            formData.append('table', table);
            formData.append('value', val); 

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