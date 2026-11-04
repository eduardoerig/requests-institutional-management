<?php
/**
 * Muda a descrição de um setor (frase que aparece no Encaminhar). Só o administrador geral.
 * POST id, description (até 160; vazio apaga)
 */
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/conn.php';
require_once __DIR__ . '/../config/security.php';
requireLoginJson($pdo);

header('Content-Type: application/json; charset=UTF-8');
requirePostJson();

if (!isGlobalAdminRole($_SESSION['role'] ?? '')) {
    jsonResponse(['success' => false, 'message' => 'Só o administrador muda os setores.'], 403);
}

$sectorId = (int)($_POST['id'] ?? 0);
$description = trim(preg_replace('/\s+/u', ' ', (string)($_POST['description'] ?? '')));

if ($sectorId <= 0 || !isset(areaTitles($pdo)[$sectorId])) {
    jsonResponse(['success' => false, 'message' => 'Setor não encontrado.'], 404);
}
if (mb_strlen($description) > 160) {
    jsonResponse(['success' => false, 'message' => 'A descrição pode ter até 160 letras.'], 422);
}

try {
    $pdo->prepare('UPDATE ctd_area SET description = ? WHERE id = ?')
        ->execute([$description !== '' ? $description : null, $sectorId]);
    jsonResponse(['success' => true, 'message' => 'Descrição salva.']);
} catch (PDOException $e) {
    error_log('update_sector.php: ' . $e->getMessage());
    jsonResponse(['success' => false, 'message' => 'Não foi possível salvar a descrição.'], 500);
}
