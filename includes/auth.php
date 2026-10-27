<?php
/**
 * Perfis, setores e regras de quem pode ver e agir sobre cada pedido.
 * Carregado por config/security.php.
 */

function allowedRequestTables(): array
{
    return ['mkt', 'shop', 'xerox', 'service', 'ti'];
}

function normalizeRequestTable(string $table): ?string
{
    $raw = preg_replace('/[^a-z_]/', '', strtolower(trim($table)));
    $raw = preg_replace('/^ctd_/', '', $raw);
    $raw = preg_replace('/_frm$/', '', $raw);

    return in_array($raw, allowedRequestTables(), true) ? $raw : null;
}

function sectorAreaAliases(string $sector): array
{
    $normalized = normalizeRequestTable($sector) ?? strtolower(trim($sector));
    $aliases = [
        'mkt' => ['mkt', 'marketing'],
        'shop' => ['shop', 'compras'],
        'xerox' => ['xerox', 'reprografia'],
        'service' => ['service', 'manutencao', 'manutenção'],
        'ti' => ['ti'],
    ];

    return $aliases[$normalized] ?? [$normalized];
}

function areaTitleToSector(string $areaTitle): ?string
{
    $raw = strtolower(trim($areaTitle));
    $raw = strtr($raw, ['ç' => 'c', 'ã' => 'a', 'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u']);
    $map = [
        'mkt' => 'mkt',
        'marketing' => 'mkt',
        'shop' => 'shop',
        'compras' => 'shop',
        'xerox' => 'xerox',
        'reprografia' => 'xerox',
        'service' => 'service',
        'manutencao' => 'service',
        'ti' => 'ti',
    ];

    return $map[$raw] ?? normalizeRequestTable($raw);
}

function isUserManagerOfSector(PDO $pdo, int $userId, string $sector): bool
{
    $aliases = sectorAreaAliases($sector);
    $placeholders = implode(',', array_fill(0, count($aliases), '?'));
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM cfg_user_area cua
        JOIN ctd_area a ON a.id = cua.id_area
        WHERE cua.id_user = ? AND LOWER(a.title) IN ($placeholders)
    ");
    $stmt->execute(array_merge([$userId], $aliases));
    return (int)$stmt->fetchColumn() > 0;
}

function globalAdminRoles(): array
{
    return ['admin', 'adm', 'coord'];
}

function adminRoles(): array
{
    return array_merge(globalAdminRoles(), ['adm_sub']);
}

function gestorRoles(): array
{
    return ['gestor', 'ti', 'xerox', 'service', 'shop', 'mkt', 'marketing'];
}

function isGlobalAdminRole(string $role): bool
{
    return in_array($role, globalAdminRoles(), true);
}

function isAdminRole(string $role): bool
{
    return in_array($role, adminRoles(), true);
}

function isGestorRole(string $role): bool
{
    return in_array($role, gestorRoles(), true);
}

function roleDefaultSector(string $role): ?string
{
    $map = [
        'gestor' => 'ti',
        'ti' => 'ti',
        'xerox' => 'xerox',
        'service' => 'service',
        'shop' => 'shop',
        'mkt' => 'mkt',
        'marketing' => 'mkt',
    ];

    return $map[$role] ?? null;
}

function userSubdivisionIds(): array
{
    return array_values(array_filter(array_map('intval', $_SESSION['subdivision_ids'] ?? [])));
}

function getUserSubdivisionIds(PDO $pdo, int $userId): array
{
    if ($userId <= 0) {
        return [];
    }

    $stmt = $pdo->prepare("
        SELECT id_subdivision
        FROM cfg_user_subdivision
        WHERE id_user = ?
    ");
    $stmt->execute([$userId]);

    return array_values(array_unique(array_filter(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)))));
}

function userHasSubdivisionAccess(?int $requestSubdivisionId): bool
{
    if (!$requestSubdivisionId) {
        return false;
    }

    return in_array((int)$requestSubdivisionId, userSubdivisionIds(), true);
}

function userCanAccessCreatorSubdivision(PDO $pdo, int $createdBy): bool
{
    $userSubs = userSubdivisionIds();
    if ($createdBy <= 0 || empty($userSubs)) {
        return false;
    }

    $placeholders = implode(',', array_fill(0, count($userSubs), '?'));
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM cfg_user_subdivision
        WHERE id_user = ?
          AND id_subdivision IN ($placeholders)
    ");
    $stmt->execute(array_merge([$createdBy], $userSubs));

    return (int)$stmt->fetchColumn() > 0;
}

function getRequestRow(PDO $pdo, string $sector, int $id): ?array
{
    $normalizedSector = normalizeRequestTable($sector);
    if ($normalizedSector === null || $id <= 0) {
        return null;
    }

    $stmt = $pdo->prepare("SELECT * FROM `ctd_{$normalizedSector}_frm` WHERE id = ?");
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

/**
 * O usuário participou (enviando ou recebendo) de algum encaminhamento deste pedido?
 * Dá direito a VER o pedido e conversar, não a agir sobre ele.
 * Quem recebeu e recusou (ou teve o envio cancelado) não continua vendo o pedido.
 */
function userHasForwardAccess(PDO $pdo, int $userId, int $requestId, string $sector): bool
{
    $normalizedSector = normalizeRequestTable($sector);
    if ($normalizedSector === null || $userId <= 0 || $requestId <= 0) {
        return false;
    }

    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM request_forwards rf
        JOIN cfg_user_area cua ON cua.id_area = rf.from_area_id
            OR (cua.id_area = rf.to_area_id AND rf.status NOT IN ('refused', 'cancelled'))
        WHERE rf.request_id = ? AND rf.request_table = ? AND cua.id_user = ?
    ");
    $stmt->execute([$requestId, $normalizedSector, $userId]);

    return (int)$stmt->fetchColumn() > 0;
}

/**
 * Está no pedido só porque o setor foi chamado para AJUDAR (ou recebeu uma transferência que ainda
 * não aceitou)? Então conversa com o responsável (internal) e, na ajuda, com quem pediu no canal helper;
 * nunca vê a conversa de quem pediu com o responsável.
 * Não vale para o setor do formulário, o responsável atual (inclui quem assumiu por transferência)
 * nem para coordenação e administração.
 */
function userSeesOnlyInternalChat(PDO $pdo, array $request, string $sector, int $userId, string $role): bool
{
    $normalizedSector = normalizeRequestTable($sector);
    $requestId = (int)($request['id'] ?? 0);
    if (!isGestorRole($role) || $normalizedSector === null || $requestId <= 0) {
        return false;
    }
    if (isUserManagerOfSector($pdo, $userId, $normalizedSector) || userIsRequestOwner($pdo, $request, $normalizedSector, $userId, $role)) {
        return false;
    }
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM request_forwards rf
        JOIN cfg_user_area cua ON cua.id_area = rf.to_area_id
        WHERE rf.request_id = ? AND rf.request_table = ? AND cua.id_user = ?
          AND ((rf.mode = 'help' AND rf.status NOT IN ('refused', 'cancelled'))
            OR (rf.mode = 'transfer' AND rf.status = 'pending'))
    ");
    $stmt->execute([$requestId, $normalizedSector, $userId]);
    return (int)$stmt->fetchColumn() > 0;
}

/**
 * Setores que ajudam (ou ajudaram) no pedido: ajuda aceita, devolvida ou concluída.
 * Cada um tem a sua conversa com quem pediu (canal helper). $forwards: RequestForwardService::forRequest().
 */
function requestHelperAreaIds(array $forwards): array
{
    $areas = [];
    foreach ($forwards as $f) {
        if ($f['mode'] === 'help' && in_array($f['status'], ['accepted', 'returned', 'completed'], true)) {
            $areas[] = (int)$f['to_area_id'];
        }
    }
    return array_values(array_unique($areas));
}

/**
 * Setores ajudando agora (ajuda aceita, ainda não devolvida): só com eles a conversa helper
 * recebe mensagem; depois de devolvida ela fica para consulta.
 */
function requestOpenHelperAreaIds(array $forwards): array
{
    $areas = [];
    foreach ($forwards as $f) {
        if ($f['mode'] === 'help' && $f['status'] === 'accepted') {
            $areas[] = (int)$f['to_area_id'];
        }
    }
    return array_values(array_unique($areas));
}

/**
 * Setor responsável atual do pedido (ctd_area.id).
 * É o destino da última transferência aceita; sem transferência, o setor do formulário.
 */
function requestOwnerAreaId(PDO $pdo, int $requestId, string $sector): ?int
{
    require_once __DIR__ . '/../classes/RequestForwardService.php';
    return RequestForwardService::ownerAreaId($pdo, $requestId, normalizeRequestTable($sector) ?? $sector);
}

/**
 * É o responsável pelo pedido? (gestor do setor responsável atual, ou administrador geral)
 */
function userIsRequestOwner(PDO $pdo, array $request, string $sector, int $userId, string $role): bool
{
    if (isGlobalAdminRole($role)) {
        return true;
    }
    if (!isGestorRole($role)) {
        return false;
    }
    require_once __DIR__ . '/../classes/RequestForwardService.php';
    // Quem já calculou o responsável (Caixa, página do pedido) manda em owner_area_id e poupa a consulta
    $ownerArea = array_key_exists('owner_area_id', $request)
        ? ($request['owner_area_id'] !== null ? (int)$request['owner_area_id'] : null)
        : requestOwnerAreaId($pdo, (int)($request['id'] ?? 0), $sector);
    return RequestForwardService::userInArea($pdo, $userId, $ownerArea);
}

function userCanViewRequest(PDO $pdo, array $request, string $sector, int $userId, string $role): bool
{
    $requestId = (int)($request['id'] ?? 0);
    $createdBy = (int)($request['created_by'] ?? 0);
    $requestSubdivisionId = isset($request['subdivision_id']) ? (int)$request['subdivision_id'] : null;

    if ($userId > 0 && $createdBy === $userId) {
        return true;
    }

    if (isGlobalAdminRole($role)) {
        return true;
    }

    if ($role === 'adm_sub') {
        return userHasSubdivisionAccess($requestSubdivisionId)
            || userCanAccessCreatorSubdivision($pdo, $createdBy);
    }

    if (isGestorRole($role)) {
        return isUserManagerOfSector($pdo, $userId, normalizeRequestTable($sector) ?? $sector)
            || userIsRequestOwner($pdo, $request, $sector, $userId, $role)
            || userHasForwardAccess($pdo, $userId, $requestId, $sector);
    }

    return false;
}

/**
 * Escopo de aprovação: administrador geral, ou coordenação da subdivisão do pedido.
 * (Também usado para mudar urgência e cancelar, como antes.)
 */
function userCanManageOriginalRequest(PDO $pdo, array $request, string $sector, int $userId, string $role): bool
{
    $requestSubdivisionId = isset($request['subdivision_id']) ? (int)$request['subdivision_id'] : null;

    if (isGlobalAdminRole($role)) {
        return true;
    }

    if ($role === 'adm_sub') {
        return userHasSubdivisionAccess($requestSubdivisionId)
            || userCanAccessCreatorSubdivision($pdo, (int)($request['created_by'] ?? 0));
    }

    return false;
}

/**
 * Pode agir sobre o pedido (urgência, reabrir)? Responsável atual ou coordenação da subdivisão.
 * Começar e concluir exigem ser gestor do setor responsável (ver api/request_actions.php).
 */
function userCanActOnRequest(PDO $pdo, array $request, string $sector, int $userId, string $role): bool
{
    return userIsRequestOwner($pdo, $request, $sector, $userId, $role)
        || userCanManageOriginalRequest($pdo, $request, $sector, $userId, $role);
}

/**
 * Setores (ctd_area) lidos uma vez por requisição: [id => título], em ordem de id.
 * A tabela é pequena e muito consultada; o banco fica longe, então cada consulta evitada conta.
 */
function areaTitles(PDO $pdo, bool $refresh = false): array
{
    static $titles = null;
    if ($titles === null || $refresh) {
        $titles = [];
        foreach ($pdo->query('SELECT id, title FROM ctd_area ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $titles[(int)$row['id']] = (string)$row['title'];
        }
    }
    return $titles;
}

/**
 * Frase curta do que cada setor faz: [id => descrição] (só os que têm). Usada no Encaminhar.
 */
function areaDescriptions(PDO $pdo): array
{
    $descriptions = [];
    foreach ($pdo->query("SELECT id, description FROM ctd_area WHERE description IS NOT NULL AND description <> ''")->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $descriptions[(int)$row['id']] = (string)$row['description'];
    }
    return $descriptions;
}

function getAreaIdBySector(PDO $pdo, string $sector): ?int
{
    $aliases = sectorAreaAliases($sector);
    if (empty($aliases)) {
        return null;
    }
    foreach (areaTitles($pdo) as $id => $title) {
        if (in_array(mb_strtolower(trim($title)), $aliases, true)) {
            return $id;
        }
    }
    return null;
}

function ensureUserAreaForRole(PDO $pdo, int $userId, string $role): void
{
    $sector = roleDefaultSector($role);
    if ($sector === null) {
        return;
    }

    $areaId = getAreaIdBySector($pdo, $sector);
    if (!$areaId) {
        throw new RuntimeException('Setor padrao do perfil nao encontrado.');
    }

    $stmt = $pdo->prepare("INSERT IGNORE INTO cfg_user_area (id_user, id_area) VALUES (?, ?)");
    $stmt->execute([$userId, $areaId]);
}

/**
 * Abrir o pedido conta como ler os avisos dele.
 */
function markRequestNotificationsRead(PDO $pdo, int $userId, string $sector, int $requestId): void
{
    $pdo->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = ? AND link = ? AND is_read = 0')
        ->execute([$userId, "request_detail?id=$requestId&table=$sector"]);
}
