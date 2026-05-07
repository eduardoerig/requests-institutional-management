<?php
require_once __DIR__ . '/../config/conn.php';

$tables = ['ctd_mkt_frm', 'ctd_xerox_frm', 'ctd_shop_frm', 'ctd_service_frm', 'ctd_ti_frm'];

echo "=== Atualizando ENUM de Status ===\n";

foreach ($tables as $table) {
    try {
        echo "Processando $table... ";
        // Alterar para incluir W e C
        $pdo->exec("ALTER TABLE `$table` MODIFY COLUMN `status` ENUM('Y', 'N', 'P', 'W', 'C') NOT NULL DEFAULT 'P'");
        echo "OK!\n";
    } catch (Exception $e) {
        echo "ERRO: " . $e->getMessage() . "\n";
    }
}

echo "\n=== Verificando novamente ID 13 ===\n";
$pdo->exec("UPDATE ctd_mkt_frm SET status = 'W' WHERE id = 13");
$stmt = $pdo->query("SELECT status FROM ctd_mkt_frm WHERE id = 13");
echo "Novo Status ID 13: " . $stmt->fetchColumn() . "\n";
