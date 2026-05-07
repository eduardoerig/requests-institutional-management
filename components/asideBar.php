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

if ($canManage || $isAdmSub) {
    $managingId = $isAdmin ? null : $sidebar_user_id;
    $subdivisionFilter = $isAdmSub ? ($_SESSION['subdivision_ids'] ?? null) : null;
    $sidebarData = RequestManager::getRequests($pdo, null, $managingId, null, null, null, 'DESC', [], null, null, $subdivisionFilter);
    $sidebarStats = $sidebarData['stats'];
    $badge_pendentes = $sidebarStats['pendentes'] ?? 0;
    $badge_aprovadas = $sidebarStats['aprovadas'] ?? 0;
    $badge_concluidas = $sidebarStats['concluidas'] ?? 0;

    // Lógica para "Em Andamento"
    if ($isAdmin || $isAdmSub) {
        // Admins (Globais ou de Subdivisão) veem tudo no seu escopo: W + F
        $badge_andamento = ($sidebarStats['emAndamento'] ?? 0) + ($sidebarStats['repassadas'] ?? 0);
    } else {
        // Gestor vê o que está no prato dele:
        // 1. Suas próprias requisições em W
        $badge_andamento = ($sidebarStats['emAndamento'] ?? 0);
        
        // 2. Requisições repassadas PARA os setores dele (pendentes ou aceitas)
        require_once __DIR__ . '/../classes/RequestForwardService.php';
        $pendingFwds = RequestForwardService::getPendingForwards($pdo, $sidebar_user_id);
        if ($pendingFwds['success']) {
            $badge_andamento += count($pendingFwds['data']);
        }
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
            <p>Colégio Evangélico Martin Luther © 2025</p>
            <p>Developed by <br><a href="https://github.com/BruDu1545" target="_blank" rel="noopener">Bruno C. Adamczyk</a> &amp; <a href="https://github.com/eduardoerig" rel="noopener">Eduardo F. S. Erig</a></p>
        </div>
    </footer>
</aside>