-- 004_chat_interno: conversa interna entre setores dentro do pedido.
-- public = conversa com quem pediu (como sempre foi); internal = só setores (quem pediu nunca vê).
-- Setor chamado só para ajudar vê apenas a conversa interna.

ALTER TABLE request_comments
    ADD COLUMN channel ENUM('public','internal') NOT NULL DEFAULT 'public' AFTER comment,
    ADD INDEX idx_request_channel (request_id, request_table, channel);
