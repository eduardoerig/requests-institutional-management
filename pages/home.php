<?php
// pages/home.php - PAINEL DA COORDENAÇÃO / ADMINISTRAÇÃO
// Triagem: uma faixa de estado, uma lista e uma gaveta. Os números que não são de triagem
// ficam recolhidos em "Números da operação".

// Setores do Painel (config/sectors.php)
$map = array_map(fn($s) => $s['label'], sectorRegistry());

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
$reqLink = static fn(array $r): string => 'request_detail?id=' . (int)$r['id'] . '&table=' . urlencode((string)($r['table'] ?? $r['sector'] ?? ''));

$pendingOld = 0;       // esperando aprovação há mais de 2 dias
$sectorLoad = [];      // setor => [abertos, atrasados]
foreach (array_keys($map) as $s) {
    $sectorLoad[$s] = ['open' => 0, 'late' => 0];
}
$visibleKeys = [];
$todayCount = 0;      // vencem hoje
$inTimeCount = 0;     // abertos e dentro do prazo
$openAges = [];       // dias em aberto, para a média do rodapé
foreach ($everything as $r) {
    $st = $normStatus($r);
    $sector = (string)($r['sector'] ?? '');
    $visibleKeys[$sector . ':' . (int)$r['id']] = $r;
    if ($st === 'P' && $daysSince($r['created_at'] ?? null) > 2) $pendingOld++;
    if ($isOpen($st)) {
        $sectorLoad[$sector] = $sectorLoad[$sector] ?? ['open' => 0, 'late' => 0];
        $sectorLoad[$sector]['open']++;
        $openAges[] = $daysSince($r['created_at'] ?? null);
        $deadline = substr((string)($r['date'] ?? ''), 0, 10);
        if (!empty($r['is_atrasada'])) {
            $sectorLoad[$sector]['late']++;
        } else {
            if ($deadline === $today) $todayCount++;
            if ($deadline === '' || $deadline >= $today) $inTimeCount++;
        }
    }
}
uasort($sectorLoad, fn($a, $b) => $b['open'] <=> $a['open']);
$loadMax = max(1, max(array_column($sectorLoad, 'open') ?: [0]));
$openAvg = $openAges ? array_sum($openAges) / count($openAges) : null;

// Encaminhamentos entre setores (últimos 30 dias + os que ainda estão em aberto)
$pairs = [];
$openForwards = [];
try {
    $since = date('Y-m-d H:i:s', strtotime('-30 days'));
    $stmtFwd = $pdo->prepare("
        SELECT request_id, request_table, from_area_id, to_area_id, mode, is_partner, due_date, status, created_at, responded_at
        FROM request_forwards
        WHERE created_at >= ? OR status = 'pending' OR (mode = 'help' AND status = 'accepted')
        ORDER BY id DESC
        LIMIT 500
    ");
    $stmtFwd->execute([$since]);
    foreach ($stmtFwd->fetchAll(PDO::FETCH_ASSOC) as $f) {
        $key = $f['request_table'] . ':' . (int)$f['request_id'];
        if (!isset($visibleKeys[$key])) continue; // só pedidos que este usuário enxerga
        $isOpenFwd = RequestForwardService::isOpen($f);
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

// "Parado com": a parte que mais pede atenção (atrasada primeiro, depois a mais antiga).
// Alimenta a linha de baixo da lista e a gaveta.
$paradoCom = [];
foreach ($openForwards as $f) {
    $k = $f['request_table'] . ':' . (int)$f['request_id'];
    $late = RequestForwardService::partnerIsLate($f);
    $age = $daysSince($late ? $f['due_date'] : $f['created_at']);
    if (isset($paradoCom[$k]) && [$paradoCom[$k]['late'], $paradoCom[$k]['days']] >= [$late, $age]) continue;
    $paradoCom[$k] = [
        'area' => RequestForwardService::areaLabel($pdo, (int)$f['to_area_id']),
        'days' => $age,
        'late' => $late,
    ];
}
// Texto da parte: "Parte de Compras atrasada há 2 dias" ou "com Compras há 3 dias"
$paradoText = static function (array $p): string {
    $d = $p['days'] . ($p['days'] === 1 ? ' dia' : ' dias');
    return $p['late'] ? 'Parte de ' . $p['area'] . ' atrasada há ' . $d : 'com ' . $p['area'] . ' há ' . $d;
};

// Filtros por situação (abas)
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

// Lente de prazo: os três chips da faixa de estado recortam a lista
$view = in_array($_GET['v'] ?? '', ['atrasados', 'hoje', 'emdia'], true) ? $_GET['v'] : null;
if ($view === 'atrasados') {
    $tableRequests = array_values(array_filter($tableRequests, fn($r) => !empty($r['is_atrasada'])));
} elseif ($view === 'hoje') {
    $tableRequests = array_values(array_filter($tableRequests, fn($r) => substr((string)($r['date'] ?? ''), 0, 10) === $today && $isOpen($normStatus($r))));
} elseif ($view === 'emdia') {
    $tableRequests = array_values(array_filter($tableRequests, function ($r) use ($isOpen, $normStatus, $today) {
        $deadline = substr((string)($r['date'] ?? ''), 0, 10);
        return $isOpen($normStatus($r)) && empty($r['is_atrasada']) && ($deadline === '' || $deadline >= $today);
    }));
}

// Paginação: 50 por página
$perPage = 50;
$totalRows = count($tableRequests);
$totalPages = max(1, (int)ceil($totalRows / $perPage));
$currentPageNum = min($totalPages, max(1, (int)($_GET['p'] ?? 1)));
$recent_requests = array_slice($tableRequests, ($currentPageNum - 1) * $perPage, $perPage);

$homeLink = static function (array $override = []) use ($activeFilterStatus, $searchTerm, $searchDate, $view) {
    $params = array_filter(array_merge([
        'status' => $activeFilterStatus,
        'v' => $view,
        'q' => $searchTerm,
        'data' => $searchDate,
    ], $override), fn($v) => $v !== null && $v !== '');
    return 'home' . ($params ? '?' . http_build_query($params) : '');
};
$pageLink = fn(int $n) => $homeLink(['p' => $n > 1 ? $n : null]);

$hasFilter = $activeFilterStatus || $searchTerm !== '' || $searchDate !== '' || $view;
$scopeText = $isAdmSub ? 'Pedidos da sua subdivisão' : ($isGestor ? 'Pedidos do seu setor' : 'Todos os setores');

// Rótulos curtos para a coluna Situação (as pílulas não cabem com o texto inteiro)
$shortStatus = ['P' => 'Ag. aprovação', 'Y' => 'Aprovada', 'W' => 'Em atendimento', 'C' => 'Concluída', 'N' => 'Recusada', 'F' => 'Encaminhada'];
$prioLabel = priorityLabels();
?>

<div class="pn">
    <!-- Cabeçalho: título à esquerda; busca, números e exportar à direita -->
    <header class="pn-head">
        <div class="pn-head__main">
            <h1 class="pn-title">Painel</h1>
            <p class="pn-sub">
                <strong><?= (int)$total ?></strong> <?= (int)$total === 1 ? 'pedido' : 'pedidos' ?><?php if ($openAvg !== null): ?> · abertos há <?= e($fmtDays($openAvg)) ?> em média<?php endif; ?>
            </p>
        </div>
        <div class="pn-head__acts">
            <form method="get" action="home" class="pn-filters" role="search">
                <?php if ($activeFilterStatus): ?><input type="hidden" name="status" value="<?= e($activeFilterStatus) ?>"><?php endif; ?>
                <?php if ($view): ?><input type="hidden" name="v" value="<?= e($view) ?>"><?php endif; ?>
                <label class="pn-search">
                    <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
                    <span class="visually-hidden">Buscar por título, número ou quem pediu</span>
                    <input type="search" name="q" value="<?= e($searchTerm) ?>" placeholder="Buscar pedido ou pessoa">
                </label>
                <?php // Filtro por dia: pouco usado, fica fechado até alguém pedir ?>
                <details class="pn-day"<?= $searchDate !== '' ? ' open' : '' ?>>
                    <summary class="pn-day__toggle"><i class="fa-regular fa-calendar" aria-hidden="true"></i> Por dia</summary>
                    <input class="pn-date" type="date" name="data" value="<?= e($searchDate) ?>" aria-label="Pedidos feitos neste dia" onchange="this.form.submit()">
                </details>
            </form>
            <details class="pn-more">
                <summary class="pn-more__toggle"><i class="fa-solid fa-chart-simple" aria-hidden="true"></i> Números da operação <i class="fa-solid fa-chevron-down pn-more__chev" aria-hidden="true"></i></summary>
                <div class="pn-more__panel">
                    <section class="pn-more__col" aria-labelledby="cargaTitulo">
                        <div class="pn-more__head"><h2 id="cargaTitulo">Carga por setor</h2><span>pedidos abertos</span></div>
                        <?php if (array_sum(array_column($sectorLoad, 'open')) === 0): ?>
                            <p class="pn-more__empty">Nenhum pedido aberto agora.</p>
                        <?php else: ?>
                            <ul class="pn-load">
                                <?php foreach ($sectorLoad as $sector => $load):
                                    $name = requestSectorName((string)$sector);
                                    $aria = $name . ': ' . $load['open'] . ($load['open'] === 1 ? ' aberto' : ' abertos')
                                        . ($load['late'] > 0 ? ', ' . $load['late'] . ($load['late'] === 1 ? ' atrasado' : ' atrasados') : ''); ?>
                                    <li class="pn-load__row<?= $load['late'] > 0 ? ' is-late' : '' ?>">
                                        <span class="pn-load__name"><?= e($name) ?></span>
                                        <span class="pn-load__meta"><?= (int)$load['open'] ?><?php if ($load['late'] > 0): ?> · <b><?= (int)$load['late'] ?></b> atrasado<?= $load['late'] === 1 ? '' : 's' ?><?php endif; ?></span>
                                        <span class="pn-load__track" role="img" aria-label="<?= e($aria) ?>"><span class="pn-load__fill" style="--w: <?= round($load['open'] / $loadMax * 100) ?>%"></span></span>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                            <p class="pn-more__foot"><span class="pn-key" aria-hidden="true"></span> Vermelho: setor com pedido atrasado.</p>
                        <?php endif; ?>
                    </section>
                    <section class="pn-more__col" aria-labelledby="ajudaTitulo">
                        <div class="pn-more__head"><h2 id="ajudaTitulo">Quem pede ajuda a quem</h2><span>30 dias</span></div>
                        <?php if ($pairs): ?>
                            <ul class="pn-pairs">
                                <?php foreach ($pairs as $p):
                                    $from = RequestForwardService::areaLabel($pdo, $p['from']);
                                    $to = RequestForwardService::areaLabel($pdo, $p['to']);
                                    $ret = $p['ret'] ? $fmtDays(array_sum($p['ret']) / count($p['ret'])) : null; ?>
                                    <li class="pn-pairs__row">
                                        <span><?= e($from) ?> <span class="pn-pairs__arrow" aria-label="pediu ajuda a">→</span> <?= e($to) ?></span>
                                        <span class="pn-pairs__info"><?= (int)$p['count'] ?><?= $ret ? ' · devolve em ' . e($ret) : '' ?></span>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php else: ?>
                            <p class="pn-more__empty">Nenhum pedido passou de um setor a outro nos últimos 30 dias.</p>
                        <?php endif; ?>
                    </section>
                </div>
            </details>
            <button type="button" onclick="exportToExcel()" class="btn pn-btn">
                <i class="fa-solid fa-download" aria-hidden="true"></i> Exportar
            </button>
        </div>
    </header>

    <!-- Uma faixa de filtros: situação à esquerda (só as que têm pedidos), prazo à direita -->
    <div class="pn-bar">
        <nav class="pn-tabs" aria-label="Filtrar por situação">
            <a class="pn-tab<?= !$activeFilterStatus ? ' is-active' : '' ?>" href="<?= e($homeLink(['status' => null, 'p' => null])) ?>"<?= !$activeFilterStatus ? ' aria-current="true"' : '' ?>>Todos <span><?= (int)($total ?? 0) ?></span></a>
            <?php foreach ($pipeline as $code => [$label, $count]):
                $isOn = $activeFilterStatus === $code;
                if ((int)$count === 0 && !$isOn) continue; // aba vazia não ocupa espaço ?>
                <a class="pn-tab<?= $isOn ? ' is-active' : '' ?>" href="<?= e($homeLink(['status' => $isOn ? null : $code, 'p' => null])) ?>"<?= $isOn ? ' aria-current="true"' : '' ?>><?= e($label) ?> <span><?= (int)$count ?></span></a>
            <?php endforeach; ?>
        </nav>
        <div class="pn-band" role="group" aria-label="Filtrar por prazo">
            <a class="pn-chip pn-chip--bad<?= $view === 'atrasados' ? ' is-on' : '' ?>" href="<?= e($homeLink(['v' => $view === 'atrasados' ? null : 'atrasados', 'p' => null])) ?>"<?= $view === 'atrasados' ? ' aria-current="true"' : '' ?>><strong><?= (int)$atrasadas ?></strong> <?= (int)$atrasadas === 1 ? 'atrasado' : 'atrasados' ?></a>
            <a class="pn-chip pn-chip--warn<?= $view === 'hoje' ? ' is-on' : '' ?>" href="<?= e($homeLink(['v' => $view === 'hoje' ? null : 'hoje', 'p' => null])) ?>"<?= $view === 'hoje' ? ' aria-current="true"' : '' ?>><strong><?= (int)$todayCount ?></strong> <?= (int)$todayCount === 1 ? 'vence hoje' : 'vencem hoje' ?></a>
            <a class="pn-chip pn-chip--ok<?= $view === 'emdia' ? ' is-on' : '' ?>" href="<?= e($homeLink(['v' => $view === 'emdia' ? null : 'emdia', 'p' => null])) ?>"<?= $view === 'emdia' ? ' aria-current="true"' : '' ?>><strong><?= (int)$inTimeCount ?></strong> em dia</a>
        </div>
    </div>

    <?php if ($hasFilter): ?>
        <?php // Com filtro ligado, uma frase diz o que está valendo e como voltar a ver tudo ?>
        <p class="pn-active">
            Mostrando <strong><?= (int)$totalRows ?></strong> de <?= (int)$total ?>
            <?php if ($searchTerm !== ''): ?> · busca “<?= e($searchTerm) ?>”<?php endif; ?>
            <?php if ($searchDate !== ''): ?> · feitos em <?= e(date('d/m', strtotime($searchDate))) ?><?php endif; ?>
            <a href="home">Ver todos</a>
        </p>
    <?php endif; ?>

    <section class="pn-card" aria-label="Pedidos">
        <?php if (!$isGestor && $pendingOld > 0): ?>
            <p class="pn-notice">
                <i class="fa-solid fa-circle-exclamation" aria-hidden="true"></i>
                <strong><?= (int)$pendingOld ?></strong> <?= $pendingOld === 1 ? 'pedido espera' : 'pedidos esperam' ?> aprovação há mais de 2 dias.
                <a href="approve">Abrir Aprovar</a>
            </p>
        <?php endif; ?>

        <div class="pn-table">
            <div class="pn-thead" aria-hidden="true">
                <span>Tarefa</span>
                <span>Prioridade</span>
                <span>Prazo</span>
                <span>Status</span>
                <span></span>
            </div>

            <?php if ($recent_requests): ?>
                <?php foreach ($recent_requests as $req):
                    $st = $normStatus($req);
                    $slug = (string)($req['table'] ?? $req['sector'] ?? '');
                    $sectorName = requestSectorName($slug);
                    $who = trim((string)($req['solicitor_name_formatted'] ?? '')) ?: '—';
                    [$dueText, $dueKind] = deadlineLabel((string)($req['date'] ?? ''));
                    $prio = (int)($req['priority'] ?? 2);
                    $isUrg = $isUrgent($req);
                    $parado = $paradoCom[$slug . ':' . (int)$req['id']] ?? null;
                    $href = $reqLink($req);
                    $desc = trim((string)($req['description'] ?? ''));
                    $stText = $shortStatus[$st] ?? statusLabel($st);
                ?>
                    <a class="pn-row<?= $st === 'C' || $st === 'N' ? ' is-done' : '' ?>" href="<?= e($href) ?>"
                       data-peek
                       data-id="<?= (int)$req['id'] ?>"
                       data-table="<?= e($slug) ?>"
                       data-title="<?= e((string)($req['title'] ?? 'Sem título')) ?>"
                       data-sector="<?= e($sectorName) ?>"
                       data-who="<?= e($who) ?>"
                       data-status="<?= e($stText) ?>"
                       data-status-code="<?= e($st) ?>"
                       data-prazo="<?= e($dueText) ?>"
                       data-prazo-kind="<?= e($dueKind) ?>"
                       data-prio="<?= e($prioLabel[$prio] ?? 'Normal') ?>"
                       data-urgent="<?= $isUrg ? '1' : '0' ?>"
                       data-parado="<?= $parado ? e($paradoText($parado)) : '' ?>"
                       data-parado-late="<?= $parado && $parado['late'] ? '1' : '0' ?>"
                       data-desc="<?= e($desc) ?>"
                       data-href="<?= e($href) ?>">
                        <span class="pn-main">
                            <i class="fa-regular fa-file-lines pn-main__ico" aria-hidden="true"></i>
                            <span class="pn-main__text">
                                <span class="pn-ttl"><?= e((string)($req['title'] ?? 'Sem título')) ?></span>
                                <span class="pn-meta">#<?= (int)$req['id'] ?> · <?= e($sectorName) ?> · <?= e($who) ?><?php if ($parado): ?> · <span class="<?= $parado['late'] ? 'pn-meta__late' : '' ?>"><?= e($paradoText($parado)) ?></span><?php endif; ?></span>
                            </span>
                        </span>
                        <span class="pn-prio pn-prio--<?= (int)$prio ?><?= $isUrg && $prio < 3 ? ' is-urgent' : '' ?>">
                            <i class="fa-solid fa-circle" aria-hidden="true"></i><?= e($prioLabel[$prio] ?? 'Normal') ?>
                        </span>
                        <span class="pn-prazo pn-prazo--<?= e($dueKind ?: 'none') ?>">
                            <i class="fa-regular fa-clock" aria-hidden="true"></i><span><?= e($dueText) ?></span>
                        </span>
                        <span class="pn-status"><span class="status-pill status-pill--<?= e($st) ?>"><?= e($stText) ?></span></span>
                        <span class="pn-open" aria-hidden="true"><i class="fa-solid fa-chevron-right"></i></span>
                    </a>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="pn-empty">
                    <i class="fa-regular fa-folder-open" aria-hidden="true"></i>
                    <p><?= $hasFilter ? 'Nenhum pedido com esses filtros.' : 'Ainda não há pedidos.' ?></p>
                    <?php if ($hasFilter): ?><a class="btn btn--ghost" href="home">Limpar filtros</a><?php endif; ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="pn-sum">
            <span>Mostrando <strong><?= count($recent_requests) ?></strong> de <strong><?= (int)$totalRows ?></strong>
                <?php if ($view === 'atrasados'): ?>na lente de atrasados<?php elseif ($view === 'hoje'): ?>que vencem hoje<?php elseif ($view === 'emdia'): ?>em dia<?php endif; ?>
                · <strong><?= (int)$atrasadas ?></strong> atrasados no total
            </span>
            <span class="pn-sum__r"><?= e($scopeText) ?></span>
        </div>
    </section>

    <?php if ($totalPages > 1): ?>
        <nav class="pager" aria-label="Páginas">
            <?php if ($currentPageNum > 1): ?><a href="<?= e($pageLink($currentPageNum - 1)) ?>">Anterior</a><?php endif; ?>
            <span class="is-current"><?= $currentPageNum ?> de <?= $totalPages ?></span>
            <?php if ($currentPageNum < $totalPages): ?><a href="<?= e($pageLink($currentPageNum + 1)) ?>">Próxima</a><?php endif; ?>
        </nav>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/../components/request_peek.php'; ?>

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
