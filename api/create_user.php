<?php
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

$name        = trim((string)($_POST['new_name'] ?? ''));
$login       = trim((string)($_POST['new_login'] ?? ''));
$password    = trim((string)($_POST['new_password'] ?? ''));
$role        = trim((string)($_POST['new_role'] ?? 'solicitante'));
$phone       = preg_replace('/\D/', '', trim((string)($_POST['new_phone'] ?? ''))); // apenas dígitos
$force_reset = isset($_POST['force_reset']) ? 1 : 0;
$subdivisions = $_POST['new_subdivisions'] ?? [];
$subdivisions = array_values(array_unique(array_filter(array_map('intval', (array)$subdivisions), fn($id) => $id > 0)));
$primary_subdivision = $subdivisions[0] ?? null;
$allowedRoles = array_merge(globalAdminRoles(), ['adm_sub', 'solicitante'], gestorRoles());

// Senha em branco: o servidor gera uma temporária (mostrada uma única vez ao administrador)
$tempPassword = null;
if ($password === '') {
    $tempPassword = generateTempPassword();
    $password = $tempPassword;
    $force_reset = 1;
}

if ($name === '' || $login === '') {
    jsonResponse(['success' => false, 'message' => 'Preencha todos os campos obrigatórios.', 'field' => $name === '' ? 'name' : 'login']);
}

if (mb_strlen($name) > 150) {
    jsonResponse(['success' => false, 'message' => 'O nome pode ter no máximo 150 caracteres.', 'field' => 'name']);
}

if (!isValidLoginFormat($login)) {
    jsonResponse(['success' => false, 'message' => 'Usuário inválido: use letras, números, ponto, hífen ou sublinhado (sem espaços nem acentos).', 'field' => 'login']);
}

if (mb_strlen($password) < 8) {
    jsonResponse(['success' => false, 'message' => 'A senha precisa ter pelo menos 8 caracteres.', 'field' => 'password']);
}

if (!in_array($role, $allowedRoles, true)) {
    jsonResponse(['success' => false, 'message' => 'Perfil de acesso inválido.', 'field' => 'role']);
}

if (in_array($role, ['solicitante', 'adm_sub'], true) && empty($subdivisions)) {
    jsonResponse(['success' => false, 'message' => 'Informe ao menos uma subdivisão para este perfil.', 'field' => 'subdivisions']);
}

if ($phone !== '' && strlen($phone) > 20) {
    jsonResponse(['success' => false, 'message' => 'WhatsApp inválido.', 'field' => 'phone']);
}

try {
    // Verificar se login já existe
    $check = $pdo->prepare("SELECT id FROM ctd_users WHERE login = ?");
    $check->execute([$login]);
    if ($check->fetch()) {
        jsonResponse(['success' => false, 'message' => 'Este usuário já existe. Escolha outro.', 'field' => 'login']);
    }

    // Verificar se telefone já existe (apenas se preenchido)
    if ($phone !== '') {
        $checkPhone = $pdo->prepare("SELECT id FROM ctd_users WHERE phone = ?");
        $checkPhone->execute([$phone]);
        if ($checkPhone->fetch()) {
            jsonResponse(['success' => false, 'message' => 'Este número de telefone já está cadastrado em outra conta.', 'field' => 'phone']);
        }
    }

    if (!empty($subdivisions)) {
        $placeholders = implode(',', array_fill(0, count($subdivisions), '?'));
        $stmtValidSubs = $pdo->prepare("SELECT COUNT(*) FROM ctd_subdivision WHERE id IN ($placeholders)");
        $stmtValidSubs->execute($subdivisions);
        if ((int)$stmtValidSubs->fetchColumn() !== count($subdivisions)) {
            jsonResponse(['success' => false, 'message' => 'Uma ou mais áreas informadas são inválidas.', 'field' => 'subdivisions']);
        }
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);
    $phoneValue = $phone !== '' ? $phone : null;

    $pdo->beginTransaction();
    $ins = $pdo->prepare("INSERT INTO ctd_users (name, login, phone, password, role, force_reset, status, subdivision_id) VALUES (?, ?, ?, ?, ?, ?, 1, ?)");
    $ins->execute([$name, $login, $phoneValue, $hash, $role, $force_reset, $primary_subdivision]);
    $userId = (int)$pdo->lastInsertId();

    // Todas as subdivisões na tabela pivot
    $insSub = $pdo->prepare("INSERT IGNORE INTO cfg_user_subdivision (id_user, id_subdivision) VALUES (?, ?)");
    foreach ($subdivisions as $subId) {
        $insSub->execute([$userId, $subId]);
    }

    ensureUserAreaForRole($pdo, $userId, $role);
    $pdo->commit();

    $response = ['success' => true, 'message' => 'Usuário cadastrado com sucesso!', 'id' => $userId, 'login' => $login];
    if ($tempPassword !== null) {
        // Só o administrador (verificado acima) recebe a senha temporária, e só nesta resposta
        $response['temp_password'] = $tempPassword;
    }
    jsonResponse($response);
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if ($e instanceof PDOException && $e->getCode() === '23000') {
        jsonResponse(['success' => false, 'message' => 'Este usuário ou telefone já está em uso.', 'field' => 'login']);
    }
    error_log('create_user.php: ' . $e->getMessage());
    jsonResponse(['success' => false, 'message' => 'Erro interno ao cadastrar usuário.']);
}
