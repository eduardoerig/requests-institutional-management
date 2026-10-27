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
 * Marca da instituição (white label): ORG_NAME/ORG_LOGO no ambiente ou org_name/org_logo
 * em config/conn.local.php. Sem configuração, usa a marca neutra do SisReq.
 */
function orgSetting(string $key): string
{
    static $local = null;
    $defaults = ['org_name' => 'SisReq', 'org_logo' => 'assets/img/LogoFavConSisreq.png'];

    $value = getenv(strtoupper($key));
    if ($value === false || $value === '') {
        if ($local === null) {
            $file = __DIR__ . '/../config/conn.local.php';
            $local = is_file($file) ? (require $file) : [];
            $local = is_array($local) ? $local : [];
        }
        $value = (string)($local[$key] ?? '');
    }
    // Logo só de dentro de assets/ (nada de caminho de fora ou endereço externo)
    if ($key === 'org_logo' && (!str_starts_with($value, 'assets/') || str_contains($value, '..'))) {
        $value = '';
    }

    return $value !== '' ? $value : ($defaults[$key] ?? '');
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

/**
 * Senha temporária gerada no servidor (mesmo alfabeto de database/reset_password.php:
 * sem caracteres ambíguos 0/O, 1/l/I). Nunca registrar o valor em log.
 */
function generateTempPassword(int $length = 12): string
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
    $max = strlen($alphabet) - 1;
    $password = '';
    for ($i = 0; $i < $length; $i++) {
        $password .= $alphabet[random_int(0, $max)];
    }
    return $password;
}

/**
 * Senha que a própria pessoa cria: o que falta, em frases curtas ([] = pode usar).
 * As mesmas regras da janela de Minha conta (pages/myAccount.php).
 */
function passwordProblems(string $new, string $current = ''): array
{
    $problems = [];
    if (mb_strlen($new) < 8) $problems[] = 'pelo menos 8 caracteres';
    if (!preg_match('/\p{Lu}/u', $new)) $problems[] = 'uma letra maiúscula';
    if (!preg_match('/\d/', $new)) $problems[] = 'um número';
    if (!preg_match('/[^\p{L}\p{N}\s]/u', $new)) $problems[] = 'um símbolo, como ! ou #';
    if ($current !== '' && $new === $current) $problems[] = 'ser diferente da senha atual';
    return $problems;
}

/**
 * Formato aceito para o usuário de entrada (login): letras, números, ponto, hífen e sublinhado.
 */
function isValidLoginFormat(string $login): bool
{
    return (bool)preg_match('/^[A-Za-z0-9._-]{2,100}$/', $login);
}
