<?php
session_start();
require_once __DIR__ . '/../config/conn.php';

header('Content-Type: application/json');

if (!isset($_SESSION['id'])) {
    echo json_encode(['success' => false, 'message' => 'Sessão expirada.']);
    exit;
}

$id = $_GET['id'] ?? null;
$table = $_GET['table'] ?? null;

if (!$id || !$table) {
    echo json_encode(['success' => false, 'message' => 'Parâmetros inválidos.']);
    exit;
}

// Segurança: Validar tabela
$allowed = ['mkt', 'shop', 'xerox', 'service', 'ti'];
if (!in_array($table, $allowed)) {
    echo json_encode(['success' => false, 'message' => 'Tabela não permitida.']);
    exit;
}

try {
    $tableName = "ctd_{$table}_frm";
    $stmt = $pdo->prepare("SELECT * FROM `$tableName` WHERE id = ?");
    $stmt->execute([$id]);
    $req = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$req) {
        echo json_encode(['success' => false, 'message' => 'Requisição não encontrada.']);
        exit;
    }

    // Segurança: Apenas dono, admin ou gestor
    $role = $_SESSION['role'] ?? 'solicitante';
    $adminRoles = ['admin', 'adm', 'coord', 'adm_sub'];
    $gestorRoles = ['gestor', 'ti', 'xerox', 'service', 'shop', 'mkt', 'marketing'];

    $isAdmin = in_array($role, $adminRoles);
    $isGestor = in_array($role, $gestorRoles);
    $isOwner = ((int)$req['created_by'] === (int)$_SESSION['id']);

    if (!$isAdmin && !$isGestor && !$isOwner) {
        echo json_encode(['success' => false, 'message' => 'Acesso negado.']);
        exit;
    }

    echo json_encode(['success' => true, 'data' => $req]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
