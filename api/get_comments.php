<?php
/**
 * api/get_comments.php
 * Devolve a linha do tempo do pedido (pedido, eventos e conversa) em HTML.
 */
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/conn.php';
require_once __DIR__ . '/../config/security.php';
requireLoginJson($pdo);
session_write_close(); // só leitura: não segura a sessão das outras abas e requisições

if (!isset($_SESSION['id'])) {
    http_response_code(401);
    exit;
}

$req_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$req_table = isset($_GET['table']) ? normalizeRequestTable((string)$_GET['table']) : null;

if (!$req_id || !$req_table) {
    exit;
}

$req = getRequestRow($pdo, $req_table, $req_id);
$role = $_SESSION['role'] ?? 'solicitante';
if (!$req || !userCanViewRequest($pdo, $req, $req_table, (int)$_SESSION['id'], $role)) {
    http_response_code(403);
    exit;
}

// Mesma linha do tempo da página do pedido (o JS compara para não redesenhar à toa)
require_once __DIR__ . '/../components/request_view.php';
$stmt = $pdo->prepare('SELECT name FROM ctd_users WHERE id = ?');
$stmt->execute([(int)($req['created_by'] ?? 0)]);
$req['creator_name'] = $stmt->fetchColumn() ?: null;
header('Content-Type: text/html; charset=UTF-8');
$helperOnly = $role !== 'solicitante' && userSeesOnlyInternalChat($pdo, $req, $req_table, (int)$_SESSION['id'], $role);
echo renderRequestFeed($pdo, $req, $req_table, (int)$_SESSION['id'], $role !== 'solicitante', null, null, $helperOnly);
