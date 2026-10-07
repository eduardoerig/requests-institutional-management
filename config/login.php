<?php
require_once __DIR__ . '/session.php';
require_once __DIR__ . '/conn.php';
require_once __DIR__ . '/security.php';

header('Content-Type: application/json; charset=UTF-8');

if (($_POST['action'] ?? '') !== 'get') {
    jsonResponse(['success' => false, 'message' => 'Requisição inválida.']);
}

requirePostJson();

$login = trim((string)($_POST['login'] ?? ''));
$password = (string)($_POST['password'] ?? '');
$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

if ($login === '' || $password === '') {
    jsonResponse(['success' => false, 'message' => 'Preencha usuário e senha.']);
}

const LOGIN_MAX_ATTEMPTS = 5;
const LOGIN_WINDOW_MINUTES = 15;

/**
 * Limite de tentativas por login+IP. Se a tabela ainda não existir, o limite é ignorado
 * (não bloqueia o acesso), mas o restante do login segue normalmente.
 */
function loginAttemptsCount(PDO $pdo, string $login, string $ip): int
{
    try {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM login_attempts WHERE login = ? AND ip = ? AND attempted_at > (NOW() - INTERVAL ' . LOGIN_WINDOW_MINUTES . ' MINUTE)');
        $stmt->execute([$login, $ip]);
        return (int)$stmt->fetchColumn();
    } catch (PDOException $e) {
        return 0;
    }
}

function registerLoginFailure(PDO $pdo, string $login, string $ip): void
{
    try {
        $pdo->prepare('INSERT INTO login_attempts (login, ip) VALUES (?, ?)')->execute([$login, $ip]);
    } catch (PDOException $e) {
        error_log('login_attempts indisponível: ' . $e->getMessage());
    }
}

function clearLoginFailures(PDO $pdo, string $login, string $ip): void
{
    try {
        $pdo->prepare('DELETE FROM login_attempts WHERE login = ? AND ip = ?')->execute([$login, $ip]);
    } catch (PDOException $e) {
        // tabela ausente: nada a limpar
    }
}

if (loginAttemptsCount($pdo, $login, $ip) >= LOGIN_MAX_ATTEMPTS) {
    jsonResponse(['success' => false, 'message' => 'Muitas tentativas erradas. Aguarde ' . LOGIN_WINDOW_MINUTES . ' minutos e tente de novo.'], 429);
}

$stmt = $pdo->prepare('SELECT * FROM ctd_users WHERE `login` = ?');
$stmt->execute([$login]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

// Mensagem única para usuário inexistente ou senha errada (não revela quais logins existem)
if (!$user || !password_verify($password, $user['password'])) {
    registerLoginFailure($pdo, $login, $ip);
    jsonResponse(['success' => false, 'message' => 'Usuário ou senha incorretos.']);
}

if ((int)($user['status'] ?? 1) !== 1) {
    jsonResponse(['success' => false, 'message' => 'Sua conta está inativa. Fale com o administrador.']);
}

clearLoginFailures($pdo, $login, $ip);

// Nova identidade de sessão após autenticar (evita fixação de sessão)
session_regenerate_id(true);

$_SESSION['id'] = $user['id'];
$_SESSION['name'] = $user['name'];
$_SESSION['role'] = $user['role'];
$_SESSION['force_reset'] = (int)($user['force_reset'] ?? 0);
$_SESSION['checked_at'] = time();

// Carregar subdivisões do usuário (Múltiplas)
$_SESSION['subdivision_ids'] = [];
$_SESSION['subdivision_names'] = [];

$subStmt = $pdo->prepare("
    SELECT s.id, s.name
    FROM ctd_subdivision s
    JOIN cfg_user_subdivision cus ON s.id = cus.id_subdivision
    WHERE cus.id_user = ?
");
$subStmt->execute([$user['id']]);

foreach ($subStmt->fetchAll(PDO::FETCH_ASSOC) as $s) {
    $_SESSION['subdivision_ids'][] = $s['id'];
    $_SESSION['subdivision_names'][] = $s['name'];
}

// Manter campos antigos para compatibilidade
$_SESSION['subdivision_id'] = $_SESSION['subdivision_ids'][0] ?? null;
$_SESSION['subdivision_name'] = $_SESSION['subdivision_names'][0] ?? null;

// Novo token CSRF para a sessão autenticada
unset($_SESSION['csrf_token']);
ensureCsrfToken();

jsonResponse(['success' => true, 'message' => 'Login realizado com sucesso!']);
