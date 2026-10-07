<?php
/**
 * api/request_forwards.php
 * Encaminhamento entre setores.
 *
 *  POST action=forward  id, table, to_area_id, mode (transfer|help), message
 *  POST action=accept   forward_id
 *  POST action=refuse   forward_id, reason
 *  POST action=cancel   forward_id
 *  POST action=return   forward_id, note
 *  GET  action=areas    id, table   (setores para onde dá para encaminhar)
 */
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/conn.php';
require_once __DIR__ . '/../config/security.php';
requireLoginJson($pdo);
require_once __DIR__ . '/../classes/RequestForwardService.php';

header('Content-Type: application/json; charset=UTF-8');

$role = $_SESSION['role'] ?? 'solicitante';
$userId = (int)$_SESSION['id'];
$userName = $_SESSION['name'] ?? 'Usuário';

if (!isGlobalAdminRole($role) && !isGestorRole($role)) {
    jsonResponse(['success' => false, 'message' => 'Só os setores e a administração encaminham pedidos.'], 403);
}

$action = (string)($_POST['action'] ?? $_GET['action'] ?? '');

if ($action === 'areas') {
    $sector = normalizeRequestTable((string)($_GET['table'] ?? ''));
    $reqId = (int)($_GET['id'] ?? 0);
    $exclude = ($sector && $reqId) ? RequestForwardService::ownerAreaId($pdo, $reqId, $sector) : null;
    jsonResponse(['success' => true, 'data' => RequestForwardService::availableAreas($pdo, $exclude)]);
}

requirePostJson();
$forwardId = (int)($_POST['forward_id'] ?? 0);

switch ($action) {
    case 'forward':
        $result = RequestForwardService::forward(
            $pdo,
            (string)($_POST['table'] ?? ''),
            (int)($_POST['id'] ?? 0),
            (int)($_POST['to_area_id'] ?? 0),
            (string)($_POST['mode'] ?? ''),
            (string)($_POST['message'] ?? ''),
            $userId, $userName, $role
        );
        break;
    case 'accept':
        $result = RequestForwardService::accept($pdo, $forwardId, $userId, $userName, $role);
        break;
    case 'refuse':
        $result = RequestForwardService::refuse($pdo, $forwardId, (string)($_POST['reason'] ?? ''), $userId, $userName, $role);
        break;
    case 'cancel':
        $result = RequestForwardService::cancel($pdo, $forwardId, $userId, $userName, $role);
        break;
    case 'return':
        $result = RequestForwardService::returnHelp($pdo, $forwardId, (string)($_POST['note'] ?? ''), $userId, $userName, $role);
        break;
    default:
        $result = ['success' => false, 'message' => 'Ação inválida.'];
}

jsonResponse($result);
