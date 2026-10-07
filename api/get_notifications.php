<?php
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/conn.php';
require_once __DIR__ . '/../config/security.php';
requireLoginJson($pdo);

header('Content-Type: application/json');

if (!isset($_SESSION['id'])) {
    echo json_encode([]);
    exit;
}

$userId = $_SESSION['id'];

// Buscar as 10 notificações mais recentes
$stmt = $pdo->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 10");
$stmt->execute([$userId]);
$notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Contar não lidas
$stmtCount = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
$stmtCount->execute([$userId]);
$unreadCount = $stmtCount->fetchColumn();

echo json_encode([
    'unreadCount' => (int)$unreadCount,
    'notifications' => $notifications
]);
