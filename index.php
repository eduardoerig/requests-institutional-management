<?php
session_start();
include 'config/conn.php';
$url = isset($_GET['url']) ? explode('/', trim($_GET['url'], '/')) : [];
$logged = $_SESSION['id'] ?? false;
$role = $_SESSION['role'] ?? 'view';
?>
<!DOCTYPE html>
<html lang="pt-br">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#111827">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link rel="icon" href="./assets/img/logo-request.png">
    <link rel="stylesheet" href="assets/css/style.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="assets/css/fase3.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="assets/css/notifications.css?v=<?php echo time(); ?>">
    <title>Request System - Martin Luther v0.1</title>
</head>

<body>
    <?php
    if ($logged) {
        echo '<main>';
        include "components/asideBar.php";
        echo '<div class="page-content-wrapper">';
    } else {
        echo '<div class="login-wrapper">';
    }
    ?>
        <?php if ($logged): ?>
        <header class="top-bar">
            <div class="top-bar-left">
                <button class="menu-toggle" id="mobile-menu-btn">
                    <i class="fa-solid fa-bars"></i>
                </button>
            </div>
            
            <div class="top-bar-right">
                <div class="notification-wrapper" id="notif-btn">
                    <i class="fa-solid fa-bell"></i>
                    <span class="notif-badge" id="notif-badge" style="display:none;">0</span>
                    
                    <div class="notif-dropdown" id="notif-dropdown">
                        <div class="notif-header">
                            <span>Notificações</span>
                            <button onclick="markAllRead()">Marcar como lidas</button>
                        </div>
                        <div class="notif-body" id="notif-body">
                            <div class="notif-empty">Nenhuma notificação nova</div>
                        </div>
                    </div>
                </div>
                
                <div class="user-profile">
                    <span class="user-name"><?php echo $_SESSION['name'] ?? 'Usuário'; ?></span>
                    <?php 
                    $role_labels = [
                        'admin' => 'ADMIN', 'adm' => 'ADMIN', 'coord' => 'COORD',
                        'adm_sub' => 'ADM. ' . strtoupper($_SESSION['subdivision_name'] ?? 'SUBDIVISÃO'),
                        'gestor'=> 'GESTOR TI', 'ti' => 'GESTOR TI', 'xerox' => 'GESTOR XEROX',
                        'service' => 'GESTOR MANUT.', 'shop' => 'GESTOR COMPRAS',
                        'mkt' => 'GESTOR MKT', 'marketing' => 'GESTOR MKT',
                        'solicitante' => 'SOLICITANTE'
                    ];
                    ?>
                    <span class="user-role"><?php echo $role_labels[$role] ?? strtoupper($role); ?></span>
                </div>
            </div>
        </header>
        <?php endif; ?>
        
        <?php
        $page = isset($url[0]) && $url[0] !== '' ? $url[0] : "home";

        if ($page === "login" || !$logged) {
            include "pages/login.php";
        } else if ($page === "home" && $logged) {
            include "pages/home.php";
        } else if ($page === "new_request" && $logged) {
            include "pages/new_request.php";
        } else if ($page === "approve" && $logged) {
            include "pages/approve.php";
        } else if ($page === "approved" && $logged) {
            include "pages/approved.php";
        } else if ($page === "inWork" && $logged) {
            include "pages/inWork.php";
        } else if ($page === "myRequests" && $logged) {
            include "pages/myRequests.php";
        } else if ($page === "myAccount" && $logged) {
            include "pages/myAccount.php";
        } else if ($page === "accounts" && $logged) {
            include "pages/accounts.php";
        } else if ($page === "request_detail" && $logged) {
            include "pages/request_detail.php";
        } else if ($page === "register" && !$logged) {
            include "pages/register.php";
        } else {
            include "pages/home.php"; // Fallback
        }
        ?>
    </div>
    <div class="toast"></div>
    <div class="loading-overlay">
        <div class="spinner"></div>
    </div>
    <?php
    if ($logged) {
        echo '</main>';
    }
    ?>
    <script src="./assets/js/script.js?v=<?php echo time(); ?>"></script>
    <script src="./assets/js/filter.js?v=<?php echo time(); ?>"></script>
    <script src="./assets/js/open_cards.js?v=<?php echo time(); ?>"></script>

    <!-- Modal de Repasse de Requisições (Fase 5) -->
    <div class="forward-overlay" id="forwardOverlay">
        <div class="forward-modal">
            <div class="forward-modal-header">
                <i class="fa-solid fa-share-from-square"></i>
                <div>
                    <h3>Repassar Requisição</h3>
                    <p>Selecione o setor de destino e adicione uma observação</p>
                </div>
            </div>

            <div class="forward-form-group">
                <label for="forwardAreaSelect"><i class="fa-solid fa-building" style="margin-right:6px;"></i>Setor de Destino</label>
                <select id="forwardAreaSelect">
                    <option value="">Carregando setores...</option>
                </select>
            </div>

            <div class="forward-form-group">
                <label for="forwardObservation"><i class="fa-solid fa-message" style="margin-right:6px;"></i>Observação (opcional)</label>
                <textarea id="forwardObservation" placeholder="Explique o motivo do repasse ou adicione orientações para o outro setor..."></textarea>
            </div>

            <div class="forward-actions">
                <button class="btn-forward btn-forward-confirm" id="forwardConfirmBtn" onclick="confirmForward()">
                    <i class="fa-solid fa-paper-plane"></i> Confirmar Repasse
                </button>
                <button class="btn-forward btn-forward-cancel" onclick="closeForwardModal()">
                    <i class="fa-solid fa-xmark"></i> Cancelar
                </button>
            </div>
        </div>
    </div>

    <script>
    // ============================================
    // LÓGICA DO MODAL DE REPASSE (Fase 5)
    // ============================================

    let forwardAreasLoaded = false;

    // Abre o modal de repasse e carrega os setores disponíveis
    function openForwardModal() {
        const rid = document.querySelector('.Rid');
        const rtype = document.querySelector('.Rtype');

        if (!rid || !rid.value || !rtype || !rtype.value) {
            showToast('Selecione uma requisição primeiro.', 'info');
            return;
        }

        const overlay = document.getElementById('forwardOverlay');
        overlay.style.display = 'flex';
        setTimeout(() => overlay.classList.add('show'), 10);

        // Carregar setores do backend (apenas na primeira vez ou se necessário)
        if (!forwardAreasLoaded) {
            loadForwardAreas();
        }
    }

    // Fecha o modal de repasse
    function closeForwardModal() {
        const overlay = document.getElementById('forwardOverlay');
        overlay.classList.remove('show');
        setTimeout(() => { overlay.style.display = 'none'; }, 300);
    }

    // Carrega os setores disponíveis via API
    async function loadForwardAreas() {
        try {
            const response = await fetch('api/request_forwards.php?action=areas');
            const data = await response.json();
            const select = document.getElementById('forwardAreaSelect');

            if (data.success && data.data.length > 0) {
                select.innerHTML = '<option value="">— Selecione o setor —</option>';
                data.data.forEach(area => {
                    select.innerHTML += `<option value="${area.id}">${area.label}</option>`;
                });
                forwardAreasLoaded = true;
            } else {
                select.innerHTML = '<option value="">Nenhum setor disponível</option>';
            }
        } catch (err) {
            console.error('Erro ao carregar setores:', err);
            document.getElementById('forwardAreaSelect').innerHTML = '<option value="">Erro ao carregar</option>';
        }
    }

    // Confirma e envia o repasse
    async function confirmForward() {
        const rid = document.querySelector('.Rid')?.value;
        const rtype = document.querySelector('.Rtype')?.value;
        const toAreaId = document.getElementById('forwardAreaSelect').value;
        const observation = document.getElementById('forwardObservation').value.trim();

        if (!toAreaId) {
            showToast('Selecione o setor de destino.', 'error');
            return;
        }

        const confirmBtn = document.getElementById('forwardConfirmBtn');
        confirmBtn.disabled = true;
        confirmBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Repassando...';

        try {
            const formData = new URLSearchParams();
            formData.append('action', 'forward');
            formData.append('id', rid);
            formData.append('table', rtype);
            formData.append('to_area_id', toAreaId);
            formData.append('observation', observation);

            const response = await fetch('api/request_forwards.php', {
                method: 'POST',
                body: formData
            });
            const data = await response.json();

            if (data.success) {
                showToast(data.message, 'success');
                closeForwardModal();
                setTimeout(() => location.reload(), 1200);
            } else {
                showToast(data.message, 'error');
            }
        } catch (err) {
            console.error('Erro ao repassar:', err);
            showToast('Falha na comunicação com o servidor.', 'error');
        } finally {
            confirmBtn.disabled = false;
            confirmBtn.innerHTML = '<i class="fa-solid fa-paper-plane"></i> Confirmar Repasse';
        }
    }

    // Fecha o modal ao clicar fora
    document.getElementById('forwardOverlay')?.addEventListener('click', function(e) {
        if (e.target === this) closeForwardModal();
    });

    // ============================================
    // AÇÕES DE REPASSE (Aceitar / Recusar / Concluir)
    // ============================================

    async function handleForwardAction(action, forwardId) {
        const messages = {
            'accept': 'Deseja ACEITAR este repasse? Você será responsável pelo atendimento.',
            'refuse': 'Deseja RECUSAR este repasse? A requisição voltará ao setor de origem.',
            'complete': 'Deseja CONCLUIR esta requisição via repasse?'
        };

        if (messages[action]) {
            const confirmed = await customConfirm(messages[action]);
            if (!confirmed) return;
        }

        const overlay = document.querySelector('.loading-overlay');
        if (overlay) overlay.style.display = 'flex';

        try {
            const formData = new URLSearchParams();
            formData.append('action', action);
            formData.append('forward_id', forwardId);

            const response = await fetch('api/request_forwards.php', {
                method: 'POST',
                body: formData
            });
            const data = await response.json();

            if (data.success) {
                showToast(data.message, 'success');
                setTimeout(() => location.reload(), 1000);
            } else {
                showToast(data.message, 'error');
            }
        } catch (err) {
            console.error('Erro na ação de repasse:', err);
            showToast('Falha na comunicação com o servidor.', 'error');
        } finally {
            if (overlay) overlay.style.display = 'none';
        }
    }
    </script>

</body>

</html>