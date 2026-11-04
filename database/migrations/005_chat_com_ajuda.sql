-- 005_chat_com_ajuda: conversa entre quem pediu e o setor chamado para ajudar.
-- helper = quem pediu × setor que ajuda (helper_area_id); o setor responsável só lê.
-- public (quem pediu × responsável) e internal (responsável × quem ajuda) continuam como estão.

ALTER TABLE request_comments
    MODIFY COLUMN channel ENUM('public','internal','helper') NOT NULL DEFAULT 'public',
    ADD COLUMN helper_area_id INT UNSIGNED NULL DEFAULT NULL AFTER channel;
