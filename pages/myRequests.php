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
    .myreq-filter-container .left_group {
        display: flex !important;
        flex-direction: column !important;
        width: 100% !important;
        gap: 16px !important;
    }
    .myreq-filter-container .left_group > div:first-child {
        width: 100% !important;
        justify-content: center !important;
    }
    .myreq-search-row {
        display: flex !important;
        gap: 12px !important;
        width: 100% !important;
        align-items: center !important;
    }
    .myreq-search-row .search_input {
        flex: 1 !important;
        width: auto !important;
    }
    .myreq-filter-container .order_icon {
        margin: 0 !important;
    }
}
</style>
<div class="main">
        <div class="page-header">
            <div style="display: flex; align-items: center; gap: 15px;">
                <i class="fa-solid fa-list-check" style="font-size: 1.8rem; color: var(--primary);"></i>
                <h3>Minhas Requisições</h3>
            </div>
        </div>
        <div class="filter_req myreq-filter-container">
            <div class="left_group">
                <div style="display: flex; gap: 20px; align-items: center; flex-wrap: wrap;">
                    <div style="display: flex; flex-direction: column; gap: 4px; width: 100%;">
                        <span style="font-size: 0.65rem; color: var(--text-muted); font-weight: 700; text-transform: uppercase; text-align: center;">Setores</span>
                        <div class="radio_field">
                            <input type="radio" value="service" name="tipo" class="radioType" data-tooltip="Serviços">
                            <input type="radio" value="shop" name="tipo" class="radioType" data-tooltip="Compras">
                            <input type="radio" value="xerox" name="tipo" class="radioType" data-tooltip="Reprografia">
                            <input type="radio" value="ti" name="tipo" class="radioType" data-tooltip="TI">
                            <input type="radio" value="mkt" name="tipo" class="radioType" data-tooltip="Marketing">
                            <input type="radio" value="all" name="tipo" class="radioType" data-tooltip="Todos os Setores" checked>
                        </div>
                    </div>
                </div>
                <div class="myreq-search-row">
                    <div class="search_input">
                        <i class="fas fa-search"></i>
                        <input type="text" placeholder="Pesquisar..." id="campoPesquisa">
                    </div>
                    <div class="order_icon" id="orderIcon" data-order="desc">
                        <i class="fas fa-sort-amount-down"></i>
                    </div>
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
                            echo '<div class="card_req ';
                            echo $map[$row['table']];
                            echo '" data-id="' . $row['id'] . '" data-table="' . $row['table'] . '">
                                    <div class="card_header">';
                            if ($row['status'] === 'P') {
                                echo '<span class="req_alert" style="color:#d9534f;"><i class="fas fa-hourglass-half"></i></span>';
                            } elseif ($row['status'] === 'N') {
                                echo '<span class="req_alert" style="color:red;">X</span>';
                            } elseif ($row['status'] === 'Y') {
                                echo '<span class="req_confirmed">✔</span>';
                            } elseif ($row['status'] === 'F') {
                                echo '<span class="req_alert" style="color:#d97706;"><i class="fa-solid fa-share-from-square"></i></span>';
                            }
                            echo '<span class="req_nome">Requisição ' . strtoupper($sectorNames[$row['table']] ?? $row['table'] ?? '') . ' #' . $row['id'] . '</span>';
                            echo !empty($row['urgent']) ? '<span class="req_alert">!</span>' : '';
                            echo '</div>
                                    <div class="card_title">' . htmlspecialchars($row['title']) . '</div>
                                    <div style="display:flex; justify-content:space-between; align-items:center; margin-top:4px; flex-wrap:wrap; gap:6px;">';
                            $rawDate = $row['date'] ?? '';
                            $prazoBadge = getPrazoBadge($rawDate, $row['status'] ?? 'P');
                            echo '<div class="card_date" style="display:flex; align-items:center;">' . $prazoBadge . '</div>';
                            if ($row['status'] === 'F') {
                                echo '<span class="badge-forwarded"><i class="fa-solid fa-share-from-square"></i> Repassada</span>';
                            }
                            echo '</div>
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
    const cards = document.querySelectorAll('.card_req');
    const modal = document.getElementById('modalReqCard');
    const emptyState = document.getElementById('modalEmptyState');
    const btnViewDetail = document.getElementById('btnViewDetail');
    const isMobile = () => window.innerWidth <= 768;

    cards.forEach(card => {
        card.addEventListener('click', function() {
            const id = this.getAttribute('data-id');
            const table = this.getAttribute('data-table');

            // MOBILE: Navegar direto para a página de detalhes
            if (isMobile()) {
                window.location.href = `request_detail?id=${id}&table=${table}`;
                return;
            }

            // DESKTOP: Manter comportamento split-layout
            cards.forEach(c => c.classList.remove('selected'));
            this.classList.add('selected');

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
    });
});
</script>
            </div>
        </div>
    </div>