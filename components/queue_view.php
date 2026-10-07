<?php
/**
 * Visão de fila de trabalho (Aprovar pedidos / Para atender / Em atendimento).
 * PC: lista compacta + painel de detalhe ao lado. Celular: lista; tocar abre o pedido.
 *
 * Espera:
 *   $requests  lista de linhas (formato de RequestManager::getRequests)
 *   $queue     [
 *     'title' => string, 'subtitle' => string, 'empty' => string, 'panelHint' => string,
 *     'sectors' => [slug => nome] (filtro), 'forwardFilter' => bool,
 *     'actions' => html dos botões do painel (dentro de #standardActions),
 *     'forwardActions' => bool (mostra Aceitar/Recusar para encaminhados)
 *   ]
 * Ids usados por assets/js/filter.js e assets/js/open_cards.js são mantidos.
 */
require_once __DIR__ . '/request_card.php';
?>
<div class="page-head">
    <div class="page-head--hide-mobile">
        <h1><?= e($queue['title']) ?></h1>
        <p><?= e($queue['subtitle']) ?></p>
    </div>
    <p class="only-mobile muted" style="width:100%;"><?= e($queue['subtitle']) ?></p>
</div>

<div class="filterbar" role="search">
    <div class="field" style="flex: 1 1 100%;">
        <span class="field-label" id="filtroSetor">Setor</span>
        <div class="radio_field" role="radiogroup" aria-labelledby="filtroSetor">
            <input type="radio" value="all" name="tipo" class="radioType" data-tooltip="Todos" checked>
            <?php foreach ($queue['sectors'] as $slug => $name): ?>
                <input type="radio" value="<?= e($slug) ?>" name="tipo" class="radioType" data-tooltip="<?= e($name) ?>">
            <?php endforeach; ?>
            <?php if (!empty($queue['forwardFilter'])): ?>
                <input type="radio" value="forwarded" name="tipo" class="radioType" data-tooltip="Vindos de outro setor">
            <?php endif; ?>
        </div>
    </div>
    <div class="field" style="flex: 1 1 240px;">
        <label for="campoPesquisa">Buscar</label>
        <div class="search-box">
            <i class="fas fa-search" aria-hidden="true"></i>
            <input type="search" id="campoPesquisa" placeholder="Título do pedido">
        </div>
    </div>
    <div class="field field--date">
        <label for="dataFiltro">Pedidos do dia</label>
        <input type="date" id="dataFiltro">
    </div>
    <button type="button" class="order-btn" id="orderIcon" data-order="desc" title="Inverter ordem do prazo" aria-label="Inverter ordem do prazo">
        <i class="fas fa-sort-amount-down" aria-hidden="true"></i>
    </button>
</div>

<div class="queue">
    <div class="queue__list card-wrapper-scroll" aria-label="Pedidos">
        <?php if (count($requests) > 0): ?>
            <?php foreach ($requests as $row): ?>
                <?= renderRequestCard($row, 'queue') ?>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="empty-state panel">
                <i class="fa-regular fa-circle-check" aria-hidden="true"></i>
                <p><?= e($queue['empty']) ?></p>
            </div>
        <?php endif; ?>
    </div>

    <aside class="queue__panel" aria-label="Pedido selecionado">
        <form class="modal_req_embedded" id="modalReqCard">
            <input type="hidden" class="Rtype">
            <input type="hidden" class="Rid">

            <div class="modal_header">
                <span class="modal_nome" id="modalNome">Pedido</span>
                <span class="modal_alert" style="display:none;">Urgente</span>
            </div>
            <div class="modal_title" id="modalTitle"></div>
            <div class="modal_date" id="modalDate"></div>
            <div class="modal_descp" id="modalDescp"></div>
            <div class="modal_obs" id="modalObs"></div>
            <div class="modal_date" id="modalDate1"></div>

            <div class="modal_actions" id="standardActions">
                <?= $queue['actions'] ?>
                <a id="btnViewDetail" href="#" class="btn"><i class="fa-solid fa-up-right-from-square" aria-hidden="true"></i> Abrir pedido completo</a>
            </div>

            <?php if (!empty($queue['forwardActions'])): ?>
                <div class="modal_actions" id="forwardActions" style="display:none;">
                    <button type="button" class="btn btn--success" onclick="handleForwardAction('accept', document.getElementById('forwardActions').dataset.forwardId)"><i class="fa-solid fa-check" aria-hidden="true"></i> Aceitar e atender</button>
                    <button type="button" class="btn btn--danger" onclick="handleForwardAction('refuse', document.getElementById('forwardActions').dataset.forwardId)"><i class="fa-solid fa-xmark" aria-hidden="true"></i> Recusar</button>
                    <a id="btnViewDetailForward" href="#" class="btn"><i class="fa-solid fa-up-right-from-square" aria-hidden="true"></i> Abrir pedido completo</a>
                </div>
            <?php endif; ?>
        </form>

        <div class="empty-state" id="modalEmptyState">
            <i class="fa-regular fa-hand-pointer" aria-hidden="true"></i>
            <p><?= e($queue['panelHint']) ?></p>
        </div>
    </aside>
</div>
