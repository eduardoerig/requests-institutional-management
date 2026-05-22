<?php
/**
 * pages/request_detail.php
 * Fase 3 — Página de detalhes, histórico e comentários de uma requisição.
 */
require_once 'config/conn.php';
require_once 'classes/RequestManager.php';
require_once 'config/security.php';

// Validação dos parâmetros
$req_id    = isset($_GET['id'])    ? (int)$_GET['id']              : 0;
$req_table = isset($_GET['table']) ? preg_replace('/[^a-z]/', '', $_GET['table']) : '';

$allowed_tables = ['mkt', 'shop', 'xerox', 'service', 'ti'];
if (!$req_id || !in_array($req_table, $allowed_tables)) {
    echo '<div class="main"><p>Requisição inválida.</p></div>';
    exit;
}

// ---------- MAP de tabelas → colunas ----------
$col_map = [
    'mkt'     => ['title' => 'title', 'desc' => 'descp', 'user' => 'created_by'],
    'shop'    => ['title' => 'title', 'desc' => 'descp', 'user' => 'created_by'],
    'xerox'   => ['title' => 'title', 'desc' => 'descp', 'user' => 'created_by'],
    'service' => ['title' => 'title', 'desc' => 'descp', 'user' => 'created_by'],
    'ti'      => ['title' => 'title', 'desc' => 'descp', 'user' => 'created_by'],
];

$label_map = ['mkt' => 'Marketing', 'shop' => 'Compras', 'xerox' => 'Reprografia', 'service' => 'Serviços', 'ti' => 'TI'];
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
    echo '<div class="main"><p>Requisição não encontrada.</p></div>';
    exit;
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
    echo '<div class="main"><div class="empty-state"><i class="fa-solid fa-lock"></i><p>Acesso negado. Você não tem permissão para visualizar esta requisição.</p></div></div>';
    exit;
}

// ---------- Tratar POST de novo comentário ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['new_comment']) || isset($_POST['new_comment_ajax']))) {
    requireCsrfTokenFromRequest();
    $comment_text = trim($_POST['new_comment'] ?? $_POST['new_comment_ajax_text'] ?? '');

    if (!empty($comment_text)) {
        $user_id   = $_SESSION['id']   ?? 0;
        $user_name = $_SESSION['name'] ?? 'Usuário';

        $ins = $pdo->prepare("INSERT INTO request_comments (request_id, request_table, user_id, user_name, comment) VALUES (?, ?, ?, ?, ?)");
        $ins->execute([$req_id, $req_table, $user_id, $user_name, $comment_text]);

        // ---------- Notificar Stakeholders (Solicitante, Gestor e Admin) ----------
        $link = "?page=request_detail&id={$req_id}&table={$req_table}#comments";
        $notifTitle = "Mensagem na Requisição #{$req_id}";
        $notifMsg   = "{$user_name}: " . (mb_strlen($comment_text) > 60 ? mb_substr($comment_text, 0, 57) . '...' : $comment_text);

        RequestManager::notifyStakeholders($pdo, $req, $req_table, $notifTitle, $notifMsg, $link, $user_id, false);

        if (!isset($_POST['new_comment_ajax'])) {
            header("Location: ?page=request_detail&id={$req_id}&table={$req_table}#comments");
            exit;
        }
        exit;
    }
}

// ---------- Buscar comentários e histórico ----------
$stmt_com = $pdo->prepare("SELECT * FROM request_comments WHERE request_id = ? AND request_table = ? ORDER BY created_at ASC");
$stmt_com->execute([$req_id, $req_table]);
$comments = $stmt_com->fetchAll();

$stmt_his = $pdo->prepare("SELECT * FROM request_history WHERE request_id = ? AND request_table = ? ORDER BY created_at DESC");
$stmt_his->execute([$req_id, $req_table]);
$history = $stmt_his->fetchAll();

// ---------- Helpers ----------
$status_label = ['P' => 'Pendente', 'Y' => 'Aprovado', 'N' => 'Recusado', 'W' => 'Em Andamento', 'C' => 'Concluída', 'F' => 'Repassada'];
$status_class = ['P' => 'pending',  'Y' => 'approved', 'N' => 'rejected', 'W' => 'in-progress', 'C' => 'completed', 'F' => 'forwarded'];
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
$histStmt = $pdo->prepare("SELECT user_name FROM request_history WHERE request_id = ? AND request_table = ? AND action LIKE 'Requisição %' AND (new_value = 'Aprovada' OR new_value = 'Rejeitada') ORDER BY id DESC LIMIT 1");
$histStmt->execute([$req_id, $req_table]);
$approverName = $histStmt->fetchColumn();

// Buscar cadeia de repasses (Fase 5)
require_once __DIR__ . '/../classes/RequestForwardService.php';
$forwardChainResult = RequestForwardService::getForwardChain($pdo, $req_id, $req_table);
$forwardChain = $forwardChainResult['data'] ?? [];
$activeForward = RequestForwardService::getActiveForward($pdo, $req_id, $req_table);

?>
<link rel="stylesheet" href="assets/css/detail.css">

<div class="main detail-page">
<input type="hidden" class="Rid" value="<?= $req_id ?>">
<input type="hidden" class="Rtype" value="<?= $req_table ?>">

<?php
// Helpers de prioridade
$priL = [1=>'Baixa', 2=>'Média', 3=>'Alta', 4=>'Crítica'];
$priCls = [1=>'pri-1', 2=>'pri-2', 3=>'pri-3', 4=>'pri-4'];
$pri = $req['priority'] ?? 2;
$status_label = ['P'=>'Pendente','Y'=>'Aprovada','N'=>'Recusada','W'=>'Em Andamento','C'=>'Concluída','F'=>'Repassada'];
$current_status = trim(strtoupper($req['status'] ?? 'P')) ?: 'P';
$title = htmlspecialchars($req[$cols['title']] ?? 'Sem título');
$desc  = htmlspecialchars($req[$cols['desc']]  ?? '');
$user  = htmlspecialchars($req['creator_name'] ?? $req[$cols['user']] ?? '—');
$date  = isset($req['created_at']) ? date('d/m/Y H:i', strtotime($req['created_at'])) : '—';
$totalMsgs = count($comments);
?>

<!-- ════════ HERO BAR ════════ -->
<div class="dp-hero">
    <div class="dp-hero-left">
        <div class="dp-hero-row1">
            <span class="dp-badge <?= $current_status ?>"><?= $status_label[$current_status] ?></span>
        </div>
        <p class="dp-hero-title"><?= $title ?></p>
        <div class="dp-hero-meta">
            <span><i class="fa-regular fa-user"></i> <?= $user ?></span>
            <span>·</span>
            <span><?= $date ?></span>
        </div>
    </div>
    <div class="dp-protocol">#<?= str_pad($req_id, 4, '0', STR_PAD_LEFT) ?></div>
</div>

<!-- ════════ BODY (Desktop: 2 colunas | Mobile: abas) ════════ -->
<div class="dp-body">

    <!-- COLUNA PRINCIPAL -->
    <div class="dp-main">

        <!-- ABA DETALHES -->
        <div class="dp-tab-section active" id="tab-detalhes">

            <!-- Card Descrição -->
            <div class="dp-card" style="margin-bottom:12px">
                <div class="dp-card-header"><h2><i class="fa-solid fa-align-left"></i> Descrição</h2></div>
                <div class="dp-card-body">
                    <div class="dp-desc"><?= nl2br($desc) ?: '<em style="color:#9ca3af">Nenhuma descrição fornecida.</em>' ?></div>
                </div>
            </div>

            <!-- Card Detalhes Técnicos -->
            <div class="dp-card" style="margin-bottom:12px">
                <div class="dp-card-header"><h2><i class="fa-solid fa-circle-info"></i> Detalhes Técnicos</h2></div>
                <div class="dp-card-body">
                    <div class="dp-info-grid">
                        <div class="dp-info-item">
                            <label>Prioridade</label>
                            <span class="<?= $priCls[$pri] ?>"><?= $priL[$pri] ?></span>
                        </div>
                        <div class="dp-info-item">
                            <label>Subdivisão</label>
                            <span><?= htmlspecialchars($subName) ?></span>
                        </div>
                        <div class="dp-info-item">
                            <label>Local / Sala</label>
                            <span><?= htmlspecialchars($req['sala'] ?? 'N/A') ?></span>
                        </div>
                        <?php if ($approverName): ?>
                        <div class="dp-info-item">
                            <label><?= $current_status === 'N' ? 'Recusada por' : 'Aprovada por' ?></label>
                            <span><?= htmlspecialchars($approverName) ?></span>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Card Repasses (condicional) -->
            <?php if (!empty($forwardChain)): ?>
            <div class="dp-card" style="margin-bottom:12px">
                <div class="dp-card-header"><h2><i class="fa-solid fa-share-from-square"></i> Cadeia de Repasses</h2></div>
                <div class="dp-card-body">
                    <?php if ($activeForward && ($isGestor || $isGlobalAdmin)):
                        $canActOnFwd = $isGlobalAdmin;
                        if (!$isGlobalAdmin) {
                            $chk = $pdo->prepare("SELECT COUNT(*) FROM cfg_user_area WHERE id_user=? AND id_area=?");
                            $chk->execute([$_SESSION['id'], $activeForward['to_area_id']]);
                            $canActOnFwd = (int)$chk->fetchColumn() > 0;
                        }
                        if ($canActOnFwd && $activeForward['status'] === 'pending'): ?>
                        <div style="padding:12px;background:#eff6ff;border:1px solid #bfdbfe;border-radius:6px;margin-bottom:12px">
                            <p style="margin:0 0 10px;font-size:.88rem;font-weight:600;color:#1d4ed8">Este repasse aguarda sua aceitação.</p>
                            <div style="display:flex;gap:8px">
                                <button class="dp-btn dp-btn-finish" style="padding:8px 14px" onclick="handleForwardAction('accept',<?= $activeForward['id'] ?>)">Aceitar</button>
                                <button class="dp-btn dp-btn-reopen" style="padding:8px 14px" onclick="handleForwardAction('refuse',<?= $activeForward['id'] ?>)">Recusar</button>
                            </div>
                        </div>
                        <?php elseif ($canActOnFwd && $activeForward['status'] === 'accepted'): ?>
                        <div style="padding:12px;background:#ecfdf5;border:1px solid #a7f3d0;border-radius:6px;margin-bottom:12px">
                            <p style="margin:0 0 10px;font-size:.88rem;font-weight:600;color:#065f46">Repasse aceito. Conclua ao finalizar o atendimento.</p>
                            <button class="dp-btn dp-btn-finish" style="padding:8px 14px" onclick="handleForwardAction('complete',<?= $activeForward['id'] ?>)">Concluir via Repasse</button>
                        </div>
                        <?php endif; ?>
                    <?php endif; ?>
                    <div class="dp-fwd-list">
                        <?php foreach ($forwardChain as $fwd): ?>
                        <div class="dp-fwd-item">
                            <div class="dp-fwd-route"><?= htmlspecialchars($fwd['from_area_label']) ?> → <?= htmlspecialchars($fwd['to_area_label']) ?></div>
                            <div class="dp-fwd-meta">
                                Encaminhado por <?= htmlspecialchars($fwd['forwarded_by_name'] ?? '—') ?> · <?= date('d/m/Y H:i', strtotime($fwd['created_at'])) ?>
                                <?php if ($fwd['received_by_name']): ?> · Recebido por <?= htmlspecialchars($fwd['received_by_name']) ?><?php endif; ?>
                            </div>
                            <?php if (!empty($fwd['observation'])): ?>
                            <div class="dp-fwd-obs">"<?= htmlspecialchars($fwd['observation']) ?>"</div>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Painel de Controle (admin) — visível na coluna principal no mobile -->
            <?php if ($role !== 'solicitante'): ?>
            <div class="dp-card dp-desktop-only" style="margin-bottom:12px">
                <div class="dp-card-header"><h2><i class="fa-solid fa-sliders"></i> Painel de Controle</h2></div>
                <div class="dp-card-body">
                    <?php if ($canManageThisRequest): ?>
                    <label class="dp-ctrl-label">PRIORIDADE</label>
                    <select class="dp-ctrl-select" onchange="globalRequestAction('set_priority','<?= $req_id ?>','ctd_<?= $req_table ?>_frm',this.value)">
                        <option value="1" <?= $pri==1?'selected':'' ?>>Baixa</option>
                        <option value="2" <?= $pri==2?'selected':'' ?>>Média</option>
                        <option value="3" <?= $pri==3?'selected':'' ?>>Alta</option>
                        <option value="4" <?= $pri==4?'selected':'' ?>>Crítica</option>
                    </select>
                    <?php endif; ?>
                    <div class="dp-btns">
                        <?php if ($isGestor && $canManageThisRequest && $current_status==='W'): ?>
                        <button class="dp-btn dp-btn-finish" onclick="globalRequestAction('finish','<?= $req_id ?>','ctd_<?= $req_table ?>_frm')"><i class="fa-solid fa-check"></i> Concluir</button>
                        <?php elseif ($isGestor && $canManageThisRequest && in_array($current_status,['Y','A'])): ?>
                        <button class="dp-btn dp-btn-start" onclick="globalRequestAction('start_progress','<?= $req_id ?>','ctd_<?= $req_table ?>_frm')"><i class="fa-solid fa-play"></i> Iniciar Atendimento</button>
                        <?php endif; ?>
                        <?php if (in_array($current_status,['C','N']) && $canManageThisRequest): ?>
                        <button class="dp-btn dp-btn-reopen" onclick="globalRequestAction('reset_status','<?= $req_id ?>','ctd_<?= $req_table ?>_frm')"><i class="fa-solid fa-rotate-left"></i> Reabrir</button>
                        <?php endif; ?>
                        <?php if (in_array($current_status,['W','F']) && $canManageThisRequest): ?>
                        <button class="dp-btn dp-btn-fwd" onclick="openForwardModal()"><i class="fa-solid fa-arrow-up-right-from-square"></i> Repassar</button>
                        <?php endif; ?>
                        <?php if ($isAdmin || $isGestor): ?>
                        <a href="print_request.php?id=<?= $req_id ?>&table=<?= $req_table ?>" target="_blank" class="dp-btn dp-btn-pdf"><i class="fa-solid fa-file-pdf"></i> Gerar Relatório PDF</a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Timeline Histórico -->
            <div class="dp-card" style="margin-bottom:12px">
                <div class="dp-card-header"><h2><i class="fa-solid fa-clock-rotate-left"></i> Histórico de Atividade</h2></div>
                <div class="dp-card-body">
                    <div class="dp-timeline">
                        <?php
                        $histCount = count($history);
                        foreach ($history as $i => $h):
                            $hiddenCls = ($i >= 3) ? 'dp-tl-extra' : '';
                            $dotCls = 'action';
                            if (stripos($h['action'],'Criou')!==false) $dotCls='create';
                            if (stripos($h['action'],'conclu')!==false) $dotCls='finish';
                        ?>
                        <div class="dp-tl-item <?= $hiddenCls ?>" <?= $i>=3 ? 'style="display:none"' : '' ?>>
                            <div class="dp-tl-dot <?= $dotCls ?>"></div>
                            <div class="dp-tl-time"><?= date('d/m/Y H:i', strtotime($h['created_at'])) ?></div>
                            <div class="dp-tl-text"><strong><?= htmlspecialchars($h['user_name']) ?></strong> <?= htmlspecialchars($h['action']) ?></div>
                        </div>
                        <?php endforeach; ?>
                        <?php if ($histCount > 3): ?>
                        <button class="dp-tl-more" id="btnVerMais" onclick="showAllHistory()">Ver mais <?= $histCount-3 ?> eventos</button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

        </div><!-- /tab-detalhes -->

        <!-- ABA CHAT MOBILE -->
        <div class="dp-chat-mobile" id="tab-chat">
            <div class="dp-chat-feed" id="chatFeedMobile">
                <?php include_once __DIR__ . '/../partials/_chat_feed.php'; ?>
            </div>
        </div>

    </div><!-- /dp-main -->

    <!-- COLUNA CHAT (desktop) -->
    <div class="dp-chat-col">
        <div class="dp-chat-col-header">
            <h2><i class="fa-regular fa-comments"></i> Comunicação</h2>
            <span class="dp-unread-badge" id="unreadBadge"><?= $totalMsgs ?></span>
        </div>
        <div class="dp-chat-feed" id="chatFeedDesktop">
            <?php
            if ($totalMsgs > 0):
                $lastDate = '';
                foreach ($comments as $c):
                    $cd = date('d/m/Y', strtotime($c['created_at']));
                    if ($cd !== $lastDate): $lastDate = $cd;
                        echo '<div class="dp-chat-date-sep">'.$cd.'</div>';
                    endif;
                    $mine = ($c['user_id'] == ($_SESSION['id'] ?? -1));
                    $init = strtoupper(substr($c['user_name'],0,1));
            ?>
            <div class="dp-msg <?= $mine?'mine':'theirs' ?>">
                <div class="dp-avatar" style="background:<?= avatarColor($c['user_name']) ?>;color:#fff"><?= $init ?></div>
                <div class="dp-bubble">
                    <div class="dp-bubble-name"><?= htmlspecialchars($c['user_name']) ?></div>
                    <?= nl2br(htmlspecialchars($c['comment'])) ?>
                    <div class="dp-bubble-time"><?= date('H:i',strtotime($c['created_at'])) ?></div>
                </div>
            </div>
            <?php endforeach; else: ?>
            <div class="dp-chat-empty"><i class="fa-regular fa-comments"></i>Nenhuma mensagem ainda.</div>
            <?php endif; ?>
        </div>
        <div class="dp-chat-input-area">
            <form id="commentFormDesktop" style="display:flex;width:100%;gap:10px;align-items:flex-end;margin:0">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(getCsrfToken(),ENT_QUOTES,'UTF-8') ?>">
                <textarea name="new_comment_ajax_text" class="dp-chat-input" placeholder="Escreva aqui..." rows="1" required oninput="this.style.height='';this.style.height=this.scrollHeight+'px'"></textarea>
                <button type="submit" class="dp-btn-send"><i class="fa-solid fa-paper-plane"></i></button>
            </form>
        </div>
    </div><!-- /dp-chat-col -->

</div><!-- /dp-body -->

</div><!-- /detail-page -->

<!-- ════════ MOBILE: BOTTOM NAV ════════ -->
<div class="dp-bottom-nav">
    <button class="dp-nav-btn active" id="navDetalhes" onclick="switchTab('detalhes')">
        <i class="fa-solid fa-list"></i> Detalhes
    </button>
    <button class="dp-nav-btn" id="navChat" onclick="switchTab('chat')">
        <i class="fa-regular fa-comments"></i> Chat
        <span class="dp-nav-badge <?= $totalMsgs>0?'visible':'' ?>" id="mobileBadge"><?= $totalMsgs ?></span>
    </button>
</div>

<!-- ════════ MOBILE: INPUT FIXO (chat ativo) ════════ -->
<div class="dp-chat-sticky-input" id="stickyInput">
    <form id="commentFormMobile" style="display:flex;width:100%;gap:10px;align-items:flex-end;margin:0">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(getCsrfToken(),ENT_QUOTES,'UTF-8') ?>">
        <textarea name="new_comment_ajax_text" class="dp-chat-input" placeholder="Escreva aqui..." rows="1" required oninput="this.style.height='';this.style.height=this.scrollHeight+'px'"></textarea>
        <button type="submit" class="dp-btn-send"><i class="fa-solid fa-paper-plane"></i></button>
    </form>
</div>

<!-- ════════ MOBILE: FAB AÇÕES ADMIN ════════ -->
<?php if ($role !== 'solicitante'): ?>
<button class="dp-fab" id="fabAcoes" onclick="openSheet()" title="Ações">
    <i class="fa-solid fa-bolt"></i>
</button>

<!-- Bottom Sheet Admin -->
<div class="dp-sheet-overlay" id="sheetOverlay" onclick="closeSheet()"></div>
<div class="dp-sheet" id="actionSheet">
    <div class="dp-sheet-handle"></div>
    <h3>Painel de Controle</h3>
    <?php if ($canManageThisRequest): ?>
    <label class="dp-ctrl-label">PRIORIDADE</label>
    <select class="dp-ctrl-select" onchange="globalRequestAction('set_priority','<?= $req_id ?>','ctd_<?= $req_table ?>_frm',this.value);closeSheet()">
        <option value="1" <?= $pri==1?'selected':'' ?>>Baixa</option>
        <option value="2" <?= $pri==2?'selected':'' ?>>Média</option>
        <option value="3" <?= $pri==3?'selected':'' ?>>Alta</option>
        <option value="4" <?= $pri==4?'selected':'' ?>>Crítica</option>
    </select>
    <?php endif; ?>
    <div class="dp-btns">
        <?php if ($isGestor && $canManageThisRequest && $current_status==='W'): ?>
        <button class="dp-btn dp-btn-finish" onclick="globalRequestAction('finish','<?= $req_id ?>','ctd_<?= $req_table ?>_frm');closeSheet()"><i class="fa-solid fa-check"></i> Concluir</button>
        <?php elseif ($isGestor && $canManageThisRequest && in_array($current_status,['Y','A'])): ?>
        <button class="dp-btn dp-btn-start" onclick="globalRequestAction('start_progress','<?= $req_id ?>','ctd_<?= $req_table ?>_frm');closeSheet()"><i class="fa-solid fa-play"></i> Iniciar Atendimento</button>
        <?php endif; ?>
        <?php if (in_array($current_status,['C','N']) && $canManageThisRequest): ?>
        <button class="dp-btn dp-btn-reopen" onclick="globalRequestAction('reset_status','<?= $req_id ?>','ctd_<?= $req_table ?>_frm');closeSheet()"><i class="fa-solid fa-rotate-left"></i> Reabrir</button>
        <?php endif; ?>
        <?php if (in_array($current_status,['W','F']) && $canManageThisRequest): ?>
        <button class="dp-btn dp-btn-fwd" onclick="openForwardModal();closeSheet()"><i class="fa-solid fa-arrow-up-right-from-square"></i> Repassar</button>
        <?php endif; ?>
        <?php if ($isAdmin || $isGestor): ?>
        <a href="print_request.php?id=<?= $req_id ?>&table=<?= $req_table ?>" target="_blank" class="dp-btn dp-btn-pdf"><i class="fa-solid fa-file-pdf"></i> Gerar PDF</a>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<script>
<?php
// Função PHP de cor de avatar (hash do nome)
function avatarColor($name) {
    $colors = ['#2c2b31','#3b82f6','#10b981','#8b5cf6','#f59e0b','#ef4444','#06b6d4','#ec4899'];
    return $colors[crc32($name) % count($colors)];
}
?>

// ── Tab switching (mobile) ──
function switchTab(tab) {
    const isMobile = window.innerWidth <= 768;
    if (!isMobile) return;
    document.getElementById('tab-detalhes').classList.toggle('active', tab === 'detalhes');
    document.getElementById('tab-chat').classList.toggle('active', tab === 'chat');
    document.getElementById('navDetalhes').classList.toggle('active', tab === 'detalhes');
    document.getElementById('navChat').classList.toggle('active', tab === 'chat');
    const sticky = document.getElementById('stickyInput');
    sticky.classList.toggle('visible', tab === 'chat');
    if (tab === 'chat') {
        scrollChat('chatFeedMobile');
        setTimeout(() => sticky.querySelector('textarea').focus(), 100);
    }
}

// ── Bottom sheet (FAB admin) ──
function openSheet()  { document.getElementById('actionSheet').classList.add('open'); document.getElementById('sheetOverlay').classList.add('open'); }
function closeSheet() { document.getElementById('actionSheet').classList.remove('open'); document.getElementById('sheetOverlay').classList.remove('open'); }

// ── Scroll chat ──
function scrollChat(id) { const el = document.getElementById(id); if (el) el.scrollTop = el.scrollHeight; }

// ── Expandir timeline ──
function showAllHistory() {
    document.querySelectorAll('.dp-tl-extra').forEach(el => el.style.display = '');
    document.getElementById('btnVerMais').style.display = 'none';
}

// ── AJAX comentários ──
function sendComment(formId, feedId) {
    const form = document.getElementById(formId);
    if (!form) return;
    form.addEventListener('submit', function(e) {
        e.preventDefault();
        const fd = new FormData(this);
        fd.append('new_comment_ajax','1');
        const btn = this.querySelector('button[type=submit]');
        btn.disabled = true;
        fetch(window.location.href, {method:'POST', body:fd})
        .then(() => { this.reset(); this.querySelector('textarea').style.height=''; btn.disabled=false; loadComments(); });
    });
    form.querySelector('textarea').addEventListener('keydown', function(e) {
        if (e.key==='Enter' && !e.shiftKey) { e.preventDefault(); form.dispatchEvent(new Event('submit',{cancelable:true,bubbles:true})); }
    });
}
sendComment('commentFormDesktop','chatFeedDesktop');
sendComment('commentFormMobile','chatFeedMobile');

// ── Polling de comentários ──
function loadComments() {
    fetch(`api/get_comments.php?id=<?= $req_id ?>&table=<?= $req_table ?>`)
    .then(r => r.text())
    .then(html => {
        ['chatFeedDesktop','chatFeedMobile'].forEach(id => {
            const el = document.getElementById(id);
            if (!el) return;
            const atBottom = el.scrollHeight - el.scrollTop <= el.clientHeight + 80;
            if (el.innerHTML !== html) { el.innerHTML = html; if(atBottom) scrollChat(id); }
        });
    });
}

// ── Ajusta top do chat sticky para respeitar a topbar real do sistema ──
function adjustChatSticky() {
    if (window.innerWidth <= 768) return;
    const topBar = document.querySelector('header.top-bar');
    const topBarH = topBar ? topBar.offsetHeight : 60;
    const chatCol = document.querySelector('.dp-chat-col');
    if (chatCol) {
        chatCol.style.top = topBarH + 'px';
        chatCol.style.height = (window.innerHeight - topBarH) + 'px';
    }
}
adjustChatSticky();
window.addEventListener('resize', adjustChatSticky);

// Inicialização
scrollChat('chatFeedDesktop');
setInterval(loadComments, 3000);
</script>