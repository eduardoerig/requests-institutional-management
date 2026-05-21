<?php
/**
 * api/get_comments.php
 * Retorna o HTML das mensagens do chat com avatares e nomes.
 */
session_start();
require_once '../config/conn.php';
require_once '../config/security.php';

if (!isset($_SESSION['id'])) {
    http_response_code(401);
    exit;
}

$req_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$req_table = isset($_GET['table']) ? normalizeRequestTable((string)$_GET['table']) : null;

if (!$req_id || !$req_table) {
    exit;
}

$req = getRequestRow($pdo, $req_table, $req_id);
$role = $_SESSION['role'] ?? 'solicitante';
if (!$req || !userCanViewRequest($pdo, $req, $req_table, (int)$_SESSION['id'], $role)) {
    http_response_code(403);
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM request_comments WHERE request_id = ? AND request_table = ? ORDER BY created_at ASC");
$stmt->execute([$req_id, $req_table]);
$comments = $stmt->fetchAll();

if (count($comments) > 0) {
    $last_chat_date = '';
    foreach ($comments as $c) {
        $this_date = date('d/m/Y', strtotime($c['created_at']));
        if ($this_date != $last_chat_date) {
            echo '<div class="chat-date-header">' . $this_date . '</div>';
            $last_chat_date = $this_date;
        }
        
        $is_mine = ($c['user_id'] == ($_SESSION['id'] ?? -1));
        $side_class = $is_mine ? 'mine' : 'theirs';
        $initials = strtoupper(substr($c['user_name'], 0, 1));
        $user_name = htmlspecialchars($c['user_name']);
        $time_label = date('H:i', strtotime($c['created_at']));
        $text = nl2br(htmlspecialchars($c['comment']));

        echo "
        <div class=\"chat-item {$side_class}\">
            <div class=\"chat-avatar\">{$initials}</div>
            <div class=\"chat-bubble {$side_class}\">
                <div class=\"chat-user-name\">{$user_name}</div>
                {$text}
                <div style=\"text-align: right; font-size: 0.6rem; margin-top: 5px; opacity: 0.7;\">
                    {$time_label}
                </div>
            </div>
        </div>";
    }
} else {
    echo '
    <div style="display: flex; flex-direction: column; align-items: center; justify-content: center; height: 100%; color: #94a3b8; text-align: center; padding: 40px 20px;">
        <i class="fa-solid fa-comments-slash" style="font-size: 2.5rem; margin-bottom: 12px; opacity: 0.3;"></i>
        <p style="font-size: 0.9rem; font-weight: 600; margin: 0;">Nenhuma mensagem ainda.</p>
        <p style="font-size: 0.75rem; margin-top: 4px;">Inicie a conversa usando o campo abaixo.</p>
    </div>';
}
