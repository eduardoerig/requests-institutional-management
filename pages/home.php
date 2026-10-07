<?php
// pages/home.php - DASHBOARD ADMINISTRATIVO

$map = [
    'mkt' => 'MKT',
    'xerox' => 'Reprografia',
    'shop' => 'Compras',
    'service' => 'Manutenção',
    'ti' => 'TI',
];

$role = $_SESSION['role'] ?? 'view';
$user_id = $_SESSION['id'];

// Definição de perfis com permissão de gestão
$isAdmin = isGlobalAdminRole($role);
$isAdmSub = ($role === 'adm_sub');
$isGestor = isGestorRole($role);
$canManage = $isAdmin || $isGestor || $isAdmSub;

if (!$canManage) {
    echo "<script>location.href='myRequests';</script>";
    return;
}

require_once __DIR__ . '/../classes/RequestManager.php';
require_once __DIR__ . '/../components/request_card.php';

// Pegar setores permitidos para este usuário
$allowedSectors = [];
if (!$isAdmin && !$isAdmSub) {
    $stmtSectors = $pdo->prepare("SELECT a.title FROM ctd_area a JOIN cfg_user_area cua ON a.id = cua.id_area WHERE cua.id_user = ?");
    $stmtSectors->execute([$user_id]);
    $allowedSectors = array_values(array_filter(array_map('areaTitleToSector', $stmtSectors->fetchAll(PDO::FETCH_COLUMN))));
    
    // Filtrar o $map para mostrar apenas os setores permitidos no dropdown
    $filteredMap = [];
    foreach ($map as $key => $label) {
        if (in_array(strtolower($key), $allowedSectors)) {
            $filteredMap[$key] = $label;
        }
    }
    $map = $filteredMap;
}

$managingId = ($isAdmin || $isAdmSub) ? null : $user_id;
$activeFilterStatus = in_array($_GET['status'] ?? '', ['P', 'Y', 'N', 'W', 'C', 'F'], true) ? $_GET['status'] : null;

$tableTitle = 'Atividade Recente';
if ($activeFilterStatus === 'C') $tableTitle = 'Requisições Concluídas';
elseif ($activeFilterStatus === 'P') $tableTitle = 'Requisições Pendentes';
elseif ($activeFilterStatus === 'Y') $tableTitle = 'Requisições Aprovadas';
elseif ($activeFilterStatus === 'W') $tableTitle = 'Requisições em Atendimento';
elseif ($activeFilterStatus === 'N') $tableTitle = 'Requisições Recusadas';

// REGRA: Gestor só vê requisições aprovadas (Y), em andamento (W) e concluídas (C)
$excludeStatuses = [];
if ($isGestor) {
    $excludeStatuses = ['P', 'N'];
}

// Filtro de subdivisão para adm_sub
$subdivisionFilter = $isAdmSub ? ($_SESSION['subdivision_ids'] ?? null) : null;

$data = RequestManager::getRequests($pdo, null, $managingId, $activeFilterStatus ?: null, null, null, 'DESC', $excludeStatuses, null, null, $subdivisionFilter);
$all_requests = $data['requests'];
$stats = $data['stats'];

extract($stats); // Extracts variables like $total, $pendentes, $emAndamento, $repassadas, etc.

// Busca e data filtram no servidor (resultado vale para TODAS as requisições, não só as da tela)
$searchTerm = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 100);
$searchDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['data'] ?? '')) ? $_GET['data'] : '';
$tableRequests = $all_requests;
if ($searchTerm !== '' || $searchDate !== '') {
    $filtered = RequestManager::getRequests($pdo, null, $managingId, $activeFilterStatus ?: null, null, null, 'DESC', $excludeStatuses, $searchDate ?: null, $searchDate ?: null, $subdivisionFilter);
    $termLower = mb_strtolower($searchTerm);
    $tableRequests = array_values(array_filter($filtered['requests'], function ($r) use ($termLower) {
        if ($termLower === '') return true;
        $haystack = mb_strtolower(($r['title'] ?? '') . ' #' . ($r['id'] ?? '') . ' ' . ($r['solicitor_name'] ?? ''));
        return mb_strpos($haystack, $termLower) !== false;
    }));
}

// Paginação: 50 por página
$perPage = 50;
$totalRows = count($tableRequests);
$totalPages = max(1, (int)ceil($totalRows / $perPage));
$currentPageNum = min($totalPages, max(1, (int)($_GET['p'] ?? 1)));
$recent_requests = array_slice($tableRequests, ($currentPageNum - 1) * $perPage, $perPage);
$pageLink = function (int $n) use ($activeFilterStatus, $searchTerm, $searchDate) {
    return 'home?' . http_build_query(array_filter(['status' => $activeFilterStatus, 'q' => $searchTerm, 'data' => $searchDate, 'p' => $n > 1 ? $n : null]));
};

// Buscar notificações não lidas
$unread_stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
$unread_stmt->execute([$_SESSION['id'] ?? 0]);
$unread_count = $unread_stmt->fetchColumn();

// Status stepper labels
$stepperMap = ['P' => 0, 'Y' => 1, 'W' => 2, 'C' => 3, 'N' => -1];
?>
<?php
$pageHeading = [
    'P' => 'Aguardando aprovação', 'Y' => 'Aprovadas', 'W' => 'Em atendimento',
    'C' => 'Concluídas', 'N' => 'Recusadas', 'F' => 'Encaminhadas',
][$activeFilterStatus] ?? 'Painel';

// Esteira: as etapas em ordem; setores não veem as etapas de aprovação
$pipeline = [
    'P' => ['Aguardando aprovação', $pendentes ?? 0],
    'Y' => [$isGestor ? 'Para atender' : 'Aprovadas', $aprovadas ?? 0],
    'W' => ['Em atendimento', $emAndamento ?? 0],
    'C' => ['Concluídas', $concluidas ?? 0],
    'F' => ['Encaminhadas', $repassadas ?? 0],
    'N' => ['Recusadas', $recusadas ?? 0],
];
if ($isGestor) {
    unset($pipeline['P'], $pipeline['N']);
}
$hasFilter = $activeFilterStatus || $searchTerm !== '' || $searchDate !== '';
?>
<div class="page-head">
    <div class="page-head--hide-mobile">
        <h1><?= e($pageHeading) ?></h1>
        <p><?= $isAdmSub ? 'Pedidos da sua subdivisão.' : ($isGestor ? 'Pedidos do seu setor.' : 'Todos os pedidos da escola.') ?></p>
    </div>
    <button type="button" onclick="exportToExcel()" class="btn"><i class="fa-solid fa-file-csv" aria-hidden="true"></i> Exportar planilha</button>
</div>

<nav class="pipeline" aria-label="Pedidos por etapa" style="<?= $isGestor ? 'grid-template-columns: repeat(3, 1fr);' : '' ?>">
    <?php foreach ($pipeline as $code => [$label, $count]):
        $isOn = $activeFilterStatus === $code;
        $href = $isOn ? 'home' : 'home?status=' . $code;
    ?>
        <a class="pipeline__step pipeline__step--<?= $code ?><?= $isOn ? ' is-active' : '' ?>" href="<?= e($href) ?>"<?= $isOn ? ' aria-current="true"' : '' ?>>
            <strong><?= (int)$count ?></strong>
            <span><?= e($label) ?></span>
        </a>
    <?php endforeach; ?>
</nav>

<p class="alerts-row">
    <span class="<?= ($atrasadas ?? 0) > 0 ? 'is-bad' : '' ?>"><b><?= (int)($atrasadas ?? 0) ?></b> com prazo vencido</span>
    <span class="<?= ($criticas ?? 0) > 0 ? 'is-bad' : '' ?>"><b><?= (int)($criticas ?? 0) ?></b> críticas</span>
    <span><b><?= (int)($urgentes ?? 0) ?></b> com urgência alta ou crítica</span>
    <span><b><?= (int)($total ?? 0) ?></b> no total</span>
</p>

<div class="dash-grid">
    <section class="panel" aria-labelledby="tabelaTitulo">
        <div class="panel__head" style="flex-wrap:wrap;">
            <h2 id="tabelaTitulo"><?= e($activeFilterStatus ? $pageHeading : 'Pedidos') ?></h2>
            <?php if ($activeFilterStatus): ?><a class="btn btn--sm btn--ghost" href="home">Ver todos</a><?php endif; ?>
        </div>
        <div class="panel__body" style="padding-bottom:6px;">
            <form method="get" action="home" class="row" role="search" style="align-items:flex-end;">
                <?php if ($activeFilterStatus): ?><input type="hidden" name="status" value="<?= e($activeFilterStatus) ?>"><?php endif; ?>
                <div class="field" style="flex:1 1 260px; margin:0;">
                    <label for="dashSearch">Buscar</label>
                    <div class="search-box">
                        <i class="fas fa-search" aria-hidden="true"></i>
                        <input type="search" name="q" id="dashSearch" value="<?= e($searchTerm) ?>" placeholder="Título, número ou solicitante">
                    </div>
                </div>
                <div class="field field--date" style="margin:0; width:180px;">
                    <label for="dateFilter">Pedidos do dia</label>
                    <input type="date" name="data" id="dateFilter" value="<?= e($searchDate) ?>">
                </div>
                <button type="submit" class="btn btn--dark"><i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i> Buscar</button>
                <?php if ($searchTerm !== '' || $searchDate !== ''): ?>
                    <a class="btn btn--ghost" href="<?= e('home' . ($activeFilterStatus ? '?status=' . $activeFilterStatus : '')) ?>">Limpar busca</a>
                <?php endif; ?>
            </form>
            <p class="result-count" style="margin:12px 0 0;"><?= $totalRows ?> <?= $totalRows === 1 ? 'pedido encontrado' : 'pedidos encontrados' ?><?= $totalPages > 1 ? ', página ' . $currentPageNum . ' de ' . $totalPages : '' ?></p>
        </div>

        <div class="table-wrap">
            <table class="data-table data-table--stack">
                <thead>
                    <tr>
                        <th scope="col">Nº</th>
                        <th scope="col">Pedido</th>
                        <th scope="col">Setor</th>
                        <th scope="col">Prazo</th>
                        <th scope="col">Situação</th>
                        <th scope="col"><span class="visually-hidden">Ações</span></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($recent_requests) > 0): ?>
                        <?php foreach ($recent_requests as $req):
                            $st = strtoupper($req['status'] ?? 'P') ?: 'P';
                            $pri = (int)($req['priority'] ?? 2);
                            $tbl = $req['table'];
                            $detailHref = 'request_detail?id=' . (int)$req['id'] . '&table=' . urlencode($tbl);
                        ?>
                            <tr>
                                <td class="num">#<?= (int)$req['id'] ?></td>
                                <td>
                                    <a class="cell-title" href="<?= e($detailHref) ?>" style="color:inherit;"><?= e($req['title']) ?></a>
                                    <div class="cell-sub">
                                        <?= e($req['solicitor_name_formatted'] ?? '') ?><?php if (!empty($req['subdivision_name'])): ?>, <?= e($req['subdivision_name']) ?><?php endif; ?>
                                        <?php if ($pri >= 3): ?> <span class="prio-tag prio-tag--<?= $pri ?>"><?= e(priorityLabel($pri)) ?></span><?php endif; ?>
                                    </div>
                                </td>
                                <td data-label="Setor"><span class="sector-tag" data-sector="<?= e($tbl) ?>"><?= e(requestSectorName($tbl)) ?></span></td>
                                <td data-label="Prazo"><?= getPrazoBadge($req['date'] ?? '', $st) ?></td>
                                <td><span class="status-pill status-pill--<?= e($st) ?>"><?= e(statusLabel($st)) ?></span></td>
                                <td style="text-align:right; white-space:nowrap;">
                                    <?php if ($st === 'Y' && $isGestor): ?>
                                        <button type="button" class="btn btn--sm btn--primary" onclick="globalRequestAction('start_progress', '<?= (int)$req['id'] ?>', 'ctd_<?= e($tbl) ?>_frm')">Começar</button>
                                    <?php elseif ($st === 'W' && $isGestor): ?>
                                        <button type="button" class="btn btn--sm btn--success" onclick="globalRequestAction('finish', '<?= (int)$req['id'] ?>', 'ctd_<?= e($tbl) ?>_frm')">Concluir</button>
                                    <?php endif; ?>
                                    <a class="btn btn--sm" href="<?= e($detailHref) ?>">Abrir</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="6"><div class="empty-state"><i class="fa-regular fa-folder-open" aria-hidden="true"></i><p><?= $hasFilter ? 'Nenhum pedido com esses filtros.' : 'Ainda não há pedidos.' ?></p></div></td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <?php if ($totalPages > 1): ?>
            <nav class="pager" aria-label="Páginas" style="padding-bottom:14px;">
                <?php if ($currentPageNum > 1): ?><a href="<?= e($pageLink($currentPageNum - 1)) ?>">Anterior</a><?php endif; ?>
                <span class="is-current"><?= $currentPageNum ?> de <?= $totalPages ?></span>
                <?php if ($currentPageNum < $totalPages): ?><a href="<?= e($pageLink($currentPageNum + 1)) ?>">Próxima</a><?php endif; ?>
            </nav>
        <?php endif; ?>
    </section>

    <?php if (!$activeFilterStatus && ($total ?? 0) > 0): ?>
        <aside class="stack" aria-label="Resumo">
            <section class="panel">
                <div class="panel__head"><h2>Por situação</h2></div>
                <div class="panel__body"><div class="chart-box"><canvas id="statusChart" aria-label="Pedidos por situação" role="img"></canvas></div></div>
            </section>
            <section class="panel">
                <div class="panel__head"><h2>Por setor</h2></div>
                <div class="panel__body"><div class="chart-box"><canvas id="categoryChart" aria-label="Pedidos por setor" role="img"></canvas></div></div>
            </section>
        </aside>
    <?php endif; ?>
</div>

<?php if (!$activeFilterStatus && ($total ?? 0) > 0): ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
(function () {
    if (typeof Chart === 'undefined') return; // sem internet: os números da esteira continuam valendo
    Chart.defaults.font.family = getComputedStyle(document.body).fontFamily;
    Chart.defaults.color = '#565A63';

    const statusData = <?= json_encode([
        ['Aguardando aprovação', (int)($statusCounts['P'] ?? 0), '#D97706'],
        ['Aprovadas', (int)($statusCounts['Y'] ?? 0), '#2563EB'],
        ['Em atendimento', (int)($statusCounts['W'] ?? 0), '#7C3AED'],
        ['Concluídas', (int)($statusCounts['C'] ?? 0), '#15803D'],
        ['Recusadas', (int)($statusCounts['N'] ?? 0), '#B91C1C'],
    ], JSON_UNESCAPED_UNICODE) ?>.filter(d => d[1] > 0);

    new Chart(document.getElementById('statusChart'), {
        type: 'doughnut',
        data: { labels: statusData.map(d => d[0]), datasets: [{ data: statusData.map(d => d[1]), backgroundColor: statusData.map(d => d[2]), borderColor: '#fff', borderWidth: 2 }] },
        options: { responsive: true, maintainAspectRatio: false, cutout: '62%', plugins: { legend: { position: 'right', labels: { boxWidth: 10, font: { size: 12 } } } } }
    });

    const sectorColors = { ti: '#DC2626', service: '#CA8A04', shop: '#059669', xerox: '#7C3AED', mkt: '#475569' };
    const sectorNames = <?= json_encode(['ti' => 'TI', 'service' => 'Manutenção', 'shop' => 'Compras', 'xerox' => 'Reprografia', 'mkt' => 'Marketing'], JSON_UNESCAPED_UNICODE) ?>;
    const cat = <?= json_encode($categoryCounts ?? []) ?>;
    const keys = Object.keys(cat);
    new Chart(document.getElementById('categoryChart'), {
        type: 'bar',
        data: { labels: keys.map(k => sectorNames[k] || k), datasets: [{ data: keys.map(k => cat[k]), backgroundColor: keys.map(k => sectorColors[k] || '#475569'), borderRadius: 6 }] },
        options: { indexAxis: 'y', responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { x: { beginAtZero: true, ticks: { precision: 0 }, grid: { color: '#EEF0F3' } }, y: { grid: { display: false } } } }
    });
})();
</script>
<?php endif; ?>

<script>
function exportToExcel() {
    const params = new URLSearchParams({
        search: <?= json_encode($searchTerm) ?>,
        status: <?= json_encode((string)$activeFilterStatus) ?>,
        date: <?= json_encode($searchDate) ?>
    });
    window.location.href = `api/export_requests.php?${params.toString()}`;
}
</script>
