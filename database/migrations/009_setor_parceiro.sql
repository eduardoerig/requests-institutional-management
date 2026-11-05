-- 009_setor_parceiro: pedido com setor principal + setor parceiro.
-- O parceiro é uma ajuda (mode = 'help') incluída pela coordenação, com prazo próprio.
-- A sugestão de quem pediu fica à parte até a decisão da coordenação: antes disso o setor não vê o pedido.

ALTER TABLE request_forwards
    ADD COLUMN is_partner TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = setor parceiro incluído pela coordenação' AFTER mode,
    ADD COLUMN due_date DATE NULL DEFAULT NULL COMMENT 'Prazo da parte do setor parceiro' AFTER is_partner;

CREATE TABLE request_partner_suggestions (
    request_id INT UNSIGNED NOT NULL COMMENT 'ID do pedido na tabela _frm',
    request_table VARCHAR(20) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'mkt, shop, xerox, service, ti',
    area_id INT UNSIGNED NOT NULL COMMENT 'FK ctd_area.id — setor sugerido',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (request_table, request_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
