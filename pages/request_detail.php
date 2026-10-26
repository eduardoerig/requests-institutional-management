<?php
/**
 * pages/request_detail.php — página do pedido.
 * Aberto a partir de uma fila (from=inbox ou from=approve), mostra Anterior/Próximo na mesma
 * ordem da lista; depois de uma ação que tira o pedido da fila, o JS abre o próximo (data-next).
 */
require_once __DIR__ . '/../components/request_view.php';
require_once __DIR__ . '/../classes/RequestInbox.php';

$req_id = (int)($_GET['id'] ?? 0);
$req_table = normalizeRequestTable((string)($_GET['table'] ?? ''));
$req = null;
if ($req_id > 0 && $req_table) {
    $stmt = $pdo->prepare("SELECT r.*, u.name AS creator_name, sub.name AS subdivision_name FROM `ctd_{$req_table}_frm` r LEFT JOIN ctd_users u ON u.id = r.created_by LEFT JOIN ctd_subdivision sub ON sub.id = r.subdivision_id WHERE r.id = ?");
    $stmt->execute([$req_id]);
    $req = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

$role = $_SESSION['role'] ?? 'solicitante';
$userId = (int)$_SESSION['id'];
$backHref = $role === 'solicitante' ? 'myRequests' : (isGestorRole($role) ? 'inbox' : 'home');
if (!$req) {
    echo '<div class="panel"><div class="empty-big"><i class="fa-solid fa-circle-question"></i><h2>Pedido não encontrado</h2><p>Ele pode ter sido cancelado.</p><a class="btn" href="' . e($backHref) . '"><i class="fa-solid fa-arrow-left"></i> Voltar</a></div></div>';
    return;
}
if (!userCanViewRequest($pdo, $req, $req_table, $userId, $role)) {
    echo '<div class="panel"><div class="empty-big"><i class="fa-solid fa-lock"></i><h2>Sem acesso</h2><p>Você não tem permissão para ver este pedido.</p><a class="btn" href="' . e($backHref) . '"><i class="fa-solid fa-arrow-left"></i> Voltar</a></div></div>';
    return;
}

markRequestNotificationsRead($pdo, $userId, $req_table, $req_id);

// Fila de onde o pedido foi aberto: mesma lista, mesma ordem
$from = (string)($_GET['from'] ?? '');
$queue = null;
$backLabel = 'Voltar';
$listParams = [];
if ($from === 'inbox' && (isGestorRole($role) || isGlobalAdminRole($role))) {
    $tab = (string)($_GET['tab'] ?? 'fazer');
    $tab = isset(RequestInbox::TABS[$tab]) ? $tab : 'fazer';
    $areaFilter = (int)($_GET['setor'] ?? 0);
    $q = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 100);
    $listParams = array_filter(['tab' => $tab, 'setor' => $areaFilter ?: null, 'q' => $q !== '' ? $q : null]);
    $queue = RequestInbox::rowsFor(RequestInbox::build($pdo, RequestInbox::areasFor($pdo, $userId, $role, $areaFilter)), $tab, $q);
    $backHref = 'inbox?' . http_build_query($listParams);
    $backLabel = $q !== '' ? 'Caixa, busca' : 'Caixa, ' . RequestInbox::TABS[$tab];
} elseif ($from === 'approve' && (isGlobalAdminRole($role) || $role === 'adm_sub')) {
    $sector = (string)($_GET['setor'] ?? '');
    $q = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 100);
    $listParams = array_filter(['setor' => $sector !== '' ? $sector : null, 'q' => $q !== '' ? $q : null]);
    $queue = RequestInbox::approvals($pdo, $role === 'adm_sub' ? ($_SESSION['subdivision_ids'] ?? null) : null, $sector, $q)['rows'];
    $backHref = 'approve' . ($listParams ? '?' . http_build_query($listParams) : '');
    $backLabel = 'Aprovar pedidos';
}

$prev = $next = null;
$position = false;
if ($queue !== null) {
    $keys = array_map(fn($r) => $r['table'] . ':' . $r['id'], $queue);
    $position = array_search($req_table . ':' . $req_id, $keys, true);
    if ($position === false) {
        $next = $queue[0] ?? null; // o pedido já saiu da fila: o próximo é o primeiro dela
    } else {
        $prev = $queue[$position - 1] ?? null;
        $next = $queue[$position + 1] ?? null;
    }
}
$queueLink = fn(array $r) => 'request_detail?' . http_build_query(['id' => (int)$r['id'], 'table' => $r['table'], 'from' => $from] + $listParams);
?>
<div class="detail-page" data-home="<?= e($backHref) ?>"<?php if ($queue !== null): ?> data-back="<?= e($backHref) ?>"<?= $next ? ' data-next="' . e($queueLink($next)) . '"' : '' ?><?php endif; ?>>
    <nav class="queue-nav" aria-label="<?= $queue !== null ? 'Fila de pedidos' : 'Navegação' ?>">
        <a href="<?= e($backHref) ?>" class="back-link"><i class="fa-solid fa-arrow-left" aria-hidden="true"></i> <?= e($backLabel) ?></a>
        <?php if ($queue !== null && $queue): ?>
            <span class="queue-nav__pager">
                <?php if ($prev): ?>
                    <a class="btn btn--sm btn--ghost" href="<?= e($queueLink($prev)) ?>" rel="prev"><i class="fa-solid fa-chevron-left" aria-hidden="true"></i> Anterior</a>
                <?php endif; ?>
                <?php if ($position !== false): ?>
                    <span class="queue-nav__pos"><?= $position + 1 ?> de <?= count($queue) ?></span>
                <?php endif; ?>
                <?php if ($next): ?>
                    <a class="btn btn--sm btn--ghost" href="<?= e($queueLink($next)) ?>" rel="next">Próximo <i class="fa-solid fa-chevron-right" aria-hidden="true"></i></a>
                <?php endif; ?>
            </span>
        <?php endif; ?>
    </nav>
    <?php renderRequestView($pdo, $req, $req_table, 'page'); ?>
</div>
