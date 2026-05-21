<?php
session_start();
require_once dirname(__DIR__) . '/config/conn.php';
require_once dirname(__DIR__) . '/config/security.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Método inválido.']);
    exit;
}

if (!isset($_SESSION['id'])) {
    echo json_encode(['success' => false, 'message' => 'Não logado.']);
    exit;
}

requireCsrfTokenFromRequest();

$notifId = isset($_POST['id']) ? (int)$_POST['id'] : 0;

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
    error_log('mark_notification_read_single.php: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Erro interno ao atualizar a notificação.']);
}
