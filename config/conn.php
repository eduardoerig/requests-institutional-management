<?php
ini_set('display_errors', '0');
ini_set('log_errors', '1');

$host = getenv('DB_HOST') ?: '';
$dbname = getenv('DB_NAME') ?: '';
$user = getenv('DB_USER') ?: '';
$pass = getenv('DB_PASS') ?: '';
// Conexão persistente: reaproveita a conexão entre requisições (útil com banco remoto, cada conexão nova
// custa várias idas e voltas). Ligar só se o banco aceitar uma conexão aberta por processo do PHP.
$persistent = filter_var(getenv('DB_PERSISTENT') ?: '0', FILTER_VALIDATE_BOOLEAN);

if ($host === '' || $dbname === '' || $user === '') {
    $localConfig = __DIR__ . '/conn.local.php';
    if (is_file($localConfig)) {
        $local = require $localConfig;
        if (is_array($local)) {
            $host = $local['host'] ?? $host;
            $dbname = $local['dbname'] ?? $dbname;
            $user = $local['user'] ?? $user;
            $pass = $local['pass'] ?? $pass;
            $persistent = (bool)($local['persistent'] ?? $persistent);
        }
    }
}

try {
    if ($host === '' || $dbname === '' || $user === '') {
        throw new RuntimeException('Credenciais de banco nao configuradas no ambiente.');
    }

    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $user, $pass, $persistent ? [PDO::ATTR_PERSISTENT => true] : []);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    // Horário de Brasília em PHP e MySQL (prazos e "atrasadas" consistentes)
    date_default_timezone_set('America/Sao_Paulo');
    $pdo->exec("SET time_zone = '-03:00'");
} catch (PDOException $e) {
    error_log('Erro de conexao com banco: ' . $e->getMessage());
    http_response_code(500);
    echo 'Erro interno.';
    exit();
} catch (Throwable $e) {
    error_log('Configuracao de banco invalida: ' . $e->getMessage());
    http_response_code(500);
    echo 'Erro interno.';
    exit();
}
