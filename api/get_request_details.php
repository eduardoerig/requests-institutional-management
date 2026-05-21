<?php
session_start();
require_once __DIR__ . '/../config/conn.php';
require_once __DIR__ . '/../config/security.php';

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
$table = normalizeRequestTable((string)$table);
if ($table === null) {
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

    // Segurança: Apenas dono, admin autorizado ou gestor do setor/repasse.
    $role = $_SESSION['role'] ?? 'solicitante';
    if (!userCanViewRequest($pdo, $req, $table, (int)$_SESSION['id'], $role)) {
        echo json_encode(['success' => false, 'message' => 'Acesso negado.']);
        exit;
    }

    echo json_encode(['success' => true, 'data' => $req]);

} catch (Exception $e) {
    error_log('get_request_details.php: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Erro interno ao carregar os detalhes.']);
}
