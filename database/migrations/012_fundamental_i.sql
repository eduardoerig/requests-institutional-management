-- 012_fundamental_i: "Ensino Fundamental" vira "Ensino Fundamental I", ao lado do Ensino Fundamental II.
-- O slug continua 'fundamental'; os pedidos e as pessoas apontam para o id, então nada mais muda.

UPDATE ctd_subdivision SET name = 'Ensino Fundamental I'
    WHERE slug = 'fundamental' AND name = 'Ensino Fundamental';
