<?php
/**
 * Script de Migração — Fase 4 (Versão Compatível)
 * Ajustado para evitar erro em versões antigas do MySQL.
 */
require_once 'conn.php';

try {
    // 1. Tentar adicionar a coluna 'role'
    // Usamos um bloco try/catch separado pois o MySQL < 8.0 não aceita 'IF NOT EXISTS' em ALTER TABLE
    try {
        $pdo->exec("ALTER TABLE `ctd_users` ADD `role` VARCHAR(20) DEFAULT 'solicitante' COMMENT 'admin, gestor, solicitante'");
    } catch (PDOException $e) {
        // Se o erro for que a coluna já existe, apenas ignoramos (Código 42S21 no MySQL)
    }

    // 2. Criar tabela de notificações
    $sql = "
    CREATE TABLE IF NOT EXISTS `notifications` (
      `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      `user_id` INT UNSIGNED NOT NULL,
      `title` VARCHAR(150) NOT NULL,
      `message` TEXT NOT NULL,
      `link` VARCHAR(255) NULL,
      `is_read` TINYINT(1) DEFAULT 0,
      `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      INDEX idx_user (`user_id`),
      INDEX idx_read (`is_read`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

    -- Atualizar usuários existentes para administradores (apenas para teste inicial)
    UPDATE `ctd_users` SET `role` = 'admin' WHERE `role` = 'solicitante' LIMIT 5;
    ";

    $pdo->exec($sql);
    echo "✅ Fase 4: Banco de Dados atualizado com sucesso (versão compatível)!";
    
} catch (PDOException $e) {
    echo "❌ Erro: " . $e->getMessage();
}
