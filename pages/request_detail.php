<?php
/**
 * pages/request_detail.php — página do pedido (usa o mesmo componente do painel das filas).
 */
require_once __DIR__ . '/../components/request_view.php';

$req_id = (int)($_GET['id'] ?? 0);
$req_table = normalizeRequestTable((string)($_GET['table'] ?? ''));
$req = null;
if ($req_id > 0 && $req_table) {
    $stmt = $pdo->prepare("SELECT r.*, u.name AS creator_name FROM `ctd_{$req_table}_frm` r LEFT JOIN ctd_users u ON u.id = r.created_by WHERE r.id = ?");
    $stmt->execute([$req_id]);
    $req = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

$role = $_SESSION['role'] ?? 'solicitante';
if (!$req) {
    echo '<div class="panel"><div class="empty-big"><i class="fa-solid fa-circle-question"></i><h2>Pedido não encontrado</h2><p>Ele pode ter sido cancelado.</p><a class="btn" href="home"><i class="fa-solid fa-arrow-left"></i> Voltar ao início</a></div></div>';
    return;
}
if (!userCanViewRequest($pdo, $req, $req_table, (int)$_SESSION['id'], $role)) {
    echo '<div class="panel"><div class="empty-big"><i class="fa-solid fa-lock"></i><h2>Sem acesso</h2><p>Você não tem permissão para ver este pedido.</p><a class="btn" href="home"><i class="fa-solid fa-arrow-left"></i> Voltar ao início</a></div></div>';
    return;
}

$backHref = $role === 'solicitante' ? 'myRequests' : (isGestorRole($role) ? 'inbox' : 'home');
?>
<div class="detail-page">
    <a href="<?= e($backHref) ?>" class="back-link"><i class="fa-solid fa-arrow-left" aria-hidden="true"></i> Voltar</a>
    <?php renderRequestView($pdo, $req, $req_table, 'page'); ?>
</div>
