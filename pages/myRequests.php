<?php
require_once __DIR__ . '/../classes/RequestManager.php';
require_once __DIR__ . '/../components/request_card.php';

$user_id = (int)$_SESSION['id'];
$data = RequestManager::getRequests($pdo, $user_id);
$requests = $data['requests'];

// Mais recentes primeiro: é o que o solicitante procura
usort($requests, fn($a, $b) => strcmp((string)($b['created_at'] ?? ''), (string)($a['created_at'] ?? '')));

$counts = ['all' => count($requests), 'open' => 0, 'done' => 0, 'refused' => 0];
foreach ($requests as $r) {
    $st = strtoupper((string)($r['status'] ?? 'P'));
    $counts[$st === 'C' ? 'done' : ($st === 'N' ? 'refused' : 'open')]++;
}
?>
<div class="page-head">
    <div class="page-head--hide-mobile">
        <h1>Minhas requisições</h1>
        <p>Acompanhe aqui tudo o que você pediu.</p>
    </div>
    <a href="new_request" class="btn btn--primary only-desktop"><i class="fa-solid fa-plus" aria-hidden="true"></i> Nova requisição</a>
</div>

<?php if (count($requests) === 0): ?>
    <div class="empty-big panel">
        <i class="fa-regular fa-folder-open" aria-hidden="true"></i>
        <h2>Você ainda não fez nenhum pedido</h2>
        <p>Precisa de algo? Toque no botão abaixo e siga os passos.</p>
        <a href="new_request" class="btn btn--primary"><i class="fa-solid fa-plus" aria-hidden="true"></i> Fazer meu primeiro pedido</a>
    </div>
<?php else:
    $defaultTab = $counts['open'] > 0 ? 'open' : 'all';
    $tabs = ['all' => 'Todas', 'open' => 'Em aberto', 'done' => 'Concluídas', 'refused' => 'Recusadas'];
?>
    <div class="stack" style="gap:12px; margin-bottom:16px;">
        <div class="tabs" role="tablist" aria-label="Filtrar por situação">
            <?php foreach ($tabs as $key => $label): $on = $key === $defaultTab; ?>
                <button type="button" class="tab<?= $on ? ' is-active' : '' ?>" data-filter="<?= $key ?>" role="tab" aria-selected="<?= $on ? 'true' : 'false' ?>"><?= $label ?> <span><?= $counts[$key] ?></span></button>
            <?php endforeach; ?>
        </div>
        <label class="search-box">
            <i class="fas fa-search" aria-hidden="true"></i>
            <input type="search" id="myreqSearch" placeholder="Buscar pelo título ou número" aria-label="Buscar requisição">
        </label>
    </div>

    <div class="ficha-list ficha-list--grid" id="reqList">
        <?php foreach ($requests as $row): ?>
            <?= renderRequestCard($row, 'mine') ?>
        <?php endforeach; ?>
    </div>
    <div class="empty-state" id="reqListEmpty" hidden>
        <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
        <p id="reqListEmptyText">Nada por aqui.</p>
    </div>

    <p class="legend-steps">As barrinhas mostram a etapa: aguardando aprovação, aprovada, em atendimento e concluída.</p>
<?php endif; ?>

<script>
(function () {
    const list = document.getElementById('reqList');
    if (!list) return;
    const rows = Array.from(list.querySelectorAll('.ficha'));
    const empty = document.getElementById('reqListEmpty');
    const emptyText = document.getElementById('reqListEmptyText');
    const search = document.getElementById('myreqSearch');
    const emptyMessages = {
        open: 'Nenhum pedido em aberto. Tudo resolvido!',
        done: 'Nenhum pedido concluído ainda.',
        refused: 'Nenhum pedido recusado.',
        all: 'Nenhum pedido encontrado.'
    };
    let currentFilter = document.querySelector('.tabs .tab.is-active')?.dataset.filter || 'all';

    function apply() {
        const term = (search.value || '').trim().toLowerCase();
        let visible = 0;
        rows.forEach(row => {
            const show = (term !== '' || currentFilter === 'all' || row.dataset.group === currentFilter)
                && (term === '' || row.dataset.search.includes(term));
            row.hidden = !show;
            if (show) visible++;
        });
        emptyText.textContent = term ? 'Nenhum pedido com esse título ou número.' : emptyMessages[currentFilter];
        empty.hidden = visible > 0;
    }

    document.querySelectorAll('.tabs .tab').forEach(tab => {
        tab.addEventListener('click', () => {
            document.querySelectorAll('.tabs .tab').forEach(t => { t.classList.remove('is-active'); t.setAttribute('aria-selected', 'false'); });
            tab.classList.add('is-active');
            tab.setAttribute('aria-selected', 'true');
            currentFilter = tab.dataset.filter;
            apply();
        });
    });
    search.addEventListener('input', apply);
    apply();
})();
</script>
