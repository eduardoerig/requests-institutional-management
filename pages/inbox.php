<?php
/**
 * Caixa do setor (tela 2b): cada aba diz o que o setor faz com o pedido
 * (Aceitar, Começar, Em andamento, Esperando, Histórico). Topo com Urgentes/Atrasados (viram filtro),
 * abas e busca; corpo em duas colunas: lista agrupada por prazo e painel do pedido selecionado.
 * No celular o painel vira tela cheia. A ordem da lista é a de RequestInbox::rowsFor, a mesma do
 * Anterior/Próximo da página do pedido.
 * Peças: components/inbox_item.php · estilos: assets/css/pages/inbox.css · seleção e ações: assets/js/pages/inbox.js
 * A lista ([data-queue]) e os [data-sync] (abas, números, lista) são redesenhados no lugar por refreshAfterChange().
 */
require_once __DIR__ . '/../classes/RequestInbox.php';
require_once __DIR__ . '/../components/inbox_item.php';
require_once __DIR__ . '/../components/welcome_card.php';

$role = $_SESSION['role'] ?? 'solicitante';
$userId = (int)$_SESSION['id'];
$isGlobalAdmin = isGlobalAdminRole($role);

if (!$isGlobalAdmin && !isGestorRole($role)) {
    echo '<div class="panel"><div class="empty-big"><i class="fa-solid fa-lock"></i><h2>Sem acesso</h2><p>A Caixa é dos setores que atendem os pedidos.</p><a class="btn" href="myRequests">Meus pedidos</a></div></div>';
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

$q = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 100);
$searching = $q !== '';
$filter = (string)($_GET['filtro'] ?? '');
$filter = isset(RequestInbox::FILTERS[$filter]) && !$searching ? $filter : '';
$tab = RequestInbox::normalizeTab((string)($_GET['tab'] ?? ''));
if ($tab === '') {
    $tab = RequestInbox::defaultTab($inbox, $filter);
}
if (!in_array($tab, RequestInbox::ACTION_TABS, true)) {
    $filter = ''; // o filtro vale só nas três etapas de ação
}
$rows = RequestInbox::rowsFor($inbox, $tab, $q, $filter);

$setorParam = $areaFilter ?: null;
$listParams = array_filter(['tab' => $tab, 'setor' => $setorParam, 'filtro' => $filter ?: null, 'q' => $searching ? $q : null]);
// Trocar de aba limpa a busca; o filtro segue só entre as abas de ação
$tabLink = fn(string $t) => 'inbox?' . http_build_query(array_filter([
    'tab' => $t, 'setor' => $setorParam,
    'filtro' => $filter !== '' && in_array($t, RequestInbox::ACTION_TABS, true) ? $filter : null,
]));
$rowLink = fn(array $row) => 'request_detail?' . http_build_query(['id' => (int)$row['id'], 'table' => $row['table'], 'from' => 'inbox'] + $listParams);

$showSector = count($areaIds) > 1;
$areaName = $showSector ? '' : (string)($areaIds ? RequestForwardService::areaLabel($pdo, (int)$areaIds[0]) : '');

// Lote: só o gestor, só em Começar e Em andamento, sem busca
$canBulk = isGestorRole($role) && in_array($tab, ['comecar', 'andamento'], true) && !$searching;

$cut = fn(string $s, int $max = 140) => mb_strlen($s) > $max ? rtrim(mb_substr($s, 0, $max - 1)) . '…' : $s;
$fmtDay = fn(string $when) => $when !== '' ? date('d/m', strtotime($when)) : '';

/**
 * Como cada pedido aparece: próximo passo (linha), selos, notas, situação e ação (painel).
 * As ações usam os mesmos endpoints da página do pedido (inbox.js chama requestAct/forwardAct do script.js).
 */
$present = function (array $row) use ($cut, $fmtDay, $canBulk): array {
    $rowTab = (string)$row['tab'];
    $group = (string)($row['group'] ?? '');
    $fwd = $row['open_forward'] ?? null;
    [$context, $kind] = ($row['context'] ?? []) + ['', ''];
    $reason = trim((string)($row['urgent_reason'] ?? ''));
    $msg = $fwd ? trim((string)($fwd['observation'] ?? '')) : '';
    $out = [
        'next' => ['', ''], 'status' => ['', ''], 'badge' => '', 'notes' => [],
        'situation' => [$context, $kind], 'action' => null, 'check' => '', 'due' => true,
    ];
    if ($reason !== '') {
        $out['notes'][] = 'Motivo da urgência: ' . $cut($reason);
    }

    switch ($rowTab) {
        case 'aceitar':
            $isHelp = $fwd && $fwd['mode'] === 'help';
            $from = $fwd['from_label'] ?? '';
            $isPartner = $fwd && RequestForwardService::isPartner($fwd);
            $acceptLabel = $isPartner ? 'Aceitar a parte' : ($isHelp ? 'Aceitar ajuda' : 'Aceitar e assumir');
            $out['next'] = [$acceptLabel, 'go'];
            $out['status'] = ['aguardando', 'Aguardando aceite'];
            $out['badge'] = $isPartner ? 'Parceiro de ' . $from : ($isHelp ? 'Ajuda para ' : 'Transferência de ') . $from;
            if ($msg !== '') {
                array_unshift($out['notes'], ($isPartner ? 'A coordenação' : $from) . ' pede: “' . $cut($msg) . '”');
            }
            if ($fwd) {
                $out['action'] = [$acceptLabel, $isPartner ? 'partner' : ($isHelp ? 'accept' : 'assume'), (int)$fwd['id'], true];
            }
            break;
        case 'comecar':
            $out['next'] = ['Começar', 'go'];
            $out['status'] = !empty($row['is_fresh']) ? ['chegou', 'Chegou agora'] : ['novo', 'Novo'];
            if ($context === '') {
                $day = $fmtDay((string)($row['approved_at'] ?? ''));
                $out['situation'] = ['Aprovado pela coordenação' . ($day !== '' ? ' em ' . $day : ''), ''];
            }
            $out['action'] = ['Começar atendimento', 'start', 0, true];
            $out['check'] = $canBulk ? 'novos' : '';
            break;
        case 'andamento':
            if ($group === 'ajudando') {
                $from = $fwd['from_label'] ?? '';
                $isPartner = $fwd && RequestForwardService::isPartner($fwd);
                $out['next'] = [$isPartner ? 'Sua parte · devolver' : 'Ajudando · devolver', 'go'];
                $out['status'] = ['ajuda', $isPartner ? 'Fazendo a parte' : 'Ajudando'];
                $out['badge'] = $isPartner ? 'Parceiro de ' . $from : 'Ajuda para ' . $from;
                if ($msg !== '') {
                    array_unshift($out['notes'], ($isPartner ? 'A coordenação' : $from) . ' pede: “' . $cut($msg) . '”');
                }
                if ($fwd) {
                    $out['action'] = [$isPartner ? 'Devolver a parte' : 'Devolver ajuda', 'return', (int)$fwd['id'], false];
                }
                $out['check'] = $canBulk ? 'none' : '';
            } elseif ($fwd && RequestForwardService::isPartner($fwd)) {
                // O principal só conclui depois que o parceiro devolver: sem botão que daria erro
                $names = (string)($row['partner_names'] ?? $fwd['to_label']);
                $out['next'] = ['Aguardar ' . $names . ' para concluir', 'wait'];
                $out['status'] = ['andamento', 'Em atendimento'];
                $out['badge'] = 'Com ' . $names;
                $out['check'] = $canBulk ? 'none' : '';
            } else {
                $out['next'] = ['Concluir', 'go'];
                $out['status'] = ['andamento', 'Em atendimento'];
                $out['action'] = ['Concluir', 'finish', 0, false];
                $out['check'] = $canBulk ? 'andamento' : '';
            }
            break;
        case 'esperando':
            $to = $fwd['to_label'] ?? '';
            $out['next'] = ['Aguardar ' . $to, 'wait'];
            $out['status'] = match (true) {
                $fwd && $fwd['mode'] === 'help' && $fwd['status'] === 'accepted' => ['ajuda', 'Com ajuda'],
                $fwd && $fwd['mode'] === 'transfer' => ['aguardando', 'Aguardando aceite'],
                default => ['aguardando', 'Aguardando resposta'],
            };
            $out['badge'] = $to !== '' ? 'Com ' . $to : '';
            break;
        default: // historico
            $out['next'] = [$context, 'done'];
            $out['status'] = str_starts_with($context, 'Transferido') ? ['transferido', 'Transferido'] : ['concluido', 'Concluído'];
            $out['due'] = false;
    }
    return $out;
};

$ownerLabel = fn(array $row) => RequestForwardService::areaLabel($pdo, (int)$row['owner_area_id']);
$item = function (array $row) use ($present, $rowLink, $ownerLabel, $areaIds, $showSector, $searching): array {
    return $present($row) + [
        'key' => $row['table'] . ':' . (int)$row['id'],
        'href' => $rowLink($row),
        'owner' => $ownerLabel($row),
        // O mesmo número existe em cada setor: o dono aparece quando não é o seu setor ou há vários na tela
        'showOwner' => $showSector || !in_array((int)$row['owner_area_id'], $areaIds, true),
        'tabLabel' => $searching ? RequestInbox::TABS[$row['tab']] : '',
    ];
};

// Montado uma vez por pedido: a linha e o painel usam o mesmo
$items = [];
foreach ($rows as $row) {
    $items[$row['table'] . ':' . (int)$row['id']] = $item($row);
}

// Grupos da lista: por prazo (vazios somem); histórico num grupo só; na busca, o histórico vem no fim
$groups = [];
foreach ($rows as $row) {
    $g = $row['tab'] === 'historico' ? 'historico' : $row['due_group'];
    $groups[$g][] = $row;
}
$groupTitles = RequestInbox::DUE_GROUPS + ['historico' => $searching ? 'No histórico' : 'Mais recentes'];
$groups = array_filter(array_replace(array_fill_keys(array_keys($groupTitles), []), $groups));

$emptyText = match (true) {
    $searching => 'Nada com “' . $q . '” em nenhuma aba.',
    $filter !== '' => 'Nenhum pedido desta etapa se encaixa aqui. Veja as outras abas.',
    default => [
        'aceitar' => 'Pedidos de ajuda e transferências de outros setores aparecem aqui.',
        'comecar' => 'Quando a coordenação aprovar um pedido do seu setor, ele aparece aqui.',
        'andamento' => 'Nada em atendimento agora.',
        'esperando' => 'Quando vocês pedirem ajuda ou encaminharem um pedido, ele fica aqui até o outro setor responder.',
        'historico' => 'Pedidos concluídos e transferidos para outros setores ficam guardados aqui.',
    ][$tab],
};

// Números do topo: clicar liga o filtro e leva à primeira etapa que tem pedidos desse tipo; clicar de novo desliga
$statLink = function (string $f) use ($filter, $tab, $inbox, $setorParam): string {
    if ($filter === $f) {
        return 'inbox?' . http_build_query(array_filter(['tab' => $tab, 'setor' => $setorParam]));
    }
    return 'inbox?' . http_build_query(array_filter(['tab' => RequestInbox::defaultTab($inbox, $f), 'setor' => $setorParam, 'filtro' => $f]));
};
$tabCount = fn(string $t) => $filter !== '' && isset($summary['byTab'][$t]) ? $summary['byTab'][$t][$filter] : (int)$counts[$t];
?>
<div class="inbox-top">
<div class="page-head page-head--hide-mobile inbox-head">
    <div>
        <?php if ($areaName !== ''): ?><p class="inbox-head__area"><?= e($areaName) ?></p><?php endif; ?>
        <h1><?= $isGlobalAdmin ? 'Caixa dos setores' : 'Caixa do setor' ?></h1>
    </div>
</div>
    <div class="inbox-stats" data-sync="inbox-stats" aria-label="Pedidos que pedem atenção">
        <?php foreach (['urgentes' => 'Urgentes', 'atrasados' => 'Atrasados'] as $f => $label):
            $n = (int)$summary[$f];
            $on = $filter === $f;
        ?>
            <a class="inbox-stat inbox-stat--<?= e($f) ?><?= $n > 0 ? ' has-items' : '' ?><?= $on ? ' is-on' : '' ?>" href="<?= e($statLink($f)) ?>"
               aria-pressed="<?= $on ? 'true' : 'false' ?>">
                <span class="inbox-stat__value"><?= $n ?></span>
                <span class="inbox-stat__label"><?= e($label) ?></span>
                <span class="visually-hidden"><?= $on ? '(mostrar tudo)' : '(mostrar só estes)' ?></span>
            </a>
        <?php endforeach; ?>
    </div>
</div>

<div class="toolbar inbox-toolbar">
    <nav class="tabs inbox-tabs" aria-label="Etapas da Caixa" data-sync="inbox-tabs">
        <?php foreach (RequestInbox::TABS as $key => $label):
            $on = $tab === $key && !$searching;
            $n = $tabCount($key);
            $hot = in_array($key, RequestInbox::ACTION_TABS, true) && $n > 0;
        ?>
            <a class="tab<?= $on ? ' is-active' : '' ?><?= $hot ? ' tab--hot' : '' ?>" href="<?= e($tabLink($key)) ?>"<?= $on ? ' aria-current="page"' : '' ?>>
                <?= e($label) ?><?php if ($key !== 'historico'): ?> <span><?= $n ?></span><?php endif; ?>
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
            <input type="search" name="q" value="<?= e($q) ?>" placeholder="Buscar por número, título ou nome" aria-label="Buscar em todas as abas">
        </div>
    </form>
</div>

<section class="inbox-body<?= $rows ? '' : ' is-empty' ?>" data-queue data-tab="<?= e($searching ? '' : $tab) ?>" data-sync="inbox-list"
         data-url="<?= e('inbox?' . http_build_query($listParams)) ?>" aria-label="Pedidos">
    <?php if ($searching): ?>
        <p class="inbox-note"><?= count($rows) === 1 ? '1 pedido encontrado' : count($rows) . ' pedidos encontrados' ?> em todas as abas. <a href="<?= e($tabLink($tab)) ?>">Limpar busca</a></p>
    <?php elseif ($filter !== ''): ?>
        <p class="inbox-note">Mostrando só <?= e($filter) ?>. <a href="<?= e('inbox?' . http_build_query(array_filter(['tab' => $tab, 'setor' => $setorParam]))) ?>">Ver tudo</a></p>
    <?php endif; ?>

    <?php if ($rows): ?>
        <div class="inbox-cols">
            <div class="inbox-list" data-list>
                <?php if ($canBulk && array_filter($rows, fn($r) => $r['group'] !== 'ajudando')): ?>
                    <label class="inbox-pickall"><input type="checkbox" data-pick-all> Selecionar todos</label>
                <?php endif; ?>
                <?php foreach ($groups as $g => $groupRows): ?>
                    <section class="inbox-group inbox-group--<?= e($g) ?>" aria-labelledby="g-<?= e($g) ?>">
                        <h2 class="inbox-group__title" id="g-<?= e($g) ?>"><?= e($groupTitles[$g]) ?> <span><?= count($groupRows) ?></span></h2>
                        <ul class="inbox-rows">
                            <?php foreach ($groupRows as $row): ?>
                                <?= renderInboxRow($row, $items[$row['table'] . ':' . (int)$row['id']]) ?>
                            <?php endforeach; ?>
                        </ul>
                    </section>
                <?php endforeach; ?>
            </div>
            <div class="inbox-panel" data-panel aria-live="polite">
                <?php foreach ($groups as $groupRows): ?>
                    <?php foreach ($groupRows as $row): ?>
                        <?= renderInboxDetail($row, $items[$row['table'] . ':' . (int)$row['id']]) ?>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            </div>
        </div>
    <?php else: ?>
        <div class="empty-state"><i class="fa-regular fa-circle-check" aria-hidden="true"></i><p><?= e($emptyText) ?></p></div>
    <?php endif; ?>

    <?php if ($canBulk && $rows): ?>
        <?= renderBulkBar([
            'start' => ['Começar atendimento', 'btn--primary', 'fa-play', 'novos'],
            'finish' => ['Concluir', 'btn--primary', 'fa-check-double', 'andamento'],
        ]) ?>
    <?php endif; ?>
</section>

<?php // Boas-vindas depois da lista: o trabalho aparece primeiro, principalmente no celular ?>
<?php if (!$isGlobalAdmin): ?><?= renderWelcomeCard($role, (int)$_SESSION['id']) ?><?php endif; ?>
