<?php
require_once __DIR__ . '/config/session.php';
include 'config/conn.php';
require_once 'config/security.php';
header('Content-Type: text/html; charset=UTF-8');
$url = isset($_GET['url']) ? explode('/', trim($_GET['url'], '/')) : [];
$logged = $_SESSION['id'] ?? false;
$role = $_SESSION['role'] ?? 'view';
$force_reset = (int)($_SESSION['force_reset'] ?? 0);
$assetVersion = max(array_map(fn($f) => (int)@filemtime($f), glob(__DIR__ . '/assets/{js,css}/{,pages/}*.{js,css}', GLOB_BRACE) ?: [])) ?: time();
$csrfToken = getCsrfToken();
// Caminho base da aplicação (funciona em subpasta local ou na raiz do domínio)
$basePath = rtrim(str_replace(chr(92), '/', dirname($_SERVER['SCRIPT_NAME'])), '/') . '/';

// Solicitante não tem painel: vai direto para as próprias requisições
$requestedPage = (isset($url[0]) && $url[0] !== '') ? $url[0] : 'home';
// Sair só por POST + CSRF (api/logout.php); o endereço antigo por GET não encerra a sessão
if ($requestedPage === 'logout') {
    header('Location: ./');
    exit;
}
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

// Se forçado a resetar, só permite acessar 'myAccount' (de onde também se sai do sistema)
// Ignora redirecionamento para arquivos estáticos (assets)
if ($logged && $force_reset === 1) {
    $current_page = (isset($url[0]) && $url[0] !== '') ? $url[0] : 'home';
    $allowed_pages = ['myAccount'];
    
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
session_write_close();
$currentStatusParam = $_GET['status'] ?? '';
$isGestorShell = isGestorRole($role);
$pageTitles = [
    'home' => $currentStatusParam === 'C' ? 'Concluídas' : 'Painel',
    'new_request' => 'Novo pedido',
    'myRequests' => 'Meus pedidos',
    'approve' => 'Aprovar pedidos',
    'inbox' => isGlobalAdminRole($role) ? 'Caixa dos setores' : 'Caixa do setor',
    'myAccount' => 'Minha conta',
    'accounts' => 'Usuários e setores',
    'request_detail' => 'Pedido',
    'ajuda' => 'Como usar',
];
$pageTitle = $pageTitles[$page] ?? 'SisReq';
$roleClass = preg_replace('/[^a-z_]/', '', (string)$role);
$pageClass = preg_replace('/[^a-zA-Z_]/', '', (string)$page);
// CSS/JS próprios da tela (assets/css/pages/<tela>.css, assets/js/pages/<tela>.js), se existirem
$assetPage = $logged ? $pageClass : 'login';
// Bibliotecas de terceiros (assets/vendor/<lib>-<versão>/) só nas telas que usam
$comboPages = ['accounts']; // <select data-combo>: Tom Select + assets/js/combo.js
$useCombo = in_array($assetPage, $comboPages, true);
?>
<!DOCTYPE html>
<html lang="pt-br">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <base href="<?= e($basePath) ?>">
    <meta name="theme-color" content="#FFFFFF">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer">
    <link rel="icon" href="assets/img/LogoFavConSisreq.png">
    <link rel="stylesheet" href="assets/css/app.css?v=<?php echo $assetVersion; ?>">
    <?php if ($useCombo): ?>
    <link rel="stylesheet" href="assets/vendor/tom-select-2.6.2/tom-select.min.css">
    <link rel="stylesheet" href="assets/css/combo.css?v=<?php echo $assetVersion; ?>">
    <?php endif; ?>
    <?php if (is_file(__DIR__ . '/assets/css/pages/' . $assetPage . '.css')): ?>
    <link rel="stylesheet" href="assets/css/pages/<?= e($assetPage) ?>.css?v=<?php echo $assetVersion; ?>">
    <?php endif; ?>
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

<?php if (!$logged || $page === 'login'): ?>
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
                    <?php if ($href === 'approve' && !empty($badge_pendentes)): ?><span class="sidebar-badge" aria-label="<?= e($badgeHint('approve', (int)$badge_pendentes)) ?>"><?= (int)$badge_pendentes ?></span><?php endif; ?>
                    <?php if ($href === 'inbox' && !empty($badge_inbox)): ?><span class="sidebar-badge" aria-label="<?= e($badgeHint('inbox', (int)$badge_inbox)) ?>"><?= (int)$badge_inbox ?></span><?php endif; ?>
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
    <?php if ($useCombo): ?>
    <script src="assets/vendor/tom-select-2.6.2/tom-select.complete.min.js"></script>
    <script src="assets/js/combo.js?v=<?php echo $assetVersion; ?>"></script>
    <?php endif; ?>
    <?php if (is_file(__DIR__ . '/assets/js/pages/' . $assetPage . '.js')): ?>
    <script src="assets/js/pages/<?= e($assetPage) ?>.js?v=<?php echo $assetVersion; ?>"></script>
    <?php endif; ?>

    <!-- Encaminhar: três passos, um por tela (como → para qual setor → recado); o botão final diz o que vai acontecer -->
    <div class="modal-overlay fw" id="forwardWizard" role="dialog" aria-modal="true" aria-labelledby="fwTitle" hidden>
        <form class="modal-card fw__card" id="fwForm" novalidate>
            <header class="fw__head">
                <div class="fw__titles">
                    <p class="fw__kicker" id="fwKicker"></p>
                    <h3 id="fwTitle">O que você quer fazer?</h3>
                </div>
                <button type="button" class="fw__close" data-fw-close aria-label="Fechar"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
            </header>
            <ol class="fw__progress" aria-hidden="true"><li></li><li></li><li></li></ol>

            <div class="fw__body">
                <fieldset class="fw__step" data-fw-step="1">
                    <legend class="visually-hidden">O que você quer fazer?</legend>
                    <div class="fw__choices">
                        <label class="fw__choice">
                            <input type="radio" name="mode" value="transfer" required>
                            <span class="fw__radio" aria-hidden="true"></span>
                            <span class="fw__text"><strong>Transferir</strong><span data-fw-mode-text="transfer">O outro setor assume o pedido inteiro.</span><small>Exemplo: o "computador que não liga" era uma tomada queimada. Vai para Manutenção.</small></span>
                        </label>
                        <label class="fw__choice">
                            <input type="radio" name="mode" value="help" required>
                            <span class="fw__radio" aria-hidden="true"></span>
                            <span class="fw__text"><strong>Pedir ajuda</strong><span data-fw-mode-text="help">O outro setor faz uma parte e devolve com um retorno. O pedido continua com você.</span><small>Exemplo: Compras compra a lâmpada do projetor e vocês instalam.</small></span>
                        </label>
                    </div>
                </fieldset>

                <fieldset class="fw__step" data-fw-step="2" hidden>
                    <legend class="visually-hidden">Para qual setor?</legend>
                    <div class="fw__choices fw__choices--areas" id="fwAreas"><p class="fw__hint">Carregando setores...</p></div>
                </fieldset>

                <div class="fw__step" data-fw-step="3" hidden>
                    <label for="fwMessage" class="fw__label" id="fwMessageLabel">Recado</label>
                    <textarea id="fwMessage" name="message" rows="4" maxlength="1000" required aria-describedby="fwCount fwAfter"></textarea>
                    <p class="fw__count" id="fwCount" aria-live="polite"></p>
                    <p class="fw__after" id="fwAfter"></p>
                </div>
            </div>

            <div class="fw__actions">
                <button type="button" class="btn" id="fwBack">Cancelar</button>
                <button type="submit" class="btn btn--primary" id="fwNext" disabled>Continuar</button>
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
        const next = document.getElementById('fwNext');
        const back = document.getElementById('fwBack');
        const count = document.getElementById('fwCount');
        const after = document.getElementById('fwAfter');
        const kicker = document.getElementById('fwKicker');
        const title = document.getElementById('fwTitle');
        const msgLabel = document.getElementById('fwMessageLabel');
        const steps = [...wiz.querySelectorAll('[data-fw-step]')];
        const bars = [...wiz.querySelectorAll('.fw__progress li')];
        const questions = ['O que você quer fazer?', 'Para qual setor?', 'O que eles precisam saber?'];
        const placeholders = {
            transfer: 'Ex.: o computador está bom. A tomada atrás da mesa não tem energia; testei com outro aparelho.',
            help: 'Ex.: precisamos de uma lâmpada para o projetor da sala 21 (modelo na etiqueta: ELPLP96).'
        };
        const MIN = 10;
        let current = null; // { id, table, from }
        let step = 1;

        const mode = () => form.mode.value;
        const area = () => form.querySelector('input[name="area"]:checked');
        const len = () => msg.value.trim().length;

        // Monta a tela do passo atual: título, linha de cima, barra, botões
        function render(focus) {
            const m = mode();
            const a = area();
            const to = a ? a.dataset.label : '';
            const from = current && current.from ? current.from : 'seu setor';
            steps.forEach(s => { s.hidden = Number(s.dataset.fwStep) !== step; });
            bars.forEach((b, i) => b.classList.toggle('is-done', i < step));
            title.textContent = questions[step - 1];
            const what = m === 'help' ? 'Pedir ajuda' : 'Transferir';
            kicker.textContent = (step === 1
                ? 'Encaminhar #' + current.id + (current.from ? ' · ' + current.from : '')
                : step === 2 ? what : what + (m === 'help' ? ' a ' : ' para ') + to) + ' · Passo ' + step + ' de 3';
            back.textContent = step === 1 ? 'Cancelar' : 'Voltar';

            if (step < 3) {
                next.textContent = 'Continuar';
                next.disabled = step === 1 ? !m : !a;
            } else {
                const n = len();
                msgLabel.textContent = 'Recado para ' + to;
                msg.placeholder = placeholders[m];
                count.className = 'fw__count' + (n >= MIN ? ' is-ok' : n > 0 ? ' is-short' : '');
                count.innerHTML = n >= MIN
                    ? '<i class="fa-solid fa-check" aria-hidden="true"></i> Pronto para enviar.'
                    : n > 0 ? '<i class="fa-regular fa-circle-question" aria-hidden="true"></i> Escreva mais ' + (MIN - n) + (MIN - n === 1 ? ' letra.' : ' letras.') : '';
                after.textContent = m === 'help'
                    ? to + ' precisa aceitar. Se recusar, diz o motivo e o pedido continua com ' + from + ' como está.'
                    : 'Quando ' + to + ' aceitar, o pedido sai da Caixa de ' + from + '. Você continua lendo a conversa com quem pediu, só para leitura.';
                next.textContent = (m === 'help' ? 'Pedir ajuda a ' : 'Transferir para ') + to;
                next.disabled = n < MIN;
            }
            if (focus) {
                const target = step === 1 ? (form.querySelector('input[name="mode"]:checked') || form.querySelector('input[name="mode"]'))
                    : step === 2 ? (area() || areasBox.querySelector('input'))
                    : msg;
                if (target) target.focus();
            }
        }

        function go(to) {
            step = to;
            render(true);
        }

        let opener = null; // ao fechar, o foco volta para o botão que abriu
        function close() {
            wiz.classList.remove('show');
            wiz.hidden = true;
            current = null;
            if (opener && opener.isConnected) opener.focus();
        }

        window.openForwardWizard = async function (id, table) {
            opener = document.activeElement;
            current = { id: id, table: table, from: '' };
            form.reset();
            step = 1;
            areasBox.innerHTML = '<p class="fw__hint">Carregando setores...</p>';
            wiz.hidden = false;
            requestAnimationFrame(() => wiz.classList.add('show'));
            render(true);
            try {
                const response = await fetch('api/request_forwards.php?action=areas&id=' + encodeURIComponent(id) + '&table=' + encodeURIComponent(table));
                const data = await readJson(response);
                if (!current || current.id !== id) return; // fechou ou abriu outro enquanto carregava
                current.from = data.from || '';
                if (!data.success || !data.data.length) {
                    areasBox.innerHTML = '<p class="fw__hint">Nenhum outro setor disponível.</p>';
                    render(false);
                    return;
                }
                areasBox.innerHTML = '';
                data.data.forEach(a => {
                    const label = document.createElement('label');
                    label.className = 'fw__choice fw__choice--area';
                    label.innerHTML = '<input type="radio" name="area" required><span class="fw__radio" aria-hidden="true"></span><span class="fw__text"><strong></strong><small></small></span>';
                    const input = label.querySelector('input');
                    input.value = a.id;
                    input.dataset.label = a.label;
                    label.querySelector('strong').textContent = a.label;
                    const small = label.querySelector('small');
                    if (a.description) small.textContent = a.description; else small.remove();
                    areasBox.appendChild(label);
                });
                render(false);
            } catch (err) {
                areasBox.innerHTML = '<p class="fw__hint">Não foi possível carregar os setores. Feche e tente de novo.</p>';
            }
        };

        form.addEventListener('input', () => render(false));
        form.addEventListener('change', () => render(false));
        back.addEventListener('click', () => { if (step === 1) close(); else go(step - 1); });
        wiz.addEventListener('click', e => { if (e.target === wiz || e.target.closest('[data-fw-close]')) close(); });
        document.addEventListener('keydown', e => { if (e.key === 'Escape' && !wiz.hidden) close(); });
        // Enter na escolha avança; no recado, Enter quebra linha (o envio é pelo botão)
        form.addEventListener('keydown', e => {
            if (e.key === 'Enter' && e.target.type === 'radio') {
                e.preventDefault();
                if (!next.disabled) form.requestSubmit();
            }
        });

        form.addEventListener('submit', async (e) => {
            e.preventDefault();
            render(false);
            if (next.disabled || !current) return;
            if (step < 3) { go(step + 1); return; }
            next.disabled = true;
            next.setAttribute('aria-busy', 'true');
            const data = await postForm('api/request_forwards.php', {
                action: 'forward', id: current.id, table: current.table,
                mode: mode(),
                to_area_id: area().value,
                message: msg.value.trim()
            });
            next.removeAttribute('aria-busy');
            next.disabled = false;
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
