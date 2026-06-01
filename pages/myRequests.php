<?php
require_once __DIR__ . '/../classes/RequestManager.php';

$user_id = $_SESSION['id'];
$data = RequestManager::getRequests($pdo, $user_id);
$requests = $data['requests'];


$map = [
    'mkt' => 'gray',
    'xerox' => 'blue',
    'shop' => 'green',
    'service' => 'yellow',
    'ti' => 'red',
];

$sectorNames = [
    'mkt' => 'MKT', 'marketing' => 'Marketing', 'xerox' => 'Reprografia', 
    'shop' => 'Compras', 'service' => 'Manutenção', 'ti' => 'TI',
];
?>
<style>
@media (max-width: 768px) {
    .myreq-filter-container {
        display: flex !important;
        flex-direction: column !important;
        align-items: stretch !important;
        gap: 16px !important;
    }
    .filter-sectors {
        align-items: center !important;
        width: 100% !important;
    }
    .filter-sectors span {
        margin-left: 0 !important;
        text-align: center !important;
    }
    .myreq-search-row {
        display: flex !important;
        gap: 12px !important;
        width: 100% !important;
        align-items: center !important;
    }
    .myreq-search-row .search_input {
        flex: 1 !important;
        width: 100% !important;
    }
    .myreq-search-row .search_input input {
        width: 100% !important;
    }
    .myreq-filter-container .order_icon {
        margin: 0 !important;
    }
    
    /* Configuração para o card ocupar toda a tela centralizado */
    .inbox-split-layout.myreq-layout {
        width: 100% !important;
        padding: 0 10px !important;
        box-sizing: border-box !important;
        display: flex !important;
        flex-direction: column !important;
        align-items: center !important;
    }
    .request_list_container {
        width: 100% !important;
    }
    .card-wrapper-scroll {
        width: 100% !important;
        display: flex !important;
        flex-direction: column !important;
        align-items: center !important;
    }
    .card_req {
        width: 100% !important;
        max-width: 100% !important;
        margin: 0 auto !important;
        box-sizing: border-box !important;
    }
}
</style>
<div class="main">
        <div class="page-header">
            <div>
                <i class="fa-solid fa-list-check"></i>
                <h3>Minhas Requisições</h3>
            </div>
        </div>
        <div class="filter_req myreq-filter-container" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px;">
            <div class="filter-sectors" style="display: flex; flex-direction: column; gap: 6px;">
                <span style="font-size: 0.7rem; color: var(--text-muted); font-weight: 700; text-transform: uppercase; margin-left: 4px;">Setores</span>
                <div class="radio_field" style="display: flex; gap: 8px;">
                    <input type="radio" value="service" name="tipo" class="radioType" data-tooltip="Serviços">
                    <input type="radio" value="shop" name="tipo" class="radioType" data-tooltip="Compras">
                    <input type="radio" value="xerox" name="tipo" class="radioType" data-tooltip="Reprografia">
                    <input type="radio" value="ti" name="tipo" class="radioType" data-tooltip="TI">
                    <input type="radio" value="mkt" name="tipo" class="radioType" data-tooltip="Marketing">
                    <input type="radio" value="all" name="tipo" class="radioType" data-tooltip="Todos os Setores" checked>
                </div>
            </div>
            <div class="myreq-search-row" style="display: flex; gap: 12px; align-items: center;">
                <div class="search_input" style="position: relative; display: flex; align-items: center;">
                    <i class="fas fa-search" style="color: var(--text-muted); left: 16px; position: absolute;"></i>
                    <input type="text" placeholder="Pesquisar..." id="campoPesquisa" style="padding-left: 48px; min-height: 44px; border-radius: 8px; border: 1px solid var(--border-color); width: 250px;">
                </div>
                <div class="order_icon" id="orderIcon" data-order="desc" style="display: flex; align-items: center; justify-content: center; width: 44px; height: 44px; border-radius: 8px; background: var(--bg-main); border: 1px solid var(--border-color); cursor: pointer;">
                    <i class="fas fa-sort-amount-down" style="color: var(--text-muted);"></i>
                </div>
            </div>
        </div>
        <div class="inbox-split-layout myreq-layout">
            <!-- Esquerda: Lista de Requisições -->
            <div class="request_list_container">
                <div class="card-wrapper-scroll">
                    <?php
                    if (count($requests) > 0) {
                        foreach ($requests as $row) {
                            $st = trim(strtoupper($row['status'] ?? 'P'));
                            $cardClass = isset($map[$row['table']]) ? $map[$row['table']] : 'gray';

                            // Priority Badges
                            $pri = $row['priority'] ?? 2;
                            $priBadge = '';
                            if ($pri == 4) $priBadge = '<span class="card-priority critical"><i class="fa-solid fa-fire-extinguisher"></i></span>';
                            elseif ($pri == 3) $priBadge = '<span class="card-priority high"><i class="fa-solid fa-angles-up"></i></span>';

                            $sectorBadge = '<span class="card-sector-tag">' . ($sectorNames[$row['table']] ?? ucfirst($row['table'])) . '</span>';
                            
                            // Subdivision Badge
                            $subBadge = '';
                            if (!empty($row['subdivision_name'])) {
                                $subBadge = '<span class="badge-subdivision badge-sub-' . ($row['subdivision_slug'] ?? 'default') . '">' . htmlspecialchars($row['subdivision_name']) . '</span>';
                            }

                            // Timer visual
                            $timerHtml = '';
                            if ($st === 'W' || $st === 'F') {
                                $createdAt = strtotime($row['created_at'] ?? 'now');
                                $elapsed = time() - $createdAt;
                                $days = floor($elapsed / 86400);
                                $hours = floor(($elapsed % 86400) / 3600);
                                $timeLabel = $days > 0 ? $days . 'd ' . $hours . 'h' : $hours . 'h';
                                $timeClass = ($elapsed > (3 * 86400)) ? 'timer-late' : 'timer-ok';
                                $timerHtml = '<span class="card-timer ' . $timeClass . '"><i class="fa-solid fa-stopwatch"></i> ' . $timeLabel . '</span>';
                            }

                            // Status Icons
                            $statusIcon = '';
                            if ($st === 'Y' || $st === 'A') {
                                $statusIcon = '<span class="req_confirmed"><i class="fa-solid fa-check-circle"></i></span>';
                            } elseif ($st === 'P') {
                                $statusIcon = '<span class="req_alert" style="color:#d9534f;"><i class="fas fa-hourglass-half"></i></span>';
                            } elseif ($st === 'N') {
                                $statusIcon = '<span class="req_alert" style="color:red;"><i class="fa-solid fa-xmark"></i></span>';
                            }

                            $forwardedBadge = '';
                            if ($st === 'F') {
                                $forwardedBadge = '<span class="badge-forwarded"><i class="fa-solid fa-share-from-square"></i> Repassada</span>';
                                $statusIcon = '<span class="req_alert" style="color:#d97706;"><i class="fa-solid fa-share-from-square"></i></span>';
                            }

                            $reqNome = 'Requisição ' . strtoupper($sectorNames[$row['table']] ?? $row['table'] ?? '') . ' #' . $row['id'];
                            $rd = $row['date'] ?? '';
                            $prazoBadge = getPrazoBadge($rd, $st);

                            echo '
                            <div class="card_req ' . $cardClass . ($st === 'F' ? ' forwarded' : '') . '" data-id="' . $row['id'] . '" data-table="' . $row['table'] . '">
                                <div class="card_header">
                                    <span class="req_nome">' . $reqNome . ($st === 'F' ? ' <i class="fa-solid fa-share" style="font-size: 0.8rem; color: #f59e0b;"></i>' : '') . '</span>
                                    <div style="display:flex; gap:6px; align-items:center;">' . $timerHtml . $statusIcon . $priBadge . '</div>
                                </div>
                                <div class="card_title">' . htmlspecialchars($row['title']) . '</div>
                                <div style="display:flex; justify-content:space-between; align-items:center; margin-top:8px; flex-wrap:wrap; gap:6px;">
                                    <div class="card_date" style="display:flex; align-items:center;">' . $prazoBadge . '</div>
                                    <div style="display:flex; gap:6px; align-items:center;">' . $forwardedBadge . $subBadge . $sectorBadge . '</div>
                                </div>
                            </div>';
                        }
                    } else {
                        echo '<div class="empty-state"><i class="fa-solid fa-inbox"></i><p>Nenhuma requisição encontrada.</p></div>';
                    }
                    ?>
                </div>
            </div>

            <!-- Direita: Visualização Detalhada da Requisição -->
            <div class="form-container-embedded myreq-detail-panel">
                <form class="modal_req_embedded" id="modalReqCard" style="display: none;">
                    <input type="hidden" class="Rtype">
                    <input type="hidden" class="Rid">

                    <div class="modal_header">
                        <span class="modal_nome" id="modalNome">Solicitação</span>
                        <span class="modal_alert" style="display: none;">!</span>
                    </div>

                    <div class="modal_title" id="modalTitle">&nbsp;</div>

                    <div class="modal_descp" id="modalDescp">&nbsp;</div>

                    <div class="modal_obs" id="modalObs">&nbsp;</div>

                    <div class="modal_date" id="modalDate1">&nbsp;</div>
                    <div class="modal_date" id="modalDate">&nbsp;</div>

                    <div class="modal_actions" style="display:flex; gap:10px;">
                        <button type="submit" name="action_value" value="delete" class="btn_dell" style="flex:1;">Excluir <i class="fas fa-trash-can"></i></button>
                        <a id="btnViewDetail" href="#" class="btn-primary" style="flex:1.5; padding: 10px 20px; font-size: 0.9rem; text-decoration: none; display: flex; align-items: center; justify-content: center; gap: 8px; background: #3b82f6;">
                            <i class="fas fa-comments"></i> Detalhes & Chat
                        </a>
                    </div>
                </form>

                <div class="empty-state" id="modalEmptyState">
                    <i class="fa-solid fa-hand-pointer"></i>
                    <p>Selecione uma requisição ao lado para visualizar os detalhes.</p>
                </div>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const container = document.querySelector('.request_list_container');
    const modal = document.getElementById('modalReqCard');
    const emptyState = document.getElementById('modalEmptyState');
    const btnViewDetail = document.getElementById('btnViewDetail');
    const isMobile = () => window.innerWidth <= 768;

    if (container) {
        container.addEventListener('click', function(e) {
            const card = e.target.closest('.card_req');
            if (!card) return;

            const id = card.getAttribute('data-id');
            const table = card.getAttribute('data-table');

            // MOBILE: Navegar direto para a página de detalhes
            if (isMobile()) {
                window.location.href = `request_detail?id=${id}&table=${table}`;
                return;
            }

            // DESKTOP: Manter comportamento split-layout
            document.querySelectorAll('.card_req').forEach(c => c.classList.remove('selected'));
            card.classList.add('selected');

            if (modal && modal.style.display === 'flex') {
                modal.style.opacity = '0.5';
            }

            fetch(`api/get_request_details.php?id=${id}&table=${table}`)
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        modal.querySelector('.Rid').value = id;
                        modal.querySelector('.Rtype').value = table;
                        document.getElementById('modalTitle').textContent = data.data.title;
                        document.getElementById('modalDescp').textContent = data.data.descp || 'Sem descrição';
                        document.getElementById('modalObs').textContent = data.data.obs || 'Sem observações';
                        document.getElementById('modalDate').textContent = 'Prazo: ' + (data.data.date || '—');

                        if (btnViewDetail) {
                            btnViewDetail.href = `request_detail?id=${id}&table=${table}`;
                        }

                        modal.style.display = 'flex';
                        modal.style.transition = 'opacity 0.3s ease';
                        modal.style.opacity = '1';
                        emptyState.style.display = 'none';
                    }
                });
        });
    }
});
</script>
            </div>
        </div>
    </div>