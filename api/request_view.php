<?php
/**
 * api/request_view.php?id=&table=
 * Devolve o pedido completo (HTML) para o painel das filas — mesma visão da página do pedido.
 */
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/conn.php';
require_once __DIR__ . '/../config/security.php';
requireLoginJson($pdo);
require_once __DIR__ . '/../components/request_view.php';

header('Content-Type: text/html; charset=UTF-8');

$sector = normalizeRequestTable((string)($_GET['table'] ?? ''));
$id = (int)($_GET['id'] ?? 0);
$req = null;
if ($sector && $id > 0) {
    $stmt = $pdo->prepare("SELECT r.*, u.name AS creator_name FROM `ctd_{$sector}_frm` r LEFT JOIN ctd_users u ON u.id = r.created_by WHERE r.id = ?");
    $stmt->execute([$id]);
    $req = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

if (!$req || !userCanViewRequest($pdo, $req, $sector, (int)$_SESSION['id'], $_SESSION['role'] ?? 'solicitante')) {
    http_response_code($req ? 403 : 404);
    echo '<div class="empty-state"><i class="fa-solid fa-circle-question" aria-hidden="true"></i><p>' . ($req ? 'Você não tem acesso a este pedido.' : 'Pedido não encontrado. Ele pode ter sido cancelado.') . '</p></div>';
    exit;
}

renderRequestView($pdo, $req, $sector, 'panel');
