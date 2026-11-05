<?php
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/conn.php';
require_once __DIR__ . '/../config/security.php';
requireLoginJson($pdo);
require_once __DIR__ . '/../classes/RequestManager.php';
require_once __DIR__ . '/../classes/RequestForwardService.php';

// Aprovação: administradores globais e administradores de subdivisão (na própria subdivisão)
$role = $_SESSION['role'] ?? 'solicitante';
if (!isAdminRole($role)) {
    jsonResponse(['success' => false, 'message' => 'Você não tem permissão para aprovar pedidos.'], 403);
}

if (($_POST['action'] ?? '') !== 'post') {
    jsonResponse(['success' => false, 'message' => 'Pedido inválido.']);
}

requirePostJson();

$id = (int)($_POST['id'] ?? 0);
$table = normalizeRequestTable((string)($_POST['table'] ?? ''));
$value = (string)($_POST['value'] ?? '');
$reason = trim((string)($_POST['reason'] ?? ''));

if ($id <= 0 || $table === null || !in_array($value, ['Y', 'N'], true)) {
    jsonResponse(['success' => false, 'message' => 'Dados do pedido inválidos.']);
}
if ($value === 'N' && $reason === '') {
    jsonResponse(['success' => false, 'message' => 'Escreva o motivo da recusa para quem pediu entender.']);
}
$reason = mb_substr($reason, 0, 1000);

$fullTable = requestTableName($table);
$response = $value === 'Y' ? 'Aprovada' : 'Recusada';
$done = $value === 'Y' ? 'aprovado' : 'recusado'; // "o pedido foi …" (o $response acima vai para o histórico)
$user_id = (int)$_SESSION['id'];
$user_name = $_SESSION['name'] ?? 'Usuário';

$sectorLabel = sectorLabel($table);

$reqData = getRequestRow($pdo, $table, $id);
if (!$reqData) {
    jsonResponse(['success' => false, 'message' => 'Pedido não encontrado.']);
}

if (!userCanManageOriginalRequest($pdo, $reqData, $table, $user_id, $role)) {
    jsonResponse(['success' => false, 'message' => 'Você não tem permissão para aprovar pedidos desta subdivisão.'], 403);
}

// Ninguém decide o próprio pedido: quem aprova precisa ser outra pessoa
if ((int)($reqData['created_by'] ?? 0) === (int)$user_id) {
    jsonResponse(['success' => false, 'message' => 'Você não pode aprovar nem recusar um pedido feito por você. Outra pessoa da coordenação ou da administração precisa decidir.'], 403);
}

// Setor parceiro: a coordenação confirma, troca ou tira a sugestão de quem pediu
$partners = [];
if ($value === 'Y') {
    try {
        $partners = RequestForwardService::resolvePartnerChoices($pdo, $table, $reqData, array_intersect_key($_POST, array_flip(['partner_area_id', 'partner_due', 'partner_note', 'partner2_area_id', 'partner2_due', 'partner2_note'])));
    } catch (UserMessage $e) {
        jsonResponse(['success' => false, 'message' => $e->getMessage()]);
    }
}

try {
    $pdo->beginTransaction();
    // Guarda de estado: só sai de "Pendente" uma vez (evita duplo clique / dois aprovadores)
    $stmt = $pdo->prepare("UPDATE `$fullTable` SET `status` = ? WHERE id = ? AND `status` = 'P'");
    $stmt->execute([$value, $id]);
    if ($stmt->rowCount() === 0) {
        $pdo->rollBack();
        jsonResponse(['success' => false, 'message' => 'Este pedido já foi analisado por outra pessoa. Atualize a página.']);
    }

    RequestManager::addHistory(
        $pdo, $id, $table, $user_id, $user_name,
        'Requisição ' . $response,
        'P',
        $value,
        $value === 'Y' ? 'approved' : 'refused'
    );

    // Motivo fica registrado no chat da requisição, visível ao solicitante
    if ($reason !== '') {
        $stmtComment = $pdo->prepare("INSERT INTO request_comments (request_id, request_table, user_id, user_name, comment) VALUES (?, ?, ?, ?, ?)");
        $stmtComment->execute([$id, $table, $user_id, $user_name, ($value === 'N' ? 'Motivo da recusa: ' : 'Observação da aprovação: ') . $reason]);
    }
    foreach ($partners as $partner) {
        RequestForwardService::insertPartner($pdo, $table, $id, $partner, 'Y', $user_id, $user_name);
    }
    RequestForwardService::clearSuggestion($pdo, $table, $id);
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('approve_request.php: ' . $e->getMessage());
    jsonResponse(['success' => false, 'message' => 'Não foi possível salvar. Tente novamente.'], 500);
}

$reqTitle = $reqData['title'] ?? "Requisição #$id";
$createdBy = $reqData['created_by'] ?? 0;
$link = "request_detail?id=$id&table=$table";

// A decisão já foi gravada: falha ao buscar destinatários vai para o log e não vira erro na tela
$safeRecipients = function (callable $fetch) use ($id, $table): array {
    try {
        return $fetch();
    } catch (Throwable $e) {
        error_log("approve_request.php: destinatários dos avisos de $table/$id: " . $e->getMessage());
        return [];
    }
};

// ---- Notificar o Solicitante ----
if ($createdBy) {
    RequestManager::notify(
        $pdo, $createdBy,
        $value === 'Y' ? "Pedido nº $id aprovado" : "Pedido nº $id recusado",
        "Seu pedido \"$reqTitle\" ($sectorLabel) foi " . $done . " por $user_name." . ($reason !== '' ? " Motivo: $reason" : ''),
        $link
    );
}

// ---- Notificar Gestores do Setor (quando aprovada) ----
if ($value === 'Y') {
    $managers = $safeRecipients(fn() => RequestManager::getManagersForSector($pdo, $table));
    foreach ($managers as $manager) {
        if ($manager['id'] == $user_id) continue; // Não notificar quem aprovou
        if ($manager['id'] == $createdBy) continue; // Já notificado acima
        RequestManager::notify(
            $pdo, $manager['id'],
            "Pedido novo para atender",
            "O pedido nº $id, \"$reqTitle\", foi aprovado e está na Caixa do setor.",
            $link
        );
    }
}

// ---- Notificar Admins (quando aprovada/recusada por outro admin) ----
$admins = $safeRecipients(fn() => RequestManager::getAdmins($pdo));
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
            "Pedido nº $id " . $done,
            "O pedido nº $id ($sectorLabel) foi " . $done . " por $user_name.",
            $link
        );
    }
}

$partnerLabels = [];
foreach ($partners as $partner) {
    RequestForwardService::notifyPartnerAdded($pdo, $reqData, $table, $partner, $user_id);
    $partnerLabels[] = RequestForwardService::areaLabel($pdo, $partner['area']);
}
$partnerText = match (count($partnerLabels)) {
    0 => '',
    1 => ' ' . $partnerLabels[0] . ' foi chamado como setor parceiro.',
    default => ' ' . implode(' e ', $partnerLabels) . ' foram chamados como setores parceiros.',
};

jsonResponse(['success' => true, 'message' => 'Pedido ' . $done . ' com sucesso.' . $partnerText]);
