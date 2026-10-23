<?php
/**
 * Caixa do setor: o que o setor precisa fazer, o que espera de outros setores e o que já saiu.
 * A lista ocupa a tela toda; cada pedido abre na própria página, com Anterior/Próximo.
 */
require_once __DIR__ . '/../classes/RequestInbox.php';
require_once __DIR__ . '/../components/request_card.php';

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
$rowOpt = fn(array $row) => [
    'href' => $rowLink($row),
    'sector' => $showSector,
    'check' => $canBulk ? (in_array($row['group'] ?? '', ['novos', 'andamento'], true) ? $row['group'] : 'none') : '',
    'due' => ($row['tab'] ?? $tab) !== 'historico',
    'tab' => $searching ? RequestInbox::TABS[$row['tab']] : '',
];

// Grupos: só "Para fazer" (sem busca) é dividido
$groups = [];
if ($tab === 'fazer' && !$searching) {
    foreach ($rows as $row) {
        $groups[$row['group']][] = $row;
    }
} elseif ($rows) {
    $groups[''] = $rows;
}

$emptyText = [
    'fazer' => 'Nada para fazer agora. Quando a coordenação aprovar um pedido do seu setor, ele aparece aqui.',
    'esperando' => 'Nenhum pedido esperando outro setor.',
    'historico' => 'Nenhum pedido concluído ou transferido ainda.',
][$tab];
?>
<div class="page-head page-head--hide-mobile">
    <h1><?= $isGlobalAdmin ? 'Caixa dos setores' : 'Caixa do setor' ?></h1>
</div>

<div class="toolbar">
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
            <input type="search" name="q" value="<?= e($q) ?>" placeholder="Buscar em todos os pedidos" aria-label="Buscar em todos os pedidos">
        </div>
    </form>
</div>

<section class="queue<?= $showSector ? ' queue--sector' : '' ?>" data-queue data-url="<?= e('inbox?' . http_build_query($listParams)) ?>" aria-label="Pedidos">
    <?php if ($searching): ?>
        <p class="queue__note"><?= count($rows) === 1 ? '1 pedido encontrado' : count($rows) . ' pedidos encontrados' ?> para "<?= e($q) ?>" nas três abas. <a href="<?= e($tabLink($tab)) ?>">Limpar busca</a></p>
    <?php endif; ?>

    <?php if ($groups): ?>
        <?= renderQueueHead(['sector' => $showSector, 'check' => $canBulk ? 'all' : '', 'contextLabel' => $tab === 'historico' ? 'Como saiu' : 'Situação', 'dueLabel' => $tab === 'historico' ? '' : 'Prazo']) ?>
        <?php foreach ($groups as $group => $groupRows): ?>
            <section class="queue-group" <?= $group !== '' ? 'aria-labelledby="g-' . e($group) . '"' : '' ?>>
                <?php if ($group !== ''): ?>
                    <h2 class="queue-group__title" id="g-<?= e($group) ?>"><?= e(RequestInbox::GROUPS[$group]) ?> <span><?= count($groupRows) ?></span></h2>
                <?php endif; ?>
                <ul class="queue-list">
                    <?php foreach ($groupRows as $row): ?>
                        <?= renderQueueRow($row, $rowOpt($row)) ?>
                    <?php endforeach; ?>
                </ul>
            </section>
        <?php endforeach; ?>
    <?php else: ?>
        <div class="empty-state"><i class="fa-regular fa-circle-check" aria-hidden="true"></i><p><?= e($searching ? 'Nada encontrado para "' . $q . '".' : $emptyText) ?></p></div>
    <?php endif; ?>

    <?php if ($bulk && $rows): ?><?= renderBulkBar($bulk) ?><?php endif; ?>
</section>
