<?php
require_once __DIR__ . '/../classes/RequestManager.php';

$role = $_SESSION['role'] ?? 'solicitante';
$user_id = $_SESSION['id'] ?? 0;

$adminRoles = ['admin', 'adm', 'coord'];
$gestorRoles = ['gestor', 'ti', 'xerox', 'service', 'shop', 'mkt', 'marketing'];

$isAdmin = in_array($role, $adminRoles);
$isAdmSub = ($role === 'adm_sub');
$isGestor = in_array($role, $gestorRoles);
$canManage = $isAdmin || $isGestor || $isAdmSub;

if (!$canManage) {
    echo '<div class="main"><div class="empty-state"><i class="fa-solid fa-lock"></i><p>Acesso restrito a gestores e administradores.</p></div></div>';
    exit;
}

$managingId = $isAdmin ? null : $user_id;

// 1. Buscar requisições Em Andamento (W)
$dataW = RequestManager::getRequests($pdo, null, $managingId, 'W');
$requests = $dataW['requests'] ?? [];

// 2. Buscar requisições Repassadas (F)
// Admin vê todas as Repassadas (F) do sistema
if ($isAdmin) {
    $dataF = RequestManager::getRequests($pdo, null, null, 'F');
    $requestsF = $dataF['requests'] ?? [];
    $requests = array_merge($requests, $requestsF);
} else {
    // Gestor: Buscar repasses recebidos (Pendente ou Aceito)
    require_once __DIR__ . '/../classes/RequestForwardService.php';
    $pendingForwards = RequestForwardService::getPendingForwards($pdo, $user_id);
    if ($pendingForwards['success'] && !empty($pendingForwards['data'])) {
        foreach ($pendingForwards['data'] as $fwd) {
            $rd = $fwd['request_data'] ?? [];
            if (empty($rd)) continue;
            
            $requests[] = [
                'id' => $rd['id'],
                'title' => $rd['title'] ?? 'Sem título',
                'date' => $rd['date'] ?? '—',
                'created_at' => $rd['created_at'] ?? date('Y-m-d H:i:s'),
                'urgent' => $rd['urgent'] ?? 0,
                'priority' => $rd['priority'] ?? 2,
                'status' => 'F',
                'st_raw' => 'F',
                'table' => $fwd['request_table'],
                'sector' => $fwd['request_table'],
                'solicitor_name' => $rd['solicitor_name'] ?? '—',
                'solicitor_name_formatted' => $rd['solicitor_name'] ?? '—',
                'subdivision_name' => '',
                'subdivision_slug' => '',
                'is_forwarded_to_me' => true,
                'forward_from' => $fwd['from_area_label'] ?? '',
            ];
        }
    }
}

$totalAndamento = count($requests);

$map = [
    'mkt' => 'gray',
    'xerox' => 'blue',
    'shop' => 'green',
    'service' => 'yellow',
    'ti' => 'red',
];

$sectorNames = [
    'mkt' => 'MKT', 'xerox' => 'Xerox', 'shop' => 'Compras',
    'service' => 'Manutenção', 'ti' => 'TI',
];

$allowedSectors = [];
if (!$isAdmin && $user_id) {
    $stmtSectors = $pdo->prepare("SELECT LOWER(a.title) FROM ctd_area a JOIN cfg_user_area cua ON a.id = cua.id_area WHERE cua.id_user = ?");
    $stmtSectors->execute([$user_id]);
    $allowedSectors = $stmtSectors->fetchAll(PDO::FETCH_COLUMN);
}
if (!is_array($allowedSectors)) $allowedSectors = [];
?>
    <div class="main">
        <div class="page-header">
            <div style="display: flex; align-items: center; gap: 15px;">
                <i class="fa-solid fa-gears" style="color: #3b82f6;"></i>
                <div>
                    <h3>Em Andamento</h3>
                    <p style="font-size: 0.85rem; color: var(--text-muted); margin: 2px 0 0;">
                        <?= $totalAndamento ?> requisição(ões) em atendimento
                    </p>
                </div>
            </div>
        </div>
        <div class="filter_req">
            <div class="left_group">
                <div class="radio_field">
                    <?php if ($isAdmin || in_array('service', $allowedSectors)): ?>
                        <input type="radio" value="service" name="tipo" class="radioType" data-status="W" data-tooltip="Manutenção">
                    <?php endif; ?>
                    <?php if ($isAdmin || in_array('shop', $allowedSectors)): ?>
                        <input type="radio" value="shop" name="tipo" class="radioType" data-status="W" data-tooltip="Compras">
                    <?php endif; ?>
                    <?php if ($isAdmin || in_array('xerox', $allowedSectors)): ?>
                        <input type="radio" value="xerox" name="tipo" class="radioType" data-status="W" data-tooltip="Xerox">
                    <?php endif; ?>
                    <?php if ($isAdmin || in_array('ti', $allowedSectors)): ?>
                        <input type="radio" value="ti" name="tipo" class="radioType" data-status="W" data-tooltip="TI">
                    <?php endif; ?>
                    <?php if ($isAdmin || in_array('mkt', $allowedSectors)): ?>
                        <input type="radio" value="mkt" name="tipo" class="radioType" data-status="W" data-tooltip="Marketing">
                    <?php endif; ?>
                    <input type="radio" value="all" name="tipo" class="radioType" data-status="W" data-tooltip="Todas solicitações" checked>
                </div>
                <div class="search_input">
                    <i class="fas fa-search"></i>
                    <input type="text" placeholder="Pesquisar..." id="campoPesquisa">
                </div>
            </div>
            <div class="order_icon" id="orderIcon" data-order="desc">
                <i class="fas fa-sort-amount-down"></i>
            </div>
        </div>
        <div class="inbox-split-layout">
            <div class="request_list_container">
                <div class="card-wrapper-scroll">
                    <?php
                    if (count($requests) > 0) {
                        foreach ($requests as $row) {
                            $urgentMark = !empty($row['urgent']) ? '<span class="req_alert">!</span>' : '';
                            $cardClass = isset($map[$row['table']]) ? $map[$row['table']] : 'gray';
                            $sectorBadge = '<span class="card-sector-tag">' . ($sectorNames[$row['table']] ?? '') . '</span>';
                            $subBadge = '';
                            if (!empty($row['subdivision_name'])) {
                                $subBadge = '<span class="badge-subdivision badge-sub-' . ($row['subdivision_slug'] ?? 'default') . '">' . htmlspecialchars($row['subdivision_name']) . '</span>';
                            }
                            
                            // Calcular tempo decorrido
                            $createdAt = strtotime($row['created_at'] ?? 'now');
                            $elapsed = time() - $createdAt;
                            $days = floor($elapsed / 86400);
                            $hours = floor(($elapsed % 86400) / 3600);
                            $timeLabel = '';
                            if ($days > 0) $timeLabel = $days . 'd ' . $hours . 'h';
                            else $timeLabel = $hours . 'h';
                            
                            $isLate = ($elapsed > (3 * 86400));
                            $timeClass = $isLate ? 'timer-late' : 'timer-ok';
                            
                            // Identificar visualmente requisições repassadas
                            $isForwarded = (trim(strtoupper($row['st_raw'] ?? $row['status'] ?? '')) === 'F');
                            $forwardedClass = $isForwarded ? ' forwarded' : '';
                            $forwardedBadge = '';
                            if ($isForwarded) {
                                if (!empty($row['is_forwarded_to_me']) && !empty($row['forward_from'])) {
                                    $forwardedBadge = '<span class="badge-forwarded"><i class="fa-solid fa-inbox"></i> De: ' . htmlspecialchars($row['forward_from']) . '</span>';
                                } else {
                                    $forwardedBadge = '<span class="badge-forwarded"><i class="fa-solid fa-share-from-square"></i> Repassada</span>';
                                }
                            }
                            
                            echo '
                                <div class="card_req ' . $cardClass . $forwardedClass . '" data-id="' . $row['id'] . '" data-table="' . $row['table'] . '">
                                    <div class="card_header">
                                        <span class="req_nome">Requisição #' . $row['id'] . '</span>
                                        <span class="card-timer ' . $timeClass . '"><i class="fa-solid fa-stopwatch"></i> ' . $timeLabel . '</span>
                                    </div>
                                    <div class="card_title">' . htmlspecialchars($row['title']) . '</div>
                                    <div style="display:flex; justify-content:space-between; align-items:center; margin-top:8px; flex-wrap:wrap; gap:6px;">
                                        <div class="card_date">Entrega: ' . ($row['date'] ?? '—') . '</div>
                                        ' . $forwardedBadge . $sectorBadge . $subBadge . '
                                    </div>
                                </div>
                            ';
                        }
                    } else {
                        echo '<div class="empty-state"><i class="fa-solid fa-inbox"></i><p>Nenhuma requisição em andamento.</p><p style="font-size:0.85rem; color:var(--text-muted);">Vá para "Aprovadas" para iniciar atendimentos.</p></div>';
                    }
                    ?>
                </div>
            </div>

            <div class="form-container-embedded">
                <form class="modal_req_embedded" id="modalReqCard" style="display: none;">
                    <input type="hidden" class="Rtype">
                    <input type="hidden" class="Rid">

                    <div class="modal_header">
                        <span class="modal_nome" id="modalNome">Solicitação</span>
                        <span class="badge-status in-progress" style="font-size: 0.75rem;"><i class="fa-solid fa-gears"></i> Em Andamento</span>
                    </div>

                    <div class="modal_title" id="modalTitle">Título da Requisição</div>
                    <div class="modal_descp" id="modalDescp">Descrição da Requisição</div>
                    <div class="modal_obs" id="modalObs">OBS</div>
                    <div class="modal_date" id="modalDate1">Data</div>
                    <div class="modal_date" id="modalDate">Data</div>

                    <div class="modal_actions">
                        <?php if ($isGestor): ?>
                            <button type="submit" name="action_value" value="finish" class="btn_ok" style="flex:1; background: #15803d;">
                                <i class="fa-solid fa-check-double" style="margin-right:6px;"></i> Concluir Requisição
                            </button>
                            <button type="button" class="btn-repassar" onclick="openForwardModal()" style="flex:1;">
                                <i class="fa-solid fa-share-from-square"></i> Repassar
                            </button>
                        <?php endif; ?>
                        <a id="btnViewDetail" href="#" class="btn-primary" style="padding: 10px 20px; font-size: 0.9rem; text-decoration: none;">
                            <i class="fas fa-eye" style="margin-right:4px;"></i> Detalhes
                        </a>
                    </div>
                </form>

                <div class="empty-state" id="modalEmptyState">
                    <i class="fa-solid fa-hand-pointer"></i>
                    <p>Selecione uma requisição em andamento para visualizar detalhes e concluir.</p>
                </div>
            </div>
        </div>
    </div>

    </div>