<?php
/**
 * Executor de migrações (somente linha de comando).
 *
 *   php database/migrate.php            aplica migrações pendentes em database/migrations/
 *   php database/migrate.php --status   lista migrações aplicadas e pendentes
 *   php database/migrate.php --fresh    banco VAZIO: cria tudo via database/schema.sql
 *                                       e marca as migrações existentes como aplicadas
 *
 * Credenciais: variáveis DB_HOST/DB_NAME/DB_USER/DB_PASS ou config/conn.local.php.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/config/conn.php';

$pdo->exec("SET time_zone = '-03:00'");
$pdo->exec("
    CREATE TABLE IF NOT EXISTS schema_migrations (
        name VARCHAR(190) NOT NULL PRIMARY KEY,
        applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

$files = glob(__DIR__ . '/migrations/*.sql');
sort($files, SORT_STRING);
$applied = $pdo->query('SELECT name FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);
$pending = array_values(array_filter($files, fn($f) => !in_array(basename($f), $applied, true)));

/**
 * Divide um arquivo SQL em instruções (separador: ';' no fim da linha). Ignora comentários "--".
 */
function splitSql(string $sql): array
{
    $lines = array_filter(
        preg_split('/\R/', $sql),
        fn($line) => !preg_match('/^\s*--/', $line)
    );
    $statements = preg_split('/;\s*(\R|$)/', implode("\n", $lines));
    return array_values(array_filter(array_map('trim', $statements), fn($s) => $s !== ''));
}

function runFile(PDO $pdo, string $file): void
{
    $statements = splitSql(file_get_contents($file));
    foreach ($statements as $i => $statement) {
        try {
            $pdo->exec($statement);
        } catch (PDOException $e) {
            fwrite(STDERR, sprintf(
                "\nFALHOU em %s, instrução %d/%d:\n%s\nErro: %s\n",
                basename($file), $i + 1, count($statements), $statement, $e->getMessage()
            ));
            exit(1);
        }
    }
}

$mode = $argv[1] ?? '';

if ($mode === '--status') {
    foreach ($files as $f) {
        printf("[%s] %s\n", in_array(basename($f), $applied, true) ? 'x' : ' ', basename($f));
    }
    exit(0);
}

if ($mode === '--fresh') {
    $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    if (array_diff($tables, ['schema_migrations'])) {
        fwrite(STDERR, "Banco não está vazio. --fresh só pode ser usado em banco novo.\n");
        exit(1);
    }
    echo "Criando estrutura a partir de schema.sql...\n";
    runFile($pdo, __DIR__ . '/schema.sql');
    $mark = $pdo->prepare('INSERT IGNORE INTO schema_migrations (name) VALUES (?)');
    foreach ($files as $f) {
        $mark->execute([basename($f)]);
    }
    echo "OK. Agora crie o primeiro administrador: php database/seed_admin.php\n";
    exit(0);
}

if (!$pending) {
    echo "Nenhuma migração pendente.\n";
    exit(0);
}

foreach ($pending as $file) {
    echo 'Aplicando ' . basename($file) . '... ';
    runFile($pdo, $file);
    $pdo->prepare('INSERT INTO schema_migrations (name) VALUES (?)')->execute([basename($file)]);
    echo "ok\n";
}
echo "Concluído.\n";
