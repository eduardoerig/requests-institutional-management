-- 015_anexos: arquivos anexados ao pedido (sempre opcionais). O arquivo fica fora da web
-- (uploadDir(), padrão <raiz>/uploads, bloqueado no .htaccess) e só sai por api/attachment.php.

CREATE TABLE request_attachments (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    request_id INT UNSIGNED NOT NULL COMMENT 'ID do pedido na tabela _frm',
    request_table VARCHAR(20) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'mkt, shop, xerox, service, ti',
    original_name VARCHAR(255) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Nome que a pessoa enviou (só para mostrar)',
    stored_name VARCHAR(100) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'AAAA/MM/<aleatório>.<ext> dentro de uploadDir()',
    mime VARCHAR(100) COLLATE utf8mb4_unicode_ci NOT NULL,
    size_bytes INT UNSIGNED NOT NULL,
    uploaded_by INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_request (request_table, request_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
