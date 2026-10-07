<?php
// Sidebar — Menu reorganizado para refletir o fluxo de requisições
require_once __DIR__ . '/../classes/RequestManager.php';

$role = $_SESSION['role'] ?? 'solicitante';
$sidebar_user_id = $_SESSION['id'] ?? 0;

$isAdmin = isGlobalAdminRole($role);
$isAdmSub = ($role === 'adm_sub');
$isGestor = isGestorRole($role);
$canManage = $isAdmin || $isGestor;
$canApprove = $isAdmin || $isAdmSub;
$currentPage = $page ?? ((isset($url[0]) && $url[0] !== '') ? $url[0] : 'home');
$currentStatus = $_GET['status'] ?? '';

// Contadores do menu
$badge_pendentes = 0;
$badge_inbox = 0;

// Buscar notificações não lidas
$badge_notifs = 0;
if ($sidebar_user_id) {
    $stmtNotifs = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
    $stmtNotifs->execute([$sidebar_user_id]);
    $badge_notifs = (int)$stmtNotifs->fetchColumn();
}

if ($canApprove) {
    $subdivisionFilter = $isAdmSub ? ($_SESSION['subdivision_ids'] ?? null) : null;
    $badge_pendentes = (int)(RequestManager::getRequests($pdo, null, null, 'P', null, null, 'DESC', [], null, null, $subdivisionFilter)['stats']['pendentes'] ?? 0);
}
if ($canManage) {
    require_once __DIR__ . '/../classes/RequestInbox.php';
    $inboxCounts = RequestInbox::build($pdo, RequestInbox::areasForUser($pdo, (int)$sidebar_user_id, $role))['counts'];
    $badge_inbox = $inboxCounts['novos'] + $inboxCounts['chegaram'];
}
?>
<?php
// Item de menu: ícone + texto simples + contador opcional
$menuItem = function (string $href, string $icon, string $label, int $badge = 0, string $badgeClass = '', bool $active = false) {
    $badgeHtml = $badge > 0 ? '<span class="sidebar-badge ' . e($badgeClass) . '" aria-label="' . $badge . ' itens">' . $badge . '</span>' : '';
    return '<a href="' . e($href) . '" class="menu-link' . ($active ? ' is-active' : '') . '"' . ($active ? ' aria-current="page"' : '') . '>'
        . '<i class="fa-solid ' . e($icon) . '" aria-hidden="true"></i><span>' . e($label) . '</span>' . $badgeHtml . '</a>';
};
?>
<aside id="main-sidebar" class="sidebar" aria-label="Menu">
    <div class="sidebar__grip" aria-hidden="true"></div>
    <a class="sidebar__brand" href="<?= $role === 'solicitante' ? 'myRequests' : 'home' ?>">
        <img src="assets/img/LogoFavConSisreq.png" alt="">
        <span>SisReq</span>
    </a>
    <nav aria-label="Menu principal" data-sync="menu">
        <a href="new_request" class="menu-link menu-link--new<?= $currentPage === 'new_request' ? ' is-active' : '' ?>"><i class="fa-solid fa-plus" aria-hidden="true"></i><span>Nova requisição</span></a>
        <?= $menuItem('myRequests', 'fa-folder-open', 'Minhas requisições', 0, '', $currentPage === 'myRequests') ?>

        <?php if ($canApprove || $canManage): ?>
            <p class="menu-section">Trabalho</p>
            <?php if ($canApprove): ?>
                <?= $menuItem('approve', 'fa-clipboard-check', 'Aprovar pedidos', (int)$badge_pendentes, '', $currentPage === 'approve') ?>
            <?php endif; ?>
            <?php if ($canManage): ?>
                <?= $menuItem('inbox', 'fa-inbox', $isAdmin ? 'Caixa dos setores' : 'Caixa do setor', (int)$badge_inbox, '', $currentPage === 'inbox') ?>
            <?php endif; ?>
            <?php if ($canApprove): ?>
                <?= $menuItem('home', 'fa-chart-simple', 'Painel', 0, '', $currentPage === 'home') ?>
            <?php endif; ?>
        <?php endif; ?>

        <p class="menu-section">Conta</p>
        <?php if ($isAdmin): ?>
            <?= $menuItem('accounts', 'fa-users-gear', 'Usuários e setores', 0, '', $currentPage === 'accounts') ?>
        <?php endif; ?>
        <?= $menuItem('myAccount', 'fa-user', 'Minha conta', 0, '', $currentPage === 'myAccount') ?>
        <?= $menuItem('ajuda', 'fa-circle-question', 'Como usar', 0, '', $currentPage === 'ajuda') ?>
        <a href="#" class="menu-link logout" id="btn_logout"><i class="fa-solid fa-arrow-right-from-bracket" aria-hidden="true"></i><span>Sair</span></a>
    </nav>
    <footer>
        <p>SisReq &copy; 2026</p>
    </footer>
</aside>
