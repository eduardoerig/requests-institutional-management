<?php
/**
 * Liga (padrão) ou desliga (action=remove) uma pessoa como responsável de um setor (cfg_user_area).
 * Somente administrador geral; POST com token CSRF.
 */
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/conn.php';
require_once __DIR__ . '/../config/security.php';
requireLoginJson($pdo);

header('Content-Type: application/json');

// Proteção: apenas administradores
$current_role = $_SESSION['role'] ?? 'solicitante';
if (!isGlobalAdminRole($current_role)) {
    echo json_encode(['success' => false, 'message' => 'Acesso negado.']);
    exit;
}
requirePostJson();

$userId   = (int)($_POST['user_id'] ?? 0);
$sectorId = (int)($_POST['sector_id'] ?? 0);
$action   = (string)($_POST['action'] ?? 'add');

if ($userId <= 0 || $sectorId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Dados incompletos.']);
    exit;
}

try {
    $stmtUser = $pdo->prepare("SELECT role, status FROM ctd_users WHERE id = ?");
    $stmtUser->execute([$userId]);
    $target = $stmtUser->fetch(PDO::FETCH_ASSOC);
    if (!$target) {
        echo json_encode(['success' => false, 'message' => 'Pessoa não encontrada.']);
        exit;
    }
    $targetRole = (string)$target['role'];

    $stmtArea = $pdo->prepare("SELECT title FROM ctd_area WHERE id = ?");
    $stmtArea->execute([$sectorId]);
    $areaTitle = $stmtArea->fetchColumn();
    if ($areaTitle === false) {
        echo json_encode(['success' => false, 'message' => 'Setor informado não existe.']);
        exit;
    }

    if ($action === 'remove') {
        // O setor do próprio perfil (ex.: perfil TI -> setor TI) só sai trocando o perfil
        $roleSector = roleDefaultSector($targetRole);
        if ($roleSector !== null && areaTitleToSector((string)$areaTitle) === $roleSector) {
            echo json_encode(['success' => false, 'message' => 'Esta pessoa está neste setor pelo perfil. Para tirá-la, mude o perfil dela ou desative a conta.']);
            exit;
        }
        $del = $pdo->prepare("DELETE FROM cfg_user_area WHERE id_user = ? AND id_area = ?");
        $del->execute([$userId, $sectorId]);
        if ($del->rowCount() === 0) {
            echo json_encode(['success' => false, 'message' => 'Esta pessoa já não respondia por este setor.']);
            exit;
        }
        echo json_encode(['success' => true, 'message' => 'Responsável removido do setor.']);
        exit;
    }

    if ($action !== 'add') {
        echo json_encode(['success' => false, 'message' => 'Ação inválida.']);
        exit;
    }

    if (!isGestorRole($targetRole) && !isGlobalAdminRole($targetRole)) {
        echo json_encode(['success' => false, 'message' => 'Somente gestores e administradores podem ser vinculados a setores.']);
        exit;
    }
    if ((int)($target['status'] ?? 1) !== 1) {
        echo json_encode(['success' => false, 'message' => 'Esta conta está desativada. Reative-a antes de ligar a um setor.']);
        exit;
    }

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
    error_log('assign_sector.php: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Erro interno ao vincular setor.']);
}
