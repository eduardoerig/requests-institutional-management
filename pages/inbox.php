<?php
/**
 * Caixa do setor (UI 2.0): o que o setor precisa fazer, o que espera de outros setores e o que já saiu.
 * Celular: cartões empilhados com botão de ação rápida. Computador (≥900px): linhas tipo tabela
 * e quatro números no topo. Cada pedido abre na própria página, com Anterior/Próximo
 * (mesma ordem desta lista: RequestInbox::rowsFor).
 * Estilos: assets/css/pages/inbox.css · ações rápidas: assets/js/pages/inbox.js
 */
require_once __DIR__ . '/../classes/RequestInbox.php';
require_once __DIR__ . '/../components/request_card.php';
require_once __DIR__ . '/../components/welcome_card.php';

$role = $_SESSION['role'] ?? 'solicitante';
$userId = (int)$_SESSION['id'];
$isGlobalAdmin = isGlobalAdminRole($role);

if (!$isGlobalAdmin && !isGestorRole($role)) {
    echo '<div class="panel"><div class="empty-big"><i class="fa-solid fa-lock"></i><h2>Sem acesso</h2><p>A Caixa é dos setores que atendem os pedidos.</p><a class="btn" href="myRequests">Ir para meus pedidos</a></div></div>';
    return;
}

// Admin escolhe o setor; setor vê os seus
$allAreas = RequestForwardService::availableAreas($pdo);
$areaFilter = (int)($_GET['setor'] ?? 0);
$areaIds = RequestInbox::areasFor($pdo, $userId, $role, $areaFilter);
if (!in_array($areaFilter, $areaIds, true) || count($areaIds) > 1) {
    $areaFilter = 0;
}

$inbox = RequestInbox::build($pdo, $areaIds);
$counts = $inbox['counts'];
$summary = RequestInbox::summary($inbox);

$tab = (string)($_GET['tab'] ?? 'fazer');
$tab = RequestInbox::LEGACY_TABS[$tab] ?? $tab;
if (!isset(RequestInbox::TABS[$tab])) {
    $tab = 'fazer';
}
$q = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 100);
$searching = $q !== '';
$rows = RequestInbox::rowsFor($inbox, $tab, $q);

$listParams = array_filter(['tab' => $tab, 'setor' => $areaFilter ?: null, 'q' => $searching ? $q : null]);
$tabLink = fn(string $t) => 'inbox?' . http_build_query(array_filter(['tab' => $t, 'setor' => $areaFilter ?: null]));
$rowLink = fn(array $row) => 'request_detail?' . http_build_query(['id' => (int)$row['id'], 'table' => $row['table'], 'from' => 'inbox'] + $listParams);

// Começar e concluir em lote: só o setor, só em "Para fazer"
$canBulk = isGestorRole($role) && $tab === 'fazer' && !$searching;
$bulk = $canBulk ? [
    'start' => ['Começar atendimento', 'btn--primary', 'fa-play', 'novos'],
    'finish' => ['Concluir', 'btn--primary', 'fa-check-double', 'andamento'],
] : [];
$showSector = count($areaIds) > 1;

$areaName = $showSector ? '' : (string)($areaIds ? RequestForwardService::areaLabel($pdo, (int)$areaIds[0]) : '');

$cut = fn(string $s, int $max = 120) => mb_strlen($s) > $max ? rtrim(mb_substr($s, 0, $max - 1)) . '…' : $s;

/**
 * Como cada pedido aparece: selo de outro setor, linha de apoio, situação curta e ação rápida.
 * As ações usam os mesmos endpoints da página do pedido (inbox.js chama requestAct/forwardAct do script.js).
 */
$present = function (array $row) use ($tab, $cut): array {
    $rowTab = (string)($row['tab'] ?? $tab);
    $group = (string)($row['group'] ?? '');
    $fwd = $row['open_forward'] ?? null;
    [$context, $kind] = ($row['context'] ?? []) + ['', ''];
    $who = trim((string)($row['solicitor_name_formatted'] ?? ''));
    $people = implode(' · ', array_filter([$who !== '—' ? $who : '', trim((string)($row['subdivision_name'] ?? ''))]));
    $reason = trim((string)($row['urgent_reason'] ?? ''));
    $out = ['badge' => '', 'meta' => '', 'note' => ['', ''], 'action' => null];

    if ($rowTab === 'historico') {
        $out['meta'] = $context;
        $out['action'] = ['Ver resumo', '', 0, false];
        return $out;
    }
    if ($rowTab === 'esperando') {
        $out['badge'] = $fwd ? 'Com ' . $fwd['to_label'] : '';
        $out['meta'] = $people;
        $out['note'] = [$context, $kind];
        $out['action'] = ['Ver pedido', '', 0, false];
        return $out;
    }

    // Para fazer
    if ($group === 'chegaram' && $fwd) {
        $isHelp = $fwd['mode'] === 'help';
        $msg = trim((string)($fwd['observation'] ?? ''));
        $out['badge'] = ($isHelp ? 'Ajuda para ' : 'Transferência de ') . $fwd['from_label'];
        $out['meta'] = $fwd['from_label'] . ($isHelp ? ' pede' : ' quer transferir') . ($msg !== '' ? ': “' . $cut($msg) . '”' : '');
        $out['action'] = [$isHelp ? 'Aceitar ajuda' : 'Aceitar', 'accept', (int)$fwd['id'], true];
        return $out;
    }
    if ($group === 'ajudando' && $fwd) {
        $msg = trim((string)($fwd['observation'] ?? ''));
        $out['badge'] = 'Ajudando ' . $fwd['from_label'];
        $out['meta'] = $msg !== '' ? $fwd['from_label'] . ' pediu: “' . $cut($msg) . '”' : $people;
        $out['action'] = ['Devolver ajuda', 'return', (int)$fwd['id'], false];
        return $out;
    }
    $out['meta'] = implode(' · ', array_filter([$people, $reason !== '' ? '“' . $cut($reason, 90) . '”' : '']));
    $out['note'] = [$context, $kind];
    $out['action'] = $group === 'novos' ? ['Começar', 'start', 0, true] : ['Concluir', 'finish', 0, false];
    return $out;
};

$rowOpt = function (array $row) use ($rowLink, $showSector, $canBulk, $tab, $searching, $present): array {
    $p = $present($row);
    return [
        'href' => $rowLink($row),
        'sector' => $showSector ? requestSectorName((string)$row['table']) : '',
        'check' => $canBulk ? (in_array($row['group'] ?? '', ['novos', 'andamento'], true) ? $row['group'] : 'none') : '',
        'due' => ($row['tab'] ?? $tab) !== 'historico',
        'tab' => $searching ? RequestInbox::TABS[$row['tab']] : '',
        'badge' => $p['badge'],
        'meta' => $p['meta'],
        'note' => $p['note'],
        'action' => $p['action'],
    ];
};

// Faixas: só "Para fazer" (sem busca) é dividido — Urgentes, Outros setores, Chegaram, Em andamento
$groups = [];
if ($tab === 'fazer' && !$searching) {
    foreach (array_keys(RequestInbox::LANES) as $lane) {
        $groups[$lane] = [];
    }
    foreach ($rows as $row) {
        $groups[$row['lane'] ?? 'andamento'][] = $row;
    }
    $groups = array_filter($groups);
} elseif ($rows) {
    $groups[''] = $rows;
}

$emptyText = [
    'fazer' => 'Nada para fazer agora. Quando a coordenação aprovar um pedido do seu setor, ele aparece aqui.',
    'esperando' => 'Nenhum pedido esperando outro setor. Quando vocês encaminharem um pedido, ele fica aqui até a resposta.',
    'historico' => 'Nada por aqui ainda. Os pedidos que vocês concluírem ou transferirem ficam guardados nesta aba.',
][$tab];

$stats = [
    ['Para fazer', $summary['fazer'], '', 'fazer'],
    ['Urgentes', $summary['urgentes'], 'bad', 'fazer'],
    ['Atrasados', $summary['atrasados'], '', 'fazer'],
    ['Esperando outro setor', $summary['esperando'], '', 'esperando'],
];
?>
<div class="page-head page-head--hide-mobile inbox-head">
    <div>
        <?php if ($areaName !== ''): ?><p class="inbox-head__area">Setor: <?= e($areaName) ?></p><?php endif; ?>
        <h1><?= $isGlobalAdmin ? 'Caixa dos setores' : 'Caixa do setor' ?></h1>
    </div>
</div>
<?php if (!$isGlobalAdmin): ?><?= renderWelcomeCard($role, (int)$_SESSION['id']) ?><?php endif; ?>

<div class="inbox-stats" data-sync="inbox-stats" aria-label="Resumo da Caixa">
    <?php foreach ($stats as [$label, $value, $kind, $target]): ?>
        <a class="inbox-stat<?= $kind === 'bad' && $value > 0 ? ' inbox-stat--bad' : '' ?>" href="<?= e($tabLink($target)) ?>">
            <span class="inbox-stat__label"><?= e($label) ?></span>
            <span class="inbox-stat__value"><?= (int)$value ?></span>
        </a>
    <?php endforeach; ?>
</div>

<div class="toolbar inbox-toolbar">
    <nav class="tabs" aria-label="Caixa" data-sync="inbox-tabs">
        <?php foreach (RequestInbox::TABS as $key => $label):
            $on = $tab === $key && !$searching;
        ?>
            <a class="tab<?= $on ? ' is-active' : '' ?><?= $key === 'fazer' && $counts['fazer'] > 0 ? ' tab--hot' : '' ?>" href="<?= e($tabLink($key)) ?>"<?= $on ? ' aria-current="page"' : '' ?>>
                <?= e($label) ?><?php if ($key !== 'historico'): ?> <span><?= (int)$counts[$key] ?></span><?php endif; ?>
            </a>
        <?php endforeach; ?>
    </nav>
    <form method="get" action="inbox" class="toolbar__filters" role="search">
        <input type="hidden" name="tab" value="<?= e($tab) ?>">
        <?php if ($isGlobalAdmin): ?>
            <select name="setor" aria-label="Setor" onchange="this.form.submit()">
                <option value="">Todos os setores</option>
                <?php foreach ($allAreas as $a): ?>
                    <option value="<?= (int)$a['id'] ?>" <?= $areaFilter === (int)$a['id'] ? 'selected' : '' ?>><?= e($a['label']) ?></option>
                <?php endforeach; ?>
            </select>
        <?php endif; ?>
        <div class="search-box">
            <i class="fas fa-search" aria-hidden="true"></i>
            <input type="search" name="q" value="<?= e($q) ?>" placeholder="Buscar por número, título ou nome" aria-label="Buscar em todos os pedidos">
        </div>
    </form>
</div>

<section class="queue queue--cards<?= $showSector ? ' queue--sector' : '' ?>" data-queue data-url="<?= e('inbox?' . http_build_query($listParams)) ?>" aria-label="Pedidos">
    <?php if ($searching): ?>
        <p class="queue__note"><?= count($rows) === 1 ? '1 pedido encontrado' : count($rows) . ' pedidos encontrados' ?> para "<?= e($q) ?>" nas três abas. <a href="<?= e($tabLink($tab)) ?>">Limpar busca</a></p>
    <?php endif; ?>

    <?php if ($groups): ?>
        <?php if ($canBulk): ?>
            <div class="work-pickall">
                <label><input type="checkbox" data-pick-all> Selecionar todos</label>
                <span class="work-pickall__hint">para começar ou concluir vários de uma vez</span>
            </div>
        <?php endif; ?>
        <?php foreach ($groups as $group => $groupRows): ?>
            <section class="lane<?= $group !== '' ? ' lane--' . e($group) : '' ?>" <?= $group !== '' ? 'aria-labelledby="g-' . e($group) . '"' : '' ?>>
                <?php if ($group !== ''): [$laneTitle, $laneHint] = RequestInbox::LANES[$group]; ?>
                    <h2 class="lane__title" id="g-<?= e($group) ?>">
                        <?= e($laneTitle) ?> <span class="lane__count"><?= count($groupRows) ?></span>
                        <span class="lane__hint">· <?= e($laneHint) ?></span>
                    </h2>
                <?php endif; ?>
                <ul class="work-list">
                    <?php foreach ($groupRows as $row): ?>
                        <?= renderWorkRow($row, $rowOpt($row)) ?>
                    <?php endforeach; ?>
                </ul>
            </section>
        <?php endforeach; ?>
    <?php else: ?>
        <div class="empty-state"><i class="fa-regular fa-circle-check" aria-hidden="true"></i><p><?= e($searching ? 'Nada encontrado para "' . $q . '".' : $emptyText) ?></p></div>
    <?php endif; ?>

    <?php if ($bulk && $rows): ?><?= renderBulkBar($bulk) ?><?php endif; ?>
</section>
