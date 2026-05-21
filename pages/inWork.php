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

$managingId = ($isAdmin || $isAdmSub) ? null : $user_id;
$subdivisionFilter = $isAdmSub ? ($_SESSION['subdivision_ids'] ?? null) : null;

// 1. Buscar requisições Em Andamento (W)
$dataW = RequestManager::getRequests($pdo, null, $managingId, 'W', null, null, 'DESC', [], null, null, $subdivisionFilter);
$requests = $dataW['requests'] ?? [];

// 2. Buscar requisições Repassadas (F)
require_once __DIR__ . '/../classes/RequestForwardService.php';

if ($isAdmin || $isAdmSub) {
    // Admin: Buscar todos os repasses ativos e mapeá-los para o setor de destino
    $allForwards = RequestForwardService::getForwardsByDestination($pdo, 'all', null, null, $subdivisionFilter, 'accepted');
    $requests = array_merge($requests, $allForwards);
} else {
    // Gestor: Buscar repasses recebidos (Apenas Aceitos)
    $pendingForwards = RequestForwardService::getPendingForwards($pdo, $user_id, 'accepted');
    if ($pendingForwards['success'] && !empty($pendingForwards['data'])) {
        foreach ($pendingForwards['data'] as $fwd) {
            $rd = $fwd['request_data'] ?? [];
            if (empty($rd)) continue;
            
            $destSlug = strtolower($fwd['to_area_name'] ?? '');
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
                'responsible_sector' => $destSlug,
                'solicitor_name' => $rd['solicitor_name'] ?? '—',
                'solicitor_name_formatted' => $rd['solicitor_name'] ?? '—',
                'subdivision_name' => $rd['subdivision_name'] ?? '',
                'subdivision_slug' => $rd['subdivision_slug'] ?? '',
                'is_forwarded_to_me' => true,
                'forward_from' => $fwd['from_area_label'] ?? '',
            ];
        }
    }
}

// =========================================
// DEDUPLICAÇÃO DE REQUISIÇÕES (Evitar A->B->A duplicado)
// =========================================
$uniqueRequests = [];
foreach ($requests as $req) {
    // Normalizar o nome da tabela (remover ctd_ e _frm) para garantir que as chaves batam sempre
    $cleanTable = str_replace(['ctd_', '_frm'], '', strtolower(trim($req['table'] ?? '')));
    $key = $cleanTable . '_' . trim($req['id'] ?? '');
    
    if (isset($uniqueRequests[$key])) {
        // Se a existente NÃO é repasse e a atual É repasse, sobrescrevemos
        // Isso garante que veremos a etiqueta "DE: SETOR" ao invés da versão original limpa
        if (!isset($uniqueRequests[$key]['is_forwarded_to_me']) && isset($req['is_forwarded_to_me'])) {
            $uniqueRequests[$key] = $req;
        }
    } else {
        $uniqueRequests[$key] = $req;
    }
}
$requests = array_values($uniqueRequests);

$totalAndamento = count($requests);

$map = [
    'mkt' => 'gray',
    'xerox' => 'blue',
    'shop' => 'green',
    'service' => 'yellow',
    'ti' => 'red',
];

$sectorNames = [
    'mkt' => 'MKT', 'xerox' => 'Reprografia', 'shop' => 'Compras',
    'service' => 'Manutenção', 'ti' => 'TI',
];

$allowedSectors = [];
if (!$isAdmin && !$isAdmSub && $user_id) {
    $stmtSectors = $pdo->prepare("SELECT a.title FROM ctd_area a JOIN cfg_user_area cua ON a.id = cua.id_area WHERE cua.id_user = ?");
    $stmtSectors->execute([$user_id]);
    $allowedSectors = array_values(array_filter(array_map('areaTitleToSector', $stmtSectors->fetchAll(PDO::FETCH_COLUMN))));
}
if (!is_array($allowedSectors)) $allowedSectors = [];
?>
    <div class="main">
        <div class="page-header">
            <div>
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
                <div style="display: flex; gap: 24px; align-items: center; flex-wrap: wrap; width: 100%;">
                    <!-- Filtro de Setor -->
                    <div style="display: flex; flex-direction: column; gap: 4px;">
                        <span style="font-size: 0.7rem; color: var(--text-muted); font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px;">Filtrar por Setor</span>
                        <div class="radio_field">
                            <?php 
                            // 1. Calcular setores que possuem requisições PRÓPRIAS (W)
                            $activeSectors = [];
                            $hasForwards = false;
                            foreach ($requests as $r) {
                                if (($r['st_raw'] ?? '') === 'W') {
                                    $activeSectors[] = $r['table'];
                                } elseif (($r['st_raw'] ?? '') === 'F') {
                                    $hasForwards = true;
                                }
                            }
                            $activeSectors = array_unique($activeSectors);

                            // Bolinhas dos setores permitidos que têm requisições W
                            foreach ($sectorNames as $slug => $name): 
                                if (in_array($slug, $activeSectors)):
                                    if (!$isAdmin && !$isAdmSub && !in_array($slug, $allowedSectors)) continue;
                            ?>
                                <input type="radio" value="<?= $slug ?>" name="tipo" class="radioType" data-status="W" data-tooltip="<?= $name ?>">
                            <?php 
                                endif;
                            endforeach; 
                            
                            // 2. Bolinha de Repassadas
                            if ($hasForwards):
                            ?>
                                <input type="radio" value="forwarded" name="tipo" class="radioType" data-status="W" data-tooltip="Repassadas">
                            <?php endif; ?>

                            <input type="radio" value="all" name="tipo" class="radioType" data-status="W" data-tooltip="Todas req" checked>
                        </div>
                    </div>

                    <!-- Busca -->
                    <div style="flex: 1; min-width: 200px; display: flex; flex-direction: column; gap: 4px;">
                        <span style="font-size: 0.7rem; color: var(--text-muted); font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px;">Busca</span>
                        <div class="search_input" style="width: 100%; margin: 0;">
                            <i class="fas fa-search"></i>
                            <input type="text" placeholder="Pesquisar título..." id="campoPesquisa">
                        </div>
                    </div>

                    <!-- Filtro de Data -->
                    <div style="display: flex; flex-direction: column; gap: 4px;">
                        <span style="font-size: 0.7rem; color: var(--text-muted); font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px;">Data</span>
                        <input type="date" id="dataFiltro" style="padding: 6px 12px; border-radius: 6px; border: 1px solid var(--border-color); outline: none; background: #fff; color: var(--text-muted); font-family: var(--font-sans); font-size: 0.85rem; cursor: pointer; height: 38px; box-shadow: var(--shadow-sm);">
                    </div>
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
                            
                            $rd = $row['date'] ?? '';
                            $prazoBadge = getPrazoBadge($rd, $row['status'] ?? 'P');
                            
                            echo '
                                <div class="card_req ' . $cardClass . $forwardedClass . '" data-id="' . $row['id'] . '" data-table="' . $row['table'] . '">
                                    <div class="card_header">
                                        <span class="req_nome">Requisição ' . strtoupper($sectorNames[$row['table']] ?? $row['table'] ?? '') . ' #' . $row['id'] . ($isForwarded ? ' <i class="fa-solid fa-share" style="font-size: 0.8rem; color: #f59e0b;"></i>' : '') . '</span>
                                        <span class="card-timer ' . $timeClass . '"><i class="fa-solid fa-stopwatch"></i> ' . $timeLabel . '</span>
                                     </div>
                                     <div class="card_title">' . htmlspecialchars($row['title']) . '</div>
                                     <div style="display:flex; justify-content:space-between; align-items:center; margin-top:8px; flex-wrap:wrap; gap:6px;">
                                         <div class="card_date" style="display:flex; align-items:center;">' . $prazoBadge . '</div>
                                         <div style="display:flex; gap:6px; align-items:center;">' . $forwardedBadge . $sectorBadge . '</div>
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
                    <i class="fa-solid fa-clipboard-list" style="color: #94a3b8;"></i>
                    <p>Nenhuma requisição selecionada</p>
                    <p style="font-size: 0.83rem; color: var(--text-muted); margin-top: 4px;">Clique em uma requisição da lista para ver os detalhes e iniciar o atendimento.</p>
                </div>
            </div>
        </div>
    </div>

    </div>
