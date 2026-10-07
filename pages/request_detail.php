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
    echo '<div class="main"><div class="empty-big"><i class="fa-solid fa-circle-question"></i><h2>Requisição não encontrada</h2><p>O endereço está incompleto.</p><a class="btn-big" href="home"><i class="fa-solid fa-arrow-left"></i> Voltar ao início</a></div></div>';
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
    echo '<div class="main"><div class="empty-big"><i class="fa-solid fa-circle-question"></i><h2>Requisição não encontrada</h2><p>Ela pode ter sido cancelada.</p><a class="btn-big" href="home"><i class="fa-solid fa-arrow-left"></i> Voltar ao início</a></div></div>';
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
    echo '<div class="main"><div class="empty-big"><i class="fa-solid fa-lock"></i><h2>Sem acesso</h2><p>Você não tem permissão para ver esta requisição.</p><a class="btn-big" href="home"><i class="fa-solid fa-arrow-left"></i> Voltar ao início</a></div></div>';
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
    'P' => 'Aguardando a aprovação da coordenação. Você será avisado no sininho quando houver resposta.',
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

<div class="main detail-page">
    <input type="hidden" class="Rid" value="<?= $req_id ?>">
    <input type="hidden" class="Rtype" value="<?= $req_table ?>">


    <style>
        .detail-page { padding: 20px 24px; }
        @media (max-width: 768px) { .detail-page { padding: 12px; } }
        :root {
            --ml-primary: #2c2b31;
            --ml-bg: #f8fafc;
            --ml-surface: #ffffff;
            --ml-border: #e2e8f0;
            --ml-text: #2c2b31;
            --ml-text-light: #64748b;
            --radius: 12px;
            --shadow: 0 1px 3px rgba(0,0,0,0.06);
        }


        /* Cabeçalho da página */
        .req-detail-header {
            display: block;
            margin-bottom: 20px;
        }
        .page-breadcrumb {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 14px;
        }
        .breadcrumb-back {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            color: var(--ml-text-light);
            text-decoration: none;
            font-size: 0.82rem;
            font-weight: 600;
            padding: 5px 10px 5px 6px;
            border-radius: 8px;
            transition: background 0.15s, color 0.15s;
        }
        .breadcrumb-back:hover { background: #f1f5f9; color: var(--ml-text); }
        .breadcrumb-sep { color: #cbd5e1; font-size: 0.75rem; }
        .breadcrumb-current { font-size: 0.82rem; color: var(--ml-text-light); font-weight: 500; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 240px; }
        .hero-card {
            background: #fff;
            border: 1px solid var(--ml-border);
            border-radius: 12px;
            padding: 18px 22px;
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 16px;
        }
        .hero-card-left { flex: 1; min-width: 0; }
        .hero-card-left h1 { font-size: 1.25rem; font-weight: 800; color: var(--ml-text); margin: 0 0 8px 0; letter-spacing: -0.02em; line-height: 1.3; }
        .hero-meta { display: flex; flex-wrap: wrap; gap: 10px; font-size: 0.82rem; color: var(--ml-text-light); align-items: center; font-weight: 500; }
        .hero-meta-item { display: flex; align-items: center; gap: 5px; }
        .hero-card-right { display: flex; flex-direction: column; align-items: flex-end; gap: 8px; flex-shrink: 0; }
        .detail-steps { display: grid; grid-template-columns: repeat(4, 1fr); gap: 6px; margin: 14px 0 0; padding: 0; list-style: none; }
        .detail-steps li { text-align: center; font-size: 0.82rem; font-weight: 600; color: #94a3b8; }
        .detail-steps li::before { content: ''; display: block; height: 6px; border-radius: 3px; background: #e2e8f0; margin-bottom: 6px; }
        .detail-steps li.done { color: #166534; }
        .detail-steps li.done::before { background: #16a34a; }
        .detail-steps li.current { color: #0f172a; }
        .detail-steps.refused li::before { background: #fecaca; }
        .approve-box { display: flex; gap: 10px; flex-wrap: wrap; margin-top: 12px; }
        .approve-box .btn-big { flex: 1 1 160px; }
        .protocol-badge { font-family: 'JetBrains Mono', monospace; background: #f8fafc; padding: 5px 10px; border: 1px solid var(--ml-border); border-radius: 8px; font-size: 0.9rem; font-weight: 700; color: var(--ml-text-light); }

        /* Grid */
        .detail-grid { display: grid; grid-template-columns: 1fr 380px; gap: 24px; align-items: start; }

        /* Cards */
        .card { background: var(--ml-surface); border-radius: 16px; box-shadow: 0 2px 4px rgba(0,0,0,0.02), 0 1px 2px rgba(0,0,0,0.03); border: 1px solid rgba(226, 232, 240, 0.8); margin-bottom: 20px; overflow: hidden; }
        .card:hover { transform: none !important; background: var(--ml-surface) !important; border-color: rgba(226, 232, 240, 0.8) !important; }
        .card-header { padding: 16px 20px; border-bottom: 1px solid var(--ml-border); display: flex; justify-content: space-between; align-items: center; transition: background 0.2s ease; }
        .card[onclick] .card-header, .card-header[onclick] { cursor: pointer; }
        .card[onclick] .card-header:hover, .card-header[onclick]:hover { background: #f8fafc; }
        .card-header h2 { font-size: 0.95rem; font-weight: 700; margin: 0; display: flex; align-items: center; gap: 10px; color: var(--ml-text); }
        .card-body { padding: 24px; }
        .card.collapsed .card-body { display: none; }
        .toggle-icon { font-size: 0.85rem; color: var(--ml-text-light); transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1); }
        .card.collapsed .toggle-icon { transform: rotate(-90deg); }

        /* Info */
        .info-grid-simple { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 16px; }
        .info-box { padding: 12px 14px; background: #f8fafc; border-radius: 10px; border: 1px solid #f1f5f9; }
        .info-box label { display: block; font-size: 0.68rem; font-weight: 800; color: var(--ml-text-light); text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 4px; }
        .info-box span { font-weight: 700; font-size: 0.95rem; color: var(--ml-text); }

        /* Timeline */
        .timeline { position: relative; padding-left: 32px; }
        .timeline::before { content: ''; position: absolute; left: 13px; top: 0; bottom: 0; width: 2px; background: #e2e8f0; border-radius: 2px; }
        .timeline-event { position: relative; margin-bottom: 24px; }
        .timeline-dot { position: absolute; left: -32px; width: 28px; height: 28px; background: #fff; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 0.7rem; z-index: 1; box-shadow: 0 0 0 4px #fff; border: 2px solid #cbd5e1; }
        .dot-comentario { border-color: #3b82f6; color: #3b82f6; background: #eff6ff; }
        .dot-reaberto { border-color: #f59e0b; color: #f59e0b; background: #fffbeb; }
        .dot-concluido { border-color: #10b981; color: #10b981; background: #ecfdf5; }
        .dot-aberto { border-color: #94a3b8; color: #94a3b8; background: #f8fafc; }
        .timeline-content { background: #fff; padding: 14px 16px; border-radius: 12px; border: 1px solid var(--ml-border); box-shadow: 0 1px 2px rgba(0,0,0,0.02); }
        .timeline-time { font-size: 0.75rem; font-weight: 800; color: var(--ml-text-light); margin-bottom: 4px; }

        /* Actions */
        .actions-group { display: flex; flex-direction: column; gap: 10px; }
        .btn-action { width: 100%; padding: 14px; border-radius: 12px; border: 1px solid var(--ml-border); background: #fff; font-weight: 700; font-size: 0.9rem; cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 8px; transition: all 0.2s ease; box-shadow: 0 1px 2px rgba(0,0,0,0.02); color: var(--ml-text); }
        .btn-action:hover { background: #f8fafc; transform: translateY(-1px); box-shadow: 0 4px 6px rgba(0,0,0,0.04); }
        .btn-primary { background: var(--ml-primary); color: #fff; border: none; box-shadow: 0 4px 12px rgba(44,43,49,0.2); }
        .btn-primary:hover { background: #3d3c42; color: #fff; box-shadow: 0 6px 16px rgba(44,43,49,0.3); }
        .ml-label { font-size: 0.75rem; font-weight: 800; color: var(--ml-text-light); margin-bottom: 8px; display: block; text-transform: uppercase; letter-spacing: 0.5px; }
        .ml-select { width: 100%; padding: 12px; border-radius: 10px; border: 1px solid var(--ml-border); background: #fff; font-weight: 600; color: var(--ml-text); outline: none; transition: border-color 0.2s; cursor: pointer; }
        .ml-select:focus { border-color: var(--ml-primary); box-shadow: 0 0 0 3px rgba(44,43,49,0.1); }
        .divider { height: 1px; background: var(--ml-border); margin: 16px 0; }

        /* Chat */
        .chat-card { display: flex; flex-direction: column; max-height: 560px; }
        .chat-messages { flex: 1; overflow-y: auto; padding: 20px; background: #f8fafc; display: flex; flex-direction: column; gap: 16px; }
        .chat-item { display: flex; gap: 12px; max-width: 75%; }
        .chat-item.mine { align-self: flex-end; flex-direction: row-reverse; }
        .chat-item.theirs { align-self: flex-start; }
        .chat-avatar { width: 36px; height: 36px; border-radius: 50%; background: var(--ml-primary); color: #fff; display: flex; align-items: center; justify-content: center; font-size: 0.85rem; font-weight: 800; flex-shrink: 0; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .chat-item.mine .chat-avatar { background: #3d3c42; }
        .chat-bubble { padding: 12px 16px; border-radius: 16px; font-size: 0.9rem; line-height: 1.5; box-shadow: 0 1px 2px rgba(0,0,0,0.05); position: relative; }
        .chat-bubble.mine { background: var(--ml-primary); color: #fff; border-bottom-right-radius: 4px; }
        .chat-bubble.theirs { background: #fff; color: var(--ml-text); border: 1px solid var(--ml-border); border-bottom-left-radius: 4px; }
        .chat-user-name { font-size: 0.65rem; font-weight: 800; margin-bottom: 4px; color: var(--ml-text-light); }
        .chat-item.mine .chat-user-name { text-align: right; color: #94a3b8; }
        .chat-date-header { text-align: center; font-size: 0.7rem; font-weight: 800; color: var(--ml-text-light); text-transform: uppercase; margin: 8px 0; letter-spacing: 0.5px; }
        .chat-footer { padding: 16px 20px; border-top: 1px solid var(--ml-border); background: #fff; border-bottom-left-radius: 16px; border-bottom-right-radius: 16px; }
        .chat-input-area { display: flex; gap: 10px; align-items: flex-end; }
        .chat-input { flex: 1; border: 1px solid var(--ml-border); border-radius: 24px; padding: 12px 18px; font-size: 0.95rem; resize: none; max-height: 120px; outline: none; background: #f8fafc; transition: all 0.2s; font-family: inherit; }
        .chat-input:focus { background: #fff; border-color: var(--ml-primary); box-shadow: 0 0 0 3px rgba(44,43,49,0.1); }
        .btn-send { width: 44px; height: 44px; border-radius: 50%; background: var(--ml-primary); color: #fff; border: none; cursor: pointer; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }

        .btn-back { display: flex; align-items: center; justify-content: center; gap: 8px; margin-top: 16px; color: var(--ml-text-light); text-decoration: none; font-size: 0.9rem; font-weight: 700; padding: 12px; border-radius: 12px; transition: all 0.2s; }
        .btn-back:hover { color: var(--ml-primary); background: #f1f0f2; }

        /* FAB */
        .fab-chat { position: fixed; right: 20px; bottom: 88px; width: 52px; height: 52px; border-radius: 50%; background: var(--ml-primary); color: #fff; display: flex; align-items: center; justify-content: center; font-size: 1.3rem; box-shadow: 0 2px 8px rgba(44,43,49,0.25); z-index: 99; border: none; cursor: pointer; opacity: 0; pointer-events: none; }
        .fab-chat.visible { opacity: 1; pointer-events: auto; }

        /* ===== MOBILE ===== */
        @media (max-width: 768px) {
            .detail-grid { display: flex; flex-direction: column; gap: 12px; width: 100%; box-sizing: border-box; }
            .main-column, .sidebar-column { display: contents; }

            /* Ordem de leitura no mobile */
            .card { order: 10; margin-bottom: 0 !important; border-radius: 12px; width: 100%; box-sizing: border-box; }
            .card-description { order: 1; }
            .chat-card { order: 2; max-height: 420px !important; }
            .card-actions { order: 3; }
            .card-details { order: 4; }
            .card-forward { order: 5; }
            .card-history { order: 6; }

            /* Cabeçalho mobile */
            .req-detail-header { margin-bottom: 16px; }
            .page-breadcrumb { margin-top: 12px; margin-bottom: 10px; margin-left: 12px; }
            .hero-card { flex-direction: column; gap: 12px; padding: 14px 16px; }
            .hero-card-right { flex-direction: row; align-items: center; justify-content: flex-start; width: 100%; }
            .hero-card-left h1 { font-size: 1.1rem; }
            .breadcrumb-current { max-width: 160px; }

            /* Cards */
            .card-header { padding: 13px 16px; }
            .card-body { padding: 14px 16px; }

            /* Info: 2 colunas no mobile */
            .info-grid-simple { grid-template-columns: repeat(2, 1fr); gap: 10px; }

            /* Chat */
            .chat-messages { min-height: 200px; padding: 14px; gap: 12px; }
            .chat-footer { padding: 10px 14px; }
            .chat-input { font-size: 16px !important; padding: 10px 14px; }
            .btn-send { width: 40px; height: 40px; min-width: 40px; font-size: 0.9rem; }
            .chat-item { max-width: 85%; }

            /* Botão voltar */
            .btn-back { order: 10; margin: 8px 0 20px; padding: 12px 16px; background: #fff; border: 1px solid var(--ml-border); justify-content: flex-start; width: 100%; box-sizing: border-box; }
        }
    </style>



    <!-- CABEÇALHO CLEAN -->
    <div class="req-detail-header">
        <!-- Breadcrumb / Navegação de volta -->
        <div class="page-breadcrumb">
            <a href="<?= $isOwner ? 'myRequests' : 'home' ?>" class="breadcrumb-back">
                <i class="fa-solid fa-arrow-left" style="font-size:0.8rem;"></i>
                Voltar
            </a>
        </div>

        <!-- Hero card compacto -->
        <div class="hero-card">
            <div class="hero-card-left">
                <h1><?= $title ?></h1>
                <div class="hero-meta">
                    <span class="hero-meta-item">
                        <i class="fa-regular fa-user" style="font-size:0.75rem;"></i>
                        <strong><?= $user ?></strong>
                    </span>
                    <span class="breadcrumb-sep">·</span>
                    <span class="hero-meta-item">
                        <i class="fa-regular fa-calendar" style="font-size:0.75rem;"></i>
                        <?= $date ?>
                    </span>
                    <span class="breadcrumb-sep">·</span>
                    <span class="hero-meta-item">
                        <i class="fa-solid fa-tag" style="font-size:0.7rem;"></i>
                        <?= e($cat) ?>
                    </span>
                    <span class="breadcrumb-sep">·</span>
                    <span class="hero-meta-item">
                        <i class="fa-regular fa-calendar-check" style="font-size:0.75rem;"></i>
                        Precisa para: <strong><?= e($deadlineText) ?></strong>
                    </span>
                </div>
                <ol class="detail-steps <?= $current_status === 'N' ? 'refused' : '' ?>" aria-label="Etapas da requisição">
                    <?php foreach ($stepNames as $i => $stepName): ?>
                        <li class="<?= ($current_status !== 'N' && $i <= $stepIndex) ? 'done' : '' ?> <?= $i === $stepIndex ? 'current' : '' ?>"><?= e($stepName) ?></li>
                    <?php endforeach; ?>
                </ol>
            </div>
            <div class="hero-card-right">
                <span class="status-pill status-pill--<?= e($current_status) ?>"><?= e(statusLabel($current_status)) ?></span>
                <span class="protocol-badge">#<?= $req_id ?></span>
            </div>
        </div>

        <div class="next-step" style="margin-top:14px;">
            <i class="fa-solid fa-circle-info" aria-hidden="true"></i>
            <div>
                <strong>O que acontece agora:</strong> <?= e($nextStepText) ?>
                <?php if ($canApproveHere): ?>
                    <div class="approve-box">
                        <button type="button" class="btn-big btn-big--success" onclick="decideRequest('Y')"><i class="fa-solid fa-check"></i> Aprovar</button>
                        <button type="button" class="btn-big btn-big--danger" onclick="decideRequest('N')"><i class="fa-solid fa-xmark"></i> Recusar</button>
                    </div>
                <?php endif; ?>
                <?php if ($isOwner && $current_status === 'P'): ?>
                    <div class="approve-box">
                        <button type="button" class="btn-big btn-big--danger" onclick="globalRequestAction('delete', '<?= $req_id ?>', 'ctd_<?= $req_table ?>_frm')"><i class="fa-solid fa-trash-can"></i> Cancelar esta requisição</button>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- 2. GRID DE DUAS COLUNAS -->
    <div class="detail-grid">
        <!-- Coluna Esquerda (65%) -->
        <div class="main-column">
            <!-- Card Descrição -->
            <div class="card card-description">
                <div class="card-header">
                    <h2><i class="fa-solid fa-align-left" style="color: var(--ml-primary);"></i> O que foi pedido</h2>
                </div>
                <div class="card-body">
                    <div style="line-height: 1.7; color: var(--ml-text); font-size: 1.05rem;">
                        <?= nl2br($desc) ?: '<em style="color:var(--ml-text-light)">Nenhuma descrição fornecida.</em>' ?>
                    </div>
                    <?php if ($extraFields): ?>
                        <div class="info-grid-simple" style="margin-top:16px;">
                            <?php foreach ($extraFields as $label => $value): ?>
                                <div class="info-box">
                                    <label><?= e($label) ?></label>
                                    <span><?= e($value !== '' ? $value : '—') ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Card Informações (Grid Limpo) -->
            <div class="card card-details">
                <div class="card-header" onclick="toggleCard(this)">
                    <h2><i class="fa-solid fa-circle-info" style="color: var(--ml-primary);"></i> Mais informações</h2>
                    <i class="fa-solid fa-chevron-down toggle-icon"></i>
                </div>
                <div class="card-body">
                    <div class="info-grid-simple">
                        <div class="info-box">
                            <label>Prioridade</label>
                            <span style="color: <?= [1 => '#047857', 2 => '#334155', 3 => '#c2410c', 4 => '#b91c1c'][(int)($req['priority'] ?? 2)] ?? '#334155' ?>;">
                                <?= e(priorityLabel((int)($req['priority'] ?? 2))) ?>
                            </span>
                        </div>
                        <div class="info-box">
                            <label>Subdivisão</label>
                            <span><?= htmlspecialchars($subName) ?></span>
                        </div>
                        <div class="info-box">
                            <label>Prazo pedido</label>
                            <span><?= e($deadlineText) ?></span>
                        </div>
                        <?php if ($approverName): ?>
                        <div class="info-box">
                            <label><?= ($current_status === 'N') ? 'Recusada por' : 'Aprovada por' ?></label>
                            <span><?= htmlspecialchars($approverName) ?></span>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <?php if (!empty($forwardChain)): ?>
            <!-- Card Cadeia de Repasses (Fase 5) - COLAPSÁVEL -->
            <div class="card card-forward <?= ($activeForward && $activeForward['status'] === 'pending' && $isGestor) ? '' : 'collapsed' ?>">
                <div class="card-header" onclick="toggleCard(this)">
                    <h2><i class="fa-solid fa-share-from-square" style="color: #d97706;"></i> Encaminhamentos entre setores</h2>
                    <i class="fa-solid fa-chevron-down toggle-icon"></i>
                </div>
                <div class="card-body">
                    <?php if ($activeForward && ($isGestor || $isGlobalAdmin)): ?>
                        <?php
                            // Verifica se o gestor logado tem permissão sobre o setor de destino
                            $canActOnForward = false;
                            if ($isGlobalAdmin) {
                                $canActOnForward = true;
                            } else {
                                $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM cfg_user_area WHERE id_user = ? AND id_area = ?");
                                $checkStmt->execute([$_SESSION['id'], $activeForward['to_area_id']]);
                                $canActOnForward = (int)$checkStmt->fetchColumn() > 0;
                            }
                        ?>
                        <?php if ($canActOnForward && $activeForward['status'] === 'pending'): ?>
                            <div class="forward-pending-indicator">
                                <i class="fa-solid fa-clock"></i>
                                <span>Outro setor encaminhou este pedido para você. Vai assumir o atendimento?</span>
                            </div>
                            <div style="display: flex; gap: 10px; margin-bottom: 20px;">
                                <button class="btn-accept-forward" onclick="handleForwardAction('accept', <?= $activeForward['id'] ?>)">
                                    <i class="fa-solid fa-check"></i> Aceitar e atender
                                </button>
                                <button class="btn-refuse-forward" onclick="handleForwardAction('refuse', <?= $activeForward['id'] ?>)">
                                    <i class="fa-solid fa-xmark"></i> Recusar
                                </button>
                            </div>
                        <?php elseif ($canActOnForward && $activeForward['status'] === 'accepted'): ?>
                            <div class="forward-pending-indicator" style="background: linear-gradient(135deg, #eff6ff, #dbeafe); border-color: #93c5fd;">
                                <i class="fa-solid fa-hand-holding" style="color: #2563eb;"></i>
                                <span>Repasse aceito. Você pode concluir a requisição quando o atendimento for finalizado.</span>
                            </div>
                            <div style="display: flex; gap: 10px; margin-bottom: 20px;">
                                <button class="btn-accept-forward" style="background: #10b981;" onclick="handleForwardAction('complete', <?= $activeForward['id'] ?>)">
                                    <i class="fa-solid fa-check-double"></i> Marcar como concluída
                                </button>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>

                    <ul class="forward-chain">
                        <?php foreach ($forwardChain as $fwd):
                            $fwdIcon = 'fa-clock';
                            if ($fwd['status'] === 'accepted') $fwdIcon = 'fa-handshake';
                            elseif ($fwd['status'] === 'refused') $fwdIcon = 'fa-ban';
                            elseif ($fwd['status'] === 'completed') $fwdIcon = 'fa-circle-check';
                        ?>
                        <li class="forward-chain-item">
                            <div class="forward-chain-icon <?= $fwd['status'] ?>"><i class="fa-solid <?= $fwdIcon ?>"></i></div>
                            <div class="forward-chain-content">
                                <strong><?= htmlspecialchars($fwd['from_area_label']) ?> → <?= htmlspecialchars($fwd['to_area_label']) ?></strong>
                                <div class="forward-meta">
                                    <span><i class="fa-regular fa-user"></i> <?= htmlspecialchars($fwd['forwarded_by_name'] ?? '—') ?></span>
                                    <span><i class="fa-regular fa-calendar"></i> <?= date('d/m/Y H:i', strtotime($fwd['created_at'])) ?></span>
                                    <span class="forward-chain-status <?= $fwd['status'] ?>"><?= $fwd['status_label'] ?></span>
                                </div>
                                <?php if (!empty($fwd['observation'])): ?>
                                    <div class="forward-obs">"<?= htmlspecialchars($fwd['observation']) ?>"</div>
                                <?php endif; ?>
                                <?php if ($fwd['received_by_name']): ?>
                                    <div class="forward-meta" style="margin-top: 8px;">
                                        <span><i class="fa-solid fa-user-check"></i> Recebido por: <?= htmlspecialchars($fwd['received_by_name']) ?></span>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
            <?php endif; ?>

            <!-- Card Histórico (Timeline) - COLAPSÁVEL -->
            <div class="card card-history collapsed">
                <div class="card-header" onclick="toggleCard(this)">
                    <h2><i class="fa-solid fa-clock-rotate-left" style="color: var(--ml-primary);"></i> Histórico</h2>
                    <i class="fa-solid fa-chevron-down toggle-icon"></i>
                </div>
                <div class="card-body">
                    <div class="timeline">
                        <?php foreach ($history as $h):
                            $type = 'aberto';
                            $icon = 'fa-plus';
                            if(strpos($h['action'], 'Comentário') !== false) { $type = 'comentario'; $icon = 'fa-comment'; }
                            elseif(strpos($h['action'], 'reaberto') !== false || strpos($h['action'], 'reaberta') !== false) { $type = 'reaberto'; $icon = 'fa-rotate-left'; }
                            elseif(strpos($h['action'], 'concluí') !== false) { $type = 'concluido'; $icon = 'fa-check'; }
                            elseif(strpos($h['action'], 'Prioridade') !== false) { $type = 'aberto'; $icon = 'fa-bolt'; }
                            elseif(strpos($h['action'], 'repassada') !== false || strpos($h['action'], 'Repasse') !== false || strpos($h['action'], 'repasse') !== false) { $type = 'reaberto'; $icon = 'fa-share-from-square'; }
                        ?>
                        <div class="timeline-event">
                            <div class="timeline-dot dot-<?= $type ?>"><i class="fa-solid <?= $icon ?>"></i></div>
                            <div class="timeline-content">
                                <div class="timeline-time"><?= date('d/m/Y \à\s H:i', strtotime($h['created_at'])) ?></div>
                                <div style="font-size: 0.95rem;">
                                    <strong><?= htmlspecialchars($h['user_name']) ?></strong> <?= htmlspecialchars($h['action']) ?>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Coluna Direita (35%) -->
        <div class="sidebar-column">
            <?php if ($role !== 'solicitante'): ?>
            <!-- Card Ações -->
            <div class="card card-actions">
                <div class="card-header">
                    <h2><i class="fa-solid fa-gear" style="color: var(--ml-primary);"></i> Ações</h2>
                </div>
                <div class="card-body">
                    <div class="actions-group">
                        <?php if ($isGestor && $canManageThisRequest && $current_status == 'W'): ?>
                            <button onclick="globalRequestAction('finish', '<?= $req_id ?>', 'ctd_<?= $req_table ?>_frm')" class="btn-action btn-primary">
                                <i class="fa-solid fa-check-circle"></i> Marcar como concluída
                            </button>
                        <?php elseif ($isGestor && $canManageThisRequest && $current_status === 'Y'): ?>
                            <button onclick="globalRequestAction('start_progress', '<?= $req_id ?>', 'ctd_<?= $req_table ?>_frm')" class="btn-action btn-primary">
                                <i class="fa-solid fa-play"></i> Começar atendimento
                            </button>
                        <?php endif; ?>

                        <?php if ($current_status === 'C' && $canManageThisRequest): ?>
                            <button onclick="globalRequestAction('reset_status', '<?= $req_id ?>', 'ctd_<?= $req_table ?>_frm')" class="btn-action" style="background:#fff7ed; color:#c2410c;">
                                <i class="fa-solid fa-rotate-left"></i> Reabrir
                            </button>
                        <?php endif; ?>

                        <?php if (in_array($current_status, ['W', 'F']) && $canManageThisRequest): ?>
                            <button type="button" onclick="openForwardModal()" class="btn-action" style="background:#fff7ed; color:#c2410c;">
                                <i class="fa-solid fa-share-from-square"></i> Encaminhar a outro setor
                            </button>
                        <?php endif; ?>

                        <?php if($canManageThisRequest): ?>
                            <div style="margin-top: 10px;">
                                <label class="ml-label" for="prioritySelect">Urgência</label>
                                <select id="prioritySelect" class="ml-select" style="margin-bottom:0" onchange="globalRequestAction('set_priority', '<?= $req_id ?>', 'ctd_<?= $req_table ?>_frm', this.value)">
                                    <option value="1" <?= ($req['priority'] ?? 2) == 1 ? 'selected' : '' ?>>Baixa</option>
                                    <option value="2" <?= ($req['priority'] ?? 2) == 2 ? 'selected' : '' ?>>Normal</option>
                                    <option value="3" <?= ($req['priority'] ?? 2) == 3 ? 'selected' : '' ?>>Alta</option>
                                    <option value="4" <?= ($req['priority'] ?? 2) == 4 ? 'selected' : '' ?>>Crítica</option>
                                </select>
                            </div>
                        <?php endif; ?>

                        <?php if($isAdmin || $isGestor): ?>
                            <div class="divider"></div>
                            <a href="print_request.php?id=<?= $req_id ?>&table=<?= $req_table ?>" target="_blank" class="btn-action" style="text-decoration: none; justify-content: center;">
                                <i class="fa-solid fa-print" style="color: #ef4444;"></i> Imprimir / salvar PDF
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Card Mensagens Internas -->
            <div class="card chat-card">
                <div class="card-header">
                    <h2><i class="fa-solid fa-comments" style="color: var(--ml-primary);"></i> Conversa sobre o pedido</h2>
                </div>
                <div class="chat-messages" id="comments-feed">
                    <?php
                        if (count($comments) > 0):
                            $last_chat_date = '';
                            foreach ($comments as $c):
                                $this_date = date('d/m/Y', strtotime($c['created_at']));
                                if($this_date != $last_chat_date):
                                    echo '<div class="chat-date-header">'.$this_date.'</div>';
                                    $last_chat_date = $this_date;
                                endif;
                                $is_mine = ($c['user_id'] == ($_SESSION['id'] ?? -1));
                                $initials = e(mb_strtoupper(mb_substr((string)$c['user_name'], 0, 1)));
                    ?>
                    <div class="chat-item <?= $is_mine ? 'mine' : 'theirs' ?>">
                        <div class="chat-avatar"><?= $initials ?></div>
                        <div class="chat-bubble <?= $is_mine ? 'mine' : 'theirs' ?>">
                            <div class="chat-user-name"><?= htmlspecialchars($c['user_name']) ?></div>
                            <?= nl2br(htmlspecialchars($c['comment'])) ?>
                            <div style="text-align: right; font-size: 0.6rem; margin-top: 5px; opacity: 0.7;">
                                <?= date('H:i', strtotime($c['created_at'])) ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    <?php else: ?>
                        <div style="display: flex; flex-direction: column; align-items: center; justify-content: center; height: 100%; color: #94a3b8; text-align: center; padding: 40px 20px;">
                            <i class="fa-solid fa-comments-slash" style="font-size: 2.5rem; margin-bottom: 12px; opacity: 0.3;"></i>
                            <p style="font-size: 0.9rem; font-weight: 600; margin: 0;">Nenhuma mensagem ainda.</p>
                            <p style="font-size: 0.85rem; margin-top: 4px;">Tem alguma dúvida? Escreva abaixo: quem cuida do pedido recebe sua mensagem.</p>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="chat-footer">
                    <form id="commentForm">
                        <div class="chat-input-area">
                            <textarea name="comment" class="chat-input" placeholder="Escreva sua mensagem..." rows="1" required maxlength="3000" aria-label="Mensagem"
                                oninput="this.style.height = ''; this.style.height = this.scrollHeight + 'px'"></textarea>
                            <button type="submit" class="btn-send" aria-label="Enviar mensagem">
                                <i class="fa-solid fa-paper-plane"></i>
                            </button>
                        </div>
                    </form>
                </div>
            </div>


        </div>
    </div>
</div>

<!-- Floating Chat Button (Mobile) -->
<button class="fab-chat" id="fabChat" onclick="scrollToChat()" aria-label="Ir para a conversa">
    <i class="fa-solid fa-comments"></i>
</button>

<script>
function toggleCard(header) {
    const card = header.closest('.card');
    card.classList.toggle('collapsed');
}

function scrollToBottom() {
    const feed = document.getElementById('comments-feed');
    if(feed) feed.scrollTop = feed.scrollHeight;
}

function scrollToChat() {
    const chatCard = document.querySelector('.chat-card');
    if(chatCard) {
        chatCard.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
}

// Controle de visibilidade do FAB
window.addEventListener('scroll', () => {
    const fab = document.getElementById('fabChat');
    const chatCard = document.querySelector('.chat-card');
    if (!fab || !chatCard) return;

    const chatRect = chatCard.getBoundingClientRect();
    // Se o chat sair da visão (para cima ou para baixo), mostra o FAB
    if (chatRect.bottom < 0 || chatRect.top > window.innerHeight) {
        fab.classList.add('visible');
    } else {
        fab.classList.remove('visible');
    }
});

function loadComments() {
    if (document.hidden) return;
    fetch(`api/get_comments.php?id=<?= $req_id ?>&table=<?= $req_table ?>`)
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

// Decisão do aprovador direto na tela de detalhes
async function decideRequest(value) {
    let reason = '';
    if (value === 'N') {
        reason = await showConfirm({
            title: 'Recusar requisição',
            message: 'O solicitante vai receber o motivo abaixo.',
            type: 'error',
            confirmLabel: 'Recusar',
            isDanger: true,
            input: { label: 'Motivo da recusa *', placeholder: 'Ex.: falta informar a quantidade', required: true }
        });
        if (reason === false) return;
    } else {
        const ok = await showConfirm({
            title: 'Aprovar requisição',
            message: 'A requisição será enviada ao setor responsável para atendimento.',
            type: 'success',
            confirmLabel: 'Aprovar'
        });
        if (!ok) return;
    }
    const formData = new FormData();
    formData.append('action', 'post');
    formData.append('id', '<?= $req_id ?>');
    formData.append('table', '<?= $req_table ?>');
    formData.append('value', value);
    formData.append('reason', reason);
    const data = await fetchAjax('config/aprovRequest.php', formData);
    if (data && data.success) {
        showToast(data.message, 'success');
        setTimeout(() => location.reload(), 900);
    }
}

document.getElementById('commentForm')?.addEventListener('submit', async function(e) {
    e.preventDefault();
    const textarea = this.querySelector('textarea');
    const text = textarea.value.trim();
    if (!text) { textarea.focus(); return; }

    const btn = this.querySelector('button');
    btn.disabled = true;
    const formData = new URLSearchParams();
    formData.append('id', '<?= $req_id ?>');
    formData.append('table', '<?= $req_table ?>');
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

document.querySelector('#commentForm textarea')?.addEventListener('keydown', function(e) {
    if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        document.getElementById('commentForm').dispatchEvent(new Event('submit', {cancelable: true, bubbles: true}));
    }
});

scrollToBottom();
setInterval(loadComments, 15000);
document.addEventListener('visibilitychange', () => { if (!document.hidden) loadComments(); });
</script>
