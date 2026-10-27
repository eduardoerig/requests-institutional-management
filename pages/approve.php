<?php
/**
 * Aprovar pedidos (UI 2.0).
 * Celular: um pedido por vez em cartão grande (urgentes primeiro), progresso "X de Y decididos"
 * e Recusar/Aprovar fixos acima da barra inferior. Computador (≥900px): grade de 3 colunas, faixa
 * vermelha com prioridade e motivo nos urgentes e Recusar | Aprovar no rodapé do cartão.
 * A decisão espera ~5 s com "Desfazer" antes de ir para api/approve_request.php (assets/js/pages/approve.js):
 * no próprio cartão (computador e lista) ou no aviso fixo (celular, um por vez).
 * A aprovação em lote (caixas de seleção + barra de lote do script.js) continua igual.
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
$urgentCount = count(array_filter($approvals['all'], fn($r) => !empty($r['is_urgent'])));

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

<div class="toolbar approve-toolbar" data-sync="approve-sub"<?= $total === 0 ? ' hidden' : '' ?>>
    <?php // "N aguardando decisão · X enviando · urgentes primeiro"; approve.js atualiza N e X enquanto há decisões esperando o Desfazer ?>
    <p class="toolbar__text approve-summary" data-approve-summary data-total="<?= $total ?>" aria-live="polite">
        <strong data-summary-waiting><?= $total ?> aguardando decisão</strong><span class="approve-summary__sending" data-summary-sending hidden></span><?php if ($urgentCount > 0): ?><span> · urgentes primeiro</span><?php endif; ?>
    </p>
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

<?php if ($rows): ?>
<div class="approve-progress" data-approve-progress>
    <div class="approve-progress__row">
        <span class="approve-progress__text" data-progress-text aria-live="polite">0 de <?= count($rows) ?> decididos</span>
        <button type="button" class="btn btn--ghost btn--sm approve-progress__toggle" data-deck-toggle aria-pressed="false">Ver todos</button>
    </div>
    <div class="approve-progress__track" aria-hidden="true"><span data-progress-bar></span></div>
</div>
<?php endif; ?>

<section class="queue approve-deck" data-queue data-approve-deck data-url="<?= e('approve' . ($listParams ? '?' . http_build_query($listParams) : '')) ?>" aria-label="Pedidos aguardando aprovação">
    <?php if ($q !== '' || $sector !== ''): ?>
        <p class="queue__note"><?= count($rows) ?> de <?= $total ?> pedidos. <a href="approve">Limpar filtros</a></p>
    <?php endif; ?>
    <?php if ($rows): ?>
        <div class="approve-pickall">
            <label><input type="checkbox" data-pick-all> Selecionar todos</label>
            <span class="approve-pickall__hint">para aprovar ou recusar vários de uma vez</span>
        </div>
        <ul class="approve-list">
            <?php foreach ($rows as $row): ?>
                <?= renderApproveCard($row, ['href' => $rowLink($row), 'check' => true]) ?>
            <?php endforeach; ?>
        </ul>
        <p class="approve-next" data-next-hint aria-live="polite"></p>
        <div class="empty-state approve-done" data-approve-done hidden><i class="fa-regular fa-circle-check" aria-hidden="true"></i><p><strong>Tudo em dia.</strong> Nenhum pedido esperando sua decisão.</p></div>
        <?= renderBulkBar(['approve' => ['Aprovar', 'btn--primary', 'fa-check'], 'refuse' => ['Recusar', '', 'fa-xmark']]) ?>
    <?php else: ?>
        <div class="empty-state"><i class="fa-regular fa-circle-check" aria-hidden="true"></i><p><?= $total === 0 ? 'Tudo em dia: nenhum pedido aguardando aprovação. Quando alguém pedir algo, o pedido aparece aqui e você recebe um aviso.' : 'Nada encontrado com esses filtros.' ?></p></div>
    <?php endif; ?>
</section>

<?php if ($rows): ?>
<!-- Celular: decisão do cartão atual, fixa acima da barra inferior -->
<div class="approve-dock" data-approve-dock hidden>
    <button type="button" class="btn approve-dock__refuse" data-dock="refuse">Recusar</button>
    <button type="button" class="btn btn--primary approve-dock__approve" data-dock="approve">Aprovar</button>
</div>
<?php endif; ?>

<!-- Aviso com Desfazer: a decisão só vai para o servidor quando ele some -->
<div class="undo-toast" data-undo-toast role="status" aria-live="polite" hidden>
    <span class="undo-toast__text" data-undo-text></span>
    <button type="button" class="undo-toast__btn" data-undo>Desfazer</button>
</div>
