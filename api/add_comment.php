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

// Canais: public (quem pediu × responsável), internal (responsável × quem ajuda) e
// helper (quem pediu × um setor que ajuda, em helper_area_id). O servidor decide o que cada um pode usar:
// quem pediu: public ou helper; quem só ajuda: internal ou helper do próprio setor; responsável: public ou internal
require_once __DIR__ . '/../classes/RequestForwardService.php';
$wanted = (string)($_POST['channel'] ?? 'public');
$helperArea = null;
if ($wanted === 'helper') {
    $allowed = requestOpenHelperAreaIds(RequestForwardService::forRequest($pdo, $reqId, $sector)); // devolvida = só consulta
    if ($role !== 'solicitante') {
        $allowed = userSeesOnlyInternalChat($pdo, $req, $sector, $userId, $role)
            ? array_values(array_intersect($allowed, RequestForwardService::userAreaIds($pdo, $userId)))
            : []; // o responsável só lê esta conversa
    }
    $helperArea = (int)($_POST['area'] ?? 0);
    if (!in_array($helperArea, $allowed, true)) {
        jsonResponse(['success' => false, 'message' => 'Você não pode escrever nesta conversa.'], 403);
    }
    $channel = 'helper';
} elseif ($role === 'solicitante') {
    $channel = 'public';
} elseif (userSeesOnlyInternalChat($pdo, $req, $sector, $userId, $role)) {
    $channel = 'internal';
} else {
    $channel = $wanted === 'internal' ? 'internal' : 'public';
}

$userName = $_SESSION['name'] ?? 'Usuário';
$pdo->prepare('INSERT INTO request_comments (request_id, request_table, user_id, user_name, comment, channel, helper_area_id) VALUES (?, ?, ?, ?, ?, ?, ?)')
    ->execute([$reqId, $sector, $userId, $userName, $text, $channel, $helperArea]);

$preview = mb_strlen($text) > 60 ? mb_substr($text, 0, 57) . '...' : $text;
$link = "request_detail?id=$reqId&table=$sector";
if ($channel === 'helper') {
    // Só os dois lados: quem pediu e o setor que ajuda
    try {
        $stmt = $pdo->prepare('SELECT DISTINCT u.id FROM cfg_user_area cua JOIN ctd_users u ON u.id = cua.id_user WHERE cua.id_area = ? AND u.status = 1');
        $stmt->execute([$helperArea]);
        $ids = array_unique(array_filter(array_map('intval', array_merge($stmt->fetchAll(PDO::FETCH_COLUMN), [$req['created_by'] ?? 0]))));
        RequestManager::notifyMany($pdo, $ids, "Nova mensagem no pedido nº $reqId", "$userName: $preview", $link, $userId);
    } catch (Throwable $e) {
        error_log("add_comment ajuda $sector/$reqId: " . $e->getMessage());
    }
} elseif ($channel === 'internal') {
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
