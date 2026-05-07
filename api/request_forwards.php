<?php
/**
 * API de Repasse de Requisições
 * 
 * Endpoints:
 *   POST action=forward    → Repassar requisição para outro setor
 *   POST action=accept     → Aceitar repasse recebido
 *   POST action=refuse     → Recusar repasse recebido
 *   POST action=complete   → Concluir atendimento do repasse
 *   GET  action=chain      → Cadeia de repasses de uma requisição
 *   GET  action=pending    → Repasses pendentes para o gestor logado
 *   GET  action=areas      → Lista setores disponíveis para repasse
 */

session_start();
require_once __DIR__ . '/../config/conn.php';
require_once __DIR__ . '/../classes/RequestForwardService.php';

header('Content-Type: application/json');

if (!isset($_SESSION['id'])) {
    echo json_encode(['success' => false, 'message' => 'Sessão expirada. Por favor, faça login novamente.']);
    exit;
}

$role = $_SESSION['role'] ?? 'solicitante';
$userId = (int)$_SESSION['id'];
$userName = $_SESSION['name'] ?? 'Usuário';

$adminRoles = ['admin', 'adm', 'coord'];
$gestorRoles = ['gestor', 'ti', 'xerox', 'service', 'shop', 'mkt', 'marketing'];
$isAdmin = in_array($role, $adminRoles);
$isGestor = in_array($role, $gestorRoles);

// Suporte para JSON body e POST tradicional
$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    $input = array_merge($_GET, $_POST);
}

$action = $input['action'] ?? null;

if (!$action) {
    echo json_encode(['success' => false, 'message' => 'Ação não especificada.']);
    exit;
}

try {
    switch ($action) {

        // ==============================
        // REPASSAR REQUISIÇÃO
        // ==============================
        case 'forward':
            if (!$isAdmin && !$isGestor) {
                echo json_encode(['success' => false, 'message' => 'Apenas gestores e administradores podem repassar requisições.']);
                exit;
            }

            $reqId       = (int)($input['id'] ?? 0);
            $reqTable    = $input['table'] ?? '';
            $toAreaId    = (int)($input['to_area_id'] ?? 0);
            $observation = trim($input['observation'] ?? '');

            // Limpar prefixo da tabela se veio completo (ctd_ti_frm → ti)
            $reqTable = str_replace(['ctd_', '_frm'], '', $reqTable);

            if (!$reqId || !$reqTable || !$toAreaId) {
                echo json_encode(['success' => false, 'message' => 'Dados incompletos. Informe ID, tabela e setor de destino.']);
                exit;
            }

            $result = RequestForwardService::forwardRequest($pdo, $reqId, $reqTable, $toAreaId, $observation, $userId, $userName);
            echo json_encode($result);
            break;

        // ==============================
        // ACEITAR REPASSE
        // ==============================
        case 'accept':
            if (!$isAdmin && !$isGestor) {
                echo json_encode(['success' => false, 'message' => 'Apenas gestores podem aceitar repasses.']);
                exit;
            }

            $forwardId = (int)($input['forward_id'] ?? 0);
            if (!$forwardId) {
                echo json_encode(['success' => false, 'message' => 'ID do repasse não informado.']);
                exit;
            }

            $result = RequestForwardService::acceptForward($pdo, $forwardId, $userId, $userName);
            echo json_encode($result);
            break;

        // ==============================
        // RECUSAR REPASSE
        // ==============================
        case 'refuse':
            if (!$isAdmin && !$isGestor) {
                echo json_encode(['success' => false, 'message' => 'Apenas gestores podem recusar repasses.']);
                exit;
            }

            $forwardId = (int)($input['forward_id'] ?? 0);
            if (!$forwardId) {
                echo json_encode(['success' => false, 'message' => 'ID do repasse não informado.']);
                exit;
            }

            $result = RequestForwardService::refuseForward($pdo, $forwardId, $userId, $userName);
            echo json_encode($result);
            break;

        // ==============================
        // CONCLUIR REPASSE
        // ==============================
        case 'complete':
            if (!$isAdmin && !$isGestor) {
                echo json_encode(['success' => false, 'message' => 'Apenas gestores podem concluir repasses.']);
                exit;
            }

            $forwardId = (int)($input['forward_id'] ?? 0);
            if (!$forwardId) {
                echo json_encode(['success' => false, 'message' => 'ID do repasse não informado.']);
                exit;
            }

            $result = RequestForwardService::completeForward($pdo, $forwardId, $userId, $userName);
            echo json_encode($result);
            break;

        // ==============================
        // CADEIA DE REPASSES
        // ==============================
        case 'chain':
            $reqId    = (int)($input['id'] ?? 0);
            $reqTable = $input['table'] ?? '';
            $reqTable = str_replace(['ctd_', '_frm'], '', $reqTable);

            if (!$reqId || !$reqTable) {
                echo json_encode(['success' => false, 'message' => 'ID e tabela são obrigatórios.']);
                exit;
            }

            $result = RequestForwardService::getForwardChain($pdo, $reqId, $reqTable);
            echo json_encode($result);
            break;

        // ==============================
        // REPASSES PENDENTES PARA O GESTOR
        // ==============================
        case 'pending':
            if (!$isAdmin && !$isGestor) {
                echo json_encode(['success' => false, 'message' => 'Acesso restrito.']);
                exit;
            }

            $result = RequestForwardService::getPendingForwards($pdo, $userId);
            echo json_encode($result);
            break;

        // ==============================
        // LISTAR SETORES DISPONÍVEIS (para dropdown)
        // ==============================
        case 'areas':
            if (!$isAdmin && !$isGestor) {
                echo json_encode(['success' => false, 'message' => 'Acesso restrito.']);
                exit;
            }

            $areas = RequestForwardService::getAvailableAreas($pdo);
            echo json_encode(['success' => true, 'data' => $areas]);
            break;

        default:
            echo json_encode(['success' => false, 'message' => 'Ação não reconhecida.']);
    }

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Erro interno: ' . $e->getMessage()]);
}
