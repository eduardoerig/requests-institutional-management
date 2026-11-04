<?php
/**
 * Cria/atualiza contas de TESTE (uma por perfil) — somente linha de comando e
 * somente para bancos de homologação. Credenciais gravadas em
 * database/test_accounts.local.txt (ignorado pelo git).
 *
 *   php database/seed_test_users.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/config/conn.php';
require dirname(__DIR__) . '/config/security.php';

$subdivisionId = (int)$pdo->query('SELECT id FROM ctd_subdivision ORDER BY id LIMIT 1')->fetchColumn();
if (!$subdivisionId) {
    fwrite(STDERR, "Cadastre ao menos uma subdivisão antes.\n");
    exit(1);
}

$accounts = [
    ['teste_solicitante', 'Teste Solicitante', 'solicitante'],
    ['teste_aprovador', 'Teste Aprovador Subdivisão', 'adm_sub'],
    ['teste_gestor_ti', 'Teste Gestor TI', 'ti'],
    ['teste_gestor_compras', 'Teste Gestor Compras', 'shop'],
    ['teste_admin', 'Teste Administrador', 'admin'],
];

$password = 'Teste@' . random_int(100000, 999999);
$hash = password_hash($password, PASSWORD_DEFAULT);
$lines = ["Senha de todas as contas de teste: $password", ''];

foreach ($accounts as [$login, $name, $role]) {
    $stmt = $pdo->prepare('SELECT id FROM ctd_users WHERE login = ?');
    $stmt->execute([$login]);
    $id = (int)$stmt->fetchColumn();

    if ($id) {
        $pdo->prepare('UPDATE ctd_users SET name = ?, password = ?, role = ?, status = 1, force_reset = 0, subdivision_id = ? WHERE id = ?')
            ->execute([$name, $hash, $role, $subdivisionId, $id]);
    } else {
        $pdo->prepare('INSERT INTO ctd_users (name, login, password, role, status, force_reset, subdivision_id) VALUES (?, ?, ?, ?, 1, 0, ?)')
            ->execute([$name, $login, $hash, $role, $subdivisionId]);
        $id = (int)$pdo->lastInsertId();
    }

    $pdo->prepare('INSERT IGNORE INTO cfg_user_subdivision (id_user, id_subdivision) VALUES (?, ?)')->execute([$id, $subdivisionId]);
    ensureUserAreaForRole($pdo, $id, $role);
    $lines[] = sprintf('%-22s %s', $login, $role);
}

file_put_contents(__DIR__ . '/test_accounts.local.txt', implode(PHP_EOL, $lines) . PHP_EOL);
echo "Contas de teste prontas. Credenciais em database/test_accounts.local.txt\n";
