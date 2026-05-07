<?php
session_start();
require_once '../config/conn.php';

header('Content-Type: application/json');

// Proteção: apenas administradores
$current_role = $_SESSION['role'] ?? 'solicitante';
if (!in_array($current_role, ['admin', 'adm'])) {
    echo json_encode(['success' => false, 'message' => 'Acesso negado.']);
    exit;
}

$userId   = $_POST['user_id'] ?? null;
$sectorId = $_POST['sector_id'] ?? null;

if (!$userId || !$sectorId) {
    echo json_encode(['success' => false, 'message' => 'Dados incompletos.']);
    exit;
}

try {
    // Verificar se já está vinculado
    $check = $pdo->prepare("SELECT id FROM cfg_user_area WHERE id_user = ? AND id_area = ?");
    $check->execute([$userId, $sectorId]);
    if ($check->fetch()) {
        echo json_encode(['success' => false, 'message' => 'Este usuário já está vinculado a este setor.']);
        exit;
    }

    // Inserir vínculo
    $ins = $pdo->prepare("INSERT INTO cfg_user_area (id_user, id_area) VALUES (?, ?)");
    if ($ins->execute([$userId, $sectorId])) {
        echo json_encode(['success' => true, 'message' => 'Vínculo realizado com sucesso!']);
    } else {
        throw new Exception("Erro ao inserir no banco.");
    }

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Erro: ' . $e->getMessage()]);
}
