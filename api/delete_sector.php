<?php
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/conn.php';
require_once __DIR__ . '/../config/security.php';
requireLoginJson($pdo);

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Método inválido.']);
    exit;
}
requireCsrfTokenFromRequest();

if (!isGlobalAdminRole($_SESSION['role'] ?? '')) {
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

    // Repasses guardam o setor de origem/destino: excluir quebraria o histórico
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM request_forwards WHERE from_area_id = ? OR to_area_id = ?");
    $stmt->execute([$sectorId, $sectorId]);
    if ($stmt->fetchColumn() > 0) {
        echo json_encode(['success' => false, 'message' => 'Este setor aparece no histórico de repasses e não pode ser excluído.']);
        exit;
    }

    // Setores de atendimento do sistema (TI, Compras, etc.) não podem ser removidos
    $stmt = $pdo->prepare("SELECT title FROM ctd_area WHERE id = ?");
    $stmt->execute([$sectorId]);
    $title = (string)$stmt->fetchColumn();
    if ($title !== '' && areaTitleToSector($title) !== null) {
        echo json_encode(['success' => false, 'message' => 'Este é um setor de atendimento do sistema e não pode ser excluído.']);
        exit;
    }

    $stmt = $pdo->prepare("DELETE FROM ctd_area WHERE id = ?");
    $stmt->execute([$sectorId]);

    echo json_encode(['success' => true, 'message' => 'Setor excluído com sucesso!']);
} catch (PDOException $e) {
    error_log('delete_sector.php: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Erro interno ao excluir setor.']);
}
