<?php
/**
 * Aprovar pedidos: a lista ocupa a tela toda; cada pedido abre na própria página.
 * Aprovar ou recusar abre o próximo da fila (mesma ordem do Anterior/Próximo).
 */
require_once __DIR__ . '/../classes/RequestInbox.php';
require_once __DIR__ . '/../components/request_card.php';

$role = $_SESSION['role'] ?? 'solicitante';
$isAdmin = isGlobalAdminRole($role);
$isAdmSub = ($role === 'adm_sub');

if (!$isAdmin && !$isAdmSub) {
    echo '<div class="panel"><div class="empty-big"><i class="fa-solid fa-lock"></i><h2>Sem acesso</h2><p>Somente a coordenação e os administradores aprovam pedidos.</p><a class="btn" href="home"><i class="fa-solid fa-arrow-left"></i> Voltar ao início</a></div></div>';
    return;
}

// Coordenação aprova só a própria subdivisão; admin geral aprova tudo
$subdivisionFilter = $isAdmSub ? ($_SESSION['subdivision_ids'] ?? null) : null;
$sector = (string)($_GET['setor'] ?? '');
$q = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 100);
$approvals = RequestInbox::approvals($pdo, $subdivisionFilter, $sector, $q);
$rows = $approvals['rows'];
$total = count($approvals['all']);

$sectors = [];
foreach ($approvals['all'] as $r) {
    $sectors[$r['table']] = requestSectorName($r['table']);
}
asort($sectors);

$listParams = array_filter(['setor' => $sector !== '' ? $sector : null, 'q' => $q !== '' ? $q : null]);
$rowLink = fn(array $row) => 'request_detail?' . http_build_query(['id' => (int)$row['id'], 'table' => $row['table'], 'from' => 'approve'] + $listParams);
?>
<div class="page-head page-head--hide-mobile">
    <h1>Aprovar pedidos</h1>
</div>

<div class="toolbar" data-sync="approve-sub"<?= $total === 0 ? ' hidden' : '' ?>>
    <p class="toolbar__text"><?= $total === 1 ? '1 pedido esperando sua decisão.' : $total . ' pedidos esperando sua decisão.' ?></p>
    <?php if ($total > 0): ?>
    <form method="get" action="approve" class="toolbar__filters" role="search">
        <?php if (count($sectors) > 1): ?>
            <select name="setor" aria-label="Setor" onchange="this.form.submit()">
                <option value="">Todos os setores</option>
                <?php foreach ($sectors as $slug => $label): ?>
                    <option value="<?= e($slug) ?>" <?= $sector === $slug ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        <?php endif; ?>
        <div class="search-box">
            <i class="fas fa-search" aria-hidden="true"></i>
            <input type="search" name="q" value="<?= e($q) ?>" placeholder="Título, número ou nome" aria-label="Buscar">
        </div>
    </form>
    <?php endif; ?>
</div>

<section class="queue queue--sector" data-queue data-url="<?= e('approve' . ($listParams ? '?' . http_build_query($listParams) : '')) ?>" aria-label="Pedidos aguardando aprovação">
    <?php if ($q !== '' || $sector !== ''): ?>
        <p class="queue__note"><?= count($rows) ?> de <?= $total ?> pedidos. <a href="approve">Limpar filtros</a></p>
    <?php endif; ?>
    <?php if ($rows): ?>
        <?= renderQueueHead(['sector' => true, 'check' => 'all', 'contextLabel' => 'Enviado']) ?>
        <ul class="queue-list">
            <?php foreach ($rows as $row): ?>
                <?= renderQueueRow($row, ['href' => $rowLink($row), 'sector' => true, 'check' => 'approve']) ?>
            <?php endforeach; ?>
        </ul>
        <?= renderBulkBar(['approve' => ['Aprovar', 'btn--primary', 'fa-check'], 'refuse' => ['Recusar', '', 'fa-xmark']]) ?>
    <?php else: ?>
        <div class="empty-state"><i class="fa-regular fa-circle-check" aria-hidden="true"></i><p><?= $total === 0 ? 'Tudo em dia: nenhum pedido aguardando aprovação.' : 'Nada encontrado com esses filtros.' ?></p></div>
    <?php endif; ?>
</section>
