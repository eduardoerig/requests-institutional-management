<?php
session_start();
require_once '../config/conn.php';
require_once '../config/security.php';

header('Content-Type: application/json');

// Proteção: apenas administradores
$current_role = $_SESSION['role'] ?? 'solicitante';
if (!in_array($current_role, ['admin', 'adm'])) {
    echo json_encode(['success' => false, 'message' => 'Acesso negado.']);
    exit;
}
requireCsrfTokenFromRequest();

$name        = trim($_POST['new_name'] ?? '');
$login       = trim($_POST['new_login'] ?? '');
$password    = trim($_POST['new_password'] ?? '');
$role        = trim($_POST['new_role'] ?? 'solicitante');
$phone       = preg_replace('/\D/', '', trim($_POST['new_phone'] ?? '')); // apenas dígitos
$force_reset = isset($_POST['force_reset']) ? 1 : 0;
$subdivisions = $_POST['new_subdivisions'] ?? [];
$subdivisions = array_values(array_filter(array_map('intval', (array)$subdivisions)));
$primary_subdivision = !empty($subdivisions[0]) ? (int)$subdivisions[0] : null;
$allowedRoles = array_merge(['admin', 'adm_sub', 'solicitante'], gestorRoles());

if (empty($name) || empty($login) || empty($password)) {
    echo json_encode(['success' => false, 'message' => 'Preencha todos os campos obrigatórios.']);
    exit;
}

if (!in_array($role, $allowedRoles, true)) {
    echo json_encode(['success' => false, 'message' => 'Perfil de acesso inválido.']);
    exit;
}

if (in_array($role, ['solicitante', 'adm_sub'], true) && empty($subdivisions)) {
    echo json_encode(['success' => false, 'message' => 'Informe ao menos uma subdivisão para este perfil.']);
    exit;
}

try {
    // Verificar se login já existe
    $check = $pdo->prepare("SELECT id FROM ctd_users WHERE login = ?");
    $check->execute([$login]);
    if ($check->fetch()) {
        echo json_encode(['success' => false, 'message' => 'Este login já está em uso.']);
        exit;
    }

    // Verificar se telefone já existe (apenas se preenchido)
    if (!empty($phone)) {
        $checkPhone = $pdo->prepare("SELECT id FROM ctd_users WHERE phone = ?");
        $checkPhone->execute([$phone]);
        if ($checkPhone->fetch()) {
            echo json_encode(['success' => false, 'message' => 'Este número de telefone já está cadastrado em outra conta.']);
            exit;
        }
    }

    // Inserir novo usuário
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $phoneValue = !empty($phone) ? $phone : null;
    $ins = $pdo->prepare("INSERT INTO ctd_users (name, login, phone, password, role, force_reset, status, subdivision_id) VALUES (?, ?, ?, ?, ?, ?, 1, ?)");

    if ($ins->execute([$name, $login, $phoneValue, $hash, $role, $force_reset, $primary_subdivision])) {
        $userId = $pdo->lastInsertId();
        
        // Inserir todas as subdivisões na tabela pivot
        foreach ($subdivisions as $subId) {
            $insSub = $pdo->prepare("INSERT IGNORE INTO cfg_user_subdivision (id_user, id_subdivision) VALUES (?, ?)");
            $insSub->execute([$userId, $subId]);
        }

        ensureUserAreaForRole($pdo, (int)$userId, $role);

        echo json_encode(['success' => true, 'message' => 'Usuário cadastrado com sucesso!']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Erro ao cadastrar usuário.']);
    }
} catch (Exception $e) {
    error_log('create_user.php: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Erro interno ao cadastrar usuário.']);
}
