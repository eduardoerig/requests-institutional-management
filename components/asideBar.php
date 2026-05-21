<?php
// Sidebar — Menu reorganizado para refletir o fluxo de requisições
require_once __DIR__ . '/../classes/RequestManager.php';

$role = $_SESSION['role'] ?? 'solicitante';
$sidebar_user_id = $_SESSION['id'] ?? 0;

$adminRoles = ['admin', 'adm', 'coord'];
$gestorRoles = ['gestor', 'ti', 'xerox', 'service', 'shop', 'mkt', 'marketing'];
$approverRoles = array_merge($adminRoles, ['adm_sub']); // Quem pode aprovar

$isAdmin = in_array($role, $adminRoles);
$isAdmSub = ($role === 'adm_sub');
$isGestor = in_array($role, $gestorRoles);
$canManage = $isAdmin || $isGestor;
$canApprove = in_array($role, $approverRoles);

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
<aside id="main-sidebar">
    <div class="logo-container">
        <img src="assets/img/logoMartin.png" alt="Logo Martin Luther" class="logo">
    </div>
    <nav>
        <?php if ($canManage): ?>
            <a href="home" class="dashboard-link"><i class="fa-solid fa-chart-pie"></i>Dashboard</a>
        <?php endif; ?>

        <a href="new_request" class="new_request"><i class="fa-solid fa-plus"></i>Nova requisição</a>
        
        <details open>
            <summary><i class="fa-solid fa-clipboard-list"></i> Requisições<i class="fa-solid fa-angle-right arrow"></i></summary>
            <ul>    
                <?php if ($canApprove): ?>
                    <li>
                        <a href="approve">
                            <i class="fa-solid fa-clipboard-check" style="width:16px; font-size:0.85rem; color:#94a3b8; margin-right:6px;"></i>
                            Aprovar
                            <?php if ($badge_pendentes > 0): ?>
                                <span class="sidebar-badge pending-badge"><?= $badge_pendentes ?></span>
                            <?php endif; ?>
                        </a>
                    </li>
                <?php endif; ?>
                <?php if ($canManage): ?>
                    <li>
                        <a href="approved">
                            <i class="fa-solid fa-circle-check" style="width:16px; font-size:0.85rem; color:#94a3b8; margin-right:6px;"></i>
                            Aprovadas
                            <?php if ($badge_aprovadas > 0): ?>
                                <span class="sidebar-badge approved-badge"><?= $badge_aprovadas ?></span>
                            <?php endif; ?>
                        </a>
                    </li>
                    <li>
                        <a href="inWork">
                            <i class="fa-solid fa-gears" style="width:16px; font-size:0.85rem; color:#94a3b8; margin-right:6px;"></i>
                            Em Andamento
                            <?php if ($badge_andamento > 0): ?>
                                <span class="sidebar-badge progress-badge"><?= $badge_andamento ?></span>
                            <?php endif; ?>
                        </a>
                    </li>
                    <li>
                        <a href="home?status=C">
                            <i class="fa-solid fa-check-double" style="width:16px; font-size:0.85rem; color:#94a3b8; margin-right:6px;"></i>
                            Concluídas
                            <?php if ($badge_concluidas > 0): ?>
                                <span class="sidebar-badge" style="background:#10b981; color:white; padding: 2px 6px; border-radius: 10px; font-size: 0.7rem; font-weight: 700; margin-left: auto;"><?= $badge_concluidas ?></span>
                            <?php endif; ?>
                        </a>
                    </li>
                <?php endif; ?>
                <li><a href="myRequests"><i class="fa-solid fa-folder-open" style="width:16px; font-size:0.85rem; color:#94a3b8; margin-right:6px;"></i>Minhas requisições</a></li>
            </ul>
        </details>

        <details>
            <summary><i class="fa-solid fa-gear"></i>Configurações<i class="fa-solid fa-angle-right arrow"></i></summary>
            <ul>
                <li><a href="myAccount">Minha Conta</a></li>
                <?php if ($isAdmin): ?>
                    <li><a href="accounts">Contas</a></li>
                <?php endif; ?>
                <li><a href="#" class="logout" id="btn_logout"><i class="fa-solid fa-arrow-right-from-bracket"></i>Sair</a></li>
            </ul>
        </details>
    </nav>
    <footer>
        <div class="copy">
            <p>Colégio Evangélico Martin Luther © 2026</p>
            <p>Developed by <a href="https://github.com/eduardoerig" rel="noopener" target="_blank">Eduardo F. S. Erig</a></p>
        </div>
    </footer>
</aside>