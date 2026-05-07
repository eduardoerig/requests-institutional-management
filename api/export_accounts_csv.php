<?php
session_start();
require_once __DIR__ . '/../config/conn.php';

if (!in_array($_SESSION['role'], ['admin', 'adm'])) {
    die('Acesso negado.');
}

$filename = "usuarios_martin_luther_" . date('Ymd_His') . ".csv";
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename=' . $filename);

$output = fopen('php://output', 'w');
fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF)); // UTF-8 BOM para Excel

fputcsv($output, ['ID', 'Nome', 'Login', 'Perfil', 'Setor', 'Status']);

$query = "SELECT u.id, u.name, u.login, u.role, u.status, a.title as sector_title 
          FROM ctd_users u 
          LEFT JOIN cfg_user_area cua ON u.id = cua.id_user 
          LEFT JOIN ctd_area a ON cua.id_area = a.id 
          ORDER BY u.name ASC";

$stmt = $pdo->query($query);
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $status = ($row['status'] ?? 1) == 1 ? 'Ativo' : 'Inativo';
    fputcsv($output, [
        $row['id'],
        $row['name'],
        $row['login'],
        $row['role'],
        $row['sector_title'] ?? 'Nenhum',
        $status
    ]);
}

fclose($output);
exit;
