<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function ensureCsrfToken(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function getCsrfToken(): string
{
    return ensureCsrfToken();
}

function validateCsrfToken(?string $token): bool
{
    if (empty($token) || empty($_SESSION['csrf_token'])) {
        return false;
    }

    return hash_equals($_SESSION['csrf_token'], $token);
}

function requireCsrfTokenFromRequest(): void
{
    $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;

    if (!validateCsrfToken($token)) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Token CSRF invalido ou ausente.']);
        exit;
    }
}

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

function userHasForwardAccess(PDO $pdo, int $userId, int $requestId, string $sector): bool
{
    $normalizedSector = normalizeRequestTable($sector);
    if ($normalizedSector === null || $userId <= 0 || $requestId <= 0) {
        return false;
    }

    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM request_forwards rf
        JOIN cfg_user_area cua ON cua.id_area = rf.to_area_id
        WHERE rf.request_id = ?
          AND rf.request_table = ?
          AND rf.status IN ('pending', 'accepted', 'completed')
          AND cua.id_user = ?
    ");
    $stmt->execute([$requestId, $normalizedSector, $userId]);

    return (int)$stmt->fetchColumn() > 0;
}

function getActiveForwardForUser(PDO $pdo, int $userId, int $requestId, string $sector, array $statuses = ['pending', 'accepted']): ?array
{
    $normalizedSector = normalizeRequestTable($sector);
    if ($normalizedSector === null || $userId <= 0 || $requestId <= 0 || empty($statuses)) {
        return null;
    }

    $placeholders = implode(',', array_fill(0, count($statuses), '?'));
    $stmt = $pdo->prepare("
        SELECT rf.*
        FROM request_forwards rf
        JOIN cfg_user_area cua ON cua.id_area = rf.to_area_id
        WHERE rf.request_id = ?
          AND rf.request_table = ?
          AND cua.id_user = ?
          AND rf.status IN ($placeholders)
        ORDER BY rf.created_at DESC, rf.id DESC
        LIMIT 1
    ");
    $stmt->execute(array_merge([$requestId, $normalizedSector, $userId], $statuses));
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

function userCanManageForwardedRequest(PDO $pdo, array $request, string $sector, int $userId): bool
{
    return getActiveForwardForUser($pdo, $userId, (int)($request['id'] ?? 0), $sector, ['pending', 'accepted', 'completed']) !== null;
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
            || userHasForwardAccess($pdo, $userId, $requestId, $sector);
    }

    return false;
}

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

    if (isGestorRole($role)) {
        return isUserManagerOfSector($pdo, $userId, normalizeRequestTable($sector) ?? $sector);
    }

    return false;
}

function userCanActOnRequest(PDO $pdo, array $request, string $sector, int $userId, string $role): bool
{
    return userCanManageOriginalRequest($pdo, $request, $sector, $userId, $role)
        || (isGestorRole($role) && userCanManageForwardedRequest($pdo, $request, $sector, $userId));
}

function getAreaIdBySector(PDO $pdo, string $sector): ?int
{
    $aliases = sectorAreaAliases($sector);
    if (empty($aliases)) {
        return null;
    }

    $placeholders = implode(',', array_fill(0, count($aliases), '?'));
    $stmt = $pdo->prepare("SELECT id FROM ctd_area WHERE LOWER(title) IN ($placeholders) ORDER BY id LIMIT 1");
    $stmt->execute($aliases);
    $areaId = $stmt->fetchColumn();

    return $areaId ? (int)$areaId : null;
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
 * Retorna o badge formatado e destacado do prazo de entrega
 */
function getPrazoBadge(?string $rawDate, string $status = 'P'): string {
    if (empty($rawDate) || $rawDate === '0000-00-00' || $rawDate === '0000-00-00 00:00:00') {
        return '<span class="prazo-badge prazo-sem-prazo" style="display:inline-flex; align-items:center; gap:4px; font-size:0.7rem; color:#94a3b8;"><i class="fa-regular fa-calendar-times"></i> Sem prazo</span>';
    }

    $deadlineTs = strtotime($rawDate);
    if (!$deadlineTs) {
        return '<span class="prazo-badge prazo-sem-prazo" style="display:inline-flex; align-items:center; gap:4px; font-size:0.7rem; color:#94a3b8;"><i class="fa-regular fa-calendar-times"></i> Sem prazo</span>';
    }

    $deadlineDate = date('Y-m-d', $deadlineTs);
    $todayDate = date('Y-m-d');
    $tomorrowDate = date('Y-m-d', strtotime('+1 day'));

    $displayDate = date('d/m/Y', $deadlineTs);

    // Se já estiver concluída ou rejeitada/cancelada, mostramos a data normal neutra
    $st = strtoupper(trim($status));
    if ($st === 'C' || $st === 'N' || $st === 'R') {
        return '<span class="prazo-badge prazo-concluido" style="display:inline-flex; align-items:center; gap:4px; color:#64748b; font-size:0.74rem; font-weight:500;">'
             . '<i class="fa-regular fa-calendar-check"></i> ' . $displayDate . '</span>';
    }

    if ($deadlineDate < $todayDate) {
        // Vencida / Atrasada
        return '<span class="prazo-badge prazo-atrasado" style="display:inline-flex; align-items:center; gap:4px; background:#fff1f2; color:#be123c; border:1px solid #fecdd3; padding:2px 6px; border-radius:4px; font-size:0.72rem; font-weight:700;" title="Atrasada">'
             . '<i class="fa-solid fa-triangle-exclamation"></i> Vencido (' . $displayDate . ')</span>';
    } elseif ($deadlineDate === $todayDate) {
        // Vence Hoje
        return '<span class="prazo-badge prazo-hoje" style="display:inline-flex; align-items:center; gap:4px; background:#fffbeb; color:#d97706; border:1px solid #fde68a; padding:2px 6px; border-radius:4px; font-size:0.72rem; font-weight:700;" title="Vence hoje">'
             . '<i class="fa-solid fa-clock"></i> Hoje (' . $displayDate . ')</span>';
    } elseif ($deadlineDate === $tomorrowDate) {
        // Falta 1 dia (Amanhã)
        return '<span class="prazo-badge prazo-amanha" style="display:inline-flex; align-items:center; gap:4px; background:#fef3c7; color:#b45309; border:1px solid #fde68a; padding:2px 6px; border-radius:4px; font-size:0.72rem; font-weight:700;" title="Falta 1 dia">'
             . '<i class="fa-solid fa-hourglass-half"></i> Amanhã (' . $displayDate . ')</span>';
    } else {
        // Futuro (Mais de 1 dia)
        $diffDays = (int)ceil((strtotime($deadlineDate) - strtotime($todayDate)) / 86400);
        if ($diffDays <= 3) {
            // Próximo (2 ou 3 dias restantes) - Alerta amigável
            return '<span class="prazo-badge prazo-proximo" style="display:inline-flex; align-items:center; gap:4px; background:#ecfdf5; color:#047857; border:1px solid #a7f3d0; padding:2px 6px; border-radius:4px; font-size:0.72rem; font-weight:700;" title="Restam ' . $diffDays . ' dias">'
                 . '<i class="fa-regular fa-calendar-check"></i> ' . $diffDays . ' dias (' . $displayDate . ')</span>';
        } else {
            // No prazo normal
            return '<span class="prazo-badge prazo-futuro" style="display:inline-flex; align-items:center; gap:4px; color:#475569; font-size:0.74rem; font-weight:500;">'
                 . '<i class="fa-regular fa-calendar"></i> ' . $displayDate . '</span>';
        }
    }
}
