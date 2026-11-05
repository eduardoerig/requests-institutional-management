<?php
/**
 * Aprovar pedidos (UI 2.0, feita para quem aprova sem prática com o sistema).
 * Lista + painel, como a Caixa do setor: no computador, a lista fica à esquerda e o pedido escolhido
 * aberto à direita; no celular, o painel é a tela principal (um pedido por vez) e "Ver lista" mostra
 * todos. O painel diz o que é, quem pediu, quem vai fazer e para quando, e tem Perguntar, Recusar e
 * Aprovar. Não há decisão em lote: cada pedido é decidido sozinho.
 * A decisão espera ~5 s com "Desfazer" antes de ir para api/approve_request.php (assets/js/pages/approve.js).
 * Peças: components/approve_item.php · estilos: assets/css/pages/approve.css
 */
require_once __DIR__ . '/../classes/RequestInbox.php';
require_once __DIR__ . '/../components/approve_item.php';

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
$approvals = RequestInbox::approvals($pdo, $subdivisionFilter, $sector, $q, (int)($_SESSION['id'] ?? 0));
$rows = $approvals['rows'];
$partnerAreas = RequestForwardService::availableAreas($pdo);
$total = count($approvals['all']);
$urgentCount = count(array_filter($approvals['all'], fn($r) => !empty($r['is_urgent'])));
$filtering = $q !== '' || $sector !== '';
// Com poucos pedidos, busca e filtro só atrapalham
$showFilters = $total > 5 || $filtering;

$sectors = [];
foreach ($approvals['all'] as $r) {
    $sectors[$r['table']] = requestSectorName($r['table']);
}
asort($sectors);

$listParams = array_filter(['setor' => $sector !== '' ? $sector : null, 'q' => $q !== '' ? $q : null]);
$rowLink = fn(array $row) => 'request_detail?' . http_build_query(['id' => (int)$row['id'], 'table' => $row['table'], 'from' => 'approve'] + $listParams);

// Grupos da lista: urgentes primeiro (a ordem já vem de RequestInbox::approvals)
$groups = ['urgentes' => [], 'outros' => []];
foreach ($rows as $row) {
    $groups[!empty($row['is_urgent']) ? 'urgentes' : 'outros'][] = $row;
}
$groupTitles = ['urgentes' => 'Urgentes', 'outros' => 'Esperando decisão'];

$items = [];
$pos = 0;
foreach ($rows as $row) {
    $key = $row['table'] . ':' . (int)$row['id'];
    $href = $rowLink($row);
    $items[$key] = ['key' => $key, 'href' => $href, 'ask' => $href . '#perguntar', 'pos' => ++$pos, 'total' => count($rows)];
}

$waitingText = fn(int $n) => $n === 1 ? '1 pedido esperando sua decisão' : $n . ' pedidos esperando sua decisão';
?>
<div class="page-head page-head--hide-mobile">
    <h1>Aprovar pedidos</h1>
</div>

<div class="toolbar ap-toolbar" data-sync="approve-sub"<?= $total === 0 ? ' hidden' : '' ?>>
    <?php // approve.js atualiza os números enquanto há decisões esperando o Desfazer ?>
    <p class="toolbar__text ap-summary" data-approve-summary data-total="<?= $total ?>" data-urgent="<?= $urgentCount ?>" aria-live="polite">
        <strong data-summary-waiting><?= e($waitingText($total)) ?></strong><span class="ap-summary__urgent" data-summary-urgent<?= $urgentCount ? '' : ' hidden' ?>> · <?= $urgentCount === 1 ? '1 urgente' : $urgentCount . ' urgentes' ?></span><span data-summary-sending hidden></span>
    </p>
    <?php if ($showFilters): ?>
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

<section class="ap-body" data-queue data-url="<?= e('approve' . ($listParams ? '?' . http_build_query($listParams) : '')) ?>" aria-label="Pedidos esperando aprovação">
    <?php if ($filtering): ?>
        <p class="ap-note"><?= count($rows) ?> de <?= $total ?> pedidos. <a href="approve">Limpar busca</a></p>
    <?php endif; ?>
    <?php if ($rows): ?>
        <div class="ap-cols">
            <div class="ap-list" data-list>
                <button type="button" class="btn btn--sm btn--ghost ap-list__back" data-show-panel><i class="fa-solid fa-arrow-left" aria-hidden="true"></i> Voltar ao pedido</button>
                <?php foreach ($groups as $g => $groupRows): if (!$groupRows) continue; ?>
                    <section class="ap-group ap-group--<?= e($g) ?>" aria-labelledby="apg-<?= e($g) ?>">
                        <h2 class="ap-group__title" id="apg-<?= e($g) ?>"><?= e($groupTitles[$g]) ?> <span><?= count($groupRows) ?></span></h2>
                        <ul class="ap-rows">
                            <?php foreach ($groupRows as $row): ?>
                                <?= renderApproveRow($row, $items[$row['table'] . ':' . (int)$row['id']]) ?>
                            <?php endforeach; ?>
                        </ul>
                    </section>
                <?php endforeach; ?>
            </div>
            <div class="ap-panel" data-panel aria-live="polite">
                <?php foreach ($rows as $row): ?>
                    <?= renderApproveDetail($row, $items[$row['table'] . ':' . (int)$row['id']], $partnerAreas) ?>
                <?php endforeach; ?>
                <?php // Fila vazia nesta visita: enquanto há decisão esperando o Desfazer, ainda não está "em dia" ?>
                <div class="ap-state" data-approve-wait hidden><i class="fa-regular fa-clock" aria-hidden="true"></i><p>Enviando a última decisão. Ainda dá para desfazer no aviso abaixo.</p></div>
                <div class="ap-state" data-approve-done hidden><i class="fa-regular fa-circle-check" aria-hidden="true"></i><p><strong>Tudo em dia.</strong> Nenhum pedido esperando sua decisão.</p></div>
            </div>
        </div>
    <?php else: ?>
        <div class="empty-state ap-empty"><i class="fa-regular fa-circle-check" aria-hidden="true"></i><p><?= $total === 0 ? '<strong>Tudo em dia.</strong> Nenhum pedido esperando sua decisão. Quando alguém pedir algo, o pedido aparece aqui e você recebe um aviso.' : 'Nada encontrado com essa busca.' ?></p></div>
    <?php endif; ?>
</section>

<!-- Aviso com Desfazer: a decisão só vai para o servidor quando ele some -->
<div class="undo-toast" data-undo-toast role="status" aria-live="polite" hidden>
    <span class="undo-toast__text"><span data-undo-text></span><span class="undo-toast__secs" data-undo-secs aria-hidden="true"></span></span>
    <button type="button" class="undo-toast__btn" data-undo>Desfazer</button>
</div>
