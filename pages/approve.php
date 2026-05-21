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
    'xerox' => 'Reprografia',
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
                <div style="display: flex; gap: 24px; align-items: center; flex-wrap: wrap; width: 100%;">
                    <!-- Filtro de Setor -->
                    <div style="display: flex; flex-direction: column; gap: 4px;">
                        <span style="font-size: 0.7rem; color: var(--text-muted); font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px;">Filtrar por Setor</span>
                        <div class="radio_field">
                            <?php 
                            $activeSectors = array_unique(array_column($requests, 'table'));
                            foreach ($sectorNames as $slug => $name): 
                                if (in_array($slug, $activeSectors)):
                                    // Se não for admin nem adm_sub, só mostra se estiver nos allowedSectors
                                    if (!$isAdmin && !$isAdmSub && !in_array($slug, $allowedSectors)) continue;
                            ?>
                                <input type="radio" value="<?= $slug ?>" name="tipo" class="radioType" data-tooltip="<?= $name ?>">
                            <?php 
                                endif;
                            endforeach; 
                            ?>
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
            <!-- Esquerda: Lista de Requisições -->
            <div class="request_list_container">
                <div class="card-wrapper-scroll">
                    <?php
                    // Pre-carregar subdivisões do adm_sub (evitar N+1 queries)
                    $mySubData = [];
                    if ($isAdmSub && $user_id) {
                        $mySubSlugsStmt = $pdo->prepare("SELECT s.slug, s.name FROM ctd_subdivision s JOIN cfg_user_subdivision cus ON s.id = cus.id_subdivision WHERE cus.id_user = ?");
                        $mySubSlugsStmt->execute([$user_id]);
                        $mySubData = $mySubSlugsStmt->fetchAll(PDO::FETCH_KEY_PAIR); // slug => name
                    }

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
                            if ($isAdmSub && !empty($row['all_subdivision_slugs']) && !empty($mySubData)) {
                                // Para adm_sub: mostra apenas as subdivisões que ele gerencia
                                $allSlugs = explode(',', $row['all_subdivision_slugs']);
                                
                                $matchedBadges = [];
                                foreach ($allSlugs as $slug) {
                                    $slug = trim($slug);
                                    if (isset($mySubData[$slug])) {
                                        $matchedBadges[] = '<span class="badge-subdivision badge-sub-' . $slug . '">' . htmlspecialchars($mySubData[$slug]) . '</span>';
                                    }
                                }
                                $subBadge = implode(' ', $matchedBadges);
                                
                                // Fallback: se não achou nenhuma em comum, mostra a da requisição
                                if (empty($subBadge) && !empty($row['subdivision_name'])) {
                                    $subBadge = '<span class="badge-subdivision badge-sub-' . ($row['subdivision_slug'] ?? 'default') . '">' . htmlspecialchars($row['subdivision_name']) . '</span>';
                                }
                            } elseif (!empty($row['subdivision_name'])) {
                                $subBadge = '<span class="badge-subdivision badge-sub-' . ($row['subdivision_slug'] ?? 'default') . '">' . htmlspecialchars($row['subdivision_name']) . '</span>';
                            }
                            
                            $rd = $row['date'] ?? '';
                            $prazoBadge = getPrazoBadge($rd, $row['status'] ?? 'P');
                            echo '
                                <div class="card_req ' . $cardClass . '" data-id="' . $row['id'] . '" data-table="' . $row['table'] . '">
                                    <div class="card_header">
                                        <span class="req_nome">Requisição ' . strtoupper($sectorNames[$row['table']] ?? $row['table'] ?? '') . ' #' . $row['id'] . '</span>
                                        <div style="display:flex; gap:6px; align-items:center;">' . $priBadge . $urgentMark . '</div>
                                    </div>
                                    <div class="card_title">' . htmlspecialchars($row['title'] ?? 'Sem Título') . '</div>
                                    <div style="display:flex; justify-content:space-between; align-items:center; margin-top:8px; flex-wrap:wrap; gap:6px;">
                                        <div class="card_date" style="display:flex; align-items:center;">' . $prazoBadge . '</div>
                                        <div style="display:flex; gap:6px; align-items:center;">' . $sectorBadge . '</div>
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
                    <i class="fa-solid fa-clipboard-check" style="color: #94a3b8;"></i>
                    <p>Nenhuma requisição selecionada</p>
                    <p style="font-size: 0.83rem; color: var(--text-muted); margin-top: 4px;">Selecione uma requisição ao lado para analisar os detalhes e aprovar ou reprovar.</p>
                </div>
            </div>
        </div>
    </div>