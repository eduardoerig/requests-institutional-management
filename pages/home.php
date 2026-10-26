<?php
// pages/home.php - PAINEL DA COORDENAÇÃO / ADMINISTRAÇÃO (UI 2.0)

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
require_once __DIR__ . '/../classes/RequestForwardService.php';
require_once __DIR__ . '/../components/request_card.php';
require_once __DIR__ . '/../components/welcome_card.php';

// Pegar setores permitidos para este usuário
$allowedSectors = [];
if (!$isAdmin && !$isAdmSub) {
    $stmtSectors = $pdo->prepare("SELECT a.title FROM ctd_area a JOIN cfg_user_area cua ON a.id = cua.id_area WHERE cua.id_user = ?");
    $stmtSectors->execute([$user_id]);
    $allowedSectors = array_values(array_filter(array_map('areaTitleToSector', $stmtSectors->fetchAll(PDO::FETCH_COLUMN))));

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

// REGRA: Gestor só vê requisições aprovadas (Y), em andamento (W) e concluídas (C)
$excludeStatuses = [];
if ($isGestor) {
    $excludeStatuses = ['P', 'N'];
}

// Filtro de subdivisão para adm_sub
$subdivisionFilter = $isAdmSub ? ($_SESSION['subdivision_ids'] ?? null) : null;

// Uma busca só (sem filtro de situação): os números do painel valem sempre para tudo;
// o filtro de situação recorta só a lista, aqui no PHP.
$data = RequestManager::getRequests($pdo, null, $managingId, null, null, null, 'DESC', $excludeStatuses, null, null, $subdivisionFilter);
$everything = $data['requests'];
$stats = $data['stats'];

extract($stats); // $total, $pendentes, $aprovadas, $emAndamento, $repassadas, $concluidas, $recusadas, $atrasadas, $urgentes, $criticas, $categoryCounts

$normStatus = static function (array $r): string {
    $st = strtoupper(trim((string)($r['st_raw'] ?? $r['status'] ?? 'P')));
    return $st === '' ? 'P' : $st;
};
$all_requests = $activeFilterStatus
    ? array_values(array_filter($everything, fn($r) => $normStatus($r) === $activeFilterStatus))
    : $everything;

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

// ---------------------------------------------------------------------
// Leitura do painel (tudo a partir dos pedidos já carregados acima)
// ---------------------------------------------------------------------
$today = date('Y-m-d');
$daysSince = static function (?string $dt) use ($today): int {
    if (!$dt || strpos($dt, '0000') === 0) return 0;
    return max(0, (int)floor((strtotime($today) - strtotime(substr($dt, 0, 10))) / 86400));
};
$fmtDays = static function (float $d): string {
    if ($d < 1) return 'menos de 1 dia';
    $txt = rtrim(rtrim(number_format($d, 1, ',', ''), '0'), ',');
    return $txt . ($txt === '1' ? ' dia' : ' dias');
};
$isOpen = static fn(string $st): bool => !in_array($st, ['C', 'N'], true);
$isUrgent = static fn(array $r): bool => !empty($r['urgent']) || (int)($r['priority'] ?? 2) >= 3;
$reqLink = static fn(array $r): string => 'request_detail?id=' . (int)$r['id'] . '&table=' . urlencode((string)$r['sector']);

$pendingOld = 0;       // esperando aprovação há mais de 2 dias
$inSectorsUrgent = 0;  // urgentes entre aprovados/em atendimento
$sectorLoad = [];      // setor => [abertos, atrasados]
foreach (array_keys($map) as $s) {
    $sectorLoad[$s] = ['open' => 0, 'late' => 0];
}
$visibleKeys = [];
$lateList = [];
$urgentList = [];
$urgentPending = [];  // urgentes esperando aprovação (só para quem aprova)
foreach ($everything as $r) {
    $st = $normStatus($r);
    $sector = (string)($r['sector'] ?? '');
    $visibleKeys[$sector . ':' . (int)$r['id']] = $r;
    if ($st === 'P' && $daysSince($r['created_at'] ?? null) > 2) $pendingOld++;
    if (in_array($st, ['Y', 'A', 'W'], true) && $isUrgent($r)) $inSectorsUrgent++;
    if ($isOpen($st)) {
        $sectorLoad[$sector] = $sectorLoad[$sector] ?? ['open' => 0, 'late' => 0];
        $sectorLoad[$sector]['open']++;
        if (!empty($r['is_atrasada'])) {
            $sectorLoad[$sector]['late']++;
            $lateList[] = $r;
        } elseif ($isUrgent($r) && $st !== 'P') {
            $urgentList[] = $r;
        } elseif ($isUrgent($r) && !$isGestor) {
            $urgentPending[] = $r;
        }
    }
}
uasort($sectorLoad, fn($a, $b) => $b['open'] <=> $a['open']);
$loadMax = max(1, max(array_column($sectorLoad, 'open') ?: [0]));

// Encaminhamentos entre setores (últimos 30 dias + os que ainda estão em aberto)
$pairs = [];
$openForwards = [];
try {
    $since = date('Y-m-d H:i:s', strtotime('-30 days'));
    $stmtFwd = $pdo->prepare("
        SELECT request_id, request_table, from_area_id, to_area_id, mode, status, created_at, responded_at
        FROM request_forwards
        WHERE created_at >= ? OR status = 'pending' OR (mode = 'help' AND status = 'accepted')
        ORDER BY id DESC
        LIMIT 500
    ");
    $stmtFwd->execute([$since]);
    foreach ($stmtFwd->fetchAll(PDO::FETCH_ASSOC) as $f) {
        $key = $f['request_table'] . ':' . (int)$f['request_id'];
        if (!isset($visibleKeys[$key])) continue; // só pedidos que este usuário enxerga
        $isOpenFwd = $f['status'] === 'pending' || ($f['mode'] === 'help' && $f['status'] === 'accepted');
        if ($isOpenFwd) {
            $openForwards[] = $f + ['req' => $visibleKeys[$key]];
        }
        if ($f['created_at'] >= $since) {
            $pk = (int)$f['from_area_id'] . '>' . (int)$f['to_area_id'];
            $pairs[$pk] = $pairs[$pk] ?? ['from' => (int)$f['from_area_id'], 'to' => (int)$f['to_area_id'], 'count' => 0, 'ret' => []];
            $pairs[$pk]['count']++;
            if ($f['mode'] === 'help' && $f['status'] === 'returned' && !empty($f['responded_at'])) {
                $pairs[$pk]['ret'][] = max(0, (strtotime($f['responded_at']) - strtotime($f['created_at'])) / 86400);
            }
        }
    }
} catch (Throwable $e) {
    error_log('home.php encaminhamentos: ' . $e->getMessage());
}
uasort($pairs, fn($a, $b) => $b['count'] <=> $a['count']);
$pairs = array_slice($pairs, 0, 5, true);
$forwardAges = array_map(fn($f) => $daysSince($f['created_at']), $openForwards);
$forwardAvg = $forwardAges ? array_sum($forwardAges) / count($forwardAges) : null;

// "Precisa de atenção": urgentes esperando aprovação, atrasados (mais atrasado primeiro), urgentes, parados com outro setor
usort($lateList, fn($a, $b) => strcmp((string)$a['date'], (string)$b['date']));
$attention = [];
foreach ($urgentPending as $r) {
    $attention[] = [
        'href' => $reqLink($r) . '&from=approve', 'title' => 'Nº ' . (int)$r['id'] . ' · ' . ($r['title'] ?? 'Sem título'),
        'meta' => requestSectorName((string)$r['sector']) . ' · urgente · esperando sua aprovação',
        'kind' => 'warn',
    ];
}
foreach ($lateList as $r) {
    $d = $daysSince($r['date']);
    $attention[] = [
        'href' => $reqLink($r), 'title' => 'Nº ' . (int)$r['id'] . ' · ' . ($r['title'] ?? 'Sem título'),
        'meta' => requestSectorName((string)$r['sector']) . ' · atrasado ' . $d . ($d === 1 ? ' dia' : ' dias') . ($isUrgent($r) ? ' · urgente' : ''),
        'kind' => 'bad',
    ];
}
foreach ($urgentList as $r) {
    $attention[] = [
        'href' => $reqLink($r), 'title' => 'Nº ' . (int)$r['id'] . ' · ' . ($r['title'] ?? 'Sem título'),
        'meta' => requestSectorName((string)$r['sector']) . ' · urgente · ' . statusLabel($normStatus($r)),
        'kind' => 'warn',
    ];
}
foreach ($openForwards as $f) {
    $d = $daysSince($f['created_at']);
    if ($d < 2) continue;
    $attention[] = [
        'href' => $reqLink($f['req']), 'title' => 'Nº ' . (int)$f['request_id'] . ' · ' . ($f['req']['title'] ?? 'Sem título'),
        'meta' => 'Parado com ' . RequestForwardService::areaLabel($pdo, (int)$f['to_area_id']) . ' há ' . $d . ' dias',
        'kind' => '',
    ];
}
$attentionMore = max(0, count($attention) - 6);
$attention = array_slice($attention, 0, 6);
if (!$isGestor && $pendingOld > 0) {
    $attention[] = [
        'href' => 'approve', 'title' => $pendingOld . ($pendingOld === 1 ? ' pedido esperando' : ' pedidos esperando') . ' aprovação há mais de 2 dias',
        'meta' => 'Abrir Aprovar', 'kind' => '',
    ];
}

// Números por etapa
$stages = [];
if (!$isGestor) {
    $stages[] = ['P', 'Esperando aprovação', (int)($pendentes ?? 0), $pendingOld > 0 ? $pendingOld . ' há +2 dias' : 'nenhum parado', $pendingOld > 0 ? 'warn' : ''];
}
$stages[] = [null, $isGestor ? 'Para atender' : 'Nos setores', (int)($aprovadas ?? 0) + (int)($emAndamento ?? 0),
    $inSectorsUrgent > 0 ? $inSectorsUrgent . ($inSectorsUrgent === 1 ? ' urgente' : ' urgentes') : 'nenhum urgente', $inSectorsUrgent > 0 ? 'bad' : ''];
$stages[] = ['F', 'Esperando outro setor', (int)($repassadas ?? 0), $forwardAvg !== null ? 'média: ' . $fmtDays($forwardAvg) : '', ''];
$stages[] = ['C', 'Concluídos', (int)($concluidas ?? 0), 'desde o início', 'ok'];

$tableTitle = [
    'P' => 'Aguardando aprovação', 'Y' => 'Aprovadas', 'W' => 'Em atendimento',
    'C' => 'Concluídas', 'N' => 'Recusadas', 'F' => 'Encaminhadas',
][$activeFilterStatus] ?? 'Pedidos';

// Filtros por situação (os mesmos de antes, agora em abas)
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
$scopeText = $isAdmSub ? 'Pedidos da sua subdivisão' : ($isGestor ? 'Pedidos do seu setor' : 'Todos os setores');
?>
<div class="dash">
    <header class="dash-head">
        <div>
            <p class="dash-head__scope"><?= e($scopeText) ?></p>
            <h1 class="dash-head__title">Painel</h1>
        </div>
        <button type="button" onclick="exportToExcel()" class="btn"><i class="fa-solid fa-download" aria-hidden="true"></i> Exportar planilha</button>
    </header>

    <?= renderWelcomeCard($role, (int)$_SESSION['id']) ?>

    <section class="dash-card dash-attention<?= $attention ? '' : ' is-calm' ?>" aria-labelledby="atencaoTitulo">
        <div class="dash-card__head"><h2 id="atencaoTitulo"><?= $attention ? 'Comece por aqui' : 'Tudo em dia' ?></h2></div>
        <?php if ($attention): ?>
            <ul class="attn-list">
                <?php foreach ($attention as $item): ?>
                    <li><a class="attn-item" href="<?= e($item['href']) ?>">
                        <span class="attn-item__title"><?= e($item['title']) ?></span>
                        <span class="attn-item__meta<?= $item['kind'] ? ' attn-item__meta--' . e($item['kind']) : '' ?>"><?= e($item['meta']) ?></span>
                    </a></li>
                <?php endforeach; ?>
            </ul>
            <?php if ($attentionMore > 0): ?><p class="dash-card__foot">E mais <?= (int)$attentionMore ?>. Veja a lista abaixo.</p><?php endif; ?>
        <?php else: ?>
            <p class="dash-card__empty"><i class="fa-solid fa-circle-check" aria-hidden="true"></i> Nada atrasado, urgente ou parado. Quando algo precisar de você, aparece aqui.</p>
        <?php endif; ?>
    </section>

    <section class="dash-stages" aria-label="Pedidos por etapa">
        <?php foreach ($stages as [$code, $label, $value, $note, $kind]):
            $tag = $code ? 'a' : 'div';
            $isOn = $code && $activeFilterStatus === $code;
        ?>
            <<?= $tag ?> class="dash-stage<?= $isOn ? ' is-active' : '' ?>"<?php if ($code): ?> href="<?= e($isOn ? 'home' : 'home?status=' . $code) ?>"<?= $isOn ? ' aria-current="true"' : '' ?><?php endif; ?>>
                <span class="dash-stage__label"><?= e($label) ?></span>
                <strong class="dash-stage__value"><?= (int)$value ?></strong>
                <?php if ($note !== ''): ?><span class="dash-stage__note<?= $kind ? ' dash-stage__note--' . e($kind) : '' ?>"><?= e($note) ?></span><?php endif; ?>
            </<?= $tag ?>>
        <?php endforeach; ?>
    </section>

    <details class="dash-more">
    <summary class="dash-more__toggle"><i class="fa-solid fa-chart-simple" aria-hidden="true"></i> Ver números da operação <i class="fa-solid fa-chevron-down dash-more__chev" aria-hidden="true"></i></summary>
    <div class="dash-cols">
        <section class="dash-card dash-load" aria-labelledby="cargaTitulo">
            <div class="dash-card__head">
                <h2 id="cargaTitulo">Carga por setor</h2>
                <span>pedidos abertos</span>
            </div>
            <?php if (array_sum(array_column($sectorLoad, 'open')) === 0): ?>
                <p class="dash-card__empty">Nenhum pedido aberto agora.</p>
            <?php else: ?>
                <ul class="load-list">
                    <?php foreach ($sectorLoad as $sector => $load):
                        $name = requestSectorName((string)$sector);
                        $aria = $name . ': ' . $load['open'] . ($load['open'] === 1 ? ' aberto' : ' abertos')
                            . ($load['late'] > 0 ? ', ' . $load['late'] . ($load['late'] === 1 ? ' atrasado' : ' atrasados') : '');
                    ?>
                        <li class="load-row<?= $load['late'] > 0 ? ' is-late' : '' ?>">
                            <span class="load-row__name"><?= e($name) ?></span>
                            <span class="load-row__meta"><?= (int)$load['open'] ?> <?= $load['open'] === 1 ? 'aberto' : 'abertos' ?><?php if ($load['late'] > 0): ?> · <?= (int)$load['late'] ?> <?= $load['late'] === 1 ? 'atrasado' : 'atrasados' ?><?php endif; ?></span>
                            <span class="load-row__track" role="img" aria-label="<?= e($aria) ?>"><span class="load-row__fill" style="--w: <?= round($load['open'] / $loadMax * 100) ?>%"></span></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <p class="dash-card__foot"><span class="load-key" aria-hidden="true"></span> Laranja: setor com pedido atrasado.</p>
            <?php endif; ?>
        </section>

        <div class="dash-side">
            <section class="dash-card" aria-labelledby="ajudaTitulo">
                <div class="dash-card__head">
                    <h2 id="ajudaTitulo">Quem pede ajuda a quem</h2>
                    <span>30 dias</span>
                </div>
                <?php if ($pairs): ?>
                    <ul class="pair-list">
                        <?php foreach ($pairs as $p):
                            $from = RequestForwardService::areaLabel($pdo, $p['from']);
                            $to = RequestForwardService::areaLabel($pdo, $p['to']);
                            $ret = $p['ret'] ? $fmtDays(array_sum($p['ret']) / count($p['ret'])) : null;
                        ?>
                            <li class="pair-row">
                                <span><?= e($from) ?> <span class="pair-row__arrow" aria-label="pediu ajuda a">→</span> <?= e($to) ?></span>
                                <span class="pair-row__info"><?= (int)$p['count'] ?><?= $ret ? ' · devolve em ' . e($ret) : '' ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php else: ?>
                    <p class="dash-card__empty">Nenhum pedido passou de um setor a outro nos últimos 30 dias.</p>
                <?php endif; ?>
            </section>
        </div>
    </div>
    </details>

    <section class="dash-table" aria-labelledby="tabelaTitulo">
        <div class="toolbar">
            <h2 id="tabelaTitulo" class="toolbar__title"><?= e($tableTitle) ?> <span><?= $totalRows ?></span></h2>
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

        <nav class="tabs dash-tabs" aria-label="Filtrar por situação">
            <a class="tab<?= !$activeFilterStatus ? ' is-active' : '' ?>" href="home"<?= !$activeFilterStatus ? ' aria-current="true"' : '' ?>>Todos <span><?= (int)($total ?? 0) ?></span></a>
            <?php foreach ($pipeline as $code => [$label, $count]):
                $isOn = $activeFilterStatus === $code;
            ?>
                <a class="tab<?= $isOn ? ' is-active' : '' ?>" href="<?= e($isOn ? 'home' : 'home?status=' . $code) ?>"<?= $isOn ? ' aria-current="true"' : '' ?>><?= e($label) ?> <span><?= (int)$count ?></span></a>
            <?php endforeach; ?>
        </nav>

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
</div>

<script>
function exportToExcel() {
    const params = new URLSearchParams({
        search: <?= json_encode($searchTerm, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
        status: <?= json_encode((string)$activeFilterStatus, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
        date: <?= json_encode($searchDate, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>
    });
    window.location.href = `api/export_requests.php?${params.toString()}`;
}
</script>
