<?php
/**
 * Minhas requisições: o que a pessoa pediu, na mesma lista em colunas da Caixa.
 * Em andamento (agrupado por de quem é a vez) e Concluídas; a frase de cada linha diz onde o pedido está.
 */
require_once __DIR__ . '/../classes/RequestInbox.php';
require_once __DIR__ . '/../components/request_card.php';

$userId = (int)$_SESSION['id'];
$q = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 100);
$searching = $q !== '';
$mine = RequestInbox::forRequester($pdo, $userId, $q);
$total = $mine['counts']['andamento'] + $mine['counts']['concluidas'];

$tabs = ['andamento' => 'Em andamento', 'concluidas' => 'Concluídas'];
$tab = (string)($_GET['tab'] ?? '');
if (!isset($tabs[$tab])) {
    $tab = $mine['counts']['andamento'] > 0 || $mine['counts']['concluidas'] === 0 ? 'andamento' : 'concluidas';
}

// Com busca, mostra as duas listas juntas, cada linha dizendo de qual aba é
$groups = [];
if ($searching) {
    foreach ($tabs as $key => $label) {
        foreach ($mine[$key] as $row) {
            $row['tab_label'] = $label;
            $groups[''][] = $row;
        }
    }
} elseif ($tab === 'andamento') {
    foreach ($mine['andamento'] as $row) {
        $groups[$row['group']][] = $row;
    }
} elseif ($mine['concluidas']) {
    $groups[''] = $mine['concluidas'];
}
$tabLink = fn(string $t) => 'myRequests?tab=' . $t;
$emptyText = $tab === 'andamento' ? 'Nenhum pedido em andamento.' : 'Nenhum pedido concluído ainda.';
?>
<div class="page-head page-head--hide-mobile">
    <h1>Minhas requisições</h1>
    <a href="new_request" class="btn btn--primary"><i class="fa-solid fa-plus" aria-hidden="true"></i> Nova requisição</a>
</div>

<?php if ($total === 0 && !$searching): ?>
    <div class="empty-big panel">
        <i class="fa-regular fa-folder-open" aria-hidden="true"></i>
        <h2>Você ainda não fez nenhum pedido</h2>
        <p>Precisa de algo da TI, da manutenção, de compras, da reprografia ou do marketing? Comece por aqui.</p>
        <a href="new_request" class="btn btn--primary"><i class="fa-solid fa-plus" aria-hidden="true"></i> Fazer um pedido</a>
    </div>
<?php else: ?>
    <div class="toolbar">
        <nav class="tabs" aria-label="Minhas requisições">
            <?php foreach ($tabs as $key => $label):
                $on = $tab === $key && !$searching;
            ?>
                <a class="tab<?= $on ? ' is-active' : '' ?>" href="<?= e($tabLink($key)) ?>"<?= $on ? ' aria-current="page"' : '' ?>>
                    <?= e($label) ?><?php if ($key === 'andamento'): ?> <span><?= (int)$mine['counts'][$key] ?></span><?php endif; ?>
                </a>
            <?php endforeach; ?>
        </nav>
        <form method="get" action="myRequests" class="toolbar__filters" role="search">
            <input type="hidden" name="tab" value="<?= e($tab) ?>">
            <div class="search-box">
                <i class="fas fa-search" aria-hidden="true"></i>
                <input type="search" name="q" value="<?= e($q) ?>" placeholder="Buscar pelo título ou número" aria-label="Buscar pelo título ou número">
            </div>
        </form>
    </div>

    <section class="queue queue--mine" aria-label="Pedidos">
        <?php if ($searching): ?>
            <p class="queue__note"><?= count($groups[''] ?? []) === 1 ? '1 pedido encontrado' : count($groups[''] ?? []) . ' pedidos encontrados' ?> para "<?= e($q) ?>". <a href="<?= e($tabLink($tab)) ?>">Limpar busca</a></p>
        <?php endif; ?>

        <?php if ($groups): ?>
            <?= renderQueueHead(['contextLabel' => 'Onde está', 'dueLabel' => $tab === 'concluidas' && !$searching ? '' : 'Para quando']) ?>
            <?php foreach ($groups as $group => $groupRows): ?>
                <section class="queue-group" <?= $group !== '' ? 'aria-labelledby="g-' . e($group) . '"' : '' ?>>
                    <?php if ($group !== ''): ?>
                        <h2 class="queue-group__title" id="g-<?= e($group) ?>"><?= e(RequestInbox::MINE_GROUPS[$group]) ?> <span><?= count($groupRows) ?></span></h2>
                    <?php endif; ?>
                    <ul class="queue-list">
                        <?php foreach ($groupRows as $row): ?>
                            <?= renderQueueRow($row, [
                                'due' => !in_array($row['st_raw'], ['C', 'N'], true),
                                'tab' => $row['tab_label'] ?? '',
                            ]) ?>
                        <?php endforeach; ?>
                    </ul>
                </section>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="empty-state"><i class="fa-regular fa-circle-check" aria-hidden="true"></i><p><?= e($searching ? 'Nenhum pedido com "' . $q . '" no título ou no número.' : $emptyText) ?></p></div>
        <?php endif; ?>
    </section>
<?php endif; ?>
