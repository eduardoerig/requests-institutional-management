<?php
session_start();
require_once '../config/conn.php';

header('Content-Type: application/json');

if (!isset($_SESSION['id'])) {
    echo json_encode(['success' => false]);
    exit;
}

$userId = $_SESSION['id'];

$stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0");
$stmt->execute([$userId]);

echo json_encode(['success' => true]);
