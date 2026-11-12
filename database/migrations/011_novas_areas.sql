-- 011_novas_areas: Ensino Fundamental II, Ensino Médio e Educação Infantil.
-- Não duplica se a área já tiver sido cadastrada à mão (mesmo nome ou mesmo slug).

INSERT INTO ctd_subdivision (name, slug)
    SELECT 'Ensino Fundamental II', 'fundamental_2' FROM DUAL
    WHERE NOT EXISTS (SELECT 1 FROM ctd_subdivision WHERE slug = 'fundamental_2' OR name = 'Ensino Fundamental II');

INSERT INTO ctd_subdivision (name, slug)
    SELECT 'Ensino Médio', 'medio' FROM DUAL
    WHERE NOT EXISTS (SELECT 1 FROM ctd_subdivision WHERE slug = 'medio' OR name = 'Ensino Médio');

INSERT INTO ctd_subdivision (name, slug)
    SELECT 'Educação Infantil', 'infantil' FROM DUAL
    WHERE NOT EXISTS (SELECT 1 FROM ctd_subdivision WHERE slug = 'infantil' OR name = 'Educação Infantil');
