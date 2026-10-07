<?php
/**
 * api/get_comments.php
 * Retorna o HTML das mensagens do chat com avatares e nomes.
 */
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/conn.php';
require_once __DIR__ . '/../config/security.php';
requireLoginJson($pdo);

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

// Mesma marcação de pages/request_detail.php (o HTML é comparado para evitar recarregar à toa)
if (count($comments) > 0) {
    $last_chat_date = '';
    foreach ($comments as $c) {
        $this_date = date('d/m/Y', strtotime($c['created_at']));
        if ($this_date !== $last_chat_date) {
            echo '<div class="chat-date-header">' . e($this_date) . '</div>';
            $last_chat_date = $this_date;
        }
        $side = ((int)$c['user_id'] === (int)($_SESSION['id'] ?? -1)) ? 'mine' : 'theirs';
        echo '<div class="chat-item ' . $side . '">'
            . '<div class="chat-avatar" aria-hidden="true">' . e(mb_strtoupper(mb_substr((string)$c['user_name'], 0, 1))) . '</div>'
            . '<div class="chat-bubble ' . $side . '">'
            . '<div class="chat-user-name">' . e($c['user_name']) . '</div>'
            . nl2br(e($c['comment']))
            . '<span class="chat-time">' . e(date('H:i', strtotime($c['created_at']))) . '</span>'
            . '</div></div>';
    }
} else {
    echo '<p class="chat-empty">Tem alguma dúvida? Escreva abaixo: quem cuida do pedido recebe sua mensagem.</p>';
}
