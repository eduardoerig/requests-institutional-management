<?php
require_once __DIR__ . '/session.php';
require_once __DIR__ . '/conn.php';
require_once __DIR__ . '/security.php';
requireLoginJson($pdo);
require_once __DIR__ . '/../classes/RequestManager.php';

// Aprovação: administradores globais e administradores de subdivisão (na própria subdivisão)
$role = $_SESSION['role'] ?? 'solicitante';
if (!isAdminRole($role)) {
    jsonResponse(['success' => false, 'message' => 'Você não tem permissão para aprovar requisições.'], 403);
}

if (($_POST['action'] ?? '') !== 'post') {
    jsonResponse(['success' => false, 'message' => 'Requisição inválida.']);
}

requirePostJson();

$id = (int)($_POST['id'] ?? 0);
$table = normalizeRequestTable((string)($_POST['table'] ?? ''));
$value = (string)($_POST['value'] ?? '');
$reason = trim((string)($_POST['reason'] ?? ''));

if ($id <= 0 || $table === null || !in_array($value, ['Y', 'N'], true)) {
    jsonResponse(['success' => false, 'message' => 'Dados da requisição inválidos.']);
}
if ($value === 'N' && $reason === '') {
    jsonResponse(['success' => false, 'message' => 'Escreva o motivo da recusa para o solicitante entender.']);
}
$reason = mb_substr($reason, 0, 1000);

$fullTable = 'ctd_' . $table . '_frm';
$response = $value === 'Y' ? 'Aprovada' : 'Recusada';
$user_id = (int)$_SESSION['id'];
$user_name = $_SESSION['name'] ?? 'Usuário';

$sectorLabels = [
    'mkt' => 'Marketing',
    'xerox' => 'Reprografia',
    'shop' => 'Compras',
    'service' => 'Manutenção',
    'ti' => 'TI',
];
$sectorLabel = $sectorLabels[$table] ?? strtoupper($table);

$reqData = getRequestRow($pdo, $table, $id);
if (!$reqData) {
    jsonResponse(['success' => false, 'message' => 'Requisição não encontrada.']);
}

if (!userCanManageOriginalRequest($pdo, $reqData, $table, $user_id, $role)) {
    jsonResponse(['success' => false, 'message' => 'Você não tem permissão para aprovar requisições desta subdivisão.'], 403);
}

try {
    $pdo->beginTransaction();
    // Guarda de estado: só sai de "Pendente" uma vez (evita duplo clique / dois aprovadores)
    $stmt = $pdo->prepare("UPDATE `$fullTable` SET `status` = ? WHERE id = ? AND `status` = 'P'");
    $stmt->execute([$value, $id]);
    if ($stmt->rowCount() === 0) {
        $pdo->rollBack();
        jsonResponse(['success' => false, 'message' => 'Esta requisição já foi analisada por outra pessoa. Atualize a página.']);
    }

    RequestManager::addHistory(
        $pdo, $id, $table, $user_id, $user_name,
        'Requisição ' . $response,
        'Pendente',
        $response
    );

    // Motivo fica registrado no chat da requisição, visível ao solicitante
    if ($reason !== '') {
        $stmtComment = $pdo->prepare("INSERT INTO request_comments (request_id, request_table, user_id, user_name, comment) VALUES (?, ?, ?, ?, ?)");
        $stmtComment->execute([$id, $table, $user_id, $user_name, ($value === 'N' ? 'Motivo da recusa: ' : 'Observação da aprovação: ') . $reason]);
    }
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('aprovRequest.php: ' . $e->getMessage());
    jsonResponse(['success' => false, 'message' => 'Não foi possível salvar. Tente novamente.'], 500);
}

$reqTitle = $reqData['title'] ?? "Requisição #$id";
$createdBy = $reqData['created_by'] ?? 0;
$link = "request_detail?id=$id&table=$table";

// ---- Notificar o Solicitante ----
if ($createdBy) {
    $icon = $value === 'Y' ? '✅' : '❌';
    RequestManager::notify(
        $pdo, $createdBy,
        "Requisição $response",
        "$icon Sua requisição #$id ($sectorLabel) — \"$reqTitle\" — foi " . mb_strtolower($response) . " por $user_name." . ($reason !== '' ? " Motivo: $reason" : ''),
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

jsonResponse(['success' => true, 'message' => 'Requisição ' . mb_strtolower($response) . ' com sucesso.']);
