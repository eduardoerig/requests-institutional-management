<?php
/**
 * Perfis, setores e regras de quem pode ver e agir sobre cada pedido.
 * Carregado por config/security.php.
 */

/** Setores que atendem pedidos (config/sectors.php): slug => label, label_to, icon, form_type... */
function sectorRegistry(): array
{
    static $sectors = null;
    return $sectors ??= require __DIR__ . '/../config/sectors.php';
}

function allowedRequestTables(): array
{
    return array_keys(sectorRegistry());
}

/** Nome do setor ("Manutenção"); slug desconhecido volta com a primeira letra maiúscula */
function sectorLabel(string $slug): string
{
    return sectorRegistry()[$slug]['label'] ?? ucfirst($slug);
}

/** Setor com artigo, para "Enviando para a Manutenção" */
function sectorLabelTo(string $slug): string
{
    return sectorRegistry()[$slug]['label_to'] ?? sectorLabel($slug);
}

/** Tabela de pedidos do setor: ti => ctd_ti_frm (slug já validado; null se não for um setor) */
function requestTableName(string $slug): ?string
{
    $slug = normalizeRequestTable($slug);
    return $slug === null ? null : 'ctd_' . $slug . '_frm';
}

/** Slug a partir do nome da tabela ou do próprio slug: ctd_ti_frm => ti */
function requestSlug(string $table): string
{
    $raw = strtolower(trim($table));
    return normalizeRequestTable($raw) ?? preg_replace(['/^ctd_/', '/_frm$/'], '', $raw);
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
    return sectorRegistry()[$normalized]['aliases'] ?? [$normalized];
}

function areaTitleToSector(string $areaTitle): ?string
{
    $raw = mb_strtolower(trim($areaTitle));
    $plain = strtr($raw, ['ç' => 'c', 'ã' => 'a', 'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u']);
    foreach (sectorRegistry() as $slug => $sector) {
        if (in_array($raw, $sector['aliases'], true) || in_array($plain, $sector['aliases'], true)) {
            return $slug;
        }
    }
    return normalizeRequestTable($plain);
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

/**
 * A coordenação responde por este pedido? Vale a área do pedido: quem está em várias áreas pede por uma
 * delas, e a coordenação das outras não entra. Só pedido sem área cai na área de quem pediu.
 */
function userCoordinatesRequest(PDO $pdo, ?int $requestSubdivisionId, int $createdBy): bool
{
    return $requestSubdivisionId
        ? userHasSubdivisionAccess($requestSubdivisionId)
        : userCanAccessCreatorSubdivision($pdo, $createdBy);
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

/**
 * Papel na conversa do pedido. Quem pediu nunca vê a conversa interna entre setores, mesmo que o
 * perfil seja de setor ou de coordenação: se a pessoa só está no pedido porque o criou (não é do setor
 * do formulário, não é o responsável, não está ajudando), conversa como 'solicitante'.
 * Administração geral continua vendo tudo. Use no lugar do perfil em requestChatThreads,
 * get_comments e add_comment.
 */
function requestChatRole(PDO $pdo, array $request, string $sector, int $userId, string $role): string
{
    if ($role === 'solicitante' || isGlobalAdminRole($role)) {
        return $role;
    }
    if ($userId <= 0 || (int)($request['created_by'] ?? 0) !== $userId) {
        return $role;
    }
    $normalizedSector = normalizeRequestTable($sector);
    if ($normalizedSector !== null && isGestorRole($role) && (
        isUserManagerOfSector($pdo, $userId, $normalizedSector)
        || userIsRequestOwner($pdo, $request, $normalizedSector, $userId, $role)
        || userSeesOnlyInternalChat($pdo, $request, $normalizedSector, $userId, $role)
    )) {
        return $role;
    }
    return 'solicitante';
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
        return userCoordinatesRequest($pdo, $requestSubdivisionId, $createdBy);
    }

    if (isGestorRole($role)) {
        // Setor só vê o pedido depois de aprovado: pendente e recusado são da coordenação e de quem pediu
        // (quem criou já entrou pelo created_by acima)
        $status = strtoupper(trim((string)($request['status'] ?? 'P'))) ?: 'P';
        if (in_array($status, ['P', 'N'], true)) {
            return false;
        }
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
        return userCoordinatesRequest($pdo, $requestSubdivisionId, (int)($request['created_by'] ?? 0));
    }

    return false;
}

/**
 * Pode mudar a urgência do pedido? Responsável atual ou coordenação da subdivisão.
 * Começar, concluir e reabrir exigem ser o setor responsável (userIsRequestOwner, api/request_actions.php).
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
