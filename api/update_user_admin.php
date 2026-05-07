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

$userId   = $_POST['id'] ?? null;
$action   = $_POST['action'] ?? 'update';

if (!$userId) {
    echo json_encode(['success' => false, 'message' => 'ID do usuário não informado.']);
    exit;
}

try {
    if ($action === 'delete') {
        // 1. Verificar se existem chamados vinculados a este usuário em QUALQUER uma das tabelas de setor
        $stmtTables = $pdo->query("SHOW TABLES LIKE 'ctd_%_frm'");
        while ($rowTable = $stmtTables->fetch(PDO::FETCH_NUM)) {
            $tableName = $rowTable[0];
            $stmtCheck = $pdo->prepare("SELECT COUNT(*) FROM $tableName WHERE created_by = ?");
            $stmtCheck->execute([$userId]);
            if ($stmtCheck->fetchColumn() > 0) {
                echo json_encode([
                    'success' => false, 
                    'message' => 'Este usuário possui chamados registrados (Tabela: '.$tableName.'). Por segurança, inative a conta em vez de excluí-la para preservar o histórico.'
                ]);
                exit;
            }
        }

        $stmtPivot = $pdo->prepare("DELETE FROM cfg_user_area WHERE id_user = ?");
        $stmtPivot->execute([$userId]);

        // 2.1 Remover vínculos de subdivisões
        $stmtSub = $pdo->prepare("DELETE FROM cfg_user_subdivision WHERE id_user = ?");
        $stmtSub->execute([$userId]);

        // 3. Excluir usuário
        $stmt = $pdo->prepare("DELETE FROM ctd_users WHERE id = ?");
        $stmt->execute([$userId]);

        echo json_encode(['success' => true, 'message' => 'Conta excluída permanentemente.']);
        exit;
    }

    // Coletar campos para atualização dinâmica
    $fields = [];
    $params = [];

    if (isset($_POST['login'])) {
        $fields[] = "login = ?";
        $params[] = trim($_POST['login']);
    }
    if (isset($_POST['role'])) {
        $fields[] = "role = ?";
        $params[] = trim($_POST['role']);
    }
    if (array_key_exists('subdivision_id', $_POST)) {
        $fields[] = "subdivision_id = ?";
        $subId = $_POST['subdivision_id'] !== '' ? (int)$_POST['subdivision_id'] : null;
        $params[] = $subId;

        // Sincronizar com a tabela pivot
        $pdo->prepare("DELETE FROM cfg_user_subdivision WHERE id_user = ?")->execute([$userId]);
        if ($subId) {
            $pdo->prepare("INSERT INTO cfg_user_subdivision (id_user, id_subdivision) VALUES (?, ?)")->execute([$userId, $subId]);
        }
    }
    if (isset($_POST['status'])) {
        $fields[] = "status = ?";
        $params[] = (int)$_POST['status'];
    }
    if (!empty($_POST['password'])) {
        $fields[] = "password = ?";
        $params[] = password_hash(trim($_POST['password']), PASSWORD_DEFAULT);
    }

    if (empty($fields)) {
        echo json_encode(['success' => false, 'message' => 'Nenhum campo para atualizar.']);
        exit;
    }

    $params[] = $userId;
    $sql = "UPDATE ctd_users SET " . implode(", ", $fields) . " WHERE id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    echo json_encode(['success' => true, 'message' => 'Alterações salvas com sucesso!']);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Erro: ' . $e->getMessage()]);
}
