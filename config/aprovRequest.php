<?php
session_start();
include 'conn.php';
require_once __DIR__ . '/../classes/RequestManager.php';

if (!isset($_SESSION['id'])) {
    echo json_encode(['success' => false, 'message' => 'Sessão expirada.']);
    exit();
}

// Apenas admin/adm/coord/adm_sub podem aprovar
$role = $_SESSION['role'] ?? 'solicitante';
$approverRoles = ['admin', 'adm', 'coord', 'adm_sub'];
if (!in_array($role, $approverRoles)) {
    echo json_encode(['success' => false, 'message' => 'Sem permissão para aprovar requisições.']);
    exit();
}

if (isset($_POST['action']) && $_POST['action'] === 'post') {
    $id = $_POST['id'];
    $table = $_POST['table'];
    $value = $_POST['value'];
    $fullTable = 'ctd_' . $table . '_frm';
    $response = $value === 'Y' ? 'Aprovada' : 'Rejeitada';

    $user_id = $_SESSION['id'];
    $user_name = $_SESSION['name'] ?? 'Usuário';

    $sectorLabels = [
        'mkt' => 'Marketing',
        'xerox' => 'Xerox',
        'shop' => 'Compras',
        'service' => 'Manutenção',
        'ti' => 'TI',
    ];
    $sectorLabel = $sectorLabels[$table] ?? strtoupper($table);

    if ($id && $table) {
        // Buscar dados da requisição ANTES de atualizar
        $reqQuery = $pdo->prepare("SELECT * FROM $fullTable WHERE id = ?");
        $reqQuery->execute([$id]);
        $reqData = $reqQuery->fetch(PDO::FETCH_ASSOC);

        if (!$reqData) {
            echo json_encode(['success' => false, 'message' => 'Requisição não encontrada.']);
            exit();
        }

        // Verificação de subdivisão para adm_sub
        if ($role === 'adm_sub') {
            $userSubs = $_SESSION['subdivision_ids'] ?? [];
            $reqSubId = $reqData['subdivision_id'] ?? null;
            if (!in_array($reqSubId, $userSubs)) {
                echo json_encode(['success' => false, 'message' => 'Você não tem permissão para aprovar requisições desta subdivisão.']);
                exit();
            }
        }

        $stmt = $pdo->prepare("UPDATE $fullTable SET `status` = ? WHERE id = ?");
        if ($stmt->execute([$value, $id])) {

            // ---- Registrar no Histórico ----
            RequestManager::addHistory(
                $pdo, $id, $table, $user_id, $user_name,
                'Requisição ' . $response,
                'Pendente',
                $response
            );

            $reqTitle = $reqData['title'] ?? "Requisição #$id";
            $createdBy = $reqData['created_by'] ?? 0;
            $link = "request_detail?id=$id&table=$table";

            // ---- Notificar o Solicitante ----
            if ($createdBy) {
                $icon = $value === 'Y' ? '✅' : '❌';
                RequestManager::notify(
                    $pdo, $createdBy,
                    "Requisição $response",
                    "$icon Sua requisição #$id ($sectorLabel) — \"$reqTitle\" — foi $response por $user_name.",
                    $link
                );
            }

            // ---- Notificar Gestores do Setor (quando aprovada) ----
            if ($value === 'Y') {
                $managers = RequestManager::getManagersForSector($pdo, $table);
                foreach ($managers as $manager) {
                    if ($manager['id'] == $user_id) continue; // Não notificar quem aprovou
                    if ($manager['id'] == $createdBy) continue; // Já notificado acima
                    RequestManager::notify(
                        $pdo, $manager['id'],
                        "Nova Requisição Aprovada",
                        "📋 Requisição #$id ($sectorLabel) — \"$reqTitle\" — foi aprovada e aguarda atendimento.",
                        $link
                    );
                }
            }

            // ---- Notificar Admins (quando aprovada/recusada por outro admin) ----
            $admins = RequestManager::getAdmins($pdo);
            foreach ($admins as $admin) {
                if ($admin['id'] == $user_id) continue;
                if ($admin['id'] == $createdBy) continue;
                $alreadyNotified = false;
                if ($value === 'Y' && isset($managers)) {
                    foreach ($managers as $m) {
                        if ($m['id'] == $admin['id']) { $alreadyNotified = true; break; }
                    }
                }
                if (!$alreadyNotified) {
                    RequestManager::notify(
                        $pdo, $admin['id'],
                        "Requisição $response",
                        "📝 Requisição #$id ($sectorLabel) foi $response por $user_name.",
                        $link
                    );
                }
            }

            echo json_encode(['success' => true, 'message' => 'Requisição '.$response.'!']);
            exit();
        } else {
            echo json_encode(['success' => false, 'message' => 'Erro ao atualizar requisição.']);
            exit();
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'Informações inválidas!']);
        exit();
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Requisição inválida!']);
    exit();
}
