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

$sectorId = $_POST['id'] ?? null;

if (!$sectorId) {
    echo json_encode(['success' => false, 'message' => 'ID do setor não informado.']);
    exit;
}

try {
    // Verificar se há vínculos
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM cfg_user_area WHERE id_area = ?");
    $stmt->execute([$sectorId]);
    if ($stmt->fetchColumn() > 0) {
        echo json_encode(['success' => false, 'message' => 'Não é possível excluir um setor que possui usuários vinculados.']);
        exit;
    }

    $stmt = $pdo->prepare("DELETE FROM ctd_area WHERE id = ?");
    $stmt->execute([$sectorId]);

    echo json_encode(['success' => true, 'message' => 'Setor excluído com sucesso!']);
} catch (PDOException $e) {
    error_log('delete_sector.php: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Erro interno ao excluir setor.']);
}
