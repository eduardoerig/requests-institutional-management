<?php
/**
 * Abre ou baixa um anexo do pedido (GET ?id=N). Só para quem pode ver o pedido
 * (userCanViewRequest, a mesma regra da página do pedido). PDF e fotos abrem no navegador;
 * documentos do Office são baixados.
 */
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/conn.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../classes/RequestAttachments.php';
// Aberto por link numa aba nova: sem sessão, vai para o login em vez de mostrar JSON cru
if (empty($_SESSION['id']) || !refreshSessionUser($pdo)) {
    header('Location: ../login');
    exit;
}
requireLoginJson($pdo);

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    exit('Método não permitido.');
}

$notFound = function (): void {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Anexo não encontrado.');
};

$att = RequestAttachments::find($pdo, (int)($_GET['id'] ?? 0));
$sector = $att ? (string)$att['request_table'] : '';
if (!$att || !in_array($sector, allowedRequestTables(), true)) {
    $notFound();
}

$st = $pdo->prepare('SELECT r.* FROM `' . requestTableName($sector) . '` r WHERE r.id = ?');
$st->execute([(int)$att['request_id']]);
$req = $st->fetch(PDO::FETCH_ASSOC);
if (!$req) {
    $notFound();
}
if (!userCanViewRequest($pdo, $req, $sector, (int)$_SESSION['id'], (string)($_SESSION['role'] ?? 'solicitante'))) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Você não tem permissão para ver este anexo.');
}
if (!is_file($att['path'])) {
    error_log('attachment.php: arquivo do anexo ' . (int)$att['id'] . ' sumiu: ' . $att['path']);
    $notFound();
}

// Nome para o navegador: ASCII simples + filename* com o nome original (acentos)
$name = (string)$att['original_name'];
$ascii = preg_replace('/[^A-Za-z0-9._ -]+/', '_', $name) ?: 'anexo';
$disposition = RequestAttachments::opensInline((string)$att['stored_name']) ? 'inline' : 'attachment';

session_write_close(); // arquivo grande não prende as outras abas da pessoa
header('Content-Type: ' . $att['mime']);
header('Content-Length: ' . filesize($att['path']));
header("Content-Disposition: $disposition; filename=\"" . str_replace('"', '', $ascii) . "\"; filename*=UTF-8''" . rawurlencode($name));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
readfile($att['path']);
