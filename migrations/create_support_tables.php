<?php
/**
 * Script de migração: Cria tabelas de suporte para o novo fluxo de requisições.
 * Execute este script UMA VEZ para criar as tabelas necessárias.
 * 
 * Uso: php migrations/create_support_tables.php
 *      ou acessar via browser: /requests2.0/migrations/create_support_tables.php
 */

require_once __DIR__ . '/../config/conn.php';

echo "<pre>\n";
echo "=== Migração: Criando tabelas de suporte ===\n\n";

try {
    // 1. Tabela de Notificações
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS notifications (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            title VARCHAR(255) NOT NULL,
            message TEXT NOT NULL,
            link VARCHAR(500) DEFAULT NULL,
            is_read TINYINT(1) DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user_read (user_id, is_read),
            INDEX idx_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "✅ Tabela 'notifications' criada/verificada com sucesso.\n";

    // 2. Tabela de Histórico de Requisições
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS request_history (
            id INT AUTO_INCREMENT PRIMARY KEY,
            request_id INT NOT NULL,
            request_table VARCHAR(100) NOT NULL,
            user_id INT NOT NULL,
            user_name VARCHAR(255) NOT NULL,
            action VARCHAR(255) NOT NULL,
            old_value VARCHAR(255) DEFAULT NULL,
            new_value VARCHAR(255) DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_request (request_id, request_table),
            INDEX idx_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "✅ Tabela 'request_history' criada/verificada com sucesso.\n";

    // 3. Verificar se a coluna 'priority' existe nas tabelas de requisição
    $tables = $pdo->query("SHOW TABLES LIKE 'ctd_%_frm'")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($tables as $table) {
        // Verificar/adicionar coluna 'priority'
        $check = $pdo->query("SHOW COLUMNS FROM `$table` LIKE 'priority'")->fetch();
        if (!$check) {
            $pdo->exec("ALTER TABLE `$table` ADD COLUMN priority TINYINT DEFAULT 2");
            echo "✅ Coluna 'priority' adicionada à tabela '$table'.\n";
        } else {
            echo "ℹ️  Coluna 'priority' já existe em '$table'.\n";
        }

        // Verificar/adicionar coluna 'urgent'
        $check2 = $pdo->query("SHOW COLUMNS FROM `$table` LIKE 'urgent'")->fetch();
        if (!$check2) {
            $pdo->exec("ALTER TABLE `$table` ADD COLUMN urgent VARCHAR(1) DEFAULT ''");
            echo "✅ Coluna 'urgent' adicionada à tabela '$table'.\n";
        } else {
            echo "ℹ️  Coluna 'urgent' já existe em '$table'.\n";
        }
    }

    echo "\n=== Migração concluída com sucesso! ===\n";

} catch (PDOException $e) {
    echo "❌ Erro durante a migração: " . $e->getMessage() . "\n";
}

echo "</pre>";
