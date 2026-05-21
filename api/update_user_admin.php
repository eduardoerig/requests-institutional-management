<?php
session_start();
require_once '../config/conn.php';
require_once '../config/security.php';

header('Content-Type: application/json');

$is_self = isset($_GET['self']) && $_GET['self'] == 1;

// Proteção: apenas administradores para ações gerais
$current_role = $_SESSION['role'] ?? 'solicitante';
if (!$is_self && !in_array($current_role, ['admin', 'adm'])) {
    echo json_encode(['success' => false, 'message' => 'Acesso negado.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfTokenFromRequest();
}

$userId = $is_self ? ($_SESSION['id'] ?? null) : ($_POST['id'] ?? null);
$action = $_POST['action'] ?? 'update';

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
    $newRole = null;
    $subdivisionSync = null;

    if (isset($_POST['login'])) {
        $fields[] = "login = ?";
        $params[] = trim($_POST['login']);
    }
    if (isset($_POST['phone'])) {
        $phoneVal = preg_replace('/\D/', '', trim($_POST['phone']));
        // Verificar unicidade (ignorando o próprio usuário)
        if (!empty($phoneVal)) {
            $chkPhone = $pdo->prepare("SELECT id FROM ctd_users WHERE phone = ? AND id != ?");
            $chkPhone->execute([$phoneVal, $userId]);
            if ($chkPhone->fetch()) {
                echo json_encode(['success' => false, 'message' => 'Este número de telefone já está cadastrado em outra conta.']);
                exit;
            }
        }
        $fields[] = "phone = ?";
        $params[] = !empty($phoneVal) ? $phoneVal : null;
    }
    if (isset($_POST['role'])) {
        $newRole = trim($_POST['role']);
        $allowedRoles = array_merge(['admin', 'adm_sub', 'solicitante'], gestorRoles());
        if (!in_array($newRole, $allowedRoles, true)) {
            echo json_encode(['success' => false, 'message' => 'Perfil de acesso inválido.']);
            exit;
        }

        if (
            in_array($newRole, ['solicitante', 'adm_sub'], true)
            && !array_key_exists('subdivision_id', $_POST)
            && !array_key_exists('subdivisions', $_POST)
        ) {
            $stmtSubCount = $pdo->prepare("SELECT COUNT(*) FROM cfg_user_subdivision WHERE id_user = ?");
            $stmtSubCount->execute([$userId]);
            if ((int)$stmtSubCount->fetchColumn() === 0) {
                echo json_encode(['success' => false, 'message' => 'Informe uma subdivisão antes de aplicar este perfil.']);
                exit;
            }
        }

        $fields[] = "role = ?";
        $params[] = $newRole;
    }
    if (array_key_exists('subdivisions', $_POST)) {
        $postedSubdivisions = $_POST['subdivisions'];
        if (!is_array($postedSubdivisions)) {
            $postedSubdivisions = [$postedSubdivisions];
        }

        $subdivisionSync = array_values(array_unique(array_filter(
            array_map('intval', $postedSubdivisions),
            fn($id) => $id > 0
        )));

        $roleToValidate = $newRole;
        if ($roleToValidate === null) {
            $stmtRole = $pdo->prepare("SELECT role FROM ctd_users WHERE id = ?");
            $stmtRole->execute([$userId]);
            $roleToValidate = $stmtRole->fetchColumn() ?: 'solicitante';
        }
        if (in_array($roleToValidate, ['solicitante', 'adm_sub'], true) && empty($subdivisionSync)) {
            echo json_encode(['success' => false, 'message' => 'Este perfil exige pelo menos uma subdivisao vinculada.']);
            exit;
        }

        if (!empty($subdivisionSync)) {
            $placeholders = implode(',', array_fill(0, count($subdivisionSync), '?'));
            $stmtValidSubs = $pdo->prepare("SELECT COUNT(*) FROM ctd_subdivision WHERE id IN ($placeholders)");
            $stmtValidSubs->execute($subdivisionSync);
            if ((int)$stmtValidSubs->fetchColumn() !== count($subdivisionSync)) {
                echo json_encode(['success' => false, 'message' => 'Uma ou mais subdivisoes informadas sao invalidas.']);
                exit;
            }
        }

        $fields[] = "subdivision_id = ?";
        $params[] = $subdivisionSync[0] ?? null;
    } elseif (array_key_exists('subdivision_id', $_POST)) {
        $fields[] = "subdivision_id = ?";
        $subId = $_POST['subdivision_id'] !== '' ? (int)$_POST['subdivision_id'] : null;
        $roleToValidate = $newRole;
        if ($roleToValidate === null) {
            $stmtRole = $pdo->prepare("SELECT role FROM ctd_users WHERE id = ?");
            $stmtRole->execute([$userId]);
            $roleToValidate = $stmtRole->fetchColumn() ?: 'solicitante';
        }
        if (in_array($roleToValidate, ['solicitante', 'adm_sub'], true) && !$subId) {
            echo json_encode(['success' => false, 'message' => 'Este perfil exige uma subdivisão vinculada.']);
            exit;
        }
        $params[] = $subId;
        $subdivisionSync = $subId ? [$subId] : [];
    }
    if (isset($_POST['status'])) {
        $fields[] = "status = ?";
        $params[] = (int)$_POST['status'];
    }
    if (!empty($_POST['password'])) {
        // Se for auto-atualização, validar senha atual
        if ($is_self) {
            $currentPass = $_POST['currentPass'] ?? '';
            $checkPass = $pdo->prepare("SELECT password FROM ctd_users WHERE id = ?");
            $checkPass->execute([$userId]);
            $userRow = $checkPass->fetch();

            if (!$userRow || !password_verify($currentPass, $userRow['password'])) {
                echo json_encode(['success' => false, 'message' => 'Senha atual incorreta.']);
                exit;
            }
        }

        $fields[] = "password = ?";
        $params[] = password_hash(trim($_POST['password']), PASSWORD_DEFAULT);

        // Sempre que a senha é alterada, removemos o bloqueio de force_reset
        $fields[] = "force_reset = 0";
    }

    if (empty($fields)) {
        echo json_encode(['success' => false, 'message' => 'Nenhum campo para atualizar.']);
        exit;
    }

    $params[] = $userId;
    $sql = "UPDATE ctd_users SET " . implode(", ", $fields) . " WHERE id = ?";
    $stmt = $pdo->prepare($sql);
    $pdo->beginTransaction();
    if ($stmt->execute($params)) {
        if ($subdivisionSync !== null) {
            $pdo->prepare("DELETE FROM cfg_user_subdivision WHERE id_user = ?")->execute([$userId]);
            $stmtInsertSub = $pdo->prepare("INSERT INTO cfg_user_subdivision (id_user, id_subdivision) VALUES (?, ?)");
            foreach ($subdivisionSync as $subId) {
                $stmtInsertSub->execute([$userId, $subId]);
            }
        }
        if ($newRole !== null) {
            ensureUserAreaForRole($pdo, (int)$userId, $newRole);
        }
        $pdo->commit();
        if ($is_self && !empty($_POST['password'])) {
            $_SESSION['force_reset'] = 0;
        }
        echo json_encode(['success' => true, 'message' => 'Alterações salvas com sucesso!']);
    } else {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'Erro ao salvar alterações.']);
    }

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('update_user_admin.php: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Erro interno ao processar a solicitacao.']);
}
