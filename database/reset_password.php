<?php
/**
 * Gera uma senha temporária para um usuário que perdeu o acesso (somente linha de comando).
 *
 *   php database/reset_password.php <login>
 *
 * A senha aparece uma única vez. No próximo acesso o sistema obriga a troca.
 * Também libera tentativas de login bloqueadas desse usuário.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/config/conn.php';

$login = trim($argv[1] ?? '');
if ($login === '') {
    fwrite(STDERR, "Uso: php database/reset_password.php <login>\n");
    exit(1);
}

$stmt = $pdo->prepare('SELECT id, name, role, status FROM ctd_users WHERE login = ?');
$stmt->execute([$login]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$user) {
    fwrite(STDERR, "Nenhum usuário com o login '$login'.\n");
    exit(1);
}

// Sem caracteres ambíguos (0/O, 1/l/I) para facilitar a digitação
$alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
$password = '';
for ($i = 0; $i < 12; $i++) {
    $password .= $alphabet[random_int(0, strlen($alphabet) - 1)];
}

$pdo->prepare('UPDATE ctd_users SET password = ?, force_reset = 1, status = 1 WHERE id = ?')
    ->execute([password_hash($password, PASSWORD_DEFAULT), $user['id']]);
try {
    $pdo->prepare('DELETE FROM login_attempts WHERE login = ?')->execute([$login]);
} catch (Exception $e) {
    // tabela opcional
}

echo "Senha temporária gerada para {$user['name']} ({$user['role']}).\n";
echo "  Login: $login\n";
echo "  Senha temporária: $password\n";
echo "Anote agora: ela não será exibida de novo. A troca é obrigatória no próximo acesso.\n";
