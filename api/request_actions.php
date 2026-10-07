<?php
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/conn.php';
require_once __DIR__ . '/../config/security.php';
requireLoginJson($pdo);
require_once __DIR__ . '/../classes/RequestManager.php';
require_once __DIR__ . '/../classes/RequestForwardService.php';

header('Content-Type: application/json');

if (!isset($_SESSION['id'])) {
    echo json_encode(['success' => false, 'message' => 'Sessão expirada. Por favor, faça login novamente.']);
    exit;
}

requirePostJson();

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

/**
 * Troca o status somente se ainda estiver no estado esperado.
 * Impede que dois cliques (ou duas pessoas) apliquem a mesma transição duas vezes.
 */
function changeStatus(PDO $pdo, string $table, int $id, string $from, string $to): void
{
    $stmt = $pdo->prepare("UPDATE `$table` SET status = ? WHERE id = ? AND status = ?");
    $stmt->execute([$to, $id, $from]);
    if ($stmt->rowCount() === 0) {
        throw new UserMessage('Esta requisição foi alterada por outra pessoa. Atualize a página e tente de novo.');
    }
}

try {
    $link = "request_detail?id=$id&table=$sector";

    switch ($action) {
        case 'reset_status':
            if (!$isAdmin && !$isGestor) throw new UserMessage('Apenas administradores e gestores podem reabrir uma requisição.');

            $reqData = getRequestData($pdo, $table, $id);
            if (!$reqData) throw new UserMessage("Requisição #$id não encontrada.");

            if (!userCanActOnRequest($pdo, $reqData, $sector, (int)$user_id, $role)) {
                throw new UserMessage('Você não tem permissão para gerenciar esta requisição.');
            }

            $oldStatus = $reqData['status'] ?? 'P';
            if ($oldStatus !== 'C') {
                throw new UserMessage('Só é possível reabrir requisições concluídas.');
            }
            $completedForward = isGestorRole($role)
                ? getActiveForwardForUser($pdo, (int)$user_id, (int)$id, $sector, ['completed'])
                : null;
            $managesOriginalRequest = userCanManageOriginalRequest($pdo, $reqData, $sector, (int)$user_id, $role);
            $isForwardReopen = !$managesOriginalRequest && $completedForward !== null;
            $newStatus = $isForwardReopen ? 'F' : 'W';

            $pdo->beginTransaction();
            changeStatus($pdo, $table, (int)$id, 'C', $newStatus);

            if ($isForwardReopen) {
                $stmtForward = $pdo->prepare("UPDATE request_forwards SET status = 'accepted', received_by = ? WHERE id = ?");
                $stmtForward->execute([(int)$user_id, (int)$completedForward['id']]);
            }

            $historyMessage = $isForwardReopen
                ? 'Repasse reaberto para atendimento'
                : 'Requisição reaberta para atendimento';
            RequestManager::addHistory($pdo, $id, $sector, $user_id, $user_name, $historyMessage, statusLabel($oldStatus), statusLabel($newStatus));
            $pdo->commit();

            RequestManager::notifyStakeholders($pdo, $reqData, $sector,
                "Requisição Reaberta",
                "🔄 Requisição #$id ($sectorLabel) foi reaberta por $user_name e está em andamento.",
                $link, $user_id);

            $msg = "Requisição reaberta com sucesso.";
            break;

        case 'start_progress':
            if (!$isGestor) throw new UserMessage('Apenas gestores de setor podem iniciar o atendimento. O ADM apenas aprova a solicitação.');
            if (!isUserManagerOfSector($pdo, (int)$user_id, $sector)) throw new UserMessage('Você não gerencia este setor.');

            $reqData = getRequestData($pdo, $table, $id);
            if (!$reqData) throw new UserMessage("Requisição #$id não encontrada.");

            $currentStatus = trim(strtoupper($reqData['status'] ?? 'P'));
            if ($currentStatus === '') $currentStatus = 'P';

            if ($currentStatus !== 'Y') {
                throw new UserMessage('Só é possível iniciar requisições aprovadas. Situação atual: ' . statusLabel($currentStatus) . '.');
            }

            $pdo->beginTransaction();
            changeStatus($pdo, $table, (int)$id, 'Y', 'W');
            RequestManager::addHistory($pdo, $id, $sector, $user_id, $user_name, 'Atendimento iniciado', statusLabel('Y'), statusLabel('W'));
            $pdo->commit();

            RequestManager::notifyStakeholders($pdo, $reqData, $sector,
                "Atendimento Iniciado",
                "⚙️ Atendimento iniciado para a requisição #$id ($sectorLabel) por $user_name.",
                $link, $user_id
            );

            $msg = "Atendimento iniciado! A requisição agora está na aba 'Em Andamento'.";
            break;

        case 'finish':
            if (!$isGestor) throw new UserMessage('Apenas gestores de setor podem concluir requisições.');

            $reqData = getRequestData($pdo, $table, $id);
            if (!$reqData) throw new UserMessage("Requisição #$id não encontrada.");

            $currentStatus = trim(strtoupper($reqData['status'] ?? 'P'));
            if ($currentStatus === 'F') {
                $activeForward = getActiveForwardForUser($pdo, (int)$user_id, (int)$id, $sector, ['accepted']);
                if (!$activeForward) {
                    throw new UserMessage('Você não gerencia este setor.');
                }

                $result = RequestForwardService::completeForward($pdo, (int)$activeForward['id'], (int)$user_id, $user_name);
                if (empty($result['success'])) {
                    throw new UserMessage($result['message'] ?? 'Não foi possível concluir o repasse.');
                }

                $msg = $result['message'] ?? 'Requisição concluída com sucesso via repasse!';
                break;
            }

            if (!isUserManagerOfSector($pdo, (int)$user_id, $sector)) throw new UserMessage('Você não gerencia este setor.');

            if ($currentStatus !== 'W') {
                throw new UserMessage('Só é possível concluir requisições em atendimento. Situação atual: ' . statusLabel($currentStatus) . '.');
            }

            $pdo->beginTransaction();
            changeStatus($pdo, $table, (int)$id, 'W', 'C');

            // Fechar qualquer repasse ativo (pending ou accepted) vinculado a esta requisição
            // Isso evita que o forward fique "pendurado" após a conclusão
            $stmtCloseForwards = $pdo->prepare("
                UPDATE request_forwards
                SET status = 'completed', received_by = ?, updated_at = NOW()
                WHERE request_id = ? AND request_table = ?
                  AND status IN ('pending', 'accepted')
            ");
            $stmtCloseForwards->execute([$user_id, $id, $sector]);

            RequestManager::addHistory($pdo, $id, $sector, $user_id, $user_name, 'Requisição concluída', statusLabel('W'), statusLabel('C'));
            $pdo->commit();

            $reqTitle = $reqData['title'] ?? "Requisição #$id";
            RequestManager::notifyStakeholders($pdo, $reqData, $sector,
                "Requisição Concluída",
                "✅ Requisição #$id ($sectorLabel) — \"$reqTitle\" — foi concluída com sucesso por $user_name.",
                $link, $user_id
            );

            $msg = "Requisição concluída com sucesso!";
            break;

        case 'set_priority':
            if (!$canManage) throw new UserMessage('Sem permissão para alterar prioridade.');
            $priority = (int)($data['value'] ?? 2);
            if ($priority < 1 || $priority > 4) throw new UserMessage('Prioridade inválida.');

            $reqData = getRequestData($pdo, $table, $id);
            if (!$reqData) throw new UserMessage("Requisição não encontrada.");

            if (!userCanActOnRequest($pdo, $reqData, $sector, (int)$user_id, $role)) {
                throw new UserMessage('Você não tem permissão para gerenciar esta requisição.');
            }

            $oldPri = $reqData['priority'] ?? 2;

            $oldVal = priorityLabel((int)$oldPri);
            $newVal = priorityLabel($priority);

            $pdo->beginTransaction();
            $stmt = $pdo->prepare("UPDATE `$table` SET priority = ?, urgent = ? WHERE id = ?");
            $stmt->execute([$priority, $priority >= 3 ? 1 : 0, $id]);
            RequestManager::addHistory($pdo, $id, $sector, $user_id, $user_name, 'Prioridade alterada', $oldVal, $newVal);
            $pdo->commit();

            RequestManager::notifyStakeholders($pdo, $reqData, $sector,
                "Prioridade Alterada",
                "🔔 Prioridade da requisição #$id ($sectorLabel) alterada para $newVal por $user_name.",
                $link, $user_id
            );

            $msg = "Prioridade alterada para $newVal.";
            break;

        case 'delete':
            $reqData = getRequestData($pdo, $table, $id);
            if (!$reqData) throw new UserMessage("Requisição não encontrada.");

            $isOwner = ((int)($reqData['created_by'] ?? 0) === (int)$user_id);
            $currentStatus = trim(strtoupper($reqData['status'] ?? 'P'));
            if ($currentStatus === '') $currentStatus = 'P';

            // Regra: Admin pode tudo. Solicitante apenas se for dono e estiver PENDENTE.
            $canDelete = $isGlobalAdmin
                || ($role === 'adm_sub' && userCanManageOriginalRequest($pdo, $reqData, $sector, (int)$user_id, $role))
                || ($isOwner && ($currentStatus === 'P'));

            if (!$canDelete) {
                if ($isOwner) {
                    throw new UserMessage('Você só pode excluir requisições que ainda estão Pendentes. Caso já tenha sido aprovada, entre em contato com o setor.');
                } else {
                    throw new UserMessage('Sem permissão para excluir esta requisição.');
                }
            }

            $pdo->beginTransaction();
            $pdo->prepare("DELETE FROM `$table` WHERE id = ?")->execute([$id]);

            // Limpeza de órfãos
            $pdo->prepare("DELETE FROM request_comments WHERE request_id = ? AND request_table = ?")->execute([$id, $sector]);
            $pdo->prepare("DELETE FROM request_history WHERE request_id = ? AND request_table = ?")->execute([$id, $sector]);
            $pdo->prepare("DELETE FROM request_forwards WHERE request_id = ? AND request_table = ?")->execute([$id, $sector]);
            $pdo->prepare("DELETE FROM notifications WHERE link = ?")->execute(["request_detail?id=$id&table=$sector"]);
            $pdo->commit();

            $msg = "Requisição excluída com sucesso.";
            break;

        default:
            throw new UserMessage('Ação solicitada não é válida ou não foi reconhecida.');
    }

    echo json_encode(['success' => true, 'message' => $msg]);

} catch (UserMessage $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('request_actions.php: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Não foi possível concluir a ação. Tente de novo.']);
}
