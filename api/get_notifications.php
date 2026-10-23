<?php
/**
 * api/get_notifications.php — avisos do usuário (sino).
 * Cada aviso vem com "kind" (ícone/cor na tela) deduzido do título.
 */
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/conn.php';
require_once __DIR__ . '/../config/security.php';
requireLoginJson($pdo);
session_write_close(); // só leitura: não segura a sessão das outras abas e requisições

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

$userId = (int)$_SESSION['id'];

function notificationKind(string $title): string
{
    $t = mb_strtolower($title);
    $rules = [
        'message' => ['mensagem'],
        'refused' => ['recusad', 'recusou'],
        'done' => ['concluíd', 'devolveu a ajuda'],
        'approved' => ['aprovado', 'aprovada', 'novo para atender'],
        'forward' => ['encaminh', 'novo setor', 'transfer', 'ajuda', 'assumiu'],
        'approve' => ['para aprovar'],
        'started' => ['atendimento', 'reaberto'],
        'priority' => ['urgência', 'prioridade'],
    ];
    foreach ($rules as $kind => $words) {
        foreach ($words as $w) {
            if (mb_strpos($t, $w) !== false) {
                return $kind;
            }
        }
    }
    return 'info';
}

$stmt = $pdo->prepare('SELECT id, title, message, link, is_read, created_at FROM notifications WHERE user_id = ? ORDER BY id DESC LIMIT 30');
$stmt->execute([$userId]);
$notifications = array_map(function ($n) {
    $n['id'] = (int)$n['id'];
    $n['is_read'] = (int)$n['is_read'];
    $n['kind'] = notificationKind((string)$n['title']);
    // Mensagens antigas tinham emoji no começo
    $n['message'] = preg_replace('/^[^\p{L}\p{N}"“(]+/u', '', (string)$n['message']);
    return $n;
}, $stmt->fetchAll(PDO::FETCH_ASSOC));

$stmtCount = $pdo->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0');
$stmtCount->execute([$userId]);

echo json_encode([
    'unreadCount' => (int)$stmtCount->fetchColumn(),
    'latestId' => $notifications[0]['id'] ?? 0,
    'serverTime' => date('Y-m-d H:i:s'),
    'notifications' => $notifications,
], JSON_UNESCAPED_UNICODE);
