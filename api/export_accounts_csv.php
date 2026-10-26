<?php
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/conn.php';
require_once __DIR__ . '/../config/security.php';
requireLoginJson($pdo);

if (!isGlobalAdminRole($_SESSION['role'] ?? '')) {
    die('Acesso negado.');
}

// "Último acesso" só existe depois da migração 003
$hasLastLogin = false;
try {
    $hasLastLogin = (bool)$pdo->query("SHOW COLUMNS FROM ctd_users LIKE 'last_login_at'")->fetch();
} catch (PDOException $e) {
    $hasLastLogin = false;
}

$filename = "usuarios_sisreq_" . date('Ymd_His') . ".csv";
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename=' . $filename);

$output = fopen('php://output', 'w');
fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF)); // UTF-8 BOM para Excel

$header = ['ID', 'Nome', 'Login', 'Perfil', 'Setor', 'Status'];
if ($hasLastLogin) {
    $header[] = 'Último acesso';
}
fputcsv($output, $header);

$query = "SELECT u.id, u.name, u.login, u.role, u.status" . ($hasLastLogin ? ", u.last_login_at" : "") . ",
                 GROUP_CONCAT(DISTINCT a.title ORDER BY a.title SEPARATOR ', ') AS sector_title
          FROM ctd_users u
          LEFT JOIN cfg_user_area cua ON u.id = cua.id_user
          LEFT JOIN ctd_area a ON cua.id_area = a.id
          GROUP BY u.id
          ORDER BY u.name ASC";

$stmt = $pdo->query($query);
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $status = ($row['status'] ?? 1) == 1 ? 'Ativo' : 'Inativo';
    $line = [
        $row['id'],
        $row['name'],
        $row['login'],
        $row['role'],
        $row['sector_title'] ?? 'Nenhum',
        $status
    ];
    if ($hasLastLogin) {
        $line[] = !empty($row['last_login_at']) ? date('d/m/Y H:i', strtotime((string)$row['last_login_at'])) : 'Nunca entrou';
    }
    fputcsv($output, array_map('csvSafe', $line));
}

fclose($output);
exit;
