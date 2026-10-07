<?php
/**
 * Cria um administrador (somente linha de comando).
 *
 *   php database/seed_admin.php <login> "<Nome Completo>"
 *
 * Gera uma senha temporária forte, exibida uma única vez.
 * No primeiro acesso o sistema obriga a troca de senha.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/config/conn.php';

$login = trim($argv[1] ?? '');
$name = trim($argv[2] ?? '');

if ($login === '' || $name === '') {
    fwrite(STDERR, "Uso: php database/seed_admin.php <login> \"<Nome Completo>\"\n");
    exit(1);
}

$exists = $pdo->prepare('SELECT COUNT(*) FROM ctd_users WHERE login = ?');
$exists->execute([$login]);
if ((int)$exists->fetchColumn() > 0) {
    fwrite(STDERR, "Já existe um usuário com o login '$login'.\n");
    exit(1);
}

// Sem caracteres ambíguos (0/O, 1/l/I) para facilitar a digitação
$alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
$password = '';
for ($i = 0; $i < 12; $i++) {
    $password .= $alphabet[random_int(0, strlen($alphabet) - 1)];
}

$stmt = $pdo->prepare("INSERT INTO ctd_users (name, login, password, role, status, force_reset) VALUES (?, ?, ?, 'admin', 1, 1)");
$stmt->execute([$name, $login, password_hash($password, PASSWORD_DEFAULT)]);

echo "Administrador criado.\n";
echo "  Login: $login\n";
echo "  Senha temporária: $password\n";
echo "Anote agora: ela não será exibida de novo. A troca é obrigatória no primeiro acesso.\n";
