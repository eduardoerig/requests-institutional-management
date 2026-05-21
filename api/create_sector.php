<?php
session_start();
require_once __DIR__ . '/../config/conn.php';
require_once __DIR__ . '/../config/security.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Método inválido.']);
    exit;
}
requireCsrfTokenFromRequest();

if (!in_array($_SESSION['role'], ['admin', 'adm'])) {
    echo json_encode(['success' => false, 'message' => 'Acesso negado.']);
    exit;
}

$title = trim($_POST['sector_title'] ?? '');

if (empty($title)) {
    echo json_encode(['success' => false, 'message' => 'O título do setor é obrigatório.']);
    exit;
}

try {
    // Verificar se já existe
    $stmt = $pdo->prepare("SELECT id FROM ctd_area WHERE title = ?");
    $stmt->execute([$title]);
    if ($stmt->fetch()) {
        echo json_encode(['success' => false, 'message' => 'Este setor já existe.']);
        exit;
    }

    $stmt = $pdo->prepare("INSERT INTO ctd_area (title) VALUES (?)");
    $stmt->execute([$title]);

    echo json_encode(['success' => true, 'message' => 'Setor criado com sucesso!']);
} catch (PDOException $e) {
    error_log('create_sector.php: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Erro interno ao criar setor.']);
}
