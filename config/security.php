<?php

require_once __DIR__ . '/session.php';

/**
 * Erro com mensagem pensada para o usuário final (pode ser exibida como está).
 */
class UserMessage extends Exception
{
}

/**
 * Escapa texto para saída HTML.
 */
function e($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

/**
 * Vocabulário único de situação da requisição (usar em TODAS as telas).
 */
function statusLabels(): array
{
    return [
        'P' => 'Aguardando aprovação',
        'Y' => 'Aprovada',
        'W' => 'Em atendimento',
        'C' => 'Concluída',
        'N' => 'Recusada',
        'F' => 'Encaminhada a outro setor',
    ];
}

function statusLabel(?string $status): string
{
    $status = strtoupper(trim((string)$status));
    return statusLabels()[$status === '' ? 'P' : $status] ?? 'Desconhecida';
}

function priorityLabels(): array
{
    return [1 => 'Baixa', 2 => 'Normal', 3 => 'Alta', 4 => 'Crítica'];
}

function priorityLabel(int $priority): string
{
    return priorityLabels()[$priority] ?? 'Normal';
}

function jsonResponse(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($payload);
    exit;
}

/**
 * Exige usuário logado, ativo e sem troca de senha pendente.
 * Revalida no banco a cada 60s para derrubar sessões de contas desativadas.
 */
function requireLoginJson(?PDO $pdo = null, bool $allowForceReset = false): void
{
    if (empty($_SESSION['id'])) {
        jsonResponse(['success' => false, 'message' => 'Sua sessão expirou. Entre novamente.', 'redirect' => 'login'], 401);
    }

    if ($pdo !== null && (time() - (int)($_SESSION['checked_at'] ?? 0)) > 60) {
        $stmt = $pdo->prepare('SELECT status, role, force_reset FROM ctd_users WHERE id = ?');
        $stmt->execute([(int)$_SESSION['id']]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row || (int)($row['status'] ?? 1) !== 1) {
            session_unset();
            session_destroy();
            jsonResponse(['success' => false, 'message' => 'Sua conta está inativa. Fale com o administrador.', 'redirect' => 'login'], 401);
        }
        $_SESSION['role'] = $row['role'];
        $_SESSION['force_reset'] = (int)($row['force_reset'] ?? 0);
        $_SESSION['checked_at'] = time();
    }

    if (!$allowForceReset && (int)($_SESSION['force_reset'] ?? 0) === 1) {
        jsonResponse(['success' => false, 'message' => 'Troque sua senha em "Minha conta" antes de continuar.', 'redirect' => 'myAccount'], 403);
    }
}

function requirePostJson(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        jsonResponse(['success' => false, 'message' => 'Método não permitido.'], 405);
    }
    requireCsrfTokenFromRequest();
}

/**
 * Neutraliza fórmulas em células CSV (=, +, -, @).
 */
function csvSafe($value): string
{
    $value = (string)($value ?? '');
    if ($value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
        return "'" . $value;
    }
    return $value;
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
 * Selo do prazo pedido, em texto simples ("Atrasada", "Hoje", "Amanhã", "Faltam 3 dias").
 */
function getPrazoBadge(?string $rawDate, string $status = 'P'): string
{
    $ts = (!empty($rawDate) && strpos((string)$rawDate, '0000-00-00') !== 0) ? strtotime((string)$rawDate) : false;
    if (!$ts) {
        return '<span class="prazo-badge prazo-sem-prazo">Sem prazo</span>';
    }

    $date = date('Y-m-d', $ts);
    $display = date('d/m', $ts);
    $st = strtoupper(trim($status));

    if (in_array($st, ['C', 'N'], true)) {
        return '<span class="prazo-badge prazo-encerrado"><i class="fa-regular fa-calendar" aria-hidden="true"></i> Prazo ' . $display . '</span>';
    }

    $today = date('Y-m-d');
    $days = (int)round((strtotime($date) - strtotime($today)) / 86400);

    if ($days < 0) {
        return '<span class="prazo-badge prazo-atrasado"><i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i> Atrasada (era ' . $display . ')</span>';
    }
    if ($days === 0) {
        return '<span class="prazo-badge prazo-hoje"><i class="fa-solid fa-clock" aria-hidden="true"></i> Vence hoje</span>';
    }
    if ($days === 1) {
        return '<span class="prazo-badge prazo-hoje"><i class="fa-regular fa-clock" aria-hidden="true"></i> Vence amanhã</span>';
    }
    if ($days <= 3) {
        return '<span class="prazo-badge prazo-proximo"><i class="fa-regular fa-calendar" aria-hidden="true"></i> Faltam ' . $days . ' dias (' . $display . ')</span>';
    }
    return '<span class="prazo-badge prazo-futuro"><i class="fa-regular fa-calendar" aria-hidden="true"></i> Até ' . $display . '</span>';
}
