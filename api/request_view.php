<?php
/**
 * api/request_view.php?id=&table=
 * Devolve o pedido completo (HTML), igual ao da página; usado para atualizar a tela sem recarregar.
 */
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/conn.php';
require_once __DIR__ . '/../config/security.php';
requireLoginJson($pdo);
session_write_close(); // só leitura: não segura a sessão das outras abas e requisições
require_once __DIR__ . '/../components/request_view.php';

header('Content-Type: text/html; charset=UTF-8');

$sector = normalizeRequestTable((string)($_GET['table'] ?? ''));
$id = (int)($_GET['id'] ?? 0);
$req = null;
if ($sector && $id > 0) {
    $stmt = $pdo->prepare("SELECT r.*, u.name AS creator_name, sub.name AS subdivision_name FROM `ctd_{$sector}_frm` r LEFT JOIN ctd_users u ON u.id = r.created_by LEFT JOIN ctd_subdivision sub ON sub.id = r.subdivision_id WHERE r.id = ?");
    $stmt->execute([$id]);
    $req = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

if (!$req || !userCanViewRequest($pdo, $req, $sector, (int)$_SESSION['id'], $_SESSION['role'] ?? 'solicitante')) {
    http_response_code($req ? 403 : 404);
    echo '<div class="empty-state"><i class="fa-solid fa-circle-question" aria-hidden="true"></i><p>' . ($req ? 'Você não tem acesso a este pedido.' : 'Pedido não encontrado. Ele pode ter sido cancelado.') . '</p></div>';
    exit;
}

markRequestNotificationsRead($pdo, (int)$_SESSION['id'], $sector, $id);
renderRequestView($pdo, $req, $sector, 'page');
