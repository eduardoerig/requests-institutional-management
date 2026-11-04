-- 001_go_live: padroniza o banco existente para o lançamento.
-- Compatível com MySQL 5.7+. Aplicado uma única vez por database/migrate.php.

-- ---------------------------------------------------------------
-- 1. Usuários: tamanhos realistas (permite índice único em login)
-- ---------------------------------------------------------------
ALTER TABLE ctd_users
    MODIFY `name` VARCHAR(150) NOT NULL,
    MODIFY `login` VARCHAR(100) NOT NULL,
    MODIFY `password` VARCHAR(255) DEFAULT NULL,
    MODIFY `role` VARCHAR(30) NOT NULL DEFAULT 'solicitante',
    MODIFY `status` TINYINT(1) NOT NULL DEFAULT 1,
    MODIFY `force_reset` TINYINT(1) NOT NULL DEFAULT 0;

-- ---------------------------------------------------------------
-- 2. Um único charset/collation (UNION entre setores falhava com mistura)
-- ---------------------------------------------------------------
ALTER TABLE cfg_user_area CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE cfg_user_subdivision CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE ctd_area CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE ctd_requests_models CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE ctd_subdivision CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE ctd_users CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE ctd_mkt_frm CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE ctd_shop_frm CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE ctd_xerox_frm CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE ctd_service_frm CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE ctd_ti_frm CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE notifications CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE request_comments CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE request_forwards CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE request_history CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- ---------------------------------------------------------------
-- 3. Campo "urgente" uniforme: 0/1 em todos os setores
-- ---------------------------------------------------------------
ALTER TABLE ctd_mkt_frm     MODIFY urgent VARCHAR(5) NOT NULL DEFAULT '';
ALTER TABLE ctd_shop_frm    MODIFY urgent VARCHAR(5) NOT NULL DEFAULT '';
ALTER TABLE ctd_xerox_frm   MODIFY urgent VARCHAR(5) NOT NULL DEFAULT '';
ALTER TABLE ctd_service_frm MODIFY urgent VARCHAR(5) NOT NULL DEFAULT '';
ALTER TABLE ctd_ti_frm      MODIFY urgent VARCHAR(5) NOT NULL DEFAULT '';

UPDATE ctd_mkt_frm     SET urgent = IF(LOWER(urgent) IN ('y', 'on', 'sim', 's', '1'), '1', '0');
UPDATE ctd_shop_frm    SET urgent = IF(LOWER(urgent) IN ('y', 'on', 'sim', 's', '1'), '1', '0');
UPDATE ctd_xerox_frm   SET urgent = IF(LOWER(urgent) IN ('y', 'on', 'sim', 's', '1'), '1', '0');
UPDATE ctd_service_frm SET urgent = IF(LOWER(urgent) IN ('y', 'on', 'sim', 's', '1'), '1', '0');
UPDATE ctd_ti_frm      SET urgent = IF(LOWER(urgent) IN ('y', 'on', 'sim', 's', '1'), '1', '0');

ALTER TABLE ctd_mkt_frm     MODIFY urgent TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE ctd_shop_frm    MODIFY urgent TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE ctd_xerox_frm   MODIFY urgent TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE ctd_service_frm MODIFY urgent TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE ctd_ti_frm      MODIFY urgent TINYINT(1) NOT NULL DEFAULT 0;

-- Prioridade sempre preenchida (1 baixa, 2 normal, 3 alta, 4 crítica)
UPDATE ctd_mkt_frm     SET priority = 2 WHERE priority IS NULL OR priority NOT BETWEEN 1 AND 4;
UPDATE ctd_shop_frm    SET priority = 2 WHERE priority IS NULL OR priority NOT BETWEEN 1 AND 4;
UPDATE ctd_xerox_frm   SET priority = 2 WHERE priority IS NULL OR priority NOT BETWEEN 1 AND 4;
UPDATE ctd_service_frm SET priority = 2 WHERE priority IS NULL OR priority NOT BETWEEN 1 AND 4;
UPDATE ctd_ti_frm      SET priority = 2 WHERE priority IS NULL OR priority NOT BETWEEN 1 AND 4;

ALTER TABLE ctd_mkt_frm     MODIFY priority TINYINT NOT NULL DEFAULT 2 COMMENT '1 baixa, 2 normal, 3 alta, 4 crítica';
ALTER TABLE ctd_shop_frm    MODIFY priority TINYINT NOT NULL DEFAULT 2 COMMENT '1 baixa, 2 normal, 3 alta, 4 crítica';
ALTER TABLE ctd_xerox_frm   MODIFY priority TINYINT NOT NULL DEFAULT 2 COMMENT '1 baixa, 2 normal, 3 alta, 4 crítica';
ALTER TABLE ctd_service_frm MODIFY priority TINYINT NOT NULL DEFAULT 2 COMMENT '1 baixa, 2 normal, 3 alta, 4 crítica';
ALTER TABLE ctd_ti_frm      MODIFY priority TINYINT NOT NULL DEFAULT 2 COMMENT '1 baixa, 2 normal, 3 alta, 4 crítica';

-- ---------------------------------------------------------------
-- 4. Índices para listas, filtros e permissões
-- ---------------------------------------------------------------
ALTER TABLE ctd_mkt_frm     ADD INDEX idx_created_by (created_by), ADD INDEX idx_status_created (status, created_at), ADD INDEX idx_subdivision (subdivision_id);
ALTER TABLE ctd_shop_frm    ADD INDEX idx_created_by (created_by), ADD INDEX idx_status_created (status, created_at), ADD INDEX idx_subdivision (subdivision_id);
ALTER TABLE ctd_xerox_frm   ADD INDEX idx_created_by (created_by), ADD INDEX idx_status_created (status, created_at), ADD INDEX idx_subdivision (subdivision_id);
ALTER TABLE ctd_service_frm ADD INDEX idx_created_by (created_by), ADD INDEX idx_status_created (status, created_at), ADD INDEX idx_subdivision (subdivision_id);
ALTER TABLE ctd_ti_frm      ADD INDEX idx_created_by (created_by), ADD INDEX idx_status_created (status, created_at), ADD INDEX idx_subdivision (subdivision_id);

ALTER TABLE ctd_users ADD UNIQUE KEY uq_login (login), ADD INDEX idx_role_status (role, status);
ALTER TABLE cfg_user_area ADD UNIQUE KEY uq_user_area (id_user, id_area);
ALTER TABLE notifications ADD INDEX idx_user_read_created (user_id, is_read, created_at);
ALTER TABLE request_history ADD INDEX idx_created (created_at);

-- ---------------------------------------------------------------
-- 5. Proteção contra força bruta no login
-- ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS login_attempts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    login VARCHAR(100) NOT NULL,
    ip VARCHAR(45) NOT NULL,
    attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_login_ip_time (login, ip, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
