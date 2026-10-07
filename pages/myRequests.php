<?php
require_once __DIR__ . '/../classes/RequestManager.php';

$user_id = (int)$_SESSION['id'];
$data = RequestManager::getRequests($pdo, $user_id);
$requests = $data['requests'];

// Mais recentes primeiro: é o que o solicitante procura
usort($requests, fn($a, $b) => strcmp((string)($b['created_at'] ?? ''), (string)($a['created_at'] ?? '')));

$sectorNames = [
    'mkt' => 'Marketing', 'xerox' => 'Reprografia', 'shop' => 'Compras', 'service' => 'Manutenção', 'ti' => 'TI',
];
$sectorColors = ['mkt' => 'gray', 'xerox' => 'blue', 'shop' => 'green', 'service' => 'yellow', 'ti' => 'red'];

// Grupo de cada situação para as abas
$groupOf = fn(string $st) => in_array($st, ['C'], true) ? 'done' : ($st === 'N' ? 'refused' : 'open');
$counts = ['all' => count($requests), 'open' => 0, 'done' => 0, 'refused' => 0];
foreach ($requests as $r) {
    $counts[$groupOf(strtoupper((string)($r['status'] ?? 'P')))]++;
}

// Passo atual (1 a 4) para a barrinha de progresso
$stepOf = ['P' => 1, 'Y' => 2, 'F' => 3, 'W' => 3, 'C' => 4, 'N' => 1];
?>
<div class="main">
    <div class="page-header">
        <div>
            <i class="fa-solid fa-list-check"></i>
            <h3>Minhas requisições</h3>
        </div>
        <a href="new_request" class="btn-big btn-big--primary"><i class="fa-solid fa-plus"></i> Nova requisição</a>
    </div>

    <?php if (count($requests) === 0): ?>
        <div class="empty-big">
            <i class="fa-regular fa-folder-open"></i>
            <h2>Você ainda não fez nenhuma requisição</h2>
            <p>Precisa de algo? Clique no botão abaixo e siga os passos.</p>
            <a href="new_request" class="btn-big btn-big--primary"><i class="fa-solid fa-plus"></i> Fazer minha primeira requisição</a>
        </div>
    <?php else: ?>
        <div class="myreq-toolbar">
            <div class="tabs" role="tablist" aria-label="Filtrar por situação">
                <button type="button" class="tab is-active" data-filter="all" role="tab" aria-selected="true">Todas <span><?= $counts['all'] ?></span></button>
                <button type="button" class="tab" data-filter="open" role="tab" aria-selected="false">Em aberto <span><?= $counts['open'] ?></span></button>
                <button type="button" class="tab" data-filter="done" role="tab" aria-selected="false">Concluídas <span><?= $counts['done'] ?></span></button>
                <button type="button" class="tab" data-filter="refused" role="tab" aria-selected="false">Recusadas <span><?= $counts['refused'] ?></span></button>
            </div>
            <label class="search-box">
                <i class="fas fa-search" aria-hidden="true"></i>
                <input type="search" id="myreqSearch" placeholder="Buscar pelo título ou número" aria-label="Buscar requisição">
            </label>
        </div>

        <div class="req-list" id="reqList">
            <?php foreach ($requests as $row):
                $st = strtoupper(trim((string)($row['status'] ?? 'P'))) ?: 'P';
                $sector = $row['table'];
                $step = $stepOf[$st] ?? 1;
                $link = 'request_detail?id=' . (int)$row['id'] . '&table=' . urlencode($sector);
            ?>
                <a class="req-row <?= e($sectorColors[$sector] ?? 'gray') ?>" href="<?= e($link) ?>"
                   data-group="<?= e($groupOf($st)) ?>"
                   data-search="<?= e(mb_strtolower(($row['title'] ?? '') . ' #' . $row['id'])) ?>">
                    <div class="req-row__main">
                        <div class="req-row__meta">
                            <span class="req-row__sector"><?= e($sectorNames[$sector] ?? $sector) ?></span>
                            <span class="req-row__id">#<?= (int)$row['id'] ?></span>
                        </div>
                        <div class="req-row__title"><?= e($row['title'] ?? 'Sem título') ?></div>
                        <div class="req-row__info">
                            <?= getPrazoBadge($row['date'] ?? '', $st) ?>
                            <span class="req-row__created">Pedida em <?= e(date('d/m/Y', strtotime((string)$row['created_at']))) ?></span>
                        </div>
                    </div>
                    <div class="req-row__side">
                        <span class="status-pill status-pill--<?= e($st) ?>"><?= e(statusLabel($st)) ?></span>
                        <?php if ($st !== 'N'): ?>
                            <span class="mini-progress" aria-label="Etapa <?= $step ?> de 4">
                                <?php for ($i = 1; $i <= 4; $i++): ?><i class="<?= $i <= $step ? 'on' : '' ?>"></i><?php endfor; ?>
                            </span>
                        <?php endif; ?>
                        <i class="fa-solid fa-chevron-right req-row__arrow" aria-hidden="true"></i>
                    </div>
                </a>
            <?php endforeach; ?>
            <div class="empty-state" id="reqListEmpty" style="display:none;">
                <i class="fa-solid fa-magnifying-glass"></i>
                <p>Nenhuma requisição encontrada com esse filtro.</p>
            </div>
        </div>

        <p class="legend-steps">Etapas: <strong>1</strong> Aguardando aprovação → <strong>2</strong> Aprovada → <strong>3</strong> Em atendimento → <strong>4</strong> Concluída</p>
    <?php endif; ?>
</div>

<script>
(function () {
    const list = document.getElementById('reqList');
    if (!list) return;
    const rows = Array.from(list.querySelectorAll('.req-row'));
    const empty = document.getElementById('reqListEmpty');
    const search = document.getElementById('myreqSearch');
    let currentFilter = 'all';

    function apply() {
        const term = (search.value || '').trim().toLowerCase();
        let visible = 0;
        rows.forEach(row => {
            const show = (currentFilter === 'all' || row.dataset.group === currentFilter)
                && (term === '' || row.dataset.search.includes(term));
            row.style.display = show ? '' : 'none';
            if (show) visible++;
        });
        empty.style.display = visible ? 'none' : '';
    }

    document.querySelectorAll('.myreq-toolbar .tab').forEach(tab => {
        tab.addEventListener('click', () => {
            document.querySelectorAll('.myreq-toolbar .tab').forEach(t => { t.classList.remove('is-active'); t.setAttribute('aria-selected', 'false'); });
            tab.classList.add('is-active');
            tab.setAttribute('aria-selected', 'true');
            currentFilter = tab.dataset.filter;
            apply();
        });
    });
    search.addEventListener('input', apply);
})();
</script>
