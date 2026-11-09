<?php
/**
 * api/request_forwards.php
 * Encaminhamento entre setores.
 *
 *  POST action=forward  id, table, to_area_id, mode (transfer|help), message
 *  POST action=accept   forward_id
 *  POST action=refuse   forward_id, reason
 *  POST action=cancel   forward_id [, reason: obrigatório para encerrar uma ajuda já aceita]
 *  POST action=return   forward_id, note
 *  POST action=add_partner  id, table, partner_area_id, partner_due, partner_note (coordenação)
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

$action = (string)($_POST['action'] ?? $_GET['action'] ?? '');

// A coordenação só entra para incluir ou tirar o setor parceiro: as demais ações são dos setores e do admin geral
$coordinationAction = $role === 'adm_sub' && in_array($action, ['add_partner', 'cancel'], true);
if (!isGlobalAdminRole($role) && !isGestorRole($role) && !$coordinationAction) {
    jsonResponse(['success' => false, 'message' => 'Só os setores e a administração encaminham pedidos.'], 403);
}

if ($action === 'areas') {
    $sector = normalizeRequestTable((string)($_GET['table'] ?? ''));
    $reqId = (int)($_GET['id'] ?? 0);
    $exclude = ($sector && $reqId) ? RequestForwardService::ownerAreaId($pdo, $reqId, $sector) : null;
    jsonResponse([
        'success' => true,
        'data' => RequestForwardService::availableAreas($pdo, $exclude),
        'from' => $exclude ? RequestForwardService::areaLabel($pdo, $exclude) : '', // responsável atual, para as frases do assistente
    ]);
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
        $result = RequestForwardService::cancel($pdo, $forwardId, $userId, $userName, $role, (string)($_POST['reason'] ?? ''));
        break;
    case 'return':
        $result = RequestForwardService::returnHelp($pdo, $forwardId, (string)($_POST['note'] ?? ''), $userId, $userName, $role);
        break;
    case 'add_partner':
        $result = RequestForwardService::addPartner(
            $pdo,
            (string)($_POST['table'] ?? ''),
            (int)($_POST['id'] ?? 0),
            array_intersect_key($_POST, array_flip(['partner_area_id', 'partner_due', 'partner_note'])),
            $userId, $userName, $role
        );
        break;
    default:
        $result = ['success' => false, 'message' => 'Ação inválida.'];
}

jsonResponse($result);
