<?php
require_once __DIR__ . '/../classes/RequestManager.php';

$role = $_SESSION['role'] ?? 'solicitante';
$user_id = $_SESSION['id'] ?? 0;

$adminRoles = ['admin', 'adm', 'coord'];
$gestorRoles = ['gestor', 'ti', 'xerox', 'service', 'shop', 'mkt', 'marketing'];

$isAdmin = in_array($role, $adminRoles);
$isAdmSub = ($role === 'adm_sub');
$isGestor = in_array($role, $gestorRoles);
$canApprove = $isAdmin || $isAdmSub;

if (!$canApprove) {
    echo '<div class="main"><div class="empty-state"><i class="fa-solid fa-lock"></i><p>Você não tem permissão para acessar esta página.<br>Apenas administradores e coordenadores podem aprovar requisições.</p></div></div>';
    exit;
}

$managingId = null; // Admin vê tudo para aprovação
$subdivisionFilter = $isAdmSub ? ($_SESSION['subdivision_ids'] ?? null) : null;

$data = RequestManager::getRequests($pdo, null, $managingId, 'P', null, null, 'DESC', [], null, null, $subdivisionFilter);
$requests = $data['requests'] ?? [];
$totalPendentes = count($requests);

$map = [
    'mkt' => 'gray',
    'xerox' => 'blue',
    'shop' => 'green',
    'service' => 'yellow',
    'ti' => 'red',
];

$sectorNames = [
    'mkt' => 'MKT',
    'xerox' => 'Xerox',
    'shop' => 'Compras',
    'service' => 'Manutenção',
    'ti' => 'TI',
];

// Pegar setores permitidos para os botões de rádio
$allowedSectors = [];
if ($user_id) {
    $stmtSectors = $pdo->prepare("SELECT LOWER(a.title) FROM ctd_area a JOIN cfg_user_area cua ON a.id = cua.id_area WHERE cua.id_user = ?");
    $stmtSectors->execute([$user_id]);
    $allowedSectors = $stmtSectors->fetchAll(PDO::FETCH_COLUMN);
}
if (!is_array($allowedSectors)) $allowedSectors = [];
?>
    <!-- DEBUG: count($requests) = <?= count($requests) ?> -->
    <div class="main">
        <div class="page-header">
            <div style="display: flex; align-items: center; gap: 15px;">
                <i class="fa-solid fa-clipboard-check"></i>
                <div>
                    <h3>Aprovar Requisições</h3>
                    <p style="font-size: 0.85rem; color: var(--text-muted); margin: 2px 0 0;">
                        <?= $totalPendentes ?> requisição(ões) aguardando aprovação
                    </p>
                </div>
            </div>
            <?php if ($totalPendentes > 0): ?>
                <span class="flow-count-header"><?= $totalPendentes ?> pendente<?= $totalPendentes > 1 ? 's' : '' ?></span>
            <?php endif; ?>
        </div>

        <div class="filter_req">
            <div class="left_group">
                <div class="radio_field">
                    <?php if ($isAdmin || in_array('service', $allowedSectors)): ?>
                        <input type="radio" value="service" name="tipo" class="radioType" data-status="MY" data-tooltip="Manutenção">
                    <?php endif; ?>
                    <?php if ($isAdmin || in_array('shop', $allowedSectors)): ?>
                        <input type="radio" value="shop" name="tipo" class="radioType" data-status="MY" data-tooltip="Compras">
                    <?php endif; ?>
                    <?php if ($isAdmin || in_array('xerox', $allowedSectors)): ?>
                        <input type="radio" value="xerox" name="tipo" class="radioType" data-status="MY" data-tooltip="Xerox">
                    <?php endif; ?>
                    <?php if ($isAdmin || in_array('ti', $allowedSectors)): ?>
                        <input type="radio" value="ti" name="tipo" class="radioType" data-status="MY" data-tooltip="TI">
                    <?php endif; ?>
                    <?php if ($isAdmin || in_array('mkt', $allowedSectors)): ?>
                        <input type="radio" value="mkt" name="tipo" class="radioType" data-status="MY" data-tooltip="Marketing">
                    <?php endif; ?>
                    <input type="radio" value="all" name="tipo" class="radioType" data-status="MY" data-tooltip="Todas solicitações" checked>
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
            <!-- Esquerda: Lista de Requisições -->
            <div class="request_list_container">
                <div class="card-wrapper-scroll">
                    <?php
                    if (count($requests) > 0) {
                        foreach ($requests as $row) {
                            $urgentMark = !empty($row['urgent']) ? '<span class="req_alert">!</span>' : '';
                            $cardClass = isset($map[$row['table']]) ? $map[$row['table']] : 'gray';
                            $pri = $row['priority'] ?? 2;
                            $priBadge = '';
                            if ($pri == 4) $priBadge = '<span class="card-priority critical"><i class="fa-solid fa-fire-extinguisher"></i></span>';
                            elseif ($pri == 3) $priBadge = '<span class="card-priority high"><i class="fa-solid fa-angles-up"></i></span>';
                            
                            $sectorBadge = '<span class="card-sector-tag">' . ($sectorNames[$row['table']] ?? '') . '</span>';
                            $subBadge = '';
                            if (!empty($row['subdivision_name'])) {
                                $subBadge = '<span class="badge-subdivision badge-sub-' . ($row['subdivision_slug'] ?? 'default') . '">' . htmlspecialchars($row['subdivision_name']) . '</span>';
                            }
                            
                            echo '
                                <div class="card_req ' . $cardClass . '" data-id="' . $row['id'] . '" data-table="' . $row['table'] . '">
                                    <div class="card_header">
                                        <span class="req_nome">' . htmlspecialchars($row['table'] ?? '') . ' #' . $row['id'] . '</span>
                                        <div style="display:flex; gap:6px; align-items:center;">' . $priBadge . $urgentMark . '</div>
                                    </div>
                                    <div class="card_title">' . htmlspecialchars($row['title'] ?? 'Sem Título') . '</div>
                                    <div style="display:flex; justify-content:space-between; align-items:center; margin-top:8px; flex-wrap:wrap; gap:6px;">
                                        <div class="card_date">Entrega: ' . htmlspecialchars($row['date'] ?? '—') . '</div>
                                        ' . $sectorBadge . $subBadge . '
                                    </div>
                                </div>
                            ';
                        }
                    } else {
                        echo '<div class="empty-state"><i class="fa-solid fa-inbox"></i><p>Nenhuma requisição pendente encontrada.</p><p style="font-size:0.85rem; color:var(--text-muted);">Todas as requisições foram analisadas! 🎉</p></div>';
                    }
                    ?>
                </div>
            </div>

            <!-- Direita: Visualização Detalhada da Requisição -->
            <div class="form-container-embedded">
                <form class="modal_req_embedded" id="modalReqCard" style="display: none;">
                    <input type="hidden" class="Rtype">
                    <input type="hidden" class="Rid">

                    <div class="modal_header">
                        <span class="modal_nome" id="modalNome">Solicitação</span>
                        <span class="modal_alert" style="display: none;">!</span>
                    </div>

                    <div class="modal_title" id="modalTitle">Título da Requisição</div>

                    <div class="modal_descp" id="modalDescp">Descrição da Requisição</div>

                    <div class="modal_obs" id="modalObs">OBS</div>

                    <div class="modal_date" id="modalDate1">Data</div>
                    <div class="modal_date" id="modalDate">Data</div>

                    <div class="modal_actions">
                        <button type="submit" class="btn_ok" value="Y">
                            <i class="fas fa-check" style="margin-right:6px;"></i> Aprovar
                        </button>
                        <button type="submit" class="btn_x" value="N">
                            <i class="fas fa-xmark" style="margin-right:6px;"></i> Reprovar
                        </button>
                    </div>
                </form>

                <div class="empty-state" id="modalEmptyState">
                    <i class="fa-solid fa-hand-pointer"></i>
                    <p>Selecione uma requisição ao lado para analisar os detalhes e aprovar ou reprovar.</p>
                </div>
            </div>
        </div>
    </div>