<?php
/**
 * Migração: Adicionar campo 'phone' (WhatsApp) na tabela ctd_users.
 * Execute uma única vez acessando: /requests2.0/config/migrate_add_phone.php
 */
require_once __DIR__ . '/conn.php';
header('Content-Type: text/plain; charset=utf-8');

echo "=== Migrando: Adicionando campo 'phone' em ctd_users ===\n\n";

try {
    // Verificar se a coluna já existe
    $cols = $pdo->query("SHOW COLUMNS FROM `ctd_users` LIKE 'phone'")->fetchAll(PDO::FETCH_ASSOC);
    if (!empty($cols)) {
        echo "Coluna 'phone' já existe. Nenhuma alteração necessária.\n";
    } else {
        $pdo->exec("ALTER TABLE `ctd_users` ADD COLUMN `phone` VARCHAR(20) NULL DEFAULT NULL COMMENT 'Telefone/WhatsApp (formato E.164 sem +, ex: 5511999998888)' AFTER `login`");
        echo "Coluna 'phone' adicionada com sucesso.\n";
    }

    // Verificar se o índice UNIQUE já existe
    $idx = $pdo->query("SHOW INDEX FROM `ctd_users` WHERE Key_name = 'uq_phone'")->fetchAll();
    if (!empty($idx)) {
        echo "Índice UNIQUE em 'phone' já existe.\n";
    } else {
        $pdo->exec("ALTER TABLE `ctd_users` ADD UNIQUE INDEX `uq_phone` (`phone`)");
        echo "Índice UNIQUE criado em 'phone'.\n";
    }

    echo "\nMigração concluída com sucesso!\n";
} catch (Exception $e) {
    echo "ERRO: " . $e->getMessage() . "\n";
}
