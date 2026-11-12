-- 014_itens_do_pedido: lista de itens com link (Compras) e links de referência (Marketing).
-- O pedido é o par (request_table, request_id), como nas outras tabelas de apoio.

CREATE TABLE request_items (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    request_id INT UNSIGNED NOT NULL COMMENT 'ID do pedido na tabela _frm',
    request_table VARCHAR(20) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'mkt, shop, xerox, service, ti',
    position TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Ordem em que a pessoa listou',
    name VARCHAR(255) COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL COMMENT 'Item (Compras) ou sobre o link (Marketing)',
    qty VARCHAR(50) COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL COMMENT 'Quantidade em texto livre (ex.: 2 caixas)',
    url VARCHAR(1000) COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL COMMENT 'Link http(s)',
    PRIMARY KEY (id),
    KEY idx_request (request_table, request_id, position)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
