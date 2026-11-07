-- Surowce wskazujące nieistniejącą kategorię (stare numery kategorii) : przypisanie do istniejącej
-- kategorii według nazwy i według tego, gdzie są już podobne surowce. Zmiana tylko gdy surowiec ma
-- nadal nieistniejącą kategorię, jego nazwa się zgadza, a kategoria docelowa istnieje.
--   Mąka : Mąka pszenna T50 ; mieszanki pieczywa (Pieczywo Wielozarniste Sobotka, Easy Gorski)
--   Nabiał : Masa jajowa (jak « Masa Jajowa »)          Czekoladi : Czekolada Ciemna 811, Kakao
--   Cukier : Cukier biały                               Tłuszcze : Masło (jak « masło klarowane »), Margaryna 80 %
--   Inny : Pieprz czarny mielony (jak « Pieprz »)       Owoce : Cytryny
--   Warzyma : Por, Szpinak, Czosnek, Brokuł             Opakowanie (Plastikowe) : wytłoczki plastikowe
-- Baza bez tabel surowców (np. baza testowa API) : nic do zmiany.

SET @ok := (SELECT COUNT(*) FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('raw_material', 'category_material')) = 2;
SET @sql := IF(@ok, 'UPDATE raw_material r
JOIN (
    SELECT 4015 AS id, ''Mąka pszenna  - T50'' AS name, 105 AS cat
    UNION ALL SELECT 4031, ''Pieczywo Wielozarniste Sobotka'', 105
    UNION ALL SELECT 4032, ''Easy Gorski'', 105
    UNION ALL SELECT 4012, ''Masa jajowa'', 109
    UNION ALL SELECT 4011, ''Czekolada Ciemna - 811'', 102
    UNION ALL SELECT 4016, ''Kakao'', 102
    UNION ALL SELECT 4014, ''Cukier biały'', 108
    UNION ALL SELECT 4010, ''Masło'', 112
    UNION ALL SELECT 4013, ''Margaryna 80%'', 112
    UNION ALL SELECT 4021, ''Pieprz czarny mielony'', 101
    UNION ALL SELECT 4033, ''Cytryny'', 106
    UNION ALL SELECT 4023, ''Por'', 107
    UNION ALL SELECT 4026, ''Szpinak'', 107
    UNION ALL SELECT 4028, ''Czosnek'', 107
    UNION ALL SELECT 4029, ''Brokuł'', 107
    UNION ALL SELECT 4067, ''wytłoczki plastikowe - 14 O'', 201
    UNION ALL SELECT 4070, ''wytłoczki plastikowe - 3 x 190 Ø'', 201
) m ON m.id = r.id AND CAST(TRIM(r.name) AS BINARY) = CAST(m.name AS BINARY)
JOIN category_material target ON target.id = m.cat
LEFT JOIN category_material current_cat ON current_cat.id = r.id_category
SET r.id_category = m.cat
WHERE current_cat.id IS NULL', 'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
