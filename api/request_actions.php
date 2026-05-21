<?php
session_start();
require_once __DIR__ . '/../config/conn.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../classes/RequestManager.php';
require_once __DIR__ . '/../classes/RequestForwardService.php';

header('Content-Type: application/json');

if (!isset($_SESSION['id'])) {
    echo json_encode(['success' => false, 'message' => 'Sessão expirada. Por favor, faça login novamente.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfTokenFromRequest();
}

// Suporte para JSON e POST tradicional
$data = json_decode(file_get_contents('php://input'), true);
if (!$data) {
    $data = $_POST;
}

$id = $data['id'] ?? null;
$table = $data['table'] ?? null;
$action = $data['action'] ?? null;
$role = $_SESSION['role'] ?? 'solicitante';
$user_id = $_SESSION['id'] ?? 0;
$user_name = $_SESSION['name'] ?? 'Usuário';

if (!$id || !$table || !$action) {
    echo json_encode(['success' => false, 'message' => 'Dados incompletos para a ação.']);
    exit;
}

// Segurança: Validar se a tabela segue o padrão (ex: ctd_mkt_frm)
if (!preg_match('/^ctd_.*_frm$/', $table)) {
    echo json_encode(['success' => false, 'message' => 'Tabela de requisição inválida.']);
    exit;
}

$isAdmin = isAdminRole($role);
$isGlobalAdmin = isGlobalAdminRole($role);
$isGestor = isGestorRole($role);
$canManage = $isAdmin || $isGestor;

$normalizedTable = normalizeRequestTable((string)$table);
if ($normalizedTable === null) {
    echo json_encode(['success' => false, 'message' => 'Tabela de requisicao invalida.']);
    exit;
}
$table = 'ctd_' . $normalizedTable . '_frm';
$sector = $normalizedTable;
$sectorLabels = [
    'mkt' => 'Marketing', 'xerox' => 'Reprografia', 'shop' => 'Compras',
    'service' => 'Manutenção', 'ti' => 'TI',
];
$sectorLabel = $sectorLabels[$sector] ?? strtoupper($sector);

// Helper: Buscar dados da requisição
function getRequestData($pdo, $table, $id) {
    $stmt = $pdo->prepare("SELECT * FROM `$table` WHERE id = ?");
    $stmt->execute([$id]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

// (notifyAll removido, usando RequestManager::notifyStakeholders)

try {
    $link = "request_detail?id=$id&table=$sector";

    switch ($action) {
        case 'reset_status':
            if (!$isAdmin && !$isGestor) throw new Exception('Apenas administradores e gestores podem reabrir uma requisição.');

            $reqData = getRequestData($pdo, $table, $id);
            if (!$reqData) throw new Exception("Requisição #$id não encontrada.");

            if (!userCanActOnRequest($pdo, $reqData, $sector, (int)$user_id, $role)) {
                throw new Exception('Você não tem permissão para gerenciar esta requisição.');
            }

            $oldStatus = $reqData['status'] ?? 'P';
            $completedForward = isGestorRole($role)
                ? getActiveForwardForUser($pdo, (int)$user_id, (int)$id, $sector, ['completed'])
                : null;
            $managesOriginalRequest = userCanManageOriginalRequest($pdo, $reqData, $sector, (int)$user_id, $role);
            $isForwardReopen = !$managesOriginalRequest && $completedForward !== null;
            $newStatus = $isForwardReopen ? 'F' : 'W';

            $stmt = $pdo->prepare("UPDATE `$table` SET status = ? WHERE id = ?");
            if (!$stmt->execute([$newStatus, $id])) {
                $err = $stmt->errorInfo();
                throw new Exception("Erro ao reabrir requisição: " . ($err[2] ?? 'Erro desconhecido'));
            }

            if ($isForwardReopen) {
                $stmtForward = $pdo->prepare("UPDATE request_forwards SET status = 'accepted', received_by = ? WHERE id = ?");
                $stmtForward->execute([(int)$user_id, (int)$completedForward['id']]);
            }

            $historyMessage = $isForwardReopen
                ? 'Repasse reaberto para atendimento'
                : 'Requisição reaberta para Em Andamento';
            RequestManager::addHistory($pdo, $id, $sector, $user_id, $user_name, $historyMessage, $oldStatus, $newStatus);

            RequestManager::notifyStakeholders($pdo, $reqData, $sector,
                "Requisição Reaberta",
                "🔄 Requisição #$id ($sectorLabel) foi reaberta por $user_name e está em andamento.",
                $link, $user_id);

            $msg = "Requisição reaberta com sucesso.";
            break;

        case 'start_progress':
            if (!$isGestor) throw new Exception('Apenas gestores de setor podem iniciar o atendimento. O ADM apenas aprova a solicitação.');
            if (!isUserManagerOfSector($pdo, (int)$user_id, $sector)) throw new Exception('Você não gerencia este setor.');

            $reqData = getRequestData($pdo, $table, $id);
            if (!$reqData) throw new Exception("Requisição #$id não encontrada.");

            $currentStatus = trim(strtoupper($reqData['status'] ?? 'P'));
            if ($currentStatus === '') $currentStatus = 'P';

            // Aceita Y (Aprovado) ou A (Antigo sistema para aprovado)
            if ($currentStatus !== 'Y' && $currentStatus !== 'A') {
                throw new Exception("Apenas requisições já aprovadas podem ser iniciadas. Status atual: $currentStatus");
            }

            $stmt = $pdo->prepare("UPDATE `$table` SET status = 'W' WHERE id = ?");
            if (!$stmt->execute([$id])) {
                $err = $stmt->errorInfo();
                throw new Exception("Erro ao atualizar banco de dados: " . ($err[2] ?? 'Erro desconhecido'));
            }

            RequestManager::addHistory($pdo, $id, $sector, $user_id, $user_name, 'Atendimento iniciado', 'Aprovada', 'Em Andamento');

            RequestManager::notifyStakeholders($pdo, $reqData, $sector,
                "Atendimento Iniciado",
                "⚙️ Atendimento iniciado para a requisição #$id ($sectorLabel) por $user_name.",
                $link, $user_id
            );

            $msg = "Atendimento iniciado! A requisição agora está na aba 'Em Andamento'.";
            break;

        case 'finish':
            if (!$isGestor) throw new Exception('Apenas gestores de setor podem concluir requisições.');

            $reqData = getRequestData($pdo, $table, $id);
            if (!$reqData) throw new Exception("Requisição #$id não encontrada.");

            $currentStatus = trim(strtoupper($reqData['status'] ?? 'P'));
            if ($currentStatus === 'F') {
                $activeForward = getActiveForwardForUser($pdo, (int)$user_id, (int)$id, $sector, ['accepted']);
                if (!$activeForward) {
                    throw new Exception('Você não gerencia este setor.');
                }

                $result = RequestForwardService::completeForward($pdo, (int)$activeForward['id'], (int)$user_id, $user_name);
                if (empty($result['success'])) {
                    throw new Exception($result['message'] ?? 'Não foi possível concluir o repasse.');
                }

                $msg = $result['message'] ?? 'Requisição concluída com sucesso via repasse!';
                break;
            }

            if (!isUserManagerOfSector($pdo, (int)$user_id, $sector)) throw new Exception('Você não gerencia este setor.');

            if ($currentStatus !== 'W') {
                throw new Exception("Apenas requisições em andamento podem ser concluídas. Status atual: $currentStatus");
            }

            $pdo->beginTransaction();

            $stmt = $pdo->prepare("UPDATE `$table` SET status = 'C' WHERE id = ?");
            if (!$stmt->execute([$id])) {
                $pdo->rollBack();
                $err = $stmt->errorInfo();
                throw new Exception("Erro ao concluir requisição: " . ($err[2] ?? 'Erro desconhecido'));
            }

            // Fechar qualquer repasse ativo (pending ou accepted) vinculado a esta requisição
            // Isso evita que o forward fique "pendurado" após a conclusão
            $stmtCloseForwards = $pdo->prepare("
                UPDATE request_forwards
                SET status = 'completed', received_by = ?, updated_at = NOW()
                WHERE request_id = ? AND request_table = ?
                  AND status IN ('pending', 'accepted')
            ");
            $stmtCloseForwards->execute([$user_id, $id, $sector]);

            $pdo->commit();

            RequestManager::addHistory($pdo, $id, $sector, $user_id, $user_name, 'Requisição concluída', 'Em Andamento', 'Concluída');

            $reqTitle = $reqData['title'] ?? "Requisição #$id";
            RequestManager::notifyStakeholders($pdo, $reqData, $sector,
                "Requisição Concluída",
                "✅ Requisição #$id ($sectorLabel) — \"$reqTitle\" — foi concluída com sucesso por $user_name.",
                $link, $user_id
            );

            $msg = "Requisição concluída com sucesso!";
            break;

        case 'set_priority':
            if (!$canManage) throw new Exception('Sem permissão para alterar prioridade.');
            $priority = (int)($data['value'] ?? 2);
            if ($priority < 1 || $priority > 4) throw new Exception('Prioridade inválida.');

            $reqData = getRequestData($pdo, $table, $id);
            if (!$reqData) throw new Exception("Requisição não encontrada.");

            if (!userCanActOnRequest($pdo, $reqData, $sector, (int)$user_id, $role)) {
                throw new Exception('Você não tem permissão para gerenciar esta requisição.');
            }

            $oldPri = $reqData['priority'] ?? 2;

            $stmt = $pdo->prepare("UPDATE `$table` SET priority = ? WHERE id = ?");
            if (!$stmt->execute([$priority, $id])) {
                $err = $stmt->errorInfo();
                throw new Exception("Erro ao definir prioridade: " . ($err[2] ?? 'Erro desconhecido'));
            }

            $priLabels = [1 => 'Baixa', 2 => 'Média', 3 => 'Alta', 4 => 'Crítica'];
            $oldVal = $priLabels[$oldPri] ?? 'Média';
            $newVal = $priLabels[$priority];

            RequestManager::addHistory($pdo, $id, $sector, $user_id, $user_name, 'Prioridade alterada', $oldVal, $newVal);

            RequestManager::notifyStakeholders($pdo, $reqData, $sector,
                "Prioridade Alterada",
                "🔔 Prioridade da requisição #$id ($sectorLabel) alterada para $newVal por $user_name.",
                $link, $user_id
            );

            $msg = "Prioridade alterada para $newVal.";
            break;

        case 'delete':
            $reqData = getRequestData($pdo, $table, $id);
            if (!$reqData) throw new Exception("Requisição não encontrada.");

            $isOwner = ((int)($reqData['created_by'] ?? 0) === (int)$user_id);
            $currentStatus = trim(strtoupper($reqData['status'] ?? 'P'));
            if ($currentStatus === '') $currentStatus = 'P';

            // Regra: Admin pode tudo. Solicitante apenas se for dono e estiver PENDENTE.
            $canDelete = $isGlobalAdmin
                || ($role === 'adm_sub' && userCanManageOriginalRequest($pdo, $reqData, $sector, (int)$user_id, $role))
                || ($isOwner && ($currentStatus === 'P'));

            if (!$canDelete) {
                if ($isOwner) {
                    throw new Exception('Você só pode excluir requisições que ainda estão Pendentes. Caso já tenha sido aprovada, entre em contato com o setor.');
                } else {
                    throw new Exception('Sem permissão para excluir esta requisição.');
                }
            }

            $stmt = $pdo->prepare("DELETE FROM `$table` WHERE id = ?");
            if (!$stmt->execute([$id])) {
                throw new Exception("Erro ao excluir requisição no banco de dados.");
            }

            // Limpeza de órfãos
            $pdo->prepare("DELETE FROM request_comments WHERE request_id = ? AND request_table = ?")->execute([$id, $sector]);
            $pdo->prepare("DELETE FROM request_history WHERE request_id = ? AND request_table = ?")->execute([$id, $sector]);
            $pdo->prepare("DELETE FROM request_forwards WHERE request_id = ? AND request_table = ?")->execute([$id, $sector]);

            $msg = "Requisição excluída com sucesso.";
            break;

        default:
            throw new Exception('Ação solicitada não é válida ou não foi reconhecida.');
    }

    echo json_encode(['success' => true, 'message' => $msg]);

} catch (Exception $e) {
    error_log('request_actions.php: ' . $e->getMessage());
    $safeMessages = [
        'Apenas gestores de setor podem iniciar o atendimento. O ADM apenas aprova a solicitação.',
        'Apenas gestores de setor podem concluir requisições.',
        'Você não gerencia este setor.',
        'Você não tem permissão para gerenciar esta requisição.',
        'Sem permissão para alterar prioridade.',
        'Prioridade inválida.',
        'Sem permissão para excluir esta requisição.',
        'Você só pode excluir requisições que ainda estão Pendentes. Caso já tenha sido aprovada, entre em contato com o setor.',
    ];

    $message = in_array($e->getMessage(), $safeMessages, true)
        ? $e->getMessage()
        : 'Nao foi possivel concluir a acao.';

    echo json_encode(['success' => false, 'message' => $message]);
}
