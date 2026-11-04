<?php
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/conn.php';
require_once __DIR__ . '/../config/security.php';

header('Content-Type: application/json; charset=UTF-8');

if (($_POST['action'] ?? '') !== 'get') {
    jsonResponse(['success' => false, 'message' => 'Requisição inválida.']);
}

requirePostJson();

$login = trim((string)($_POST['login'] ?? ''));
// Toda senha é gravada sem espaços nas pontas (update_user_admin/create_user): compara do mesmo jeito
$password = trim((string)($_POST['password'] ?? ''));
$ip = clientIp();

if ($login === '' || $password === '') {
    jsonResponse(['success' => false, 'message' => 'Preencha usuário e senha.']);
}

const LOGIN_MAX_ATTEMPTS = 5;        // mesmo login, mesmo IP
const LOGIN_MAX_PER_IP = 20;         // um IP, somando todos os logins (senha comum testada em muitas contas)
const LOGIN_MAX_PER_LOGIN = 10;      // um login, somando todos os IPs (ataque distribuído)
const LOGIN_WINDOW_MINUTES = 15;

/**
 * Tentativas erradas recentes: por login+IP, só por IP ou só por login (null = não filtra).
 * Se a tabela ainda não existir, o limite é ignorado (não bloqueia o acesso).
 */
function loginAttemptsCount(PDO $pdo, ?string $login, ?string $ip): int
{
    $where = ['attempted_at > (NOW() - INTERVAL ' . LOGIN_WINDOW_MINUTES . ' MINUTE)'];
    $params = [];
    if ($login !== null) { $where[] = 'login = ?'; $params[] = $login; }
    if ($ip !== null) { $where[] = 'ip = ?'; $params[] = $ip; }
    try {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM login_attempts WHERE ' . implode(' AND ', $where));
        $stmt->execute($params);
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

if (loginAttemptsCount($pdo, $login, $ip) >= LOGIN_MAX_ATTEMPTS
    || loginAttemptsCount($pdo, null, $ip) >= LOGIN_MAX_PER_IP
    || loginAttemptsCount($pdo, $login, null) >= LOGIN_MAX_PER_LOGIN) {
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

// Último acesso (tela Usuários e setores). A coluna vem da migração 003; sem ela, o login segue normalmente.
try {
    $pdo->prepare('UPDATE ctd_users SET last_login_at = NOW() WHERE id = ?')->execute([(int)$user['id']]);
} catch (Throwable $e) {
    // coluna ainda não criada: ignora
}

// Nova identidade de sessão após autenticar (evita fixação de sessão)
session_regenerate_id(true);

$_SESSION['id'] = $user['id'];
$_SESSION['name'] = $user['name'];
$_SESSION['login'] = $user['login'];
$_SESSION['role'] = $user['role'];
$_SESSION['force_reset'] = (int)($user['force_reset'] ?? 0);
// Trocar a senha sobe a versão no banco e derruba as sessões com a versão antiga (refreshSessionUser)
$_SESSION['session_version'] = (int)($user['session_version'] ?? 0);
loadSessionSubdivisions($pdo, (int)$user['id']);

// Novo token CSRF para a sessão autenticada
unset($_SESSION['csrf_token']);
$csrf = ensureCsrfToken();

// csrf_token e user_id: a janela "Entrar de novo" (script.js) continua na mesma página com o token novo
jsonResponse(['success' => true, 'message' => 'Login realizado com sucesso!', 'csrf_token' => $csrf, 'user_id' => (int)$user['id']]);
