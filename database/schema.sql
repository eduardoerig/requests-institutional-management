-- Estrutura completa do SisReq (instalação nova). Inclui migrações 001 a 004.
-- Uso: php database/migrate.php --fresh
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;
CREATE TABLE `cfg_user_area` (
  `id` int NOT NULL AUTO_INCREMENT,
  `id_user` int DEFAULT NULL,
  `id_area` int DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user_area` (`id_user`,`id_area`),
  KEY `FK_cfg_user_area_ctd_users` (`id_user`),
  KEY `FK_cfg_user_area_ctd_area` (`id_area`),
  CONSTRAINT `FK_cfg_user_area_ctd_area` FOREIGN KEY (`id_area`) REFERENCES `ctd_area` (`id`),
  CONSTRAINT `FK_cfg_user_area_ctd_users` FOREIGN KEY (`id_user`) REFERENCES `ctd_users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `cfg_user_subdivision` (
  `id_user` int unsigned NOT NULL,
  `id_subdivision` int unsigned NOT NULL,
  PRIMARY KEY (`id_user`,`id_subdivision`),
  KEY `idx_user` (`id_user`),
  KEY `idx_sub` (`id_subdivision`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `ctd_area` (
  `id` int NOT NULL AUTO_INCREMENT,
  `title` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `description` varchar(160) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `ctd_mkt_frm` (
  `id` int NOT NULL AUTO_INCREMENT,
  `created_at` datetime NOT NULL,
  `created_by` int NOT NULL,
  `title` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `date` date DEFAULT NULL,
  `descp` text COLLATE utf8mb4_unicode_ci,
  `type` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `obs` text COLLATE utf8mb4_unicode_ci,
  `status` enum('Y','N','P','W','C','F') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'P',
  `table` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'mkt',
  `priority` tinyint NOT NULL DEFAULT '2' COMMENT '1 baixa, 2 normal, 3 alta, 4 crítica',
  `urgent` tinyint(1) NOT NULL DEFAULT '0',
  `subdivision_id` int DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_created_by` (`created_by`),
  KEY `idx_status_created` (`status`,`created_at`),
  KEY `idx_subdivision` (`subdivision_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `ctd_requests_models` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `form_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `descp` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `color` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` enum('Y','N') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Y',
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `ctd_service_frm` (
  `id` int NOT NULL AUTO_INCREMENT,
  `created_by` int DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `title` varchar(1000) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `date` date DEFAULT NULL,
  `descp` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `urgent` tinyint(1) NOT NULL DEFAULT '0',
  `obs` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `status` enum('Y','N','P','W','C','F') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'P',
  `table` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'service',
  `priority` tinyint NOT NULL DEFAULT '2' COMMENT '1 baixa, 2 normal, 3 alta, 4 crítica',
  `subdivision_id` int DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_created_by` (`created_by`),
  KEY `idx_status_created` (`status`,`created_at`),
  KEY `idx_subdivision` (`subdivision_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `ctd_shop_frm` (
  `id` int NOT NULL AUTO_INCREMENT,
  `created_by` int DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `title` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `date` date DEFAULT NULL,
  `descp` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `obs` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `status` enum('Y','N','P','W','C','F') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'P',
  `table` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'shop',
  `priority` tinyint NOT NULL DEFAULT '2' COMMENT '1 baixa, 2 normal, 3 alta, 4 crítica',
  `urgent` tinyint(1) NOT NULL DEFAULT '0',
  `subdivision_id` int DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_created_by` (`created_by`),
  KEY `idx_status_created` (`status`,`created_at`),
  KEY `idx_subdivision` (`subdivision_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `ctd_subdivision` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `slug` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` tinyint DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `ctd_ti_frm` (
  `id` int NOT NULL AUTO_INCREMENT,
  `created_by` int DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `title` varchar(1000) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `date` date DEFAULT NULL,
  `descp` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `urgent` tinyint(1) NOT NULL DEFAULT '0',
  `obs` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `status` enum('Y','N','P','W','C','F') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'P',
  `table` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'ti',
  `priority` tinyint NOT NULL DEFAULT '2' COMMENT '1 baixa, 2 normal, 3 alta, 4 crítica',
  `subdivision_id` int DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  KEY `idx_created_by` (`created_by`),
  KEY `idx_status_created` (`status`,`created_at`),
  KEY `idx_subdivision` (`subdivision_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `ctd_users` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `login` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `phone` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Telefone/WhatsApp (formato E.164 sem +, ex: 5511999998888)',
  `password` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `role` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'solicitante',
  `status` tinyint(1) NOT NULL DEFAULT '1',
  `force_reset` tinyint(1) NOT NULL DEFAULT '0',
  `session_version` int unsigned NOT NULL DEFAULT '0' COMMENT 'Sobe quando a senha muda: sessões antigas caem',
  `subdivision_id` int DEFAULT NULL,
  `last_login_at` datetime DEFAULT NULL COMMENT 'Último acesso (gravado no login)',
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE KEY `uq_login` (`login`),
  UNIQUE KEY `uq_phone` (`phone`),
  KEY `idx_role_status` (`role`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `ctd_xerox_frm` (
  `id` int NOT NULL AUTO_INCREMENT,
  `created_at` datetime DEFAULT NULL,
  `created_by` int DEFAULT NULL,
  `title` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `date` date DEFAULT NULL,
  `arquive` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `qtd` int DEFAULT NULL,
  `type` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `obs` varchar(2000) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` enum('Y','N','P','W','C','F') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'P',
  `table` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'xerox',
  `priority` tinyint NOT NULL DEFAULT '2' COMMENT '1 baixa, 2 normal, 3 alta, 4 crítica',
  `urgent` tinyint(1) NOT NULL DEFAULT '0',
  `subdivision_id` int DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_created_by` (`created_by`),
  KEY `idx_status_created` (`status`,`created_at`),
  KEY `idx_subdivision` (`subdivision_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `login_attempts` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `login` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `ip` varchar(45) COLLATE utf8mb4_unicode_ci NOT NULL,
  `attempted_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_login_ip_time` (`login`,`ip`,`attempted_at`),
  KEY `idx_ip_time` (`ip`,`attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `notifications` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned NOT NULL,
  `title` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `message` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `link` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_read` tinyint(1) DEFAULT '0',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_read` (`is_read`),
  KEY `idx_user_read_created` (`user_id`,`is_read`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `request_comments` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `request_id` int unsigned NOT NULL,
  `request_table` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_id` int unsigned NOT NULL,
  `user_name` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `comment` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `channel` enum('public','internal','helper') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'public' COMMENT 'public = quem pediu × responsável; internal = só setores; helper = quem pediu × setor que ajuda',
  `helper_area_id` int unsigned DEFAULT NULL COMMENT 'canal helper: ctd_area.id do setor que ajuda',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_request` (`request_id`,`request_table`),
  KEY `idx_request_channel` (`request_id`,`request_table`,`channel`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `request_forwards` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `request_id` int unsigned NOT NULL COMMENT 'ID da requisição original na tabela _frm',
  `request_table` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Setor original: mkt, shop, xerox, service, ti',
  `from_area_id` int unsigned NOT NULL COMMENT 'FK ctd_area.id — setor de origem',
  `forwarded_by` int unsigned NOT NULL COMMENT 'FK ctd_users.id — gestor que repassou',
  `to_area_id` int unsigned NOT NULL COMMENT 'FK ctd_area.id — setor de destino',
  `mode` enum('transfer','help') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'transfer',
  `is_partner` tinyint(1) NOT NULL DEFAULT '0' COMMENT '1 = setor parceiro incluído pela coordenação',
  `due_date` date DEFAULT NULL COMMENT 'Prazo da parte do setor parceiro',
  `received_by` int unsigned DEFAULT NULL COMMENT 'FK ctd_users.id — gestor que aceitou o repasse',
  `observation` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci COMMENT 'Observação do gestor sobre o motivo do repasse',
  `response_note` text COLLATE utf8mb4_unicode_ci,
  `responded_by` int unsigned DEFAULT NULL,
  `responded_at` datetime DEFAULT NULL,
  `previous_status` varchar(5) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'Y',
  `status` enum('pending','accepted','refused','completed','cancelled','returned') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_request` (`request_id`,`request_table`),
  KEY `idx_to_area` (`to_area_id`,`status`),
  KEY `idx_from_area` (`from_area_id`),
  KEY `idx_forwarded_by` (`forwarded_by`),
  KEY `idx_status` (`status`),
  KEY `idx_req_status` (`request_table`,`request_id`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `request_partner_suggestions` (
  `request_id` int unsigned NOT NULL COMMENT 'ID do pedido na tabela _frm',
  `request_table` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'mkt, shop, xerox, service, ti',
  `area_id` int unsigned NOT NULL COMMENT 'FK ctd_area.id — setor sugerido',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`request_table`,`request_id`,`area_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `request_history` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `request_id` int unsigned NOT NULL,
  `request_table` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'mkt, shop, xerox, service, ti',
  `user_id` int unsigned NOT NULL,
  `user_name` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `action` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Ex: Status alterado para Aprovado',
  `event_code` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'O que aconteceu (created, approved, started...); valores em código',
  `old_value` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `new_value` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_request` (`request_id`,`request_table`),
  KEY `idx_created` (`created_at`),
  KEY `idx_request_event` (`request_id`,`request_table`,`event_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dados de referência
INSERT INTO `ctd_area` VALUES (1,'shop','Compra de peças, materiais e serviços');
INSERT INTO `ctd_area` VALUES (2,'mkt','Artes, comunicação e redes');
INSERT INTO `ctd_area` VALUES (3,'service','Elétrica, hidráulica, móveis e prédio');
INSERT INTO `ctd_area` VALUES (4,'Reprografia','Cópias, impressões e encadernação');
INSERT INTO `ctd_area` VALUES (5,'ti','Computadores, internet, projetores e sistemas');
INSERT INTO `ctd_requests_models` VALUES (1,'Solicitação de Serviço','form_servico.php','Pedido de serviços','#ffcd00','Y','2025-11-26 10:03:22');
INSERT INTO `ctd_requests_models` VALUES (2,'Solicitação de Compra','form_compra.php','Pedido de materiais','#2ecc71','Y','2025-11-26 10:03:22');
INSERT INTO `ctd_requests_models` VALUES (3,'Solicitação de Cópia Colorida','form_color_print.php','Liberação de copias coloridas na reprografia','#00cdff','Y','2025-11-26 00:00:00');
INSERT INTO `ctd_requests_models` VALUES (4,'Abertura de Chamado TI','form_chamado_ti.php','Problemas técnicos e suporte','#e74c3c','Y','2025-11-26 15:31:13');
INSERT INTO `ctd_requests_models` VALUES (5,'Solicitação de MKT','form_mkt.php','Solicitação de artes/posts/banners','#54585a','Y','2025-11-26 00:00:00');

SET FOREIGN_KEY_CHECKS = 1;
