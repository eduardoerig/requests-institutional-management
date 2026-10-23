<?php
/**
 * Utilitários gerais: escape, rótulos, respostas JSON e proteção CSRF.
 * Carregado por config/security.php.
 */

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
        // Acontece com a página aberta há muito tempo (a sessão expirou e o token mudou)
        echo json_encode(['success' => false, 'message' => 'Esta página ficou aberta por muito tempo. Recarregue a página e tente de novo.']);
        exit;
    }
}
