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
// Já entrou: a tela de login leva para o início
if ($logged && $requestedPage === 'login' && $force_reset !== 1) {
    header('Location: ./');
    exit;
}
// Filas antigas viraram abas da Caixa do setor
$legacyQueues = ['approved' => 'fazer', 'inWork' => 'fazer'];
if ($logged && isset($legacyQueues[$requestedPage]) && $force_reset !== 1) {
    header('Location: inbox?tab=' . $legacyQueues[$requestedPage]);
    exit;
}
// Setores trabalham pela Caixa; o Painel é da coordenação e da administração
if ($logged && isGestorRole($role) && $requestedPage === 'home' && $force_reset !== 1) {
    header('Location: inbox' . (($_GET['status'] ?? '') === 'C' ? '?tab=historico' : ''));
    exit;
}
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
// Daqui em diante a página só lê a sessão: libera o arquivo para os avisos e a conversa
// (que rodam em segundo plano) não esperarem esta página terminar
if ($page !== 'logout') {
    session_write_close();
}
$currentStatusParam = $_GET['status'] ?? '';
$isGestorShell = isGestorRole($role);
$pageTitles = [
    'home' => $currentStatusParam === 'C' ? 'Concluídas' : 'Painel',
    'new_request' => 'Nova requisição',
    'myRequests' => 'Minhas requisições',
    'approve' => 'Aprovar pedidos',
    'inbox' => isGlobalAdminRole($role) ? 'Caixa dos setores' : 'Caixa do setor',
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
                modalBtnCancel.hidden = false;

                const modalInput = document.getElementById('gModalInput');
                const modalInputLabel = document.getElementById('gModalInputLabel');
                const modalInputError = document.getElementById('gModalInputError');
                modalInput.value = '';
                modalInputError.hidden = true;
                modalInput.hidden = !input;
                modalInputLabel.hidden = !input;
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
                        modalInputError.hidden = false;
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

                const handleKey = (e) => { if (e.key === 'Escape') handleCancel(); };
                const handleBackdrop = (e) => { if (e.target === globalModal) handleCancel(); };

                const cleanup = () => {
                    modalBtnConfirm.removeEventListener('click', handleConfirm);
                    modalBtnCancel.removeEventListener('click', handleCancel);
                    document.removeEventListener('keydown', handleKey);
                    globalModal.removeEventListener('click', handleBackdrop);
                };

                modalBtnConfirm.addEventListener('click', handleConfirm);
                modalBtnCancel.addEventListener('click', handleCancel);
                document.addEventListener('keydown', handleKey);
                globalModal.addEventListener('click', handleBackdrop);
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
            modalBtnCancel.hidden = true;
            document.getElementById('gModalInput').hidden = true;
            document.getElementById('gModalInputLabel').hidden = true;
            document.getElementById('gModalInputError').hidden = true;

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
                <div class="notification-wrapper icon-btn" id="notif-btn" role="button" tabindex="0" aria-label="Avisos" aria-haspopup="true" aria-expanded="false">
                    <i class="fa-solid fa-bell" aria-hidden="true"></i>
                    <span class="notif-badge" id="notif-badge" hidden>0</span>
                    <div class="notif-dropdown" id="notif-dropdown" role="dialog" aria-label="Avisos">
                        <div class="notif-header">
                            <span>Avisos</span>
                            <button type="button" id="notif-mark-all" onclick="markAllRead()" disabled>Marcar todos como lidos</button>
                        </div>
                        <div class="notif-body" id="notif-body">
                            <div class="notif-empty"><p>Carregando avisos...</p></div>
                        </div>
                        <div class="notif-foot" id="notif-foot" hidden>
                            <i class="fa-solid fa-bell" aria-hidden="true"></i>
                            <span>Quer ser avisado mesmo com o SisReq em outra aba?</span>
                            <button type="button" class="btn btn--sm" onclick="enableBrowserNotifs()">Ativar</button>
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
                'inbox' => 'pages/inbox.php',
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
                ['inbox', 'fa-inbox', 'Caixa'],
                ['myRequests', 'fa-folder-open', 'Meus'],
                ['new_request', 'fa-plus', 'Novo'],
                ['ajuda', 'fa-circle-question', 'Ajuda'],
            ];
        } else {
            $bottomItems = [
                ['approve', 'fa-clipboard-check', 'Aprovar'],
                isGlobalAdminRole($role) ? ['inbox', 'fa-inbox', 'Caixa'] : ['home', 'fa-chart-simple', 'Painel'],
                ['new_request', 'fa-plus', 'Novo'],
                ['myRequests', 'fa-folder-open', 'Meus'],
            ];
        }
        ?>
        <nav class="bottom-nav" aria-label="Navegação principal" data-sync="bottom-nav">
            <?php foreach ($bottomItems as [$href, $icon, $label]):
                $isNew = $href === 'new_request';
                $active = $page === $href;
            ?>
                <a href="<?= e($href) ?>" class="bottom-nav__item<?= $isNew ? ' bottom-nav__new' : '' ?><?= $active ? ' is-active' : '' ?>"<?= $active ? ' aria-current="page"' : '' ?>>
                    <i class="fa-solid <?= e($icon) ?>" aria-hidden="true"></i>
                    <span><?= e($label) ?></span>
                    <?php if ($href === 'approve' && !empty($badge_pendentes)): ?><span class="sidebar-badge"><?= (int)$badge_pendentes ?></span><?php endif; ?>
                    <?php if ($href === 'inbox' && !empty($badge_inbox)): ?><span class="sidebar-badge"><?= (int)$badge_inbox ?></span><?php endif; ?>
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

    <!-- Encaminhar: como, para quem, mensagem, resumo -->
    <div class="modal-overlay fw" id="forwardWizard" role="dialog" aria-modal="true" aria-labelledby="fwTitle" hidden>
        <form class="modal-card fw__card" id="fwForm" novalidate>
            <header class="fw__head">
                <div>
                    <h3 id="fwTitle">Encaminhar pedido</h3>
                    <p class="fw__req" id="fwReq"></p>
                </div>
                <button type="button" class="fw__close" data-fw-close aria-label="Fechar"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
            </header>

            <fieldset class="fw__step">
                <legend class="fw__legend"><span>1</span> Como encaminhar?</legend>
                <div class="fw__choices">
                    <label class="fw__choice">
                        <input type="radio" name="mode" value="transfer" required>
                        <span><strong><i class="fa-solid fa-right-left" aria-hidden="true"></i> Transferir</strong>O outro setor passa a cuidar do pedido.</span>
                    </label>
                    <label class="fw__choice">
                        <input type="radio" name="mode" value="help" required>
                        <span><strong><i class="fa-solid fa-handshake-angle" aria-hidden="true"></i> Pedir ajuda</strong>O outro setor faz uma parte e devolve para você.</span>
                    </label>
                </div>
            </fieldset>

            <fieldset class="fw__step">
                <legend class="fw__legend"><span>2</span> Para qual setor?</legend>
                <div class="fw__choices fw__choices--areas" id="fwAreas"><p class="fw__hint">Carregando setores...</p></div>
            </fieldset>

            <div class="fw__step">
                <label for="fwMessage" class="fw__legend"><span>3</span> O que o outro setor precisa saber ou fazer?</label>
                <textarea id="fwMessage" name="message" rows="3" maxlength="1000" required placeholder="Ex.: o projetor da sala 12 não liga, já trocamos o cabo."></textarea>
                <p class="fw__hint" id="fwCount">Mínimo de 10 letras.</p>
            </div>

            <p class="fw__summary" id="fwSummary" aria-live="polite">Escolha como e para qual setor.</p>
            <div class="fw__actions">
                <button type="button" class="btn btn--ghost" data-fw-close>Cancelar</button>
                <button type="submit" class="btn btn--primary" id="fwSubmit" disabled><i class="fa-solid fa-paper-plane" aria-hidden="true"></i> Encaminhar</button>
            </div>
        </form>
    </div>

    <script>
    // Janela de encaminhar (usada pela visão do pedido: página ou painel da Caixa)
    (function () {
        const wiz = document.getElementById('forwardWizard');
        if (!wiz) return;
        const form = document.getElementById('fwForm');
        const areasBox = document.getElementById('fwAreas');
        const msg = document.getElementById('fwMessage');
        const submit = document.getElementById('fwSubmit');
        const summary = document.getElementById('fwSummary');
        const placeholders = {
            transfer: 'Ex.: isso é com vocês: troca de lâmpadas da sala 12, não é da TI.',
            help: 'Ex.: precisamos de 2 cabos HDMI para terminar a instalação na sala 12.'
        };
        let current = null;

        function update() {
            const mode = form.mode.value;
            const area = form.querySelector('input[name="area"]:checked');
            const len = msg.value.trim().length;
            if (mode) msg.placeholder = placeholders[mode];
            document.getElementById('fwCount').textContent = len >= 10 ? 'Pronto.' : 'Mínimo de 10 letras (faltam ' + (10 - len) + ').';
            const verb = mode === 'help' ? 'Pedir ajuda a' : 'Transferir o pedido para';
            summary.textContent = mode && area
                ? verb + ' ' + area.dataset.label + '.' + (mode === 'help' ? ' Seu setor continua responsável.' : ' Eles precisam aceitar.')
                : 'Escolha como e para qual setor.';
            submit.disabled = !(mode && area && len >= 10);
        }

        function close() {
            wiz.classList.remove('show');
            wiz.hidden = true;
            current = null;
        }

        window.openForwardWizard = async function (id, table) {
            current = { id: id, table: table };
            form.reset();
            const view = document.querySelector('.request-view[data-id="' + id + '"][data-table="' + table + '"]');
            const title = view && view.querySelector('.rv-title') ? view.querySelector('.rv-title').textContent.trim() : '';
            document.getElementById('fwReq').textContent = 'Nº ' + id + (title ? ' · ' + title : '');
            areasBox.innerHTML = '<p class="fw__hint">Carregando setores...</p>';
            update();
            wiz.hidden = false;
            requestAnimationFrame(() => wiz.classList.add('show'));
            form.querySelector('input[name="mode"]').focus();
            try {
                const response = await fetch('api/request_forwards.php?action=areas&id=' + encodeURIComponent(id) + '&table=' + encodeURIComponent(table));
                const data = await response.json();
                if (!data.success || !data.data.length) {
                    areasBox.innerHTML = '<p class="fw__hint">Nenhum outro setor disponível.</p>';
                    return;
                }
                areasBox.innerHTML = '';
                data.data.forEach(a => {
                    const label = document.createElement('label');
                    label.className = 'fw__choice fw__choice--area';
                    label.innerHTML = '<input type="radio" name="area" required><span><strong></strong></span>';
                    const input = label.querySelector('input');
                    input.value = a.id;
                    input.dataset.label = a.label;
                    label.querySelector('strong').textContent = a.label;
                    areasBox.appendChild(label);
                });
            } catch (err) {
                areasBox.innerHTML = '<p class="fw__hint">Não foi possível carregar os setores. Feche e tente de novo.</p>';
            }
        };

        form.addEventListener('input', update);
        form.addEventListener('change', update);
        wiz.addEventListener('click', e => { if (e.target === wiz || e.target.closest('[data-fw-close]')) close(); });
        document.addEventListener('keydown', e => { if (e.key === 'Escape' && !wiz.hidden) close(); });

        form.addEventListener('submit', async (e) => {
            e.preventDefault();
            update();
            if (submit.disabled || !current) return;
            submit.disabled = true;
            const data = await postForm('api/request_forwards.php', {
                action: 'forward', id: current.id, table: current.table,
                mode: form.mode.value,
                to_area_id: form.querySelector('input[name="area"]:checked').value,
                message: msg.value.trim()
            });
            submit.disabled = false;
            if (!data) return;
            showToast(data.message, data.success ? 'success' : 'error');
            if (data.success) {
                close();
                refreshAfterChange({ advance: true, message: data.message }); // o pedido vai para "Esperando": abre o próximo da fila
            }
        });
    })();
    </script>

    <!-- Global Modal Structure (Moved to end for correct stacking) -->
    <div id="globalModal" class="modal-overlay" role="dialog" aria-modal="true" aria-labelledby="gModalTitle">
        <div class="modal-card">
            <div id="gModalIcon" class="modal-icon">
                <i class="fa-solid"></i>
            </div>
            <h3 id="gModalTitle" class="modal-title">Título</h3>
            <p id="gModalMessage" class="modal-message">Mensagem do modal aqui.</p>
            <label id="gModalInputLabel" for="gModalInput" class="modal-input-label" hidden></label>
            <textarea id="gModalInput" class="modal-input" rows="3" maxlength="1000" hidden></textarea>
            <p id="gModalInputError" class="modal-input-error" hidden>Este campo é obrigatório.</p>

            <div id="gModalActions" class="modal-actions">
                <button id="gModalBtnConfirm" class="btn-modal btn-modal-confirm">Confirmar</button>
                <button id="gModalBtnCancel" class="btn-modal btn-modal-cancel">Cancelar</button>
            </div>
        </div>
    </div>

</body>

</html>
