<?php
/**
 * Caixa do setor: tudo que o setor precisa fazer, num lugar só.
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
$areaIds = RequestInbox::areasForUser($pdo, $userId, $role);
$areaFilter = (int)($_GET['setor'] ?? 0);
if ($isGlobalAdmin && $areaFilter > 0 && in_array($areaFilter, array_column($allAreas, 'id'), true)) {
    $areaIds = [$areaFilter];
}

$inbox = RequestInbox::build($pdo, $areaIds);
$counts = $inbox['counts'];

$tab = (string)($_GET['tab'] ?? '');
if (!isset(RequestInbox::TABS[$tab])) {
    // Abre onde há trabalho: primeiro o que chegou de outros setores
    $tab = $counts['chegaram'] > 0 ? 'chegaram' : ($counts['novos'] > 0 ? 'novos' : ($counts['andamento'] > 0 ? 'andamento' : 'novos'));
}
$q = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 100);
$rows = RequestInbox::search($inbox['tabs'][$tab], $q);

$tabLink = function (string $t) use ($areaFilter, $q) {
    return 'inbox?' . http_build_query(array_filter(['tab' => $t, 'setor' => $areaFilter ?: null, 'q' => $q !== '' ? $q : null]));
};

// Frase de contexto de cada linha (encaminhamentos)
$noteFor = function (array $row) use ($tab): array {
    $f = $row['inbox_forward'] ?? $row['open_forward'] ?? null;
    if (!$f) {
        return ['', ''];
    }
    $text = trim((string)$f['observation']);
    $preview = $text === '' ? '' : ': ' . (mb_strlen($text) > 70 ? mb_substr($text, 0, 67) . '...' : $text);
    if ($tab === 'chegaram') {
        return [($f['mode'] === 'help' ? $f['from_label'] . ' pede ajuda' : $f['from_label'] . ' quer transferir') . $preview, 'in'];
    }
    if ($tab === 'ajudando') {
        return ['Ajudando ' . $f['from_label'] . $preview, 'in'];
    }
    if ($tab === 'enviados') {
        $state = [
            'pending' => 'aguardando resposta',
            'accepted' => $f['mode'] === 'help' ? 'ajudando agora' : 'aceito, agora é deles',
            'refused' => 'recusado: ' . (string)$f['response_note'],
            'returned' => 'ajuda devolvida: ' . (string)$f['response_note'],
            'cancelled' => 'envio cancelado',
            'completed' => 'concluído',
        ][$f['status']] ?? $f['status'];
        return ['Para ' . $f['to_label'] . ', ' . $state, $f['status'] === 'refused' ? 'bad' : ''];
    }
    return [$f['mode'] === 'help'
        ? 'Ajuda pedida a ' . $f['to_label'] . ($f['status'] === 'accepted' ? ' (ajudando)' : ' (aguardando)')
        : 'Aguardando ' . $f['to_label'] . ' aceitar a transferência', ''];
};

$emptyText = [
    'novos' => 'Nenhum pedido novo. Quando a coordenação aprovar um pedido do seu setor, ele aparece aqui.',
    'andamento' => 'Nenhum pedido em atendimento.',
    'chegaram' => 'Nada chegou de outros setores.',
    'ajudando' => 'Seu setor não está ajudando em nenhum pedido agora.',
    'enviados' => 'Seu setor não encaminhou pedidos nos últimos 30 dias.',
    'concluidos' => 'Nenhum pedido concluído ainda.',
][$tab];
?>
<div class="page-head">
    <div class="page-head--hide-mobile">
        <h1><?= $isGlobalAdmin ? 'Caixa dos setores' : 'Caixa do setor' ?></h1>
        <p>Tudo que precisa de atenção, num lugar só.</p>
    </div>
    <form method="get" action="inbox" class="row" role="search" style="flex:1 1 320px; justify-content:flex-end;">
        <input type="hidden" name="tab" value="<?= e($tab) ?>">
        <?php if ($isGlobalAdmin): ?>
            <select name="setor" aria-label="Setor" onchange="this.form.submit()" style="width:auto;">
                <option value="">Todos os setores</option>
                <?php foreach ($allAreas as $a): ?>
                    <option value="<?= (int)$a['id'] ?>" <?= $areaFilter === (int)$a['id'] ? 'selected' : '' ?>><?= e($a['label']) ?></option>
                <?php endforeach; ?>
            </select>
        <?php endif; ?>
        <div class="search-box" style="flex:1 1 220px; max-width:360px;">
            <i class="fas fa-search" aria-hidden="true"></i>
            <input type="search" name="q" value="<?= e($q) ?>" placeholder="Título, número ou nome" aria-label="Buscar">
        </div>
    </form>
</div>

<nav class="inbox-tabs tabs" aria-label="Caixa" data-sync="inbox-tabs">
    <?php foreach (RequestInbox::TABS as $key => $label):
        $n = $counts[$key];
        $hot = in_array($key, ['chegaram', 'novos'], true) && $n > 0;
    ?>
        <a class="tab<?= $tab === $key ? ' is-active' : '' ?><?= $hot ? ' tab--hot' : '' ?>" href="<?= e($tabLink($key)) ?>"<?= $tab === $key ? ' aria-current="page"' : '' ?>>
            <?= e($label) ?> <span><?= $n ?></span>
        </a>
    <?php endforeach; ?>
</nav>

<div class="workbench" data-queue data-url="<?= e($tabLink($tab)) ?>">
    <div class="workbench__list" aria-label="Pedidos">
        <?php if ($q !== ''): ?>
            <p class="result-count"><?= count($rows) ?> resultado(s) para "<?= e($q) ?>" <a href="<?= e($tabLink($tab)) ?>">limpar</a></p>
        <?php endif; ?>
        <?php if ($rows): ?>
            <?php foreach ($rows as $row):
                [$note, $kind] = $noteFor($row);
            ?>
                <?= renderRequestRow($row, $note, $kind) ?>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="empty-state"><i class="fa-regular fa-circle-check" aria-hidden="true"></i><p><?= e($q !== '' ? 'Nada encontrado nesta aba.' : $emptyText) ?></p></div>
        <?php endif; ?>
    </div>
    <aside class="workbench__panel" id="requestPanel" aria-live="polite" aria-label="Pedido selecionado">
        <div class="empty-state"><i class="fa-regular fa-hand-pointer" aria-hidden="true"></i><p>Escolha um pedido na lista.</p></div>
    </aside>
</div>
