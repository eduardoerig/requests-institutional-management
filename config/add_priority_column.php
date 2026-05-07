<?php
require_once 'conn.php';

$tables = ['ctd_mkt_frm', 'ctd_shop_frm', 'ctd_xerox_frm', 'ctd_service_frm', 'ctd_ti_frm'];

foreach ($tables as $table) {
    try {
        // Tentar adicionar a coluna priority
        $pdo->exec("ALTER TABLE `$table` ADD `priority` TINYINT DEFAULT 2 COMMENT '1:Baixa, 2:Média, 3:Alta, 4:Crítica'");
        echo "✅ Coluna priority adicionada em $table\n";
    } catch (PDOException $e) {
        if ($e->getCode() == '42S21') {
            echo "ℹ️ Coluna priority já existe em $table\n";
        } else {
            echo "❌ Erro em $table: " . $e->getMessage() . "\n";
        }
    }
}
