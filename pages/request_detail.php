<?php
/**
 * pages/request_detail.php
 * Fase 3 — Página de detalhes, histórico e comentários de uma requisição.
 */
require_once 'config/conn.php';
require_once 'classes/RequestManager.php';

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

$label_map = ['mkt' => 'Marketing', 'shop' => 'Compras', 'xerox' => 'Xerox', 'service' => 'Serviços', 'ti' => 'TI'];
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
$adminRoles = ['admin', 'adm', 'coord', 'adm_sub'];
$gestorRoles = ['gestor', 'ti', 'xerox', 'service', 'shop', 'mkt', 'marketing'];

$isAdmin = in_array($role, $adminRoles);
$isGestor = in_array($role, $gestorRoles);
$isOwner = ((int)($req['created_by'] ?? 0) === (int)($_SESSION['id'] ?? -1));

if (!$isAdmin && !$isGestor && !$isOwner) {
    echo '<div class="main"><div class="empty-state"><i class="fa-solid fa-lock"></i><p>Acesso negado. Você só pode visualizar os detalhes das suas próprias requisições.</p></div></div>';
    exit;
}

// ---------- Tratar POST de novo comentário ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['new_comment']) || isset($_POST['new_comment_ajax']))) {
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

        // Registrar no histórico
        $his = $pdo->prepare("INSERT INTO request_history (request_id, request_table, user_id, user_name, action) VALUES (?, ?, ?, ?, 'Comentário adicionado')");
        $his->execute([$req_id, $req_table, $user_id, $user_name]);

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
    <!-- Breadcrumb -->
    <nav class="breadcrumb-nav">
        <a href="home"><i class="fas fa-home"></i> Dashboard</a>
        <i class="fas fa-chevron-right"></i>
        <a href="home?status=all"><?= $cat ?></a>
        <i class="fas fa-chevron-right"></i>
        <span>#<?= $req_id ?></span>
    </nav>

    <style>
        :root {
            --ml-primary: #2563eb;
            --ml-bg: #f1f5f9;
            --ml-surface: #ffffff;
            --ml-border: #e2e8f0;
            --ml-text: #1e293b;
            --ml-text-light: #64748b;
            --radius: 10px;
            --shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05), 0 2px 4px -2px rgba(0, 0, 0, 0.05);
            
            /* Event Colors */
            --ev-blue: #3b82f6;
            --ev-amber: #f59e0b;
            --ev-green: #10b981;
            --ev-gray: #94a3b8;
        }

        /* Hero Bar */
        .hero-bar {
            background: var(--ml-surface);
            padding: 30px;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            border: 1px solid var(--ml-border);
            margin-bottom: 25px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .hero-content h1 {
            font-size: 1.8rem;
            font-weight: 800;
            color: var(--ml-text);
            margin: 10px 0;
            letter-spacing: -0.5px;
        }

        .hero-meta {
            display: flex;
            gap: 15px;
            font-size: 0.85rem;
            color: var(--ml-text-light);
        }

        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-weight: 700;
            font-size: 0.8rem;
            color: var(--ml-text-light);
        }

        .status-dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
        }

        .protocol-badge {
            font-family: 'JetBrains Mono', 'Courier New', monospace;
            background: #f8fafc;
            padding: 8px 15px;
            border: 1px solid var(--ml-border);
            border-radius: 6px;
            font-size: 1.1rem;
            font-weight: 700;
            color: var(--ml-text);
        }

        /* Main Grid Layout */
        .detail-grid {
            display: grid;
            grid-template-columns: 65% 35%;
            gap: 25px;
            align-items: start;
        }

        .card {
            background: var(--ml-surface);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            border: 1px solid var(--ml-border);
            margin-bottom: 25px;
        }

        .card-header {
            padding: 20px 25px;
            border-bottom: 1px solid var(--ml-border);
        }

        .card-header h2 {
            font-size: 1rem;
            font-weight: 700;
            margin: 0;
            color: var(--ml-text);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .card-body { padding: 25px; }

        /* Information Grid 2x2 */
        .info-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }

        .info-item {
            padding: 16px;
            background: #f8fafc;
            border-radius: 8px;
            border: 1px solid #f1f5f9;
        }

        .info-label {
            display: block;
            font-size: 0.7rem;
            font-weight: 700;
            color: var(--ml-text-light);
            text-transform: uppercase;
            margin-bottom: 4px;
            letter-spacing: 0.5px;
        }

        .info-value {
            font-size: 1rem;
            font-weight: 600;
            color: var(--ml-text);
        }

        /* Timeline Vertical */
        .timeline {
            position: relative;
            padding-left: 35px;
        }

        .timeline::before {
            content: '';
            position: absolute;
            left: 14px;
            top: 0;
            bottom: 0;
            width: 2px;
            background: #e2e8f0;
        }

        .timeline-event {
            position: relative;
            margin-bottom: 30px;
        }

        .timeline-dot {
            position: absolute;
            left: -35px;
            width: 30px;
            height: 30px;
            background: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.8rem;
            z-index: 1;
            box-shadow: 0 0 0 4px white;
            border: 2px solid #cbd5e1;
        }

        .dot-comentario { border-color: var(--ev-blue); color: var(--ev-blue); }
        .dot-reaberto { border-color: var(--ev-amber); color: var(--ev-amber); }
        .dot-concluido { border-color: var(--ev-green); color: var(--ev-green); }
        .dot-aberto { border-color: var(--ev-gray); color: var(--ev-gray); }

        .timeline-content {
            background: #f8fafc;
            padding: 16px;
            border-radius: 8px;
            border: 1px solid var(--ml-border);
        }

        .timeline-time {
            font-size: 0.75rem;
            font-weight: 700;
            color: var(--ml-text-light);
            margin-bottom: 4px;
        }

        /* Sidebar Column */
        .btn-concluir {
            width: 100%;
            padding: 16px;
            background: #10b981;
            color: white;
            border: none;
            border-radius: 8px;
            font-weight: 700;
            font-size: 1rem;
            cursor: pointer;
            transition: all 0.2s;
            box-shadow: 0 4px 12px rgba(16, 185, 129, 0.2);
            margin-bottom: 20px;
        }

        .btn-concluir:hover { background: #059669; transform: translateY(-1px); }

        .ml-label {
            font-size: 0.75rem;
            font-weight: 700;
            color: var(--ml-text-light);
            margin-bottom: 8px;
            display: block;
        }

        .ml-select {
            width: 100%;
            padding: 12px;
            border-radius: 8px;
            border: 1px solid var(--ml-border);
            background: white;
            font-weight: 600;
            color: var(--ml-text);
            margin-bottom: 20px;
        }

        .divider {
            height: 1px;
            background: var(--ml-border);
            margin: 20px 0;
        }

        .btn-pdf {
            width: 100%;
            padding: 12px;
            background: white;
            color: var(--ml-text-light);
            border: 1px solid var(--ml-border);
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            transition: all 0.2s;
        }
        .btn-pdf:hover { background: #f8fafc; color: var(--ml-text); }

        /* Chat Card */
        .chat-card {
            display: flex;
            flex-direction: column;
            max-height: 600px;
        }

        .chat-messages {
            flex: 1;
            overflow-y: auto;
            padding: 20px;
            background: #f8fafc;
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .chat-bubble {
            max-width: 80%;
            padding: 12px 16px;
            border-radius: 12px;
            font-size: 0.85rem;
            line-height: 1.5;
        }

        .chat-bubble.mine {
            align-self: flex-end;
            background: var(--ml-primary);
            color: white;
            border-bottom-right-radius: 2px;
        }

        .chat-bubble.theirs {
            align-self: flex-start;
            background: #e2e8f0;
            color: var(--ml-text);
            border-bottom-left-radius: 2px;
        }

        .chat-date-header {
            text-align: center;
            font-size: 0.7rem;
            font-weight: 700;
            color: var(--ml-text-light);
            text-transform: uppercase;
            margin: 15px 0 5px 0;
        }

        .chat-footer {
            padding: 15px;
            border-top: 1px solid var(--ml-border);
            background: white;
        }

        .chat-input-area {
            display: flex;
            gap: 10px;
            align-items: flex-end;
        }

        .chat-input {
            flex: 1;
            border: 1px solid var(--ml-border);
            border-radius: 20px;
            padding: 10px 16px;
            font-size: 0.85rem;
            resize: none;
            max-height: 100px;
            outline: none;
            background: #f8fafc;
        }

        .btn-send {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: #0f172a;
            color: white;
            border: none;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .btn-back {
            display: block;
            text-align: center;
            margin-top: 15px;
            color: var(--ml-text-light);
            text-decoration: none;
            font-size: 0.85rem;
            font-weight: 600;
        }
        .btn-back:hover { color: var(--ml-primary); }

        @media (max-width: 768px) {
            .detail-grid { grid-template-columns: 1fr; }
            .hero-bar { flex-direction: column; align-items: flex-start; gap: 15px; }
            .protocol-badge { align-self: flex-end; }
        }
    </style>

    <!-- 1. HERO BAR (Largura Total) -->
    <div class="hero-bar">
        <div class="hero-content">
            <div style="display: flex; gap: 12px; align-items: center;">
                <span class="badge-cat badge-<?= $req_table ?>"><?= $cat ?></span>
                <div class="status-badge">
                    <span class="status-dot" style="background: <?php 
                        if($current_status == 'W') echo '#f59e0b';
                        elseif($current_status == 'C') echo '#10b981';
                        elseif($current_status == 'F') echo '#d97706';
                        else echo '#3b82f6';
                    ?>;"></span>
                    <?= $status_label[$current_status] ?>
                    <?php if ($current_status === 'F' && $activeForward): ?>
                        <span class="badge-forwarded" style="margin-left: 8px;"><i class="fa-solid fa-share-from-square"></i> Repassada</span>
                    <?php endif; ?>
                </div>
            </div>
            <h1><?= $title ?></h1>
            <div class="hero-meta">
                <span><i class="fa-regular fa-user"></i> <strong><?= $user ?></strong></span>
                <span><i class="fa-regular fa-calendar"></i> <?= $date ?></span>
            </div>
        </div>
        <div class="protocol-badge">#<?= $req_id ?></div>
    </div>

    <!-- 2. GRID DE DUAS COLUNAS -->
    <div class="detail-grid">
        <!-- Coluna Esquerda (65%) -->
        <div class="main-column">
            <!-- Card Descrição -->
            <div class="card">
                <div class="card-header">
                    <h2><i class="fa-solid fa-align-left" style="color: var(--ml-primary);"></i> Descrição</h2>
                </div>
                <div class="card-body">
                    <div style="line-height: 1.7; color: var(--ml-text); font-size: 1.05rem;">
                        <?= nl2br($desc) ?: '<em style="color:var(--ml-text-light)">Nenhuma descrição fornecida.</em>' ?>
                    </div>
                </div>
            </div>

            <!-- Card Informações (Grid 2x2) -->
            <div class="card">
                <div class="card-header">
                    <h2><i class="fa-solid fa-circle-info" style="color: var(--ml-primary);"></i> Informações</h2>
                </div>
                <div class="card-body">
                    <div class="info-grid">
                        <div class="info-item">
                            <span class="info-label">Protocolo</span>
                            <span class="info-value">#<?= $req_id ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Prioridade</span>
                            <span class="info-value" style="color: <?php 
                                $pri_colors = [1=>'#10b981', 2=>'#f59e0b', 3=>'#ef4444', 4=>'#7f1d1d', 'W'=>'#f59e0b'];
                                $pri_labels = [1=>'Baixa', 2=>'Média', 3=>'Alta', 4=>'Crítica'];
                                echo $pri_colors[$req['priority'] ?? 2] ?? '#64748b';
                            ?>;">
                                <?= $pri_labels[$req['priority'] ?? 2] ?? 'Normal' ?>
                            </span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Data de Abertura</span>
                            <span class="info-value"><?= date('d/m/Y H:i', strtotime($req['created_at'])) ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Subdivisão</span>
                            <span class="info-value"><?= htmlspecialchars($subName) ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Local / Observações</span>
                            <span class="info-value"><?= htmlspecialchars($req['sala'] ?? 'N/A') ?></span>
                        </div>
                        <?php if ($approverName): ?>
                        <div class="info-item">
                            <span class="info-label"><?= ($current_status === 'N') ? 'Recusada por' : 'Aprovada por' ?></span>
                            <span class="info-value"><?= htmlspecialchars($approverName) ?></span>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php if(!empty($req['obs'])): ?>
                        <div style="margin-top: 15px; padding: 12px; background: #fffbeb; border-radius: 6px; border: 1px solid #fef3c7; font-size: 0.9rem;">
                            <strong>Obs:</strong> <?= htmlspecialchars($req['obs']) ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <?php if (!empty($forwardChain)): ?>
            <!-- Card Cadeia de Repasses (Fase 5) -->
            <div class="card">
                <div class="card-header">
                    <h2><i class="fa-solid fa-share-from-square" style="color: #d97706;"></i> Cadeia de Repasses</h2>
                </div>
                <div class="card-body">
                    <?php if ($activeForward && ($isGestor || $isAdmin)): ?>
                        <?php 
                            // Verifica se o gestor logado tem permissão sobre o setor de destino
                            $canActOnForward = false;
                            if ($isAdmin) {
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

            <!-- Card Histórico (Timeline) -->
            <div class="card">
                <div class="card-header">
                    <h2><i class="fa-solid fa-clock-rotate-left" style="color: var(--ml-primary);"></i> Histórico de Atividade</h2>
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
            <!-- Card Ações -->
            <div class="card">
                <div class="card-header">
                    <h2>Ações de Gestão</h2>
                </div>
                <div class="card-body">
                    <?php if ($isGestor && $current_status == 'W'): ?>
                        <button onclick="globalRequestAction('finish', '<?= $req_id ?>', 'ctd_<?= $req_table ?>_frm')" class="btn-concluir">
                            <i class="fa-solid fa-check-circle"></i> CONCLUIR REQUISIÇÃO
                        </button>
                    <?php elseif ($isGestor && in_array($current_status, ['Y', 'A'])): ?>
                        <button onclick="globalRequestAction('start_progress', '<?= $req_id ?>', 'ctd_<?= $req_table ?>_frm')" class="btn-concluir" style="background: var(--ml-primary); box-shadow: 0 4px 12px rgba(37, 99, 235, 0.2);">
                            <i class="fa-solid fa-play"></i> INICIAR ATENDIMENTO
                        </button>
                    <?php endif; ?>

                    <?php if (in_array($current_status, ['C', 'N']) && ($isAdmin || $isGestor)): ?>
                        <button onclick="globalRequestAction('reset_status', '<?= $req_id ?>', 'ctd_<?= $req_table ?>_frm')" class="btn-concluir" style="background: #f59e0b; box-shadow: 0 4px 12px rgba(245, 158, 11, 0.2);">
                            <i class="fa-solid fa-rotate-left"></i> REABRIR REQUISIÇÃO
                        </button>
                    <?php endif; ?>

                    <?php if($isAdmin || $isGestor): ?>
                        <label class="ml-label">ALTERAR PRIORIDADE</label>
                        <select class="ml-select" onchange="globalRequestAction('set_priority', '<?= $req_id ?>', 'ctd_<?= $req_table ?>_frm', this.value)">
                            <option value="1" <?= ($req['priority'] ?? 2) == 1 ? 'selected' : '' ?>>Baixa</option>
                            <option value="2" <?= ($req['priority'] ?? 2) == 2 ? 'selected' : '' ?>>Média</option>
                            <option value="3" <?= ($req['priority'] ?? 2) == 3 ? 'selected' : '' ?>>Alta</option>
                            <option value="4" <?= ($req['priority'] ?? 2) == 4 ? 'selected' : '' ?>>Crítica</option>
                        </select>
                    <?php endif; ?>

                    <div class="divider"></div>

                    <button onclick="window.print()" class="btn-pdf">
                        <i class="fa-solid fa-file-pdf" style="color: #ef4444;"></i> Exportar PDF
                    </button>
                </div>
            </div>

            <!-- Card Mensagens Internas -->
            <div class="card chat-card">
                <div class="card-header">
                    <h2>Mensagens Internas</h2>
                </div>
                <div class="chat-messages" id="comments-feed">
                    <?php 
                        $last_chat_date = '';
                        foreach ($comments as $c): 
                            $this_date = date('d/m/Y', strtotime($c['created_at']));
                            if($this_date != $last_chat_date):
                                echo '<div class="chat-date-header">'.$this_date.'</div>';
                                $last_chat_date = $this_date;
                            endif;
                            $is_mine = ($c['user_id'] == ($_SESSION['id'] ?? -1));
                    ?>
                    <div class="chat-bubble <?= $is_mine ? 'mine' : 'theirs' ?>">
                        <?php if(!$is_mine): ?><div style="font-size: 0.65rem; font-weight: 800; margin-bottom: 3px; color: #475569;"><?= $c['user_name'] ?></div><?php endif; ?>
                        <?= nl2br(htmlspecialchars($c['comment'])) ?>
                        <div style="text-align: right; font-size: 0.6rem; margin-top: 5px; opacity: 0.7;">
                            <?= date('H:i', strtotime($c['created_at'])) ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <div class="chat-footer">
                    <form id="commentForm">
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

<script>
function scrollToBottom() {
    const feed = document.getElementById('comments-feed');
    if(feed) feed.scrollTop = feed.scrollHeight;
}

function loadComments() {
    const id = "<?= $req_id ?>";
    const table = "<?= $req_table ?>";
    
    fetch(`api/get_comments.php?id=${id}&table=${table}`)
        .then(response => response.text())
        .then(html => {
            const feed = document.getElementById('comments-feed');
            if(!feed) return;
            const isAtBottom = feed.scrollHeight - feed.scrollTop <= feed.clientHeight + 150;
            feed.innerHTML = html;
            if(isAtBottom) scrollToBottom();
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
setInterval(loadComments, 5000);
</script>
