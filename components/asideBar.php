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

if ($canApprove) {
    $subdivisionFilter = $isAdmSub ? ($_SESSION['subdivision_ids'] ?? null) : null;
    $badge_pendentes = RequestManager::countPending($pdo, $subdivisionFilter);
}
if ($canManage) {
    require_once __DIR__ . '/../classes/RequestInbox.php';
    $inboxCounts = RequestInbox::build($pdo, RequestInbox::areasForUser($pdo, (int)$sidebar_user_id, $role))['counts'];
    $badge_inbox = $inboxCounts['aceitar'] + $inboxCounts['comecar'];
}
?>
<?php
// Item de menu: ícone + texto simples + contador opcional
// O número diz o que conta: na Caixa, os que chegaram e ninguém começou; em Aprovar, os que esperam decisão
$badgeHint = fn(string $href, int $n) => $href === 'approve' ? "$n para aprovar" : ($n === 1 ? '1 novo para atender' : "$n novos para atender");
$menuItem = function (string $href, string $icon, string $label, int $badge = 0, string $badgeClass = '', bool $active = false) use ($badgeHint) {
    // Na tela no máximo "99+"; o leitor de tela e o title dizem o número real
    $badgeHtml = $badge > 0
        ? '<span class="sidebar-badge ' . e($badgeClass) . '" title="' . e($badgeHint($href, $badge)) . '" aria-hidden="true">' . ($badge > 99 ? '99+' : $badge) . '</span>'
            . '<span class="visually-hidden">' . e($badgeHint($href, $badge)) . '</span>'
        : '';
    return '<a href="' . e($href) . '" class="menu-link' . ($active ? ' is-active' : '') . '"' . ($active ? ' aria-current="page"' : '') . '>'
        . '<i class="fa-solid ' . e($icon) . '" aria-hidden="true"></i><span title="' . e($label) . '">' . e($label) . '</span>' . $badgeHtml . '</a>';
};
?>
<aside id="main-sidebar" class="sidebar" aria-label="Menu">
    <div class="sidebar__grip" aria-hidden="true"></div>
    <a class="sidebar__brand" href="<?= $role === 'solicitante' ? 'myRequests' : 'home' ?>">
        <img src="assets/img/LogoFavConSisreq.png" alt="">
        <span>SisReq</span>
    </a>
    <nav aria-label="Menu principal" data-sync="menu">
        <a href="new_request" class="menu-link menu-link--new<?= $currentPage === 'new_request' ? ' is-active' : '' ?>"><i class="fa-solid fa-plus" aria-hidden="true"></i><span>Novo pedido</span></a>
        <?= $menuItem('myRequests', 'fa-folder-open', 'Meus pedidos', 0, '', $currentPage === 'myRequests') ?>

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
        <a href="#" class="menu-link logout" id="btn_logout" role="button" data-logout><i class="fa-solid fa-arrow-right-from-bracket" aria-hidden="true"></i><span>Sair</span></a>
    </nav>
    <footer>
        <p>SisReq &copy; <?= date('Y') ?></p>
    </footer>
</aside>
