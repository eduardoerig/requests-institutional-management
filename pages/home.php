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
    'F' => ['Encaminhadas', $repassadas ?? 0],
    'C' => ['Concluídas', $concluidas ?? 0],
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
    <button type="button" onclick="exportToExcel()" class="btn btn--ghost hide-mobile"><i class="fa-solid fa-download" aria-hidden="true"></i> Exportar planilha</button>
</div>

<nav class="pipeline" aria-label="Pedidos por etapa">
    <?php foreach ($pipeline as $code => [$label, $count]):
        $isOn = $activeFilterStatus === $code;
        $href = $isOn ? 'home' : 'home?status=' . $code;
    ?>
        <a class="pipeline__step<?= $isOn ? ' is-active' : '' ?>" href="<?= e($href) ?>"<?= $isOn ? ' aria-current="true"' : '' ?>>
            <strong><?= (int)$count ?></strong>
            <span><?= e($label) ?></span>
        </a>
    <?php endforeach; ?>
</nav>

<?php if (($atrasadas ?? 0) > 0 || ($criticas ?? 0) > 0): ?>
    <p class="notice notice--bad">
        <i class="fa-solid fa-circle-exclamation" aria-hidden="true"></i>
        <span class="notice__body">
            <span><?php if (($atrasadas ?? 0) > 0): ?><b><?= (int)$atrasadas ?> <?= (int)$atrasadas === 1 ? 'pedido' : 'pedidos' ?></b> <?= (int)$atrasadas === 1 ? 'passou' : 'passaram' ?> do prazo<?php endif; ?><?php if (($atrasadas ?? 0) > 0 && ($criticas ?? 0) > 0): ?>; <?php endif; ?><?php if (($criticas ?? 0) > 0): ?><b><?= (int)$criticas ?></b> com prioridade crítica<?php endif; ?>.</span>
        </span>
    </p>
<?php endif; ?>

<div class="dash-grid">
    <section aria-labelledby="tabelaTitulo">
        <div class="toolbar">
            <h2 id="tabelaTitulo" class="toolbar__title"><?= e($activeFilterStatus ? $pageHeading : 'Pedidos') ?> <span><?= $totalRows ?></span></h2>
            <form method="get" action="home" class="toolbar__filters" role="search">
                <?php if ($activeFilterStatus): ?><input type="hidden" name="status" value="<?= e($activeFilterStatus) ?>"><?php endif; ?>
                <div class="search-box">
                    <i class="fas fa-search" aria-hidden="true"></i>
                    <input type="search" name="q" value="<?= e($searchTerm) ?>" placeholder="Título, número ou quem pediu" aria-label="Buscar por título, número ou quem pediu">
                </div>
                <input type="date" name="data" value="<?= e($searchDate) ?>" aria-label="Pedidos feitos neste dia" title="Pedidos feitos neste dia" onchange="this.form.submit()">
                <?php if ($hasFilter): ?>
                    <a class="btn btn--ghost" href="home">Limpar</a>
                <?php endif; ?>
            </form>
        </div>

        <div class="queue queue--sector queue--dash">
            <?php if ($recent_requests): ?>
                <?= renderQueueHead(['sector' => true]) ?>
                <ul class="queue-list">
                    <?php foreach ($recent_requests as $req):
                        $st = strtoupper($req['status'] ?? 'P') ?: 'P';
                        $req['context'] = [statusLabel($st), ['C' => 'ok', 'N' => 'bad'][$st] ?? ''];
                    ?>
                        <?= renderQueueRow($req, ['sector' => true, 'due' => !in_array($st, ['C', 'N'], true)]) ?>
                    <?php endforeach; ?>
                </ul>
            <?php else: ?>
                <div class="empty-state"><i class="fa-regular fa-folder-open" aria-hidden="true"></i><p><?= $hasFilter ? 'Nenhum pedido com esses filtros.' : 'Ainda não há pedidos.' ?></p></div>
            <?php endif; ?>
        </div>

        <?php if ($totalPages > 1): ?>
            <nav class="pager" aria-label="Páginas">
                <?php if ($currentPageNum > 1): ?><a href="<?= e($pageLink($currentPageNum - 1)) ?>">Anterior</a><?php endif; ?>
                <span class="is-current"><?= $currentPageNum ?> de <?= $totalPages ?></span>
                <?php if ($currentPageNum < $totalPages): ?><a href="<?= e($pageLink($currentPageNum + 1)) ?>">Próxima</a><?php endif; ?>
            </nav>
        <?php endif; ?>
    </section>

    <?php if (!$activeFilterStatus && !empty($categoryCounts)): ?>
        <aside class="stack" aria-label="Resumo">
            <?php
            $sectorRows = $categoryCounts ?? [];
            arsort($sectorRows);
            $sectorMax = max($sectorRows ?: [1]);
            ?>
            <?php if ($sectorRows): ?>
            <section class="panel">
                <div class="panel__head"><h2>Por setor</h2></div>
                <div class="panel__body">
                    <ul class="bar-list">
                        <?php foreach ($sectorRows as $sector => $count): ?>
                            <li class="bar-list__row">
                                <span><?= e(requestSectorName($sector)) ?></span><strong><?= (int)$count ?></strong>
                                <span class="bar-list__track"><span class="bar-list__fill" style="--w: <?= round($count / $sectorMax * 100) ?>%"></span></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </section>
            <?php endif; ?>
        </aside>
    <?php endif; ?>
</div>


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
