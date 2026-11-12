-- 013_plastificacao: a Reprografia passa a ter dois tipos de pedido, cópia colorida e plastificação.
-- type continua guardando o papel/tamanho; kind diz o tipo. NULL (pedidos antigos) = cópia colorida.

ALTER TABLE ctd_xerox_frm
    ADD COLUMN kind VARCHAR(20) COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL COMMENT 'copia_colorida ou plastificacao; NULL = copia_colorida' AFTER `type`;
