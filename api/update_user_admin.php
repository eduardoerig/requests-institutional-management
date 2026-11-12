<?php
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/conn.php';
require_once __DIR__ . '/../config/security.php';

header('Content-Type: application/json');

$is_self = isset($_GET['self']) && $_GET['self'] == 1;

// Troca de senha própria é permitida mesmo com force_reset pendente
requireLoginJson($pdo, $is_self);
requirePostJson();

// Proteção: apenas administradores para ações gerais
$current_role = $_SESSION['role'] ?? 'solicitante';
if (!$is_self && !isGlobalAdminRole($current_role)) {
    jsonResponse(['success' => false, 'message' => 'Acesso negado.'], 403);
}

if ($is_self) {
    // Autoatendimento: somente telefone e senha. Qualquer outro campo é ignorado.
    $_POST = array_intersect_key($_POST, array_flip(['phone', 'password', 'currentPass', 'csrf_token']));
    if (!empty($_POST['password']) && empty($_POST['currentPass'])) {
        jsonResponse(['success' => false, 'message' => 'Informe sua senha atual.']);
    }
}

if (!empty($_POST['password']) && mb_strlen(trim($_POST['password'])) < 8) {
    jsonResponse(['success' => false, 'message' => 'A nova senha precisa ter pelo menos 8 caracteres.']);
}

$userId = $is_self ? ($_SESSION['id'] ?? null) : ($_POST['id'] ?? null);
$action = $is_self ? 'update' : ($_POST['action'] ?? 'update');

if (!$userId) {
    echo json_encode(['success' => false, 'message' => 'ID do usuário não informado.']);
    exit;
}

$userId = (int)$userId;

// Impedir que o sistema fique sem administrador ativo
$removesAdmin = !$is_self && (
    $action === 'delete'
    || (isset($_POST['role']) && !isGlobalAdminRole(trim($_POST['role'])))
    || (isset($_POST['status']) && (int)$_POST['status'] !== 1)
);
if ($removesAdmin) {
    if ($userId === (int)$_SESSION['id']) {
        jsonResponse(['success' => false, 'message' => 'Você não pode excluir, desativar ou rebaixar a sua própria conta.']);
    }
    $stmtTarget = $pdo->prepare('SELECT role FROM ctd_users WHERE id = ?');
    $stmtTarget->execute([$userId]);
    $targetRole = (string)$stmtTarget->fetchColumn();
    if (isGlobalAdminRole($targetRole)) {
        $rolesIn = implode(',', array_fill(0, count(globalAdminRoles()), '?'));
        $stmtAdmins = $pdo->prepare("SELECT COUNT(*) FROM ctd_users WHERE role IN ($rolesIn) AND status = 1 AND id <> ?");
        $stmtAdmins->execute(array_merge(globalAdminRoles(), [$userId]));
        if ((int)$stmtAdmins->fetchColumn() === 0) {
            jsonResponse(['success' => false, 'message' => 'Este é o último administrador ativo. Crie outro administrador antes.']);
        }
    }
}

try {
    if ($action === 'reset_password') {
        // Senha temporária gerada no servidor; aparece uma única vez para o administrador
        if ($userId === (int)$_SESSION['id']) {
            jsonResponse(['success' => false, 'message' => 'Para trocar a sua própria senha, use "Minha conta".']);
        }
        $stmtLogin = $pdo->prepare('SELECT login FROM ctd_users WHERE id = ?');
        $stmtLogin->execute([$userId]);
        $targetLogin = $stmtLogin->fetchColumn();
        if ($targetLogin === false) {
            jsonResponse(['success' => false, 'message' => 'Usuário não encontrado.']);
        }

        $tempPassword = generateTempPassword();
        // session_version + 1: as sessões abertas da pessoa caem na próxima requisição
        $pdo->prepare('UPDATE ctd_users SET password = ?, force_reset = 1, session_version = session_version + 1 WHERE id = ?')
            ->execute([password_hash($tempPassword, PASSWORD_DEFAULT), $userId]);
        try {
            // Libera tentativas bloqueadas desse login (tabela opcional)
            $pdo->prepare('DELETE FROM login_attempts WHERE login = ?')->execute([(string)$targetLogin]);
        } catch (PDOException $e) {
            // tabela ausente: nada a liberar
        }

        jsonResponse([
            'success' => true,
            'message' => 'Senha temporária gerada. A pessoa cria a própria senha no próximo acesso.',
            'temp_password' => $tempPassword,
        ]);
    }

    if ($action !== 'update' && $action !== 'delete') {
        jsonResponse(['success' => false, 'message' => 'Ação inválida.']);
    }

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
                    'message' => 'Esta pessoa já fez pedidos no sistema. Para preservar o histórico, desative a conta em vez de excluí-la.'
                ]);
                exit;
            }
        }

        $pdo->beginTransaction();
        $stmtPivot = $pdo->prepare("DELETE FROM cfg_user_area WHERE id_user = ?");
        $stmtPivot->execute([$userId]);

        // 2.1 Remover vínculos de subdivisões
        $stmtSub = $pdo->prepare("DELETE FROM cfg_user_subdivision WHERE id_user = ?");
        $stmtSub->execute([$userId]);

        // 3. Excluir usuário
        $stmt = $pdo->prepare("DELETE FROM ctd_users WHERE id = ?");
        $stmt->execute([$userId]);
        $pdo->commit();

        echo json_encode(['success' => true, 'message' => 'Conta excluída permanentemente.']);
        exit;
    }

    // Coletar campos para atualização dinâmica
    $fields = [];
    $params = [];
    $newRole = null;
    $oldRole = null;
    $subdivisionSync = null;

    if (!$is_self && isset($_POST['name'])) {
        $nameVal = trim((string)$_POST['name']);
        $nameLen = mb_strlen($nameVal);
        if ($nameLen < 1 || $nameLen > 150) {
            jsonResponse(['success' => false, 'message' => 'O nome precisa ter entre 1 e 150 caracteres.', 'field' => 'name']);
        }
        $fields[] = "name = ?";
        $params[] = $nameVal;
    }
    if (isset($_POST['login'])) {
        $loginVal = trim((string)$_POST['login']);
        $stmtCurLogin = $pdo->prepare("SELECT login FROM ctd_users WHERE id = ?");
        $stmtCurLogin->execute([$userId]);
        $currentLogin = (string)$stmtCurLogin->fetchColumn();
        // Só valida quando muda (contas antigas podem ter logins fora do formato atual)
        if ($loginVal !== $currentLogin) {
            if (!isValidLoginFormat($loginVal)) {
                jsonResponse(['success' => false, 'message' => 'Usuário inválido: use letras, números, ponto, hífen ou sublinhado (sem espaços nem acentos).', 'field' => 'login']);
            }
            $chkLogin = $pdo->prepare("SELECT id FROM ctd_users WHERE login = ? AND id != ?");
            $chkLogin->execute([$loginVal, $userId]);
            if ($chkLogin->fetch()) {
                jsonResponse(['success' => false, 'message' => 'Este usuário já existe. Escolha outro.', 'field' => 'login']);
            }
        }
        $fields[] = "login = ?";
        $params[] = $loginVal;
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
        $allowedRoles = array_merge(globalAdminRoles(), ['adm_sub', 'solicitante'], gestorRoles());
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

        $stmtOldRole = $pdo->prepare("SELECT role FROM ctd_users WHERE id = ?");
        $stmtOldRole->execute([$userId]);
        $oldRole = $stmtOldRole->fetchColumn();
        $oldRole = $oldRole === false ? null : (string)$oldRole;

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
            $stmtValidSubs = $pdo->prepare("SELECT COUNT(*) FROM ctd_subdivision WHERE status = 1 AND id IN ($placeholders)");
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
            $currentPass = trim((string)($_POST['currentPass'] ?? '')); // gravada sem espaços nas pontas
            $checkPass = $pdo->prepare("SELECT password FROM ctd_users WHERE id = ?");
            $checkPass->execute([$userId]);
            $userRow = $checkPass->fetch();

            if (!$userRow || !password_verify($currentPass, $userRow['password'])) {
                $isTemp = (int)($_SESSION['force_reset'] ?? 0) === 1;
                echo json_encode(['success' => false, 'message' => $isTemp ? 'A senha temporária não confere. Use a que a administração passou.' : 'Senha atual incorreta.']);
                exit;
            }
            // A senha da própria pessoa segue as regras da janela de Minha conta
            $problems = passwordProblems(trim((string)$_POST['password']), $currentPass);
            if ($problems) {
                echo json_encode(['success' => false, 'message' => 'A nova senha precisa de: ' . implode(', ', $problems) . '.']);
                exit;
            }
        }

        $fields[] = "password = ?";
        $params[] = password_hash(trim($_POST['password']), PASSWORD_DEFAULT);

        // Troca feita pela própria pessoa libera o acesso; senha definida pelo admin é temporária
        $fields[] = $is_self ? "force_reset = 0" : "force_reset = 1";
        // Senha nova derruba as outras sessões abertas (refreshSessionUser compara a versão)
        $fields[] = "session_version = session_version + 1";
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
            // Trocou de setor pelo perfil: deixa de responder pelo setor do perfil antigo
            $oldSector = $oldRole !== null ? roleDefaultSector($oldRole) : null;
            if ($oldSector !== null && $oldSector !== roleDefaultSector($newRole)) {
                $oldAreaId = getAreaIdBySector($pdo, $oldSector);
                if ($oldAreaId) {
                    $pdo->prepare("DELETE FROM cfg_user_area WHERE id_user = ? AND id_area = ?")->execute([$userId, $oldAreaId]);
                }
            }
            ensureUserAreaForRole($pdo, (int)$userId, $newRole);
        }
        $pdo->commit();
        if ($is_self && !empty($_POST['password'])) {
            $_SESSION['force_reset'] = 0;
            // Esta sessão continua (id novo, versão nova); as outras sessões da pessoa caem
            $ver = $pdo->prepare('SELECT session_version FROM ctd_users WHERE id = ?');
            $ver->execute([(int)$userId]);
            $_SESSION['session_version'] = (int)$ver->fetchColumn();
            session_regenerate_id(true);
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
