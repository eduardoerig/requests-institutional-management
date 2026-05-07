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

$name        = trim($_POST['new_name'] ?? '');
$login       = trim($_POST['new_login'] ?? '');
$password    = trim($_POST['new_password'] ?? '');
$role        = trim($_POST['new_role'] ?? 'solicitante');
$force_reset = isset($_POST['force_reset']) ? 1 : 0;
$subdivisions = $_POST['new_subdivisions'] ?? [];
$primary_subdivision = !empty($subdivisions[0]) ? (int)$subdivisions[0] : null;

if (empty($name) || empty($login) || empty($password)) {
    echo json_encode(['success' => false, 'message' => 'Preencha todos os campos obrigatórios.']);
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

    // Inserir novo usuário
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $ins = $pdo->prepare("INSERT INTO ctd_users (name, login, password, role, force_reset, status, subdivision_id) VALUES (?, ?, ?, ?, ?, 1, ?)");

    if ($ins->execute([$name, $login, $hash, $role, $force_reset, $primary_subdivision])) {
        $userId = $pdo->lastInsertId();
        
        // Inserir todas as subdivisões na tabela pivot
        foreach ($subdivisions as $subId) {
            if (!empty($subId)) {
                $insSub = $pdo->prepare("INSERT IGNORE INTO cfg_user_subdivision (id_user, id_subdivision) VALUES (?, ?)");
                $insSub->execute([$userId, $subId]);
            }
        }

        echo json_encode(['success' => true, 'message' => 'Usuário cadastrado com sucesso!']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Erro ao cadastrar usuário.']);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Erro: ' . $e->getMessage()]);
}
