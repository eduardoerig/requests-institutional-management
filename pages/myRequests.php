<?php
/**
 * Minhas requisições (UI 2.0): o que a pessoa pediu, em cartões.
 * Abas Em andamento / Concluídos; cada cartão diz em que etapa o pedido está e com quem.
 * No topo, a resposta mais recente de outra pessoa num pedido aberto (se houver).
 */
require_once __DIR__ . '/../classes/RequestInbox.php';
require_once __DIR__ . '/../components/my_request_card.php';
require_once __DIR__ . '/../components/welcome_card.php';

$userId = (int)$_SESSION['id'];
$q = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 100);
$searching = $q !== '';
$mine = RequestInbox::forRequester($pdo, $userId, $q);
$total = $mine['counts']['andamento'] + $mine['counts']['concluidas'];

$tabs = ['andamento' => 'Em andamento', 'concluidas' => 'Concluídos'];
$tab = (string)($_GET['tab'] ?? '');
if (!isset($tabs[$tab])) {
    $tab = $mine['counts']['andamento'] > 0 || $mine['counts']['concluidas'] === 0 ? 'andamento' : 'concluidas';
}

// Com busca, mostra as duas listas juntas, cada cartão dizendo de qual aba é
$rows = [];
if ($searching) {
    foreach ($tabs as $key => $label) {
        foreach ($mine[$key] as $row) {
            $row['tab_label'] = $label;
            $rows[] = $row;
        }
    }
} else {
    $rows = $mine[$tab];
}

// Destaque: última resposta de outra pessoa no primeiro pedido com resposta recente (a lista já vem com eles no topo)
$reply = null;
if (!$searching && $tab === 'andamento') {
    foreach ($mine['andamento'] as $row) {
        if (($row['group'] ?? '') !== 'resposta') {
            continue;
        }
        try {
            $stmt = $pdo->prepare('SELECT user_name, comment FROM request_comments WHERE request_table = ? AND request_id = ? AND user_id <> ? AND channel IN (\'public\', \'helper\') ORDER BY id DESC LIMIT 1');
            $stmt->execute([(string)$row['table'], (int)$row['id'], $userId]);
            $c = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            $c = false;
        }
        if ($c && trim((string)$c['comment']) !== '') {
            $reply = [
                'href' => 'request_detail?id=' . (int)$row['id'] . '&table=' . urlencode((string)$row['table']),
                'who' => explode(' ', trim((string)$c['user_name']))[0] ?: 'O setor',
                'id' => (int)$row['id'],
                'text' => mb_strimwidth(preg_replace('/\s+/', ' ', trim((string)$c['comment'])), 0, 160, '…'),
            ];
        }
        break;
    }
}

$tabLink = fn(string $t) => 'myRequests?tab=' . $t;
$emptyText = $tab === 'andamento'
    ? 'Nenhum pedido em andamento. Precisa de algo? Toque em Nova requisição.'
    : 'Nenhum pedido concluído ainda. Quando o setor terminar um pedido seu, ele vem para cá.';
$firstName = explode(' ', trim((string)($_SESSION['name'] ?? '')))[0];
?>
<div class="mr">
    <header class="mr-head">
        <div class="mr-head__text">
            <?php if ($firstName !== ''): ?><span class="mr-head__hello">Olá, <?= e($firstName) ?></span><?php endif; ?>
            <h1>Minhas requisições</h1>
        </div>
        <a href="new_request" class="btn btn--primary mr-head__new"><i class="fa-solid fa-plus" aria-hidden="true"></i> Nova requisição</a>
    </header>
    <?= renderWelcomeCard((string)($_SESSION['role'] ?? ''), $userId) ?>

<?php if ($total === 0 && !$searching): ?>
    <div class="empty-big card">
        <i class="fa-regular fa-folder-open" aria-hidden="true"></i>
        <h2>Você ainda não fez nenhum pedido</h2>
        <p>Precisa de algo da TI, da manutenção, de compras, da reprografia ou do marketing? Comece por aqui.</p>
        <a href="new_request" class="btn btn--primary"><i class="fa-solid fa-plus" aria-hidden="true"></i> Fazer um pedido</a>
    </div>
<?php else: ?>
    <div class="mr-bar">
        <nav class="tabs mr-tabs" aria-label="Minhas requisições">
            <?php foreach ($tabs as $key => $label):
                $on = $tab === $key && !$searching;
            ?>
                <a class="tab<?= $on ? ' is-active' : '' ?>" href="<?= e($tabLink($key)) ?>"<?= $on ? ' aria-current="page"' : '' ?>>
                    <?= e($label) ?> <span>· <?= (int)$mine['counts'][$key] ?></span>
                </a>
            <?php endforeach; ?>
        </nav>
        <form method="get" action="myRequests" class="mr-search" role="search">
            <input type="hidden" name="tab" value="<?= e($tab) ?>">
            <div class="search-box">
                <i class="fas fa-search" aria-hidden="true"></i>
                <input type="search" name="q" value="<?= e($q) ?>" placeholder="Buscar pelo título ou número" aria-label="Buscar pelo título ou número">
            </div>
        </form>
    </div>

    <?php if ($reply): ?>
        <a class="mr-reply" href="<?= e($reply['href']) ?>">
            <span class="mr-reply__who"><?= e($reply['who']) ?> respondeu no pedido nº <?= (int)$reply['id'] ?></span>
            <span class="mr-reply__text">“<?= e($reply['text']) ?>”</span>
            <span class="mr-reply__go">Ver conversa <i class="fa-solid fa-arrow-right" aria-hidden="true"></i></span>
        </a>
    <?php endif; ?>

    <section class="mr-list-wrap" aria-label="Pedidos">
        <?php if ($searching): ?>
            <p class="mr-note"><?= count($rows) === 1 ? '1 pedido encontrado' : count($rows) . ' pedidos encontrados' ?> para "<?= e($q) ?>". <a href="<?= e($tabLink($tab)) ?>">Limpar busca</a></p>
        <?php endif; ?>

        <?php if ($rows): ?>
            <ul class="mr-list">
                <?php foreach ($rows as $row): ?>
                    <?= renderMyRequestCard($row, ['tab' => $row['tab_label'] ?? '']) ?>
                <?php endforeach; ?>
            </ul>
        <?php else: ?>
            <div class="empty-state card"><i class="fa-regular fa-circle-check" aria-hidden="true"></i><p><?= e($searching ? 'Nenhum pedido com "' . $q . '" no título ou no número.' : $emptyText) ?></p></div>
        <?php endif; ?>
    </section>
<?php endif; ?>
</div>
