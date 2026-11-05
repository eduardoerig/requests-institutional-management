-- 010_duas_sugestoes_parceiro: quem pede pode sugerir até 2 setores parceiros.
-- Uma linha por setor sugerido, em vez de uma por pedido.

ALTER TABLE request_partner_suggestions
    DROP PRIMARY KEY,
    ADD PRIMARY KEY (request_table, request_id, area_id);
