<?php
/**
 * Script de Migração — Fase 3
 * Executa UMA vez para criar as tabelas de comentários e histórico.
 * Após executar, REMOVA ou RESTRINJA o acesso a este arquivo.
 */
require_once 'conn.php';

$sql = "
-- Tabela de Histórico de Requisições
CREATE TABLE IF NOT EXISTS `request_history` (
  `id`           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `request_id`   INT UNSIGNED NOT NULL,
  `request_table` VARCHAR(20) NOT NULL COMMENT 'mkt, shop, xerox, service, ti',
  `user_id`      INT UNSIGNED NOT NULL,
  `user_name`    VARCHAR(150) NOT NULL,
  `action`       VARCHAR(100) NOT NULL COMMENT 'Ex: Status alterado para Aprovado',
  `old_value`    VARCHAR(100) NULL,
  `new_value`    VARCHAR(100) NULL,
  `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_request (`request_id`, `request_table`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabela de Comentários Internos
CREATE TABLE IF NOT EXISTS `request_comments` (
  `id`           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `request_id`   INT UNSIGNED NOT NULL,
  `request_table` VARCHAR(20) NOT NULL,
  `user_id`      INT UNSIGNED NOT NULL,
  `user_name`    VARCHAR(150) NOT NULL,
  `comment`      TEXT NOT NULL,
  `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_request (`request_id`, `request_table`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
";

try {
    $pdo->exec($sql);
    echo '<div style="font-family:sans-serif;padding:40px;background:#f0fdf4;color:#166534;border:1px solid #86efac;border-radius:8px;max-width:600px;margin:40px auto;">';
    echo '<h2>✅ Tabelas criadas com sucesso!</h2>';
    echo '<ul><li><strong>request_history</strong> — criada</li>';
    echo '<li><strong>request_comments</strong> — criada</li></ul>';
    echo '<p style="color:#6b7280;margin-top:20px;"><strong>Importante:</strong> Remova ou bloqueie este arquivo agora.</p>';
    echo '</div>';
} catch (PDOException $e) {
    echo '<div style="font-family:sans-serif;padding:40px;background:#fef2f2;color:#991b1b;border:1px solid #fca5a5;border-radius:8px;max-width:600px;margin:40px auto;">';
    echo '<h2>❌ Erro ao criar tabelas</h2>';
    echo '<p>' . htmlspecialchars($e->getMessage()) . '</p>';
    echo '</div>';
}
