<?php
/**
 * Script de Migração — Fase 5 (Repasse de Requisições)
 * Cria a tabela `request_forwards` para rastrear repasses entre setores.
 * Executa UMA vez. Após executar, REMOVA ou RESTRINJA o acesso a este arquivo.
 */
require_once 'conn.php';

$sql = "
-- Tabela de Repasses de Requisições
CREATE TABLE IF NOT EXISTS `request_forwards` (
  `id`             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `request_id`     INT UNSIGNED NOT NULL COMMENT 'ID da requisição original na tabela _frm',
  `request_table`  VARCHAR(20)  NOT NULL COMMENT 'Setor original: mkt, shop, xerox, service, ti',
  `from_area_id`   INT UNSIGNED NOT NULL COMMENT 'FK ctd_area.id — setor de origem',
  `forwarded_by`   INT UNSIGNED NOT NULL COMMENT 'FK ctd_users.id — gestor que repassou',
  `to_area_id`     INT UNSIGNED NOT NULL COMMENT 'FK ctd_area.id — setor de destino',
  `received_by`    INT UNSIGNED NULL     COMMENT 'FK ctd_users.id — gestor que aceitou o repasse',
  `observation`    TEXT         NULL     COMMENT 'Observação do gestor sobre o motivo do repasse',
  `status`         ENUM('pending','accepted','refused','completed') NOT NULL DEFAULT 'pending',
  `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  
  INDEX idx_request       (`request_id`, `request_table`),
  INDEX idx_to_area       (`to_area_id`, `status`),
  INDEX idx_from_area     (`from_area_id`),
  INDEX idx_forwarded_by  (`forwarded_by`),
  INDEX idx_status        (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
";

try {
    $pdo->exec($sql);
    echo '<div style="font-family:sans-serif;padding:40px;background:#f0fdf4;color:#166534;border:1px solid #86efac;border-radius:8px;max-width:600px;margin:40px auto;">';
    echo '<h2>✅ Fase 5 — Tabela de Repasses criada com sucesso!</h2>';
    echo '<ul><li><strong>request_forwards</strong> — criada</li></ul>';
    echo '<p style="color:#6b7280;margin-top:20px;"><strong>Importante:</strong> Remova ou bloqueie este arquivo após a execução.</p>';
    echo '</div>';
} catch (PDOException $e) {
    echo '<div style="font-family:sans-serif;padding:40px;background:#fef2f2;color:#991b1b;border:1px solid #fca5a5;border-radius:8px;max-width:600px;margin:40px auto;">';
    echo '<h2>❌ Erro ao criar tabela</h2>';
    echo '<p>' . htmlspecialchars($e->getMessage()) . '</p>';
    echo '</div>';
}
