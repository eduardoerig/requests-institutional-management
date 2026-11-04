-- 007_sessao_e_login: versão da sessão e limite de login por IP.
-- session_version: sobe quando a senha muda (pela pessoa ou pelo admin); toda sessão com
-- versão antiga cai na próxima requisição (refreshSessionUser em includes/helpers.php).
-- idx_ip_time: o login conta tentativas erradas por IP somando todos os logins (api/login.php).

ALTER TABLE ctd_users
    ADD COLUMN session_version INT UNSIGNED NOT NULL DEFAULT 0 AFTER force_reset;

ALTER TABLE login_attempts
    ADD KEY idx_ip_time (ip, attempted_at);
