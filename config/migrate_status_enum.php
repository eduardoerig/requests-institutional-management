<?php
/**
 * Migração: Adicionar 'F' (Repassada) ao ENUM status em todas as tabelas _frm.
 * Execute apenas uma vez.
 */
require_once __DIR__ . '/conn.php';
header('Content-Type: text/plain; charset=utf-8');

$tables = $pdo->query("SHOW TABLES LIKE 'ctd_%_frm'")->fetchAll(PDO::FETCH_COLUMN);

echo "=== Migrando ENUM status para incluir 'F' ===\n\n";

foreach ($tables as $table) {
    try {
        // Verificar tipo atual da coluna status
        $cols = $pdo->query("SHOW COLUMNS FROM `$table` LIKE 'status'")->fetchAll(PDO::FETCH_ASSOC);
        if (empty($cols)) {
            echo "[$table] Coluna 'status' não encontrada. Pulando.\n";
            continue;
        }
        
        $currentType = $cols[0]['Type'];
        echo "[$table] Tipo atual: $currentType\n";
        
        // Verificar se 'F' já está no enum
        if (strpos($currentType, "'F'") !== false) {
            echo "  -> Já contém 'F'. Pulando.\n";
            continue;
        }
        
        // Alterar o ENUM para incluir 'F'
        $pdo->exec("ALTER TABLE `$table` MODIFY COLUMN `status` ENUM('Y','N','P','W','C','F') NOT NULL DEFAULT 'P'");
        echo "  -> ✅ Alterado com sucesso para ENUM('Y','N','P','W','C','F')\n";
        
    } catch (Exception $e) {
        echo "  -> ❌ ERRO: " . $e->getMessage() . "\n";
    }
}

// Agora corrigir as requisições que ficaram com status vazio por causa dos repasses
echo "\n=== Corrigindo requisições com status vazio que possuem forwards pendentes ===\n\n";
try {
    $forwards = $pdo->query("SELECT request_id, request_table, status FROM request_forwards WHERE status = 'pending'")->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($forwards as $fwd) {
        $fullTable = "ctd_{$fwd['request_table']}_frm";
        $stmt = $pdo->prepare("UPDATE `$fullTable` SET status = 'F' WHERE id = ? AND (status = '' OR status IS NULL OR status = 'P')");
        $stmt->execute([$fwd['request_id']]);
        $affected = $stmt->rowCount();
        echo "  [{$fullTable} #{$fwd['request_id']}] -> status='F' (rows affected: {$affected})\n";
    }
    
    echo "\n✅ Migração concluída com sucesso!\n";
} catch (Exception $e) {
    echo "\n❌ Erro ao corrigir: " . $e->getMessage() . "\n";
}
