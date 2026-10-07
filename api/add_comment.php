<?php
/**
 * api/add_comment.php
 * Adiciona uma mensagem na conversa da requisição e avisa os envolvidos.
 */
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/conn.php';
require_once __DIR__ . '/../config/security.php';
requireLoginJson($pdo);
requirePostJson();
require_once __DIR__ . '/../classes/RequestManager.php';

$reqId = (int)($_POST['id'] ?? 0);
$sector = normalizeRequestTable((string)($_POST['table'] ?? ''));
$text = trim((string)($_POST['comment'] ?? ''));

if ($reqId <= 0 || $sector === null) {
    jsonResponse(['success' => false, 'message' => 'Requisição inválida.']);
}
if ($text === '') {
    jsonResponse(['success' => false, 'message' => 'Escreva uma mensagem antes de enviar.']);
}
if (mb_strlen($text) > 3000) {
    jsonResponse(['success' => false, 'message' => 'Mensagem muito longa (máximo 3000 caracteres).']);
}

$req = getRequestRow($pdo, $sector, $reqId);
$userId = (int)$_SESSION['id'];
$role = $_SESSION['role'] ?? 'solicitante';
if (!$req || !userCanViewRequest($pdo, $req, $sector, $userId, $role)) {
    jsonResponse(['success' => false, 'message' => 'Você não tem acesso a esta requisição.'], 403);
}

$userName = $_SESSION['name'] ?? 'Usuário';
$pdo->prepare('INSERT INTO request_comments (request_id, request_table, user_id, user_name, comment) VALUES (?, ?, ?, ?, ?)')
    ->execute([$reqId, $sector, $userId, $userName, $text]);

$preview = mb_strlen($text) > 60 ? mb_substr($text, 0, 57) . '...' : $text;
RequestManager::notifyStakeholders(
    $pdo, $req, $sector,
    "Nova mensagem na requisição #$reqId",
    "$userName: $preview",
    "request_detail?id=$reqId&table=$sector",
    $userId,
    false
);

jsonResponse(['success' => true]);
