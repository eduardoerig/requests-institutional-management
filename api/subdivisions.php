<?php
/**
 * Áreas (ctd_subdivision): as divisões de quem pede, cada uma com a sua coordenação. Só o administrador geral.
 * POST action=create, name | rename, id, name | set_status, id, active (0/1) | delete, id
 *
 * Área com pessoas não sai de uso: primeiro tira-se as pessoas. Área sem pessoas e com pedidos só desativa,
 * porque os pedidos guardam o id e mostram o nome dela. Área sem pessoas e sem pedidos pode ser excluída.
 */
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/conn.php';
require_once __DIR__ . '/../config/security.php';
requireLoginJson($pdo);
requirePostJson();

if (!isGlobalAdminRole($_SESSION['role'] ?? '')) {
    jsonResponse(['success' => false, 'message' => 'Só o administrador muda as áreas.'], 403);
}

/** Nome limpo e válido; recusa o que já existe (a comparação ignora maiúsculas e acentos) */
function validAreaName(PDO $pdo, int $exceptId = 0): string
{
    $name = trim(preg_replace('/\s+/u', ' ', (string)($_POST['name'] ?? '')));
    $length = mb_strlen($name);
    if ($length < 2 || $length > 100) {
        jsonResponse(['success' => false, 'message' => 'O nome da área precisa ter entre 2 e 100 letras.'], 422);
    }
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM ctd_subdivision WHERE name = ? AND id <> ?');
    $stmt->execute([$name, $exceptId]);
    if ((int)$stmt->fetchColumn() > 0) {
        jsonResponse(['success' => false, 'message' => 'Já existe uma área com esse nome.'], 422);
    }
    return $name;
}

/** Código interno da área, único e sem acentos: "Ensino Médio" => ensino_medio */
function newAreaSlug(PDO $pdo, string $name): string
{
    $plain = strtr(mb_strtolower($name), [
        'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'é' => 'e', 'ê' => 'e', 'ë' => 'e',
        'í' => 'i', 'î' => 'i', 'ï' => 'i', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o',
        'ú' => 'u', 'û' => 'u', 'ü' => 'u', 'ç' => 'c', 'ñ' => 'n',
    ]);
    $base = substr(trim(preg_replace('/[^a-z0-9]+/', '_', $plain), '_'), 0, 40) ?: 'area';
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM ctd_subdivision WHERE slug = ?');
    $slug = $base;
    for ($n = 2; ; $n++) {
        $stmt->execute([$slug]);
        if ((int)$stmt->fetchColumn() === 0) {
            return $slug;
        }
        $slug = $base . '_' . $n;
    }
}

/** A área, ou encerra com 404 */
function areaOr404(PDO $pdo): array
{
    $stmt = $pdo->prepare('SELECT id, name, status FROM ctd_subdivision WHERE id = ?');
    $stmt->execute([(int)($_POST['id'] ?? 0)]);
    $area = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$area) {
        jsonResponse(['success' => false, 'message' => 'Área não encontrada.'], 404);
    }
    return $area;
}

/** Pessoas ligadas à área, inclusive contas desativadas e a coluna antiga ctd_users.subdivision_id */
function areaPeopleCount(PDO $pdo, int $areaId): int
{
    $stmt = $pdo->prepare('
        SELECT COUNT(*) FROM ctd_users u
        WHERE u.subdivision_id = ?
           OR EXISTS (SELECT 1 FROM cfg_user_subdivision cus WHERE cus.id_user = u.id AND cus.id_subdivision = ?)
    ');
    $stmt->execute([$areaId, $areaId]);
    return (int)$stmt->fetchColumn();
}

function areaRequestCount(PDO $pdo, int $areaId): int
{
    $parts = array_map(fn($table) => "SELECT COUNT(*) AS total FROM `ctd_{$table}_frm` WHERE subdivision_id = ?", allowedRequestTables());
    $stmt = $pdo->prepare('SELECT SUM(total) FROM (' . implode(' UNION ALL ', $parts) . ') counts');
    $stmt->execute(array_fill(0, count($parts), $areaId));
    return (int)$stmt->fetchColumn();
}

/** Encerra se a área tem pessoas, dizendo o que fazer primeiro */
function refuseIfAreaHasPeople(PDO $pdo, array $area, string $verb): void
{
    $people = areaPeopleCount($pdo, (int)$area['id']);
    if ($people > 0) {
        $who = $people === 1 ? '1 pessoa' : "$people pessoas";
        jsonResponse(['success' => false, 'message' => "\"{$area['name']}\" ainda tem $who. Mude a área delas em Pessoas antes de $verb."], 422);
    }
}

/** Encerra se esta for a última área ativa: sem nenhuma, ninguém mais consegue ser cadastrado */
function refuseIfLastActiveArea(PDO $pdo, array $area): void
{
    if ((int)$area['status'] !== 1) {
        return;
    }
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM ctd_subdivision WHERE status = 1 AND id <> ?');
    $stmt->execute([(int)$area['id']]);
    if ((int)$stmt->fetchColumn() === 0) {
        jsonResponse(['success' => false, 'message' => 'Esta é a última área ativa. Crie outra antes.'], 422);
    }
}

$action = (string)($_POST['action'] ?? '');

try {
    if ($action === 'create') {
        $name = validAreaName($pdo);
        $pdo->prepare('INSERT INTO ctd_subdivision (name, slug) VALUES (?, ?)')->execute([$name, newAreaSlug($pdo, $name)]);
        jsonResponse(['success' => true, 'message' => 'Área criada.', 'id' => (int)$pdo->lastInsertId()]);
    }

    if ($action === 'rename') {
        $area = areaOr404($pdo);
        $name = validAreaName($pdo, (int)$area['id']);
        $pdo->prepare('UPDATE ctd_subdivision SET name = ? WHERE id = ?')->execute([$name, (int)$area['id']]);
        jsonResponse(['success' => true, 'message' => 'Nome salvo.']);
    }

    if ($action === 'set_status') {
        $area = areaOr404($pdo);
        $active = (int)($_POST['active'] ?? 0) === 1;
        if (!$active) {
            refuseIfAreaHasPeople($pdo, $area, 'desativar');
            refuseIfLastActiveArea($pdo, $area);
        }
        $pdo->prepare('UPDATE ctd_subdivision SET status = ? WHERE id = ?')->execute([$active ? 1 : 0, (int)$area['id']]);
        jsonResponse(['success' => true, 'message' => $active ? 'Área reativada.' : 'Área desativada.']);
    }

    if ($action === 'delete') {
        $area = areaOr404($pdo);
        refuseIfAreaHasPeople($pdo, $area, 'excluir');
        if (areaRequestCount($pdo, (int)$area['id']) > 0) {
            jsonResponse(['success' => false, 'message' => "\"{$area['name']}\" tem pedidos no histórico e não pode ser excluída. Desative a área."], 422);
        }
        refuseIfLastActiveArea($pdo, $area);
        $pdo->prepare('DELETE FROM ctd_subdivision WHERE id = ?')->execute([(int)$area['id']]);
        jsonResponse(['success' => true, 'message' => 'Área excluída.']);
    }

    jsonResponse(['success' => false, 'message' => 'Ação inválida.'], 400);
} catch (PDOException $e) {
    error_log('subdivisions.php: ' . $e->getMessage());
    if ($e->getCode() === '23000') {
        jsonResponse(['success' => false, 'message' => 'Já existe uma área com esse nome.'], 422);
    }
    jsonResponse(['success' => false, 'message' => 'Não foi possível salvar a área.'], 500);
}
