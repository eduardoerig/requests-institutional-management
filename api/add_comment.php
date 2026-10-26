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

// Canal: quem pediu só fala na conversa pública; setor chamado só para ajudar só fala na interna;
// os demais setores escolhem
if ($role === 'solicitante') {
    $channel = 'public';
} elseif (userSeesOnlyInternalChat($pdo, $req, $sector, $userId, $role)) {
    $channel = 'internal';
} else {
    $channel = ($_POST['channel'] ?? '') === 'internal' ? 'internal' : 'public';
}

$userName = $_SESSION['name'] ?? 'Usuário';
$pdo->prepare('INSERT INTO request_comments (request_id, request_table, user_id, user_name, comment, channel) VALUES (?, ?, ?, ?, ?, ?)')
    ->execute([$reqId, $sector, $userId, $userName, $text, $channel]);

$preview = mb_strlen($text) > 60 ? mb_substr($text, 0, 57) . '...' : $text;
$link = "request_detail?id=$reqId&table=$sector";
if ($channel === 'internal') {
    // Só os setores envolvidos: o responsável atual e quem foi chamado para ajudar (quem pediu nunca)
    try {
        require_once __DIR__ . '/../classes/RequestForwardService.php';
        $areas = [RequestForwardService::ownerAreaId($pdo, $reqId, $sector)];
        foreach (RequestForwardService::forRequest($pdo, $reqId, $sector) as $f) {
            if ($f['mode'] === 'help' && !in_array($f['status'], ['refused', 'cancelled'], true)) {
                $areas[] = (int)$f['to_area_id'];
                $areas[] = (int)$f['from_area_id'];
            }
        }
        $areas = array_values(array_unique(array_filter(array_map('intval', $areas))));
        if ($areas) {
            $in = implode(',', array_fill(0, count($areas), '?'));
            $stmt = $pdo->prepare("SELECT DISTINCT u.id FROM cfg_user_area cua JOIN ctd_users u ON u.id = cua.id_user WHERE cua.id_area IN ($in) AND u.status = 1 AND u.id <> ?");
            $stmt->execute(array_merge($areas, [(int)($req['created_by'] ?? 0)]));
            RequestManager::notifyMany($pdo, $stmt->fetchAll(PDO::FETCH_COLUMN), "Mensagem entre setores no pedido nº $reqId", "$userName: $preview", $link, $userId);
        }
    } catch (Throwable $e) {
        error_log("add_comment interno $sector/$reqId: " . $e->getMessage());
    }
} else {
    RequestManager::notifyStakeholders($pdo, $req, $sector, "Nova mensagem no pedido nº $reqId", "$userName: $preview", $link, $userId, false);
}

jsonResponse(['success' => true]);
