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
// Só uma das conversas que esta pessoa pode ver (?thread=); sem ela ou fora da lista, a principal
$forwards = RequestForwardService::forRequest($pdo, $req_id, $req_table);
$chatRole = requestChatRole($pdo, $req, $req_table, (int)$_SESSION['id'], $role); // quem pediu não vê a interna
$threads = requestChatThreads($pdo, $req, $req_table, (int)$_SESSION['id'], $chatRole, $forwards, requestOwnerFromForwards($pdo, $forwards, $req_table));
$wanted = (string)($_GET['thread'] ?? '');
$thread = array_values(array_filter($threads, fn($t) => $t['key'] === $wanted))[0]
    ?? array_values(array_filter($threads, fn($t) => $t['default']))[0];
header('Content-Type: text/html; charset=UTF-8');
echo renderRequestFeed($pdo, $req, $req_table, (int)$_SESSION['id'], $chatRole !== 'solicitante', $thread, $forwards);
