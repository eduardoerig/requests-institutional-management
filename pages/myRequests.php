<?php
require_once __DIR__ . '/../classes/RequestManager.php';

$user_id = $_SESSION['id'];
$data = RequestManager::getRequests($pdo, $user_id);
$requests = $data['requests'];


$map = ['mkt' => 'gray', 'xerox' => 'blue', 'shop' => 'green', 'service' => 'yellow', 'ti' => 'red'];
?>
<div class="main">
        <div class="page-header">
            <div style="display: flex; align-items: center; gap: 15px;">
                <i class="fa-solid fa-list-check" style="font-size: 1.8rem; color: var(--primary);"></i>
                <h3>Minhas Requisições</h3>
            </div>
        </div>
        <div class="filter_req">
            <div class="left_group">
                <div class="radio_field">
                    <input type="radio" value="service" name="tipo" class="radioType" data-status="MY" data-tooltip="Solicitação de Serviço">
                    <input type="radio" value="shop" name="tipo" class="radioType" data-status="MY" data-tooltip="Solicitação de Compra">
                    <input type="radio" value="xerox" name="tipo" class="radioType" data-status="MY" data-tooltip="Solicitação de Cópia Colorida">
                    <input type="radio" value="ti" name="tipo" class="radioType" data-status="MY" data-tooltip="Abertura de Chamado TI">
                    <input type="radio" value="mkt" name="tipo" class="radioType" data-status="MY" data-tooltip="Solicitação de MKT">
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
                            echo '<span class="req_nome">Requisição #' . $row['id'] . '</span>';
                            echo !empty($row['urgent']) ? '<span class="req_alert">!</span>' : '';
                            echo '</div>
                                    <div class="card_title">' . $row['title'] . '</div>
                                    <div style="display:flex; justify-content:space-between; align-items:center; margin-top:4px; flex-wrap:wrap; gap:6px;">
                                        <div class="card_date">Data de entrega: ' . $row['date'] . '</div>';
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
            <div class="form-container-embedded">
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

    cards.forEach(card => {
        card.addEventListener('click', function() {
            const id = this.getAttribute('data-id');
            const table = this.getAttribute('data-table');

            // Reset selection
            cards.forEach(c => c.classList.remove('selected'));
            this.classList.add('selected');

            // Se o modal já estiver aberto, dar um fade out rápido para trocar os dados
            if (modal && modal.style.display === 'flex') {
                modal.style.opacity = '0.5';
            }

            // Buscar dados completos via API
            fetch(`api/get_request_details.php?id=${id}&table=${table}`)
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        // Preencher campos
                        modal.querySelector('.Rid').value = id;
                        modal.querySelector('.Rtype').value = table;
                        document.getElementById('modalTitle').textContent = data.data.title;
                        document.getElementById('modalDescp').textContent = data.data.descp || 'Sem descrição';
                        document.getElementById('modalObs').textContent = data.data.obs || 'Sem observações';
                        document.getElementById('modalDate').textContent = 'Prazo: ' + (data.data.date || '—');

                        // Atualizar link de detalhes
                        if (btnViewDetail) {
                            btnViewDetail.href = `request_detail?id=${id}&table=${table}`;
                        }

                        // Mostrar modal com animação suave
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