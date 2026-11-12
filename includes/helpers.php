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
 * Subdivisões da pessoa na sessão (login e a cada requisição, em refreshSessionUser).
 * subdivision_id/subdivision_name: campos antigos, a primeira da lista.
 */
function loadSessionSubdivisions(PDO $pdo, int $userId): void
{
    $stmt = $pdo->prepare('SELECT s.id, s.name FROM ctd_subdivision s JOIN cfg_user_subdivision cus ON s.id = cus.id_subdivision WHERE cus.id_user = ? ORDER BY s.id');
    $stmt->execute([$userId]);
    $_SESSION['subdivision_ids'] = [];
    $_SESSION['subdivision_names'] = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $s) {
        $_SESSION['subdivision_ids'][] = $s['id'];
        $_SESSION['subdivision_names'][] = $s['name'];
    }
    $_SESSION['subdivision_id'] = $_SESSION['subdivision_ids'][0] ?? null;
    $_SESSION['subdivision_name'] = $_SESSION['subdivision_names'][0] ?? null;
}

/**
 * Atualiza a sessão com o banco em toda requisição: perfil, troca de senha pendente e subdivisões
 * (o admin muda e vale na hora, sem sair do sistema).
 * false = a sessão não vale mais (conta inativa/apagada ou senha trocada em outro lugar:
 * session_version mudou); a sessão é destruída e $_SESSION['ended_reason'] diz o motivo.
 */
function refreshSessionUser(PDO $pdo): bool
{
    $userId = (int)($_SESSION['id'] ?? 0);
    if ($userId <= 0) {
        return false;
    }
    $stmt = $pdo->prepare('SELECT status, role, force_reset, session_version FROM ctd_users WHERE id = ?');
    $stmt->execute([$userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $reason = null;
    if (!$row || (int)$row['status'] !== 1) {
        $reason = 'inactive';
    } elseif ((int)$row['session_version'] !== (int)($_SESSION['session_version'] ?? 0)) {
        $reason = 'password';
    }
    if ($reason !== null) {
        session_unset();
        session_destroy();
        // Sessão nova e vazia (use_strict_mode dá outro id): a tela de login precisa de um token CSRF válido
        if (!headers_sent()) {
            session_start();
        }
        $_SESSION['ended_reason'] = $reason;
        return false;
    }
    $_SESSION['role'] = $row['role'];
    $_SESSION['force_reset'] = (int)$row['force_reset'];
    loadSessionSubdivisions($pdo, $userId);
    return true;
}

/** Mensagem para quem teve a sessão encerrada por refreshSessionUser() */
function sessionEndedMessage(?string $reason): string
{
    return match ($reason) {
        'inactive' => 'Sua conta está inativa. Fale com o administrador.',
        'password' => 'Sua senha foi trocada. Entre de novo com a senha nova.',
        default => 'Sua sessão expirou. Entre novamente.',
    };
}

/**
 * IP de quem chama. Atrás de proxy, só confia em X-Forwarded-For quando quem conectou está em
 * TRUSTED_PROXIES (variável de ambiente, IPs separados por vírgula); senão vale REMOTE_ADDR.
 */
function clientIp(): string
{
    $remote = (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    $trusted = array_filter(array_map('trim', explode(',', (string)getenv('TRUSTED_PROXIES'))));
    if ($trusted && in_array($remote, $trusted, true) && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        // O primeiro da lista é o cliente; os seguintes são os proxies pelo caminho
        $first = trim(explode(',', (string)$_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
        if (filter_var($first, FILTER_VALIDATE_IP)) {
            return $first;
        }
    }
    return $remote;
}

/**
 * Exige usuário logado, ativo e sem troca de senha pendente.
 * Confere no banco a cada requisição (refreshSessionUser): conta desativada, senha trocada em
 * outro lugar e mudança de perfil ou subdivisão valem na hora.
 */
function requireLoginJson(?PDO $pdo = null, bool $allowForceReset = false): void
{
    if (empty($_SESSION['id'])) {
        jsonResponse(['success' => false, 'message' => sessionEndedMessage(null), 'redirect' => 'login', 'session_expired' => true], 401);
    }

    if ($pdo !== null && !refreshSessionUser($pdo)) {
        jsonResponse(['success' => false, 'message' => sessionEndedMessage($_SESSION['ended_reason'] ?? null), 'redirect' => 'login', 'session_expired' => true], 401);
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
        // csrf_expired: o script.js busca um token novo (api/csrf.php) e repete o envio uma vez
        echo json_encode(['success' => false, 'message' => 'Esta página ficou aberta por muito tempo. Recarregue a página e tente de novo.', 'csrf_expired' => true]);
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

/**
 * Prazo de um pedido novo (AAAA-MM-DD): o que está errado, ou null se pode usar.
 * O prazo começa amanhã: quem pede não marca para o mesmo dia (feedback da CTAP).
 */
function deadlineProblem(string $iso): ?string
{
    $date = DateTime::createFromFormat('!Y-m-d', $iso);
    if ($iso === '' || !$date || $date->format('Y-m-d') !== $iso) {
        return 'Informe a data em que você precisa receber.';
    }
    if ($date <= new DateTime('today')) {
        return 'O prazo precisa ser a partir de amanhã.';
    }
    return null;
}

/**
 * Tipos de pedido da Reprografia (ctd_xerox_frm.kind). NULL nos pedidos antigos = cópia colorida.
 */
function xeroxKinds(): array
{
    return ['copia_colorida' => 'Cópia colorida', 'plastificacao' => 'Plastificação'];
}

/**
 * Campos próprios do tipo de pedido, na ordem em que aparecem (página do pedido e impressão).
 * @return array<string, string> rótulo => valor (vazio = não mostrar)
 */
function requestTypeFields(string $sector, array $req): array
{
    if ($sector === 'xerox') {
        $kind = (string)($req['kind'] ?? '') ?: 'copia_colorida';
        if ($kind === 'plastificacao') {
            return [
                'Tipo' => 'Plastificação',
                'Folhas' => (string)($req['qtd'] ?? ''),
                'Tamanho' => (string)($req['type'] ?? ''),
            ];
        }
        return [
            'Tipo' => 'Cópia colorida',
            'Arquivo' => (string)($req['arquive'] ?? ''),
            'Cópias' => (string)($req['qtd'] ?? ''),
            'Tamanho do papel' => (string)($req['type'] ?? ''),
        ];
    }
    if ($sector === 'mkt') {
        return ['Onde será usada' => (string)($req['type'] ?? '')];
    }
    return [];
}

/**
 * Resumo do pedido da Reprografia, que não tem descrição: "30 cópias · papel A4" ou
 * "10 folhas para plastificar · A4".
 */
function xeroxSummary(array $req): string
{
    $qtd = trim((string)($req['qtd'] ?? '')) !== '' ? (int)$req['qtd'] : 0;
    $size = trim((string)($req['type'] ?? ''));
    if (((string)($req['kind'] ?? '')) === 'plastificacao') {
        return implode(' · ', array_filter([$qtd ? "$qtd folhas para plastificar" : 'Plastificação', $size]));
    }
    return implode(' · ', array_filter([$qtd ? "$qtd cópias" : '', $size !== '' ? "papel $size" : '']));
}

/**
 * Pasta dos anexos (fora do que a web serve): UPLOAD_DIR no ambiente ou upload_dir em
 * config/conn.local.php. Padrão: <raiz>/uploads (está no .gitignore e bloqueada no .htaccess).
 */
function uploadDir(): string
{
    $dir = getenv('UPLOAD_DIR');
    if ($dir === false || $dir === '') {
        $file = __DIR__ . '/../config/conn.local.php';
        $local = is_file($file) ? (require $file) : [];
        $dir = is_array($local) ? (string)($local['upload_dir'] ?? '') : '';
    }
    return rtrim($dir !== '' ? $dir : dirname(__DIR__) . '/uploads', '/' . DIRECTORY_SEPARATOR);
}

/** "20M" / "512K" / "1G" do php.ini em bytes */
function iniBytes(string $value): int
{
    $value = trim($value);
    $n = (int)$value;
    switch (strtolower(substr($value, -1))) {
        case 'g': return $n * 1024 ** 3;
        case 'm': return $n * 1024 ** 2;
        case 'k': return $n * 1024;
        default: return $n;
    }
}
