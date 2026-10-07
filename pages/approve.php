<?php
/**
 * Aprovar pedidos: lista compacta à esquerda, pedido completo à direita (no celular, a lista abre o pedido).
 */
require_once __DIR__ . '/../classes/RequestManager.php';
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
$requests = RequestManager::getRequests($pdo, null, null, 'P', null, null, 'ASC', [], null, null, $subdivisionFilter)['requests'] ?? [];

foreach ($requests as &$r) {
    $r['table'] = str_replace(['ctd_', '_frm'], '', strtolower((string)($r['table'] ?? '')));
}
unset($r);

$sectors = [];
foreach ($requests as $r) {
    $sectors[$r['table']] = requestSectorName($r['table']);
}
asort($sectors);

$sector = (string)($_GET['setor'] ?? '');
$q = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 100);
$rows = array_values(array_filter($requests, function ($r) use ($sector, $q) {
    if ($sector !== '' && $r['table'] !== $sector) return false;
    if ($q === '') return true;
    $haystack = mb_strtolower(($r['title'] ?? '') . ' #' . ($r['id'] ?? '') . ' ' . ($r['solicitor_name_formatted'] ?? $r['solicitor_name'] ?? ''));
    return mb_strpos($haystack, mb_strtolower($q)) !== false;
}));
$total = count($requests);
?>
<div class="page-head">
    <div class="page-head--hide-mobile">
        <h1>Aprovar pedidos</h1>
        <p data-sync="approve-sub"><?= $total === 0 ? 'Nenhum pedido esperando sua decisão.' : ($total === 1 ? '1 pedido esperando sua decisão.' : $total . ' pedidos esperando sua decisão, do prazo mais próximo para o mais distante.') ?></p>
    </div>
    <?php if ($total > 0): ?>
    <form method="get" action="approve" class="row" role="search" style="flex:1 1 320px; justify-content:flex-end;">
        <?php if (count($sectors) > 1): ?>
            <select name="setor" aria-label="Setor" onchange="this.form.submit()" style="width:auto;">
                <option value="">Todos os setores</option>
                <?php foreach ($sectors as $slug => $label): ?>
                    <option value="<?= e($slug) ?>" <?= $sector === $slug ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        <?php endif; ?>
        <div class="search-box" style="flex:1 1 220px; max-width:360px;">
            <i class="fas fa-search" aria-hidden="true"></i>
            <input type="search" name="q" value="<?= e($q) ?>" placeholder="Título, número ou nome" aria-label="Buscar">
        </div>
    </form>
    <?php endif; ?>
</div>

<div class="workbench" data-queue data-advance>
    <div class="workbench__list" aria-label="Pedidos aguardando aprovação">
        <?php if ($q !== '' || $sector !== ''): ?>
            <p class="result-count"><?= count($rows) ?> de <?= $total ?> <a href="approve">limpar filtros</a></p>
        <?php endif; ?>
        <?php if ($rows): ?>
            <?php foreach ($rows as $row): ?>
                <?= renderRequestRow($row, !empty($row['subdivision_name']) ? (string)$row['subdivision_name'] : '') ?>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="empty-state"><i class="fa-regular fa-circle-check" aria-hidden="true"></i><p><?= $total === 0 ? 'Tudo em dia: nenhum pedido aguardando aprovação.' : 'Nada encontrado com esses filtros.' ?></p></div>
        <?php endif; ?>
    </div>
    <aside class="workbench__panel" id="requestPanel" aria-live="polite" aria-label="Pedido selecionado">
        <div class="empty-state"><i class="fa-regular fa-hand-pointer" aria-hidden="true"></i><p>Escolha um pedido na lista para aprovar ou recusar.</p></div>
    </aside>
</div>
