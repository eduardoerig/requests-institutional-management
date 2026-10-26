-- Último acesso de cada pessoa (tela Usuários e setores: "hoje", "nunca entrou")
ALTER TABLE ctd_users
    ADD COLUMN last_login_at DATETIME NULL DEFAULT NULL COMMENT 'Último acesso (gravado no login)' AFTER subdivision_id;
