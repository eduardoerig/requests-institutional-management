<?php
/**
 * pages/request_detail.php
 * Fase 3 — Página de detalhes, histórico e comentários de uma requisição.
 */
require_once __DIR__ . '/../classes/RequestManager.php';

// Validação dos parâmetros
$req_id    = isset($_GET['id'])    ? (int)$_GET['id']              : 0;
$req_table = isset($_GET['table']) ? preg_replace('/[^a-z]/', '', $_GET['table']) : '';

$allowed_tables = ['mkt', 'shop', 'xerox', 'service', 'ti'];
if (!$req_id || !in_array($req_table, $allowed_tables)) {
    echo '<div class="panel"><div class="empty-big"><i class="fa-solid fa-circle-question"></i><h2>Requisição não encontrada</h2><p>O endereço está incompleto.</p><a class="btn" href="home"><i class="fa-solid fa-arrow-left"></i> Voltar ao início</a></div></div>';
    return;
}

// ---------- MAP de tabelas → colunas ----------
$col_map = [
    'mkt'     => ['title' => 'title', 'desc' => 'descp', 'user' => 'created_by'],
    'shop'    => ['title' => 'title', 'desc' => 'descp', 'user' => 'created_by'],
    'xerox'   => ['title' => 'title', 'desc' => 'descp', 'user' => 'created_by'],
    'service' => ['title' => 'title', 'desc' => 'descp', 'user' => 'created_by'],
    'ti'      => ['title' => 'title', 'desc' => 'descp', 'user' => 'created_by'],
];

$label_map = ['mkt' => 'Marketing', 'shop' => 'Compras', 'xerox' => 'Reprografia', 'service' => 'Manutenção', 'ti' => 'TI'];
$cols = $col_map[$req_table];

// ---------- Buscar a requisição com JOIN no usuário para pegar o nome ----------
$stmt = $pdo->prepare("
    SELECT r.*, u.name as creator_name
    FROM `ctd_{$req_table}_frm` r
    LEFT JOIN ctd_users u ON r.created_by = u.id
    WHERE r.id = ?
");
$stmt->execute([$req_id]);
$req = $stmt->fetch();

if (!$req) {
    echo '<div class="panel"><div class="empty-big"><i class="fa-solid fa-circle-question"></i><h2>Requisição não encontrada</h2><p>Ela pode ter sido cancelada.</p><a class="btn" href="home"><i class="fa-solid fa-arrow-left"></i> Voltar ao início</a></div></div>';
    return;
}

// ---------- Segurança: Validar permissão de acesso ----------
$role = $_SESSION['role'] ?? 'solicitante';
$isAdmin = isAdminRole($role);
$isGlobalAdmin = isGlobalAdminRole($role);
$isGestor = isGestorRole($role);
$isOwner = ((int)($req['created_by'] ?? 0) === (int)($_SESSION['id'] ?? -1));
$canViewRequest = userCanViewRequest($pdo, $req, $req_table, (int)($_SESSION['id'] ?? 0), $role);
$canManageThisRequest = userCanActOnRequest($pdo, $req, $req_table, (int)($_SESSION['id'] ?? 0), $role);

if (!$canViewRequest) {
    echo '<div class="panel"><div class="empty-big"><i class="fa-solid fa-lock"></i><h2>Sem acesso</h2><p>Você não tem permissão para ver esta requisição.</p><a class="btn" href="home"><i class="fa-solid fa-arrow-left"></i> Voltar ao início</a></div></div>';
    return;
}

// ---------- Buscar comentários e histórico ----------
$stmt_com = $pdo->prepare("SELECT * FROM request_comments WHERE request_id = ? AND request_table = ? ORDER BY created_at ASC");
$stmt_com->execute([$req_id, $req_table]);
$comments = $stmt_com->fetchAll();

$stmt_his = $pdo->prepare("SELECT * FROM request_history WHERE request_id = ? AND request_table = ? ORDER BY created_at DESC");
$stmt_his->execute([$req_id, $req_table]);
$history = $stmt_his->fetchAll();

// ---------- Helpers ----------
$current_status = trim(strtoupper($req['status'] ?? 'P'));
if ($current_status === '') $current_status = 'P';

$title = htmlspecialchars($req[$cols['title']] ?? 'Sem título');
$desc  = htmlspecialchars($req[$cols['desc']]  ?? '');
$user  = htmlspecialchars($req['creator_name'] ?? $req[$cols['user']] ?? '—');
$date  = isset($req['created_at']) ? date('d/m/Y \à\s H:i', strtotime($req['created_at'])) : '—';
$cat   = $label_map[$req_table];

// Buscar nome da subdivisão se existir
$subName = 'N/A';
if (!empty($req['subdivision_id'])) {
    $stSub = $pdo->prepare("SELECT name FROM ctd_subdivision WHERE id = ?");
    $stSub->execute([$req['subdivision_id']]);
    $subName = $stSub->fetchColumn() ?: 'N/A';
}

// Buscar quem aprovou/recusou no histórico
$approverName = null;
$histStmt = $pdo->prepare("SELECT user_name FROM request_history WHERE request_id = ? AND request_table = ? AND action LIKE 'Requisição %' AND new_value IN ('Aprovada', 'Recusada', 'Rejeitada') ORDER BY id DESC LIMIT 1");
$histStmt->execute([$req_id, $req_table]);
$approverName = $histStmt->fetchColumn();

// Buscar cadeia de repasses (Fase 5)
require_once __DIR__ . '/../classes/RequestForwardService.php';
$forwardChainResult = RequestForwardService::getForwardChain($pdo, $req_id, $req_table);
$forwardChain = $forwardChainResult['data'] ?? [];
$activeForward = RequestForwardService::getActiveForward($pdo, $req_id, $req_table);

// Prazo pedido
$deadlineRaw = $req['date'] ?? '';
$deadlineText = (!empty($deadlineRaw) && $deadlineRaw !== '0000-00-00') ? date('d/m/Y', strtotime($deadlineRaw)) : 'Sem prazo';

// Quem pode aprovar/recusar daqui (requisição aguardando aprovação)
$canApproveHere = $current_status === 'P' && $isAdmin && userCanManageOriginalRequest($pdo, $req, $req_table, (int)$_SESSION['id'], $role);

// Frase "o que acontece agora" — linguagem do dia a dia
$nextStepText = [
    'P' => 'Aguardando a aprovação da coordenação. Você recebe um aviso no sino quando houver resposta.',
    'Y' => "Aprovada! Agora o setor de {$cat} vai iniciar o atendimento.",
    'W' => "O setor de {$cat} está cuidando do seu pedido.",
    'F' => 'O pedido foi encaminhado para outro setor, que vai continuar o atendimento.',
    'C' => 'Pedido concluído. Se algo não ficou certo, escreva na conversa abaixo.',
    'N' => 'Pedido recusado. Veja o motivo na conversa abaixo. Se precisar, faça uma nova requisição corrigindo o que foi pedido.',
][$current_status] ?? '';

// Campos específicos do formulário
$extraFields = [];
if ($req_table === 'xerox') {
    $extraFields['Arquivo'] = $req['arquive'] ?? '';
    $extraFields['Cópias'] = $req['qtd'] ?? '';
    $extraFields['Tamanho do papel'] = $req['type'] ?? '';
} elseif ($req_table === 'mkt') {
    $extraFields['Onde será usada'] = $req['type'] ?? '';
}
if (!empty($req['obs'])) {
    $extraFields['Observações'] = $req['obs'];
}

// Etapas para a barra de progresso
$stepIndex = ['P' => 0, 'Y' => 1, 'F' => 2, 'W' => 2, 'C' => 3, 'N' => 0][$current_status] ?? 0;
$stepNames = ['Aguardando aprovação', 'Aprovada', 'Em atendimento', 'Concluída'];

?>
<div class="detail">
    <input type="hidden" class="Rid" value="<?= $req_id ?>">
    <input type="hidden" class="Rtype" value="<?= e($req_table) ?>">

    <a href="<?= $isOwner ? 'myRequests' : 'home' ?>" class="back-link"><i class="fa-solid fa-arrow-left" aria-hidden="true"></i> Voltar</a>

    <!-- Ficha do pedido -->
    <section class="ficha ficha--hero" data-sector="<?= e($req_table) ?>" aria-labelledby="reqTitle">
        <span class="ficha__stub">
            <span class="ficha__num"><small>Nº</small><?= $req_id ?></span>
            <span class="status-pill status-pill--<?= e($current_status) ?>"><?= e(statusLabel($current_status)) ?></span>
        </span>
        <div class="ficha__body" style="gap:10px; padding:16px 18px;">
            <h1 class="ficha__title" id="reqTitle"><?= $title ?></h1>
            <div class="ficha__meta">
                <span class="sector-tag"><?= e($cat) ?></span>
                <span><i class="fa-regular fa-user" aria-hidden="true"></i> <?= $user ?></span>
                <span>Pedido em <?= e($date) ?></span>
            </div>
            <div class="row"><?= getPrazoBadge($req['date'] ?? '', $current_status) ?></div>
            <ol class="steps <?= $current_status === 'N' ? 'refused' : '' ?>" aria-label="Etapas do pedido">
                <?php foreach ($stepNames as $i => $stepName): ?>
                    <li class="<?= ($current_status !== 'N' && $i <= $stepIndex) ? 'done' : '' ?> <?= $i === $stepIndex ? 'current' : '' ?>"<?= $i === $stepIndex ? ' aria-current="step"' : '' ?>><?= e($stepName) ?></li>
                <?php endforeach; ?>
            </ol>
        </div>
    </section>

    <!-- O que acontece agora + decisão -->
    <div class="next-step">
        <i class="fa-solid fa-circle-info" aria-hidden="true"></i>
        <div>
            <strong>O que acontece agora:</strong> <?= e($nextStepText) ?>
            <?php if ($canApproveHere): ?>
                <div class="approve-box">
                    <button type="button" class="btn btn--success" onclick="decideRequest('Y')"><i class="fa-solid fa-check" aria-hidden="true"></i> Aprovar</button>
                    <button type="button" class="btn btn--danger" onclick="decideRequest('N')"><i class="fa-solid fa-xmark" aria-hidden="true"></i> Recusar</button>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="detail__cols">
        <div class="detail__col">
            <section class="panel" aria-labelledby="pedidoTitulo">
                <div class="panel__head"><h2 id="pedidoTitulo"><i class="fa-solid fa-align-left" aria-hidden="true"></i> O que foi pedido</h2></div>
                <div class="panel__body">
                    <div class="prose"><?= nl2br($desc) ?: '<em class="muted">Sem descrição.</em>' ?></div>
                    <dl class="info-grid mt-16" style="margin-bottom:0;">
                        <?php foreach ($extraFields as $label => $value): ?>
                            <div class="info-item"><dt><?= e($label) ?></dt><dd><?= e($value !== '' ? $value : '—') ?></dd></div>
                        <?php endforeach; ?>
                        <div class="info-item"><dt>Urgência</dt><dd><?= e(priorityLabel((int)($req['priority'] ?? 2))) ?></dd></div>
                        <div class="info-item"><dt>Precisa para</dt><dd><?= e($deadlineText) ?></dd></div>
                        <div class="info-item"><dt>Subdivisão</dt><dd><?= e($subName) ?></dd></div>
                        <?php if ($approverName): ?>
                            <div class="info-item"><dt><?= $current_status === 'N' ? 'Recusada por' : 'Aprovada por' ?></dt><dd><?= e($approverName) ?></dd></div>
                        <?php endif; ?>
                    </dl>
                </div>
            </section>

            <?php if (!empty($forwardChain)): ?>
                <?php
                $canActOnForward = false;
                if ($activeForward && ($isGestor || $isGlobalAdmin)) {
                    if ($isGlobalAdmin) {
                        $canActOnForward = true;
                    } else {
                        $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM cfg_user_area WHERE id_user = ? AND id_area = ?");
                        $checkStmt->execute([$_SESSION['id'], $activeForward['to_area_id']]);
                        $canActOnForward = (int)$checkStmt->fetchColumn() > 0;
                    }
                }
                $forwardNeedsMe = $canActOnForward && $activeForward['status'] === 'pending';
                ?>
                <details class="panel" <?= $forwardNeedsMe ? 'open' : '' ?>>
                    <summary class="panel__head"><h2><i class="fa-solid fa-share-from-square" aria-hidden="true"></i> Encaminhamentos entre setores</h2></summary>
                    <div class="panel__body">
                        <?php if ($forwardNeedsMe): ?>
                            <p class="forward-pending">Outro setor encaminhou este pedido para você. Vai assumir o atendimento?</p>
                            <div class="approve-box" style="margin:0 0 16px;">
                                <button type="button" class="btn btn--success" onclick="handleForwardAction('accept', <?= (int)$activeForward['id'] ?>)"><i class="fa-solid fa-check" aria-hidden="true"></i> Aceitar e atender</button>
                                <button type="button" class="btn btn--danger" onclick="handleForwardAction('refuse', <?= (int)$activeForward['id'] ?>)"><i class="fa-solid fa-xmark" aria-hidden="true"></i> Recusar</button>
                            </div>
                        <?php elseif ($canActOnForward && $activeForward['status'] === 'accepted'): ?>
                            <p class="forward-pending" style="background:var(--st-y-bg); color:var(--st-y-fg);">Você assumiu este pedido. Marque como concluído quando terminar.</p>
                            <div class="approve-box" style="margin:0 0 16px;">
                                <button type="button" class="btn btn--success" onclick="handleForwardAction('complete', <?= (int)$activeForward['id'] ?>)"><i class="fa-solid fa-check-double" aria-hidden="true"></i> Marcar como concluída</button>
                            </div>
                        <?php endif; ?>

                        <ul class="forward-chain">
                            <?php foreach ($forwardChain as $fwd): ?>
                                <li class="forward-chain-item">
                                    <strong><?= e($fwd['from_area_label']) ?> para <?= e($fwd['to_area_label']) ?></strong>
                                    <div class="forward-meta">
                                        <span><?= e($fwd['forwarded_by_name'] ?? '—') ?></span>
                                        <span><?= e(date('d/m/Y H:i', strtotime($fwd['created_at']))) ?></span>
                                        <span class="forward-chain-status"><?= e($fwd['status_label']) ?></span>
                                        <?php if (!empty($fwd['received_by_name'])): ?><span>Recebido por <?= e($fwd['received_by_name']) ?></span><?php endif; ?>
                                    </div>
                                    <?php if (!empty($fwd['observation'])): ?>
                                        <p class="forward-obs">"<?= e($fwd['observation']) ?>"</p>
                                    <?php endif; ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </details>
            <?php endif; ?>

            <details class="panel only-desktop-open" id="historyPanel">
                <summary class="panel__head"><h2><i class="fa-solid fa-clock-rotate-left" aria-hidden="true"></i> Histórico</h2></summary>
                <div class="panel__body">
                    <?php if ($history): ?>
                        <ol class="timeline">
                            <?php foreach ($history as $h): ?>
                                <li>
                                    <div>
                                        <time datetime="<?= e($h['created_at']) ?>"><?= e(date('d/m/Y \à\s H:i', strtotime($h['created_at']))) ?></time>
                                        <strong><?= e($h['user_name']) ?></strong> <?= e(mb_strtolower(mb_substr($h['action'], 0, 1)) . mb_substr($h['action'], 1)) ?>
                                    </div>
                                </li>
                            <?php endforeach; ?>
                        </ol>
                    <?php else: ?>
                        <p class="muted">Sem movimentações registradas.</p>
                    <?php endif; ?>
                </div>
            </details>
        </div>

        <div class="detail__col">
            <?php if ($role !== 'solicitante' && ($canManageThisRequest || $isAdmin || $isGestor)): ?>
                <section class="panel" aria-labelledby="acoesTitulo">
                    <div class="panel__head"><h2 id="acoesTitulo"><i class="fa-solid fa-gear" aria-hidden="true"></i> Ações</h2></div>
                    <div class="panel__body actions-group">
                        <?php if ($isGestor && $canManageThisRequest && $current_status === 'W'): ?>
                            <button type="button" onclick="globalRequestAction('finish', '<?= $req_id ?>', 'ctd_<?= e($req_table) ?>_frm')" class="btn btn--success"><i class="fa-solid fa-check-circle" aria-hidden="true"></i> Marcar como concluída</button>
                        <?php elseif ($isGestor && $canManageThisRequest && $current_status === 'Y'): ?>
                            <button type="button" onclick="globalRequestAction('start_progress', '<?= $req_id ?>', 'ctd_<?= e($req_table) ?>_frm')" class="btn btn--primary"><i class="fa-solid fa-play" aria-hidden="true"></i> Começar atendimento</button>
                        <?php endif; ?>

                        <?php if (in_array($current_status, ['W', 'F'], true) && $canManageThisRequest): ?>
                            <button type="button" onclick="openForwardModal()" class="btn"><i class="fa-solid fa-share-from-square" aria-hidden="true"></i> Encaminhar a outro setor</button>
                        <?php endif; ?>

                        <?php if ($current_status === 'C' && $canManageThisRequest): ?>
                            <button type="button" onclick="globalRequestAction('reset_status', '<?= $req_id ?>', 'ctd_<?= e($req_table) ?>_frm')" class="btn"><i class="fa-solid fa-rotate-left" aria-hidden="true"></i> Reabrir</button>
                        <?php endif; ?>

                        <?php if ($canManageThisRequest): ?>
                            <div class="field" style="margin:4px 0 0;">
                                <label for="prioritySelect">Urgência</label>
                                <select id="prioritySelect" onchange="globalRequestAction('set_priority', '<?= $req_id ?>', 'ctd_<?= e($req_table) ?>_frm', this.value)">
                                    <?php foreach (priorityLabels() as $value => $label): ?>
                                        <option value="<?= $value ?>" <?= (int)($req['priority'] ?? 2) === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        <?php endif; ?>

                        <?php if ($isAdmin || $isGestor): ?>
                            <a href="print_request.php?id=<?= $req_id ?>&table=<?= e($req_table) ?>" target="_blank" rel="noopener" class="btn btn--ghost"><i class="fa-solid fa-print" aria-hidden="true"></i> Imprimir ou salvar PDF</a>
                        <?php endif; ?>
                    </div>
                </section>
            <?php endif; ?>

            <section class="panel chat" id="conversa" aria-labelledby="conversaTitulo">
                <div class="panel__head"><h2 id="conversaTitulo"><i class="fa-solid fa-comments" aria-hidden="true"></i> Conversa sobre o pedido</h2></div>
                <div class="chat-messages" id="comments-feed" aria-live="polite">
                    <?php if (count($comments) > 0):
                        $last_chat_date = '';
                        foreach ($comments as $c):
                            $this_date = date('d/m/Y', strtotime($c['created_at']));
                            if ($this_date !== $last_chat_date):
                                echo '<div class="chat-date-header">' . e($this_date) . '</div>';
                                $last_chat_date = $this_date;
                            endif;
                            $is_mine = ((int)$c['user_id'] === (int)($_SESSION['id'] ?? -1));
                    ?>
                        <div class="chat-item <?= $is_mine ? 'mine' : 'theirs' ?>">
                            <div class="chat-avatar" aria-hidden="true"><?= e(mb_strtoupper(mb_substr((string)$c['user_name'], 0, 1))) ?></div>
                            <div class="chat-bubble <?= $is_mine ? 'mine' : 'theirs' ?>">
                                <div class="chat-user-name"><?= e($c['user_name']) ?></div>
                                <?= nl2br(e($c['comment'])) ?>
                                <span class="chat-time"><?= e(date('H:i', strtotime($c['created_at']))) ?></span>
                            </div>
                        </div>
                    <?php endforeach; else: ?>
                        <p class="chat-empty">Tem alguma dúvida? Escreva abaixo: quem cuida do pedido recebe sua mensagem.</p>
                    <?php endif; ?>
                </div>
                <div class="chat-footer">
                    <form id="commentForm">
                        <div class="chat-input-area">
                            <textarea name="comment" class="chat-input" placeholder="Escreva sua mensagem" rows="1" required maxlength="3000" aria-label="Mensagem"
                                oninput="this.style.height = ''; this.style.height = this.scrollHeight + 'px'"></textarea>
                            <button type="submit" class="btn-send" aria-label="Enviar mensagem"><i class="fa-solid fa-paper-plane" aria-hidden="true"></i></button>
                        </div>
                    </form>
                </div>
            </section>

            <?php if ($isOwner && $current_status === 'P'): ?>
                <button type="button" class="btn btn--danger btn--block" onclick="globalRequestAction('delete', '<?= $req_id ?>', 'ctd_<?= e($req_table) ?>_frm')"><i class="fa-solid fa-trash-can" aria-hidden="true"></i> Cancelar este pedido</button>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
function scrollToBottom() {
    const feed = document.getElementById('comments-feed');
    if (feed) feed.scrollTop = feed.scrollHeight;
}

// Histórico aberto no PC, recolhido no celular
if (window.innerWidth >= 900) document.getElementById('historyPanel')?.setAttribute('open', '');

function loadComments() {
    if (document.hidden) return;
    fetch(`api/get_comments.php?id=<?= $req_id ?>&table=<?= e($req_table) ?>`)
        .then(response => response.ok ? response.text() : Promise.reject())
        .then(html => {
            const feed = document.getElementById('comments-feed');
            if (!feed || feed.innerHTML === html) return;
            const isAtBottom = feed.scrollHeight - feed.scrollTop <= feed.clientHeight + 150;
            feed.innerHTML = html;
            if (isAtBottom) scrollToBottom();
        })
        .catch(() => {});
}

// Decisão do aprovador direto na tela do pedido
async function decideRequest(value) {
    let reason = '';
    if (value === 'N') {
        reason = await showConfirm({
            title: 'Recusar pedido',
            message: 'O solicitante vai receber o motivo abaixo.',
            type: 'error',
            confirmLabel: 'Recusar',
            isDanger: true,
            input: { label: 'Motivo da recusa *', placeholder: 'Ex.: falta informar a quantidade', required: true }
        });
        if (reason === false) return;
    } else {
        const ok = await showConfirm({
            title: 'Aprovar pedido',
            message: 'O pedido vai para o setor responsável começar o atendimento.',
            type: 'success',
            confirmLabel: 'Aprovar'
        });
        if (!ok) return;
    }
    const formData = new FormData();
    formData.append('action', 'post');
    formData.append('id', '<?= $req_id ?>');
    formData.append('table', '<?= e($req_table) ?>');
    formData.append('value', value);
    formData.append('reason', reason);
    const data = await fetchAjax('config/aprovRequest.php', formData);
    if (data && data.success) {
        showToast(data.message, 'success');
        setTimeout(() => location.reload(), 900);
    }
}

document.getElementById('commentForm')?.addEventListener('submit', async function (e) {
    e.preventDefault();
    const textarea = this.querySelector('textarea');
    const text = textarea.value.trim();
    if (!text) { textarea.focus(); return; }

    const btn = this.querySelector('button');
    btn.disabled = true;
    const formData = new URLSearchParams();
    formData.append('id', '<?= $req_id ?>');
    formData.append('table', '<?= e($req_table) ?>');
    formData.append('comment', text);

    try {
        const response = await fetch('api/add_comment.php', { method: 'POST', body: attachCsrfToken(formData) });
        const data = await response.json();
        if (data.success) {
            this.reset();
            textarea.style.height = '';
            loadComments();
            setTimeout(scrollToBottom, 300);
        } else {
            showToast(data.message || 'Não foi possível enviar a mensagem.', 'error');
        }
    } catch (err) {
        showToast('Sem conexão. Sua mensagem não foi enviada; tente de novo.', 'error');
    } finally {
        btn.disabled = false;
    }
});

document.querySelector('#commentForm textarea')?.addEventListener('keydown', function (e) {
    if (e.key === 'Enter' && !e.shiftKey && window.innerWidth >= 900) {
        e.preventDefault();
        document.getElementById('commentForm').dispatchEvent(new Event('submit', { cancelable: true, bubbles: true }));
    }
});

scrollToBottom();
setInterval(loadComments, 15000);
document.addEventListener('visibilitychange', () => { if (!document.hidden) loadComments(); });
</script>
