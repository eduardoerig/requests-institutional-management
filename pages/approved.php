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

// 1. Buscar requisições Aprovadas (Y)
$data = RequestManager::getRequests($pdo, null, $managingId, 'Y', null, null, 'DESC', [], null, null, $subdivisionFilter);
$requests = $data['requests'] ?? [];

// 2. Buscar requisições Repassadas PENDENTES ('pending')
require_once __DIR__ . '/../classes/RequestForwardService.php';

if ($isAdmin || $isAdmSub) {
    // Admin: Buscar todos os repasses pendentes e mapeá-los para o setor de destino
    $allForwards = RequestForwardService::getForwardsByDestination($pdo, 'all', null, null, $subdivisionFilter, 'pending');
    $requests = array_merge($requests, $allForwards);
} else {
    // Gestor: Buscar repasses pendentes direcionados a ele
    $pendingForwards = RequestForwardService::getPendingForwards($pdo, $user_id, 'pending');
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
                'forward_id' => $fwd['id']
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

$totalAprovadas = count($requests);

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
            <div style="display: flex; align-items: center; gap: 15px;">
                <i class="fa-solid fa-circle-check" style="color: #10b981;"></i>
                <div>
                    <h3>Requisições Aprovadas</h3>
                    <p style="font-size: 0.85rem; color: var(--text-muted); margin: 2px 0 0;">
                        <?= $totalAprovadas ?> requisição(ões) aguardando atendimento
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
                            // Calcular setores que possuem requisições na lista
                            $activeSectors = [];
                            $hasForwards = false;
                            foreach ($requests as $r) {
                                if (($r['st_raw'] ?? '') === 'Y' || ($r['status'] ?? '') === 'Y' || ($r['st_raw'] ?? '') === 'A') {
                                    $activeSectors[] = $r['table'];
                                } elseif (($r['st_raw'] ?? '') === 'F') {
                                    $hasForwards = true;
                                }
                            }
                            $activeSectors = array_unique($activeSectors);

                            foreach ($sectorNames as $slug => $name): 
                                if (in_array($slug, $activeSectors)):
                                    // Se não for admin nem adm_sub, só mostra se estiver nos allowedSectors
                                    if (!$isAdmin && !$isAdmSub && !in_array($slug, $allowedSectors)) continue;
                            ?>
                                <input type="radio" value="<?= $slug ?>" name="tipo" class="radioType" data-tooltip="<?= $name ?>">
                            <?php 
                                endif;
                            endforeach; 
                            
                            if ($hasForwards):
                            ?>
                                <input type="radio" value="forwarded" name="tipo" class="radioType" data-tooltip="Repassadas">
                            <?php endif; ?>
                            <input type="radio" value="all" name="tipo" class="radioType" data-tooltip="Todas req" checked>
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
                            $isForwarded = (trim(strtoupper($row['st_raw'] ?? $row['status'] ?? '')) === 'F');
                            $forwardedClass = $isForwarded ? ' forwarded' : '';
                            $forwardedBadge = '';
                            $fwdDataAttr = '';
                            if ($isForwarded) {
                                if (!empty($row['is_forwarded_to_me']) && !empty($row['forward_from'])) {
                                    $forwardedBadge = '<span class="badge-forwarded" style="background:#fef3c7; color:#d97706; font-size:0.65rem; padding:2px 6px; border-radius:4px; font-weight:600;"><i class="fa-solid fa-inbox"></i> Recebida de: ' . htmlspecialchars($row['forward_from']) . '</span>';
                                } else {
                                    $forwardedBadge = '<span class="badge-forwarded" style="background:#fef3c7; color:#d97706; font-size:0.65rem; padding:2px 6px; border-radius:4px; font-weight:600;"><i class="fa-solid fa-share-from-square"></i> Repassada (Pendente)</span>';
                                }
                                $fwdDataAttr = ' data-forwarded="true" data-forward-id="' . ($row['forward_id'] ?? '') . '"';
                            }
                            
                            $rd = $row['date'] ?? '';
                            $prazoBadge = getPrazoBadge($rd, $row['status'] ?? 'P');
                            echo '
                                <div class="card_req ' . $cardClass . $forwardedClass . '" data-id="' . $row['id'] . '" data-table="' . $row['table'] . '"' . $fwdDataAttr . '>
                                    <div class="card_header">
                                        <span class="req_nome">Requisição ' . strtoupper($sectorNames[$row['table']] ?? $row['table'] ?? '') . ' #' . $row['id'] . ($isForwarded ? ' <i class="fa-solid fa-share" style="font-size: 0.8rem; color: #f59e0b;"></i>' : '') . '</span>
                                        <span class="req_confirmed"><i class="fa-solid fa-check-circle"></i></span>
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
                        echo '<div class="empty-state"><i class="fa-solid fa-inbox"></i><p>Nenhuma requisição aprovada aguardando atendimento.</p></div>';
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
                        <span class="modal_alert" style="display: none;">!</span>
                    </div>

                    <div class="modal_title" id="modalTitle">Título da Requisição</div>
                    <div class="modal_descp" id="modalDescp">Descrição da Requisição</div>
                    <div class="modal_obs" id="modalObs">OBS</div>
                    <div class="modal_date" id="modalDate1">Data</div>
                    <div class="modal_date" id="modalDate">Data</div>

                    <div class="modal_actions" id="standardActions">
                        <?php if ($isGestor): ?>
                            <button type="submit" name="action_value" value="start_progress" class="btn_ok" style="flex:1;">
                                <i class="fa-solid fa-play" style="margin-right:6px;"></i> Iniciar Atendimento
                            </button>
                        <?php endif; ?>
                        <a id="btnViewDetail" href="#" class="btn-primary" style="padding: 10px 20px; font-size: 0.9rem; text-decoration: none;">
                            <i class="fas fa-eye" style="margin-right:4px;"></i> Detalhes
                        </a>
                    </div>
                    
                    <div class="modal_actions" id="forwardActions" style="display:none; gap: 10px;">
                        <?php if ($isGestor): ?>
                            <button type="button" class="btn_ok" onclick="handleForwardAction('accept')" style="flex:1; background: #3b82f6;">
                                <i class="fa-solid fa-check" style="margin-right:6px;"></i> Aceitar Repasse
                            </button>
                            <button type="button" class="btn_recusar" onclick="handleForwardAction('refuse')" style="flex:1;">
                                <i class="fa-solid fa-xmark" style="margin-right:6px;"></i> Recusar
                            </button>
                        <?php endif; ?>
                        <a id="btnViewDetailForward" href="#" class="btn-primary" style="padding: 10px 20px; font-size: 0.9rem; text-decoration: none;">
                            <i class="fas fa-eye" style="margin-right:4px;"></i> Detalhes
                        </a>
                    </div>
                </form>

                <div class="empty-state" id="modalEmptyState">
                    <i class="fa-solid fa-hand-pointer"></i>
                    <p>Selecione uma requisição aprovada para iniciar o atendimento.</p>
                </div>
            </div>
        </div>
    </div>
    
<script>
async function handleForwardAction(action) {
    const fwdActions = document.getElementById("forwardActions");
    const forwardId = fwdActions.getAttribute('data-forward-id');
    
    if (!forwardId) {
        showToast("Erro: ID do repasse não encontrado.", "error");
        return;
    }

    if (action === 'refuse') {
        const confirmed = await showConfirm({
            title: 'Recusar Repasse',
            message: 'Tem certeza que deseja RECUSAR este repasse? A requisição voltará ao setor de origem.',
            type: 'error',
            isDanger: true,
            confirmLabel: 'Sim, Recusar'
        });
        if (!confirmed) return;
    }

    if (action === 'accept') {
        const confirmed = await showConfirm({
            title: 'Aceitar Repasse',
            message: 'Deseja ACEITAR este repasse e assumir o atendimento desta requisição?',
            type: 'info',
            confirmLabel: 'Sim, Aceitar'
        });
        if (!confirmed) return;
    }

    const overlay = document.querySelector('.loading-overlay');
    if (overlay) overlay.style.display = 'flex';

    fetch("api/request_forwards.php", {
        method: "POST",
        headers: {
            "Content-Type": "application/json",
            "X-CSRF-Token": document.querySelector('meta[name="csrf-token"]')?.content || ''
        },
        body: JSON.stringify({ action: action, forward_id: forwardId })
    })
    .then(res => res.json())
    .then(res => {
        if (res.success) {
            showToast(res.message, 'success');
            setTimeout(() => window.location.reload(), 800);
        } else {
            if (overlay) overlay.style.display = 'none';
            showToast(res.message || "Erro ao processar repasse.", 'error');
        }
    })
    .catch(err => {
        if (overlay) overlay.style.display = 'none';
        console.error("Erro:", err);
        showToast("Erro de comunicação com o servidor.", "error");
    });
}
</script>
