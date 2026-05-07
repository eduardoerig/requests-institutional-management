<?php
session_start();
require_once '../config/conn.php';

$req_id = $_GET['id'] ?? 0;
$req_table = $_GET['table'] ?? '';

if (!$req_id || !$req_table) {
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM request_comments WHERE request_id = ? AND request_table = ? ORDER BY created_at ASC");
$stmt->execute([$req_id, $req_table]);
$comments = $stmt->fetchAll();

if (count($comments) > 0) {
    foreach ($comments as $c) {
        $initials = strtoupper(substr($c['user_name'], 0, 1));
        $is_me = ($c['user_id'] == ($_SESSION['id'] ?? -1));
        $side = $is_me ? 'me' : '';
        
        echo '
        <div class="comment-bubble '.$side.'">
            <div class="comment-avatar">'.$initials.'</div>
            <div class="comment-body">
                <div class="comment-author">'.htmlspecialchars($c['user_name']).'</div>
                <div class="comment-text">'.nl2br(htmlspecialchars($c['comment'])).'</div>
                <div class="comment-time">'.date('d/m/Y H:i', strtotime($c['created_at'])).'</div>
            </div>
        </div>';
    }
} else {
    echo '<div class="empty-state"><i class="fas fa-comments"></i><p>Nenhum comentário ainda.</p></div>';
}
