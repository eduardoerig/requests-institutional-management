-- 006_descricao_setor: uma frase curta do que cada setor faz.
-- Aparece no passo "Para qual setor?" do Encaminhar; o admin edita em Usuários e setores.

ALTER TABLE ctd_area
    ADD COLUMN description VARCHAR(160) NULL DEFAULT NULL AFTER title;

UPDATE ctd_area SET description = 'Compra de peças, materiais e serviços'
    WHERE LOWER(TRIM(title)) IN ('compras', 'shop') AND description IS NULL;
UPDATE ctd_area SET description = 'Elétrica, hidráulica, móveis e prédio'
    WHERE LOWER(TRIM(title)) IN ('manutenção', 'manutencao', 'service') AND description IS NULL;
UPDATE ctd_area SET description = 'Artes, comunicação e redes'
    WHERE LOWER(TRIM(title)) IN ('marketing', 'mkt') AND description IS NULL;
UPDATE ctd_area SET description = 'Cópias, impressões e encadernação'
    WHERE LOWER(TRIM(title)) IN ('reprografia', 'xerox') AND description IS NULL;
UPDATE ctd_area SET description = 'Computadores, internet, projetores e sistemas'
    WHERE LOWER(TRIM(title)) = 'ti' AND description IS NULL;
