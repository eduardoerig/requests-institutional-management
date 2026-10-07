<?php
require_once __DIR__ . '/config/session.php';
include 'config/conn.php';
require_once 'config/security.php';
header('Content-Type: text/html; charset=UTF-8');
$url = isset($_GET['url']) ? explode('/', trim($_GET['url'], '/')) : [];
$logged = $_SESSION['id'] ?? false;
$role = $_SESSION['role'] ?? 'view';
$force_reset = (int)($_SESSION['force_reset'] ?? 0);
$assetVersion = max(array_map(fn($f) => (int)@filemtime($f), glob(__DIR__ . '/assets/{js,css}/*.{js,css}', GLOB_BRACE) ?: [])) ?: time();
$csrfToken = getCsrfToken();
// Caminho base da aplicação (funciona em subpasta local ou na raiz do domínio)
$basePath = rtrim(str_replace(chr(92), '/', dirname($_SERVER['SCRIPT_NAME'])), '/') . '/';

// Solicitante não tem painel: vai direto para as próprias requisições
$requestedPage = (isset($url[0]) && $url[0] !== '') ? $url[0] : 'home';
if ($logged && $role === 'solicitante' && $requestedPage === 'home' && $force_reset !== 1) {
    header('Location: myRequests');
    exit;
}

// Se forçado a resetar, só permite acessar 'myAccount' ou 'logout'
// Ignora redirecionamento para arquivos estáticos (assets)
if ($logged && $force_reset === 1) {
    $current_page = (isset($url[0]) && $url[0] !== '') ? $url[0] : 'home';
    $allowed_pages = ['myAccount', 'logout'];
    
    // Lista de extensões de arquivos estáticos que não devem ser redirecionados
    $is_asset = preg_match('/\.(js|css|png|jpg|jpeg|gif|ico|svg|woff|woff2|ttf|otf)$/i', $_SERVER['REQUEST_URI']);
    
    if (!in_array($current_page, $allowed_pages) && !$is_asset) {
        header("Location: myAccount");
        exit;
    }
}

$page = $requestedPage;
$currentStatusParam = $_GET['status'] ?? '';
$isGestorShell = isGestorRole($role);
$pageTitles = [
    'home' => $currentStatusParam === 'C' ? 'Concluídas' : 'Painel',
    'new_request' => 'Nova requisição',
    'myRequests' => 'Minhas requisições',
    'approve' => 'Aprovar pedidos',
    'approved' => $isGestorShell ? 'Para atender' : 'Aprovadas',
    'inWork' => 'Em atendimento',
    'myAccount' => 'Minha conta',
    'accounts' => 'Usuários e setores',
    'request_detail' => 'Requisição',
    'ajuda' => 'Como usar',
];
$pageTitle = $pageTitles[$page] ?? 'SisReq';
$roleClass = preg_replace('/[^a-z_]/', '', (string)$role);
$pageClass = preg_replace('/[^a-zA-Z_]/', '', (string)$page);
?>
<!DOCTYPE html>
<html lang="pt-br">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <base href="<?= e($basePath) ?>">
    <meta name="theme-color" content="#26252B">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Atkinson+Hyperlegible+Mono:wght@500;700&family=Atkinson+Hyperlegible+Next:wght@400;600;700;800&display=swap">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer">
    <link rel="icon" href="assets/img/LogoFavConSisreq.png">
    <link rel="stylesheet" href="assets/css/app.css?v=<?php echo $assetVersion; ?>">
    <title><?= e($logged ? $pageTitle . ' | SisReq' : 'Entrar | SisReq') ?></title>
</head>

<body class="<?= $logged ? 'role-' . e($roleClass) . ' page-' . e($pageClass) : 'page-login' ?>">
    <script>
        window.CSRF_TOKEN = "<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>";
    </script>
    <script>
        // Global Modal Functionality (Attached to window for global access)
        // input: { label, placeholder, required } -> resolve com o texto digitado (ou false se cancelar)
        window.showConfirm = function({ title, message, type = 'warning', confirmLabel = 'Confirmar', cancelLabel = 'Cancelar', isDanger = false, input = null }) {
            const globalModal = document.getElementById('globalModal');
            const modalIcon = document.getElementById('gModalIcon');
            const modalTitle = document.getElementById('gModalTitle');
            const modalMessage = document.getElementById('gModalMessage');
            const modalBtnCancel = document.getElementById('gModalBtnCancel');
            const modalBtnConfirm = document.getElementById('gModalBtnConfirm');

            return new Promise((resolve) => {
                if(!globalModal || !modalTitle) { console.error('Global Modal not found'); resolve(false); return; }
                modalTitle.textContent = title;
                modalMessage.textContent = message;
                modalBtnConfirm.textContent = confirmLabel;
                modalBtnCancel.textContent = cancelLabel;
                modalBtnCancel.style.display = 'block';

                const modalInput = document.getElementById('gModalInput');
                const modalInputLabel = document.getElementById('gModalInputLabel');
                const modalInputError = document.getElementById('gModalInputError');
                modalInput.value = '';
                modalInputError.style.display = 'none';
                modalInput.style.display = input ? 'block' : 'none';
                modalInputLabel.style.display = input ? 'block' : 'none';
                if (input) {
                    modalInputLabel.textContent = input.label || '';
                    modalInput.placeholder = input.placeholder || '';
                    setTimeout(() => modalInput.focus(), 50);
                }

                // Setup Icon
                modalIcon.className = `modal-icon ${type}`;
                const iconMap = { warning: 'fa-triangle-exclamation', error: 'fa-circle-xmark', success: 'fa-circle-check', info: 'fa-circle-info' };
                modalIcon.querySelector('i').className = `fa-solid ${iconMap[type] || 'fa-question'}`;

                // Setup Buttons
                modalBtnConfirm.className = isDanger ? 'btn-modal btn-modal-danger' : 'btn-modal btn-modal-confirm';
                modalBtnCancel.className = 'btn-modal btn-modal-cancel';

                globalModal.classList.add('active');

                const handleConfirm = () => {
                    const text = modalInput.value.trim();
                    if (input && input.required && text === '') {
                        modalInputError.style.display = 'block';
                        modalInput.focus();
                        return;
                    }
                    globalModal.classList.remove('active');
                    cleanup();
                    resolve(input ? text : true);
                };

                const handleCancel = () => {
                    globalModal.classList.remove('active');
                    cleanup();
                    resolve(false);
                };

                const cleanup = () => {
                    modalBtnConfirm.removeEventListener('click', handleConfirm);
                    modalBtnCancel.removeEventListener('click', handleCancel);
                };

                modalBtnConfirm.addEventListener('click', handleConfirm);
                modalBtnCancel.addEventListener('click', handleCancel);
            });
        };

        window.showAlert = function({ title, message, type = 'info', confirmLabel = 'OK' }) {
            const globalModal = document.getElementById('globalModal');
            const modalIcon = document.getElementById('gModalIcon');
            const modalTitle = document.getElementById('gModalTitle');
            const modalMessage = document.getElementById('gModalMessage');
            const modalBtnCancel = document.getElementById('gModalBtnCancel');
            const modalBtnConfirm = document.getElementById('gModalBtnConfirm');

            if(!globalModal || !modalTitle) { console.error('Global Modal not found'); return; }
            modalTitle.textContent = title;
            modalMessage.textContent = message;
            modalBtnConfirm.textContent = confirmLabel;
            modalBtnCancel.style.display = 'none';
            document.getElementById('gModalInput').style.display = 'none';
            document.getElementById('gModalInputLabel').style.display = 'none';
            document.getElementById('gModalInputError').style.display = 'none';

            modalIcon.className = `modal-icon ${type}`;
            const iconMap = { warning: 'fa-triangle-exclamation', error: 'fa-circle-xmark', success: 'fa-circle-check', info: 'fa-circle-info' };
            modalIcon.querySelector('i').className = `fa-solid ${iconMap[type] || 'fa-question'}`;
            modalBtnConfirm.className = 'btn-modal btn-modal-confirm';

            globalModal.classList.add('active');

            return new Promise((resolve) => {
                const handleClose = () => {
                    globalModal.classList.remove('active');
                    modalBtnConfirm.removeEventListener('click', handleClose);
                    resolve();
                };
                modalBtnConfirm.addEventListener('click', handleClose);
            });
        };
    </script>

<?php if ($page === 'logout'): ?>
    <?php session_unset(); session_destroy(); ?>
    <script>location.href = "login";</script>
<?php elseif (!$logged || $page === 'login'): ?>
    <?php include "pages/login.php"; ?>
<?php else: ?>
    <?php
    $role_labels = [
        'admin' => 'Administrador', 'adm' => 'Administrador', 'coord' => 'Coordenação',
        'adm_sub' => 'Coordenação ' . ($_SESSION['subdivision_name'] ?? ''),
        'gestor' => 'Setor de TI', 'ti' => 'Setor de TI', 'xerox' => 'Setor de Reprografia',
        'service' => 'Setor de Manutenção', 'shop' => 'Setor de Compras',
        'mkt' => 'Setor de Marketing', 'marketing' => 'Setor de Marketing',
        'solicitante' => 'Solicitante'
    ];
    $firstName = explode(' ', trim((string)($_SESSION['name'] ?? 'Usuário')))[0];
    ?>
    <div class="app-shell">
        <?php include "components/asideBar.php"; ?>
        <div class="sidebar-backdrop" id="sidebar-backdrop"></div>

        <header class="topbar">
            <a class="topbar__brand" href="<?= $role === 'solicitante' ? 'myRequests' : 'home' ?>" aria-label="Início">
                <img src="assets/img/LogoFavConSisreq.png" alt="">
            </a>
            <p class="topbar__title"><?= e($page === 'myRequests' && $role === 'solicitante' ? 'Olá, ' . $firstName : $pageTitle) ?></p>
            <div class="topbar__actions">
                <div class="notification-wrapper icon-btn" id="notif-btn" role="button" tabindex="0" aria-label="Avisos" aria-haspopup="true">
                    <i class="fa-solid fa-bell" aria-hidden="true"></i>
                    <span class="notif-badge" id="notif-badge" style="display:none;">0</span>
                    <div class="notif-dropdown" id="notif-dropdown" role="dialog" aria-label="Avisos">
                        <div class="notif-header">
                            <span>Avisos</span>
                            <button type="button" onclick="markAllRead()">Marcar todos como lidos</button>
                        </div>
                        <div class="notif-body" id="notif-body">
                            <div class="notif-empty">Nenhum aviso novo</div>
                        </div>
                    </div>
                </div>
                <a class="user-chip" href="myAccount">
                    <strong><?= e($_SESSION['name'] ?? 'Usuário') ?></strong>
                    <span><?= e($role_labels[$role] ?? $role) ?></span>
                </a>
            </div>
        </header>

        <main class="app-main" id="conteudo">
            <?php
            $routes = [
                'home' => 'pages/home.php',
                'new_request' => 'pages/new_request.php',
                'approve' => 'pages/approve.php',
                'approved' => 'pages/approved.php',
                'inWork' => 'pages/inWork.php',
                'myRequests' => 'pages/myRequests.php',
                'myAccount' => 'pages/myAccount.php',
                'accounts' => 'pages/accounts.php',
                'request_detail' => 'pages/request_detail.php',
                'ajuda' => 'pages/ajuda.php',
            ];
            include $routes[$page] ?? 'pages/home.php';
            ?>
        </main>

        <?php
        // Barra inferior do celular: o que cada perfil mais usa ("Novo" sempre no centro)
        if ($role === 'solicitante') {
            $bottomItems = [
                ['myRequests', 'fa-folder-open', 'Pedidos'],
                ['ajuda', 'fa-circle-question', 'Ajuda'],
                ['new_request', 'fa-plus', 'Novo'],
                ['myAccount', 'fa-user', 'Conta'],
            ];
        } elseif ($isGestorShell) {
            $bottomItems = [
                ['approved', 'fa-inbox', 'Atender'],
                ['inWork', 'fa-gears', 'Andamento'],
                ['new_request', 'fa-plus', 'Novo'],
                ['myRequests', 'fa-folder-open', 'Meus'],
            ];
        } else {
            $bottomItems = [
                ['approve', 'fa-clipboard-check', 'Aprovar'],
                ['home', 'fa-chart-simple', 'Painel'],
                ['new_request', 'fa-plus', 'Novo'],
                ['myRequests', 'fa-folder-open', 'Meus'],
            ];
        }
        ?>
        <nav class="bottom-nav" aria-label="Navegação principal">
            <?php foreach ($bottomItems as [$href, $icon, $label]):
                $isNew = $href === 'new_request';
                $active = $page === $href;
            ?>
                <a href="<?= e($href) ?>" class="bottom-nav__item<?= $isNew ? ' bottom-nav__new' : '' ?><?= $active ? ' is-active' : '' ?>"<?= $active ? ' aria-current="page"' : '' ?>>
                    <i class="fa-solid <?= e($icon) ?>" aria-hidden="true"></i>
                    <span><?= e($label) ?></span>
                    <?php if ($href === 'approve' && !empty($badge_pendentes)): ?><span class="sidebar-badge"><?= (int)$badge_pendentes ?></span><?php endif; ?>
                    <?php if ($href === 'approved' && !empty($badge_aprovadas)): ?><span class="sidebar-badge"><?= (int)$badge_aprovadas ?></span><?php endif; ?>
                </a>
            <?php endforeach; ?>
            <button type="button" class="bottom-nav__item" id="mobile-menu-btn" aria-label="Mais opções">
                <i class="fa-solid fa-bars" aria-hidden="true"></i>
                <span>Mais</span>
            </button>
        </nav>
    </div>
<?php endif; ?>

    <div class="toast" role="status" aria-live="polite"></div>
    <div class="loading-overlay">
        <div class="spinner" aria-label="Carregando"></div>
    </div>
    <script src="assets/js/script.js?v=<?php echo $assetVersion; ?>"></script>
    <script src="assets/js/filter.js?v=<?php echo $assetVersion; ?>"></script>
    <script src="assets/js/open_cards.js?v=<?php echo $assetVersion; ?>"></script>

    <!-- Modal de Repasse de Requisições (Fase 5) -->
    <div class="forward-overlay" id="forwardOverlay">
        <div class="forward-modal">
            <div class="forward-modal-header">
                <i class="fa-solid fa-share-from-square"></i>
                <div>
                    <h3>Encaminhar a outro setor</h3>
                    <p>O outro setor recebe o pedido e decide se assume o atendimento.</p>
                </div>
            </div>

            <div class="forward-form-group">
                <label for="forwardAreaSelect">Para qual setor?</label>
                <select id="forwardAreaSelect">
                    <option value="">Carregando setores...</option>
                </select>
            </div>

            <div class="forward-form-group">
                <label for="forwardObservation">Explique o motivo <small>(opcional)</small></label>
                <textarea id="forwardObservation" placeholder="Explique o motivo do repasse ou adicione orientações para o outro setor..."></textarea>
            </div>

            <div class="forward-actions">
                <button class="btn-forward btn-forward-confirm" id="forwardConfirmBtn" onclick="confirmForward()">
                    <i class="fa-solid fa-paper-plane"></i> Encaminhar
                </button>
                <button class="btn-forward btn-forward-cancel" onclick="closeForwardModal()">
                    Cancelar
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
                select.innerHTML = '<option value="">Escolha o setor</option>';
                data.data.forEach(area => {
                    select.add(new Option(area.label, area.id));
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
        confirmBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Encaminhando...';

        try {
            const formData = new URLSearchParams();
            formData.append('action', 'forward');
            formData.append('id', rid);
            formData.append('table', rtype);
            formData.append('to_area_id', toAreaId);
            formData.append('observation', observation);

            const response = await fetch('api/request_forwards.php', {
                method: 'POST',
                body: attachCsrfToken(formData)
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
            confirmBtn.innerHTML = '<i class="fa-solid fa-paper-plane"></i> Encaminhar';
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
            'accept': 'Assumir este pedido? Seu setor passa a ser responsável pelo atendimento.',
            'refuse': 'Recusar este encaminhamento? O pedido volta para o setor que encaminhou.',
            'complete': 'Marcar este pedido como concluído?'
        };

        if (messages[action]) {
            const confirmed = await showConfirm({
                title: 'Encaminhamento',
                message: messages[action],
                type: 'warning',
                confirmLabel: 'Confirmar'
            });
            if (!confirmed) return;
        }

        const overlay = document.querySelector('.loading-overlay');
        if (overlay) overlay.style.display = 'flex';

        let keepOverlayUntilReload = false;
        try {
            const formData = new URLSearchParams();
            formData.append('action', action);
            formData.append('forward_id', forwardId);

            const response = await fetch('api/request_forwards.php', {
                method: 'POST',
                body: attachCsrfToken(formData)
            });
            const data = await response.json();

            if (data.success) {
                showToast(data.message, 'success');
                keepOverlayUntilReload = true;
                setTimeout(() => location.reload(), 700);
            } else {
                showToast(data.message, 'error');
            }
        } catch (err) {
            console.error('Erro na ação de repasse:', err);
            showToast('Falha na comunicação com o servidor.', 'error');
        } finally {
            if (overlay && !keepOverlayUntilReload) overlay.style.display = 'none';
        }
    }
    </script>

    <!-- Global Modal Structure (Moved to end for correct stacking) -->
    <div id="globalModal" class="modal-overlay" role="dialog" aria-modal="true" aria-labelledby="gModalTitle">
        <div class="modal-card">
            <div id="gModalIcon" class="modal-icon">
                <i class="fa-solid"></i>
            </div>
            <h3 id="gModalTitle" class="modal-title">Título</h3>
            <p id="gModalMessage" class="modal-message">Mensagem do modal aqui.</p>
            <label id="gModalInputLabel" for="gModalInput" class="modal-input-label" style="display:none;"></label>
            <textarea id="gModalInput" class="modal-input" rows="3" maxlength="1000" style="display:none;"></textarea>
            <p id="gModalInputError" class="modal-input-error" style="display:none;">Este campo é obrigatório.</p>

            <div id="gModalActions" class="modal-actions">
                <button id="gModalBtnConfirm" class="btn-modal btn-modal-confirm">Confirmar</button>
                <button id="gModalBtnCancel" class="btn-modal btn-modal-cancel">Cancelar</button>
            </div>
        </div>
    </div>

</body>

</html>
