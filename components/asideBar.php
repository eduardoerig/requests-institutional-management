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

// Buscar contagens para badges (apenas para gestores/admin)
$badge_pendentes = 0;
$badge_aprovadas = 0;
$badge_andamento = 0;
$badge_concluidas = 0;
$badge_repassadas = 0;

// Buscar notificações não lidas
$badge_notifs = 0;
if ($sidebar_user_id) {
    $stmtNotifs = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
    $stmtNotifs->execute([$sidebar_user_id]);
    $badge_notifs = (int)$stmtNotifs->fetchColumn();
}

if ($canManage || $isAdmSub) {
    // adm_sub vê todos os setores, filtrados apenas pelas suas subdivisões
    $managingId = ($isAdmin || $isAdmSub) ? null : $sidebar_user_id;
    $subdivisionFilter = $isAdmSub ? ($_SESSION['subdivision_ids'] ?? null) : null;
    $sidebarData = RequestManager::getRequests($pdo, null, $managingId, null, null, null, 'DESC', [], null, null, $subdivisionFilter);
    $sidebarStats = $sidebarData['stats'];
    $badge_pendentes = $sidebarStats['pendentes'] ?? 0;
    $badge_aprovadas = $sidebarStats['aprovadas'] ?? 0;
    $badge_concluidas = $sidebarStats['concluidas'] ?? 0;

    // Lógica para "Em Andamento" e "Aprovadas" com repasses deduplicados
    require_once __DIR__ . '/../classes/RequestForwardService.php';
    
    // Obter todas as requisições W e Y nativas
    $allReqs = $sidebarData['requests'] ?? [];
    $reqW = array_filter($allReqs, function($r) { return trim(strtoupper($r['st_raw'] ?? '')) === 'W'; });
    $reqY = array_filter($allReqs, function($r) { return trim(strtoupper($r['st_raw'] ?? '')) === 'Y'; });
    
    $dedupCount = function($baseArr, $fwdArr) {
        $unique = [];
        foreach ($baseArr as $r) {
            $cT = str_replace(['ctd_', '_frm'], '', strtolower(trim($r['table'] ?? '')));
            $key = $cT . '_' . trim($r['id'] ?? '');
            $unique[$key] = true;
        }
        foreach ($fwdArr as $f) {
            $rd = $f['request_data'] ?? [];
            if (empty($rd)) continue;
            // fwdArr pode vir de RequestForwardService (onde tem request_table) 
            // ou de getForwardsByDestination mapeado
            $cT = str_replace(['ctd_', '_frm'], '', strtolower(trim($f['request_table'] ?? $f['table'] ?? '')));
            $reqId = trim($rd['id'] ?? $f['id'] ?? '');
            if ($reqId) {
                $key = $cT . '_' . $reqId;
                $unique[$key] = true;
            }
        }
        return count($unique);
    };

    if ($isAdmin || $isAdmSub) {
        // Admin: W e Y + Forwards
        $fwdW = RequestForwardService::getForwardsByDestination($pdo, 'all', null, null, $subdivisionFilter, 'accepted');
        $badge_andamento = $dedupCount($reqW, $fwdW);
        
        $fwdY = RequestForwardService::getForwardsByDestination($pdo, 'all', null, null, $subdivisionFilter, 'completed');
        $reqF = array_filter($allReqs, function($r) { return trim(strtoupper($r['st_raw'] ?? '')) === 'F'; }); // Repassadas pendentes
        
        // O admin via as F pendentes nas aprovadas antigamente. Vamos manter a lógica deduplicada
        $uniqueAprovadas = [];
        $allAprovadas = array_merge($reqY, $reqF, $fwdY);
        foreach ($allAprovadas as $r) {
            $cT = str_replace(['ctd_', '_frm'], '', strtolower(trim($r['table'] ?? $r['request_table'] ?? '')));
            // Para repasses do fwdY, o ID pode estar em request_id ou request_data['id']
            $reqId = trim($r['id'] ?? $r['request_id'] ?? '');
            if (!$reqId && isset($r['request_data'])) $reqId = trim($r['request_data']['id'] ?? '');
            
            if ($reqId) {
                $key = $cT . '_' . $reqId;
                $uniqueAprovadas[$key] = true;
            }
        }
        $badge_aprovadas = count($uniqueAprovadas);
    } else {
        // Gestor
        $pendingFwds = RequestForwardService::getPendingForwards($pdo, $sidebar_user_id, 'pending');
        $fwdY_data = $pendingFwds['success'] ? $pendingFwds['data'] : [];
        $badge_aprovadas = $dedupCount($reqY, $fwdY_data);
        
        $acceptedFwds = RequestForwardService::getPendingForwards($pdo, $sidebar_user_id, 'accepted');
        $fwdW_data = $acceptedFwds['success'] ? $acceptedFwds['data'] : [];
        $badge_andamento = $dedupCount($reqW, $fwdW_data);
    }
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
<aside id="main-sidebar">
    <div class="logo-container">
        <img src="assets/img/LogoFavConSisreq.png" alt="SisReq" class="logo">
    </div>
    <nav aria-label="Menu principal">
        <?= $menuItem('new_request', 'fa-plus', 'Nova requisição', 0, '', $currentPage === 'new_request') ?>
        <?= $menuItem('myRequests', 'fa-folder-open', 'Minhas requisições', 0, '', $currentPage === 'myRequests') ?>

        <?php if ($canApprove || $canManage): ?>
            <p class="menu-section">Trabalho</p>
            <?php if ($canApprove): ?>
                <?= $menuItem('approve', 'fa-clipboard-check', 'Aprovar pedidos', (int)$badge_pendentes, 'pending-badge', $currentPage === 'approve') ?>
            <?php endif; ?>
            <?php if ($canManage): ?>
                <?= $menuItem('approved', 'fa-inbox', $isGestor ? 'Para atender' : 'Aprovadas', (int)$badge_aprovadas, 'approved-badge', $currentPage === 'approved') ?>
                <?= $menuItem('inWork', 'fa-gears', 'Em atendimento', (int)$badge_andamento, 'progress-badge', $currentPage === 'inWork') ?>
                <?= $menuItem('home?status=C', 'fa-check-double', 'Concluídas', 0, '', $currentPage === 'home' && $currentStatus === 'C') ?>
            <?php endif; ?>
            <?= $menuItem('home', 'fa-chart-pie', 'Painel geral', 0, '', $currentPage === 'home' && $currentStatus === '') ?>
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
        <div class="copy">
            <p>SisReq © 2026</p>
        </div>
    </footer>
</aside>
