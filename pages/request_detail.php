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


        /* Hero */
        .hero-bar {
            background: #ffffff;
            padding: 20px 24px;
            border-radius: 12px;
            border: 1px solid var(--ml-border);
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 1px 3px rgba(0,0,0,0.04);
            position: sticky;
            top: 0;
            z-index: 50;
        }
        .hero-content h1 { font-size: 1.4rem; font-weight: 800; color: var(--ml-text); margin: 8px 0 6px; letter-spacing: -0.02em; }
        .hero-meta { display: flex; gap: 12px; font-size: 0.85rem; color: var(--ml-text-light); align-items: center; font-weight: 500; }
        .status-pill { padding: 6px 14px; border-radius: 20px; font-size: 0.75rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.5px; box-shadow: 0 1px 2px rgba(0,0,0,0.05); }
        .status-P { background: #eff6ff; color: #2563eb; border: 1px solid #bfdbfe; }
        .status-Y,.status-A { background: #f0fdf4; color: #15803d; border: 1px solid #bbf7d0; }
        .status-N { background: #fef2f2; color: #dc2626; border: 1px solid #fecaca; }
        .status-W { background: #fff7ed; color: #c2410c; border: 1px solid #fed7aa; }
        .status-C { background: #f0fdf4; color: #15803d; border: 1px solid #bbf7d0; }
        .status-F { background: #faf5ff; color: #7e22ce; border: 1px solid #e9d5ff; }
        .protocol-badge { font-family: 'JetBrains Mono', monospace; background: #fff; padding: 8px 16px; border: 1px solid var(--ml-border); border-radius: 10px; font-size: 1.1rem; font-weight: 800; color: var(--ml-primary); box-shadow: 0 1px 3px rgba(0,0,0,0.04); }

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
        .btn-send { width: 44px; height: 44px; border-radius: 50%; background: linear-gradient(135deg, var(--ml-primary), #3d3c42); color: #fff; border: none; cursor: pointer; display: flex; align-items: center; justify-content: center; flex-shrink: 0; box-shadow: 0 4px 10px rgba(44,43,49,0.3); transition: transform 0.2s, box-shadow 0.2s; }
        .btn-send:hover { transform: scale(1.05); box-shadow: 0 6px 14px rgba(44,43,49,0.4); }

        .btn-back { display: flex; align-items: center; justify-content: center; gap: 8px; margin-top: 16px; color: var(--ml-text-light); text-decoration: none; font-size: 0.9rem; font-weight: 700; padding: 12px; border-radius: 12px; transition: all 0.2s; }
        .btn-back:hover { color: var(--ml-primary); background: #f1f0f2; }

        /* FAB */
        .fab-chat { position: fixed; right: 24px; bottom: 96px; width: 56px; height: 56px; border-radius: 50%; background: linear-gradient(135deg, var(--ml-primary), #3d3c42); color: #fff; display: flex; align-items: center; justify-content: center; font-size: 1.4rem; box-shadow: 0 6px 16px rgba(44,43,49,0.4); z-index: 99; border: none; cursor: pointer; opacity: 0; transform: scale(0); transition: all 0.3s cubic-bezier(0.4,0,0.2,1); }
        .fab-chat.visible { opacity: 1; transform: scale(1); }
        .fab-chat:hover { transform: scale(1.05); box-shadow: 0 8px 20px rgba(44,43,49,0.5); }

        /* ===== MOBILE UX PERFECTED ===== */
        @media (max-width: 768px) {
            /* Desfazemos o grid e usamos display contents para reordenar cards individualmente */
            /* Ajustes Finos Mobile - CONTEÚDO À ESQUERDA */
            .detail-grid { display: flex; flex-direction: column; gap: 16px; width: 100%; box-sizing: border-box; }
            .main-column, .sidebar-column { display: contents; }

            /* Nova Ordem de Leitura no Mobile */
            .card { order: 10; margin-bottom: 0 !important; border-radius: 14px; width: 100%; box-sizing: border-box; } /* Padrão */
            .card-description { order: 1; }
            .chat-card { order: 2; max-height: 480px !important; }
            .card-actions { order: 3; }
            .card-details { order: 4; }
            .card-forward { order: 5; }
            .card-history { order: 6; }

            /* Containers alinhados e consistentes */
            .hero-bar { flex-direction: column; align-items: flex-start; text-align: left; gap: 12px; padding: 18px 16px; margin-bottom: 0; border-radius: 14px; width: 100%; box-sizing: border-box; }
            .hero-content { display: flex; flex-direction: column; align-items: flex-start; width: 100%; }
            .hero-content > div:first-child { justify-content: flex-start; }
            .hero-content h1 { font-size: 1.3rem; margin: 6px 0; text-align: left; }
            .protocol-badge { align-self: flex-start; font-size: 0.95rem; padding: 6px 12px; margin-top: 4px; }
            .hero-meta { flex-wrap: wrap; gap: 8px; justify-content: flex-start; }

            .card-header { padding: 14px 16px; }
            .card-body { padding: 16px; }
            .chat-messages { min-height: 250px; padding: 16px; }
            .chat-footer { padding: 12px 14px; }
            .chat-input { font-size: 16px !important; padding: 10px 16px; } /* Prevents iOS Zoom */
            .btn-send { width: 42px; height: 42px; min-width: 42px; }

            .btn-back { order: 10; margin: 16px 0 24px; padding: 14px; background: #fff; border: 1px solid var(--ml-border); justify-content: flex-start; width: 100%; box-sizing: border-box; }

        }
    </style>



    <!-- 1. HERO BAR COMPACTO -->
    <div class="hero-bar">
        <div class="hero-content">
            <div style="display: flex; gap: 8px; align-items: center; margin-bottom: 4px;">
                <span class="status-pill status-<?= $current_status ?>"><?= $status_label[$current_status] ?></span>
            </div>
            <h1><?= $title ?></h1>
            <div class="hero-meta">
                <span><strong><?= $user ?></strong></span>
                <span style="color:#cbd5e1;">•</span>
                <span><?= $date ?></span>
            </div>
        </div>
        <div class="protocol-badge">#<?= $req_id ?></div>
    </div>

    <!-- 2. GRID DE DUAS COLUNAS -->
    <div class="detail-grid">
        <!-- Coluna Esquerda (65%) -->
        <div class="main-column">
            <!-- Card Descrição -->
            <div class="card card-description">
                <div class="card-header">
                    <h2><i class="fa-solid fa-align-left" style="color: var(--ml-primary);"></i> Descrição</h2>
                </div>
                <div class="card-body">
                    <div style="line-height: 1.7; color: var(--ml-text); font-size: 1.05rem;">
                        <?= nl2br($desc) ?: '<em style="color:var(--ml-text-light)">Nenhuma descrição fornecida.</em>' ?>
                    </div>
                </div>
            </div>

            <!-- Card Informações (Grid Limpo) -->
            <div class="card card-details">
                <div class="card-header" onclick="toggleCard(this)">
                    <h2><i class="fa-solid fa-circle-info" style="color: var(--ml-primary);"></i> Detalhes Técnicos</h2>
                    <i class="fa-solid fa-chevron-down toggle-icon"></i>
                </div>
                <div class="card-body">
                    <div class="info-grid-simple">
                        <div class="info-box">
                            <label>Prioridade</label>
                            <span style="color: <?php
                                $pri_colors = [1=>'#10b981', 2=>'#f59e0b', 3=>'#ef4444', 4=>'#7f1d1d'];
                                $pri_labels = [1=>'Baixa', 2=>'Média', 3=>'Alta', 4=>'Crítica'];
                                echo $pri_colors[$req['priority'] ?? 2] ?? '#64748b';
                            ?>;">
                                <?= $pri_labels[$req['priority'] ?? 2] ?? 'Normal' ?>
                            </span>
                        </div>
                        <div class="info-box">
                            <label>Subdivisão</label>
                            <span><?= htmlspecialchars($subName) ?></span>
                        </div>
                        <div class="info-box">
                            <label>Local / Sala</label>
                            <span><?= htmlspecialchars($req['sala'] ?? 'N/A') ?></span>
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
            <div class="card card-forward collapsed">
                <div class="card-header" onclick="toggleCard(this)">
                    <h2><i class="fa-solid fa-share-from-square" style="color: #d97706;"></i> Cadeia de Repasses</h2>
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
                                <span>Este repasse aguarda sua aceitação. Deseja assumir o atendimento?</span>
                            </div>
                            <div style="display: flex; gap: 10px; margin-bottom: 20px;">
                                <button class="btn-accept-forward" onclick="handleForwardAction('accept', <?= $activeForward['id'] ?>)">
                                    <i class="fa-solid fa-check"></i> Aceitar Repasse
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
                                    <i class="fa-solid fa-check-double"></i> Concluir via Repasse
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
                    <h2><i class="fa-solid fa-clock-rotate-left" style="color: var(--ml-primary);"></i> Histórico de Atividade</h2>
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
                                <div class="timeline-time"><?= date('d M, Y - H:i', strtotime($h['created_at'])) ?></div>
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
                    <h2><i class="fa-solid fa-gear" style="color: var(--ml-primary);"></i> Gerenciar Requisição</h2>
                </div>
                <div class="card-body">
                    <div class="actions-group">
                        <?php if ($isGestor && $canManageThisRequest && $current_status == 'W'): ?>
                            <button onclick="globalRequestAction('finish', '<?= $req_id ?>', 'ctd_<?= $req_table ?>_frm')" class="btn-action btn-primary">
                                <i class="fa-solid fa-check-circle"></i> CONCLUIR
                            </button>
                        <?php elseif ($isGestor && $canManageThisRequest && in_array($current_status, ['Y', 'A'])): ?>
                            <button onclick="globalRequestAction('start_progress', '<?= $req_id ?>', 'ctd_<?= $req_table ?>_frm')" class="btn-action btn-primary">
                                <i class="fa-solid fa-play"></i> INICIAR ATENDIMENTO
                            </button>
                        <?php endif; ?>

                        <?php if (in_array($current_status, ['C', 'N']) && $canManageThisRequest): ?>
                            <button onclick="globalRequestAction('reset_status', '<?= $req_id ?>', 'ctd_<?= $req_table ?>_frm')" class="btn-action" style="background:#fff7ed; color:#c2410c;">
                                <i class="fa-solid fa-rotate-left"></i> REABRIR
                            </button>
                        <?php endif; ?>

                        <?php if (in_array($current_status, ['W', 'F']) && $canManageThisRequest): ?>
                            <button type="button" onclick="openForwardModal()" class="btn-action" style="background:#fff7ed; color:#c2410c;">
                                <i class="fa-solid fa-share-from-square"></i> REPASSAR
                            </button>
                        <?php endif; ?>

                        <?php if($canManageThisRequest): ?>
                            <div style="margin-top: 10px;">
                                <label class="ml-label">MUDAR PRIORIDADE</label>
                                <select class="ml-select" style="margin-bottom:0" onchange="globalRequestAction('set_priority', '<?= $req_id ?>', 'ctd_<?= $req_table ?>_frm', this.value)">
                                    <option value="1" <?= ($req['priority'] ?? 2) == 1 ? 'selected' : '' ?>>Baixa</option>
                                    <option value="2" <?= ($req['priority'] ?? 2) == 2 ? 'selected' : '' ?>>Média</option>
                                    <option value="3" <?= ($req['priority'] ?? 2) == 3 ? 'selected' : '' ?>>Alta</option>
                                    <option value="4" <?= ($req['priority'] ?? 2) == 4 ? 'selected' : '' ?>>Crítica</option>
                                </select>
                            </div>
                        <?php endif; ?>

                        <?php if($isAdmin || $isGestor): ?>
                            <div class="divider"></div>
                            <a href="print_request.php?id=<?= $req_id ?>&table=<?= $req_table ?>" target="_blank" class="btn-action" style="text-decoration: none; justify-content: center;">
                                <i class="fa-solid fa-file-pdf" style="color: #ef4444;"></i> Gerar Relatório PDF
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Card Mensagens Internas -->
            <div class="card chat-card">
                <div class="card-header">
                    <h2>Mensagens Internas</h2>
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
                                $initials = strtoupper(substr($c['user_name'], 0, 1));
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
                            <p style="font-size: 0.75rem; margin-top: 4px;">Inicie a conversa usando o campo abaixo.</p>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="chat-footer">
                    <form id="commentForm">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(getCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                        <div class="chat-input-area">
                            <textarea name="new_comment_ajax_text" class="chat-input" placeholder="Escreva aqui..." rows="1" required
                                oninput="this.style.height = ''; this.style.height = this.scrollHeight + 'px'"></textarea>
                            <button type="submit" class="btn-send">
                                <i class="fa-solid fa-paper-plane"></i>
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <a href="home" class="btn-back">
                <i class="fa-solid fa-arrow-left"></i> Voltar para o painel
            </a>
        </div>
    </div>
</div>

<!-- Floating Chat Button (Mobile) -->
<button class="fab-chat" id="fabChat" onclick="scrollToChat()">
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
    const id = "<?= $req_id ?>";
    const table = "<?= $req_table ?>";

    fetch(`api/get_comments.php?id=${id}&table=${table}`)
        .then(response => response.text())
        .then(html => {
            const feed = document.getElementById('comments-feed');
            if(!feed) return;
            // Só scrolla se o usuário estiver próximo do fundo ou se o HTML mudou significativamente
            const isAtBottom = feed.scrollHeight - feed.scrollTop <= feed.clientHeight + 150;
            const oldHtml = feed.innerHTML;

            if (oldHtml !== html) {
                feed.innerHTML = html;
                if(isAtBottom) scrollToBottom();
            }
        });
}

document.getElementById('commentForm')?.addEventListener('submit', function(e) {
    e.preventDefault();
    const formData = new FormData(this);
    formData.append('new_comment_ajax', '1');
    const btn = this.querySelector('button');
    btn.disabled = true;

    fetch(window.location.href, {
        method: 'POST',
        body: formData
    })
    .then(() => {
        this.reset();
        this.querySelector('textarea').style.height = '';
        btn.disabled = false;
        loadComments();
    });
});

document.querySelector('textarea[name="new_comment_ajax_text"]')?.addEventListener('keydown', function(e) {
    if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        document.getElementById('commentForm').dispatchEvent(new Event('submit', {cancelable: true, bubbles: true}));
    }
});

scrollToBottom();
setInterval(loadComments, 3000);
</script>
