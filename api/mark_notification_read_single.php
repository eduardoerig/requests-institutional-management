<?php
session_start();
require_once dirname(__DIR__) . '/config/conn.php';

header('Content-Type: application/json');

if (!isset($_SESSION['id'])) {
    echo json_encode(['success' => false, 'message' => 'Não logado.']);
    exit;
}

$notifId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$notifId) {
    echo json_encode(['success' => false, 'message' => 'ID inválido.']);
    exit;
}

$userId = $_SESSION['id'];

try {
    $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?");
    $stmt->execute([$notifId, $userId]);
    
    echo json_encode(['success' => true]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
