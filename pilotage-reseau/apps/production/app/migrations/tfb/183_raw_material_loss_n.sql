-- [295] Perte des matières (odpad przy obróbce) dans une table de cette version : n_raw_material_loss.
--   raw_material.waste_perc reste à l'ERP d'origine. Sur une base où la migration 158 est passée (colonne
--   raw_material.waste_review présente), waste_perc est déjà la perte : copiée telle quelle avec son repère « à
--   vérifier ». Sur la base de l'ERP d'origine (mode cohabitation, 158 non appliquée), waste_perc est encore
--   « wykorzystanie » (100 = sans perte) : même conversion que 158 (perte = 100 − valeur ; ≤ 20 = déjà une perte,
--   à vérifier ; vide ou > 100 → 0, à vérifier). Rejouable : n'ajoute que les matières absentes.

CREATE TABLE IF NOT EXISTS n_raw_material_loss (
    id_raw_material INT NOT NULL,
    loss_perc       DECIMAL(5,2) NOT NULL DEFAULT 0 COMMENT 'Odpad przy obróbce [%] : 0 = bez odpadu ; jabłko 35 = z 1 kg zostaje 650 g',
    review          TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = przeniesiony automatycznie, do sprawdzenia ; zapis karty potwierdza',
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id_raw_material)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'raw_material' AND COLUMN_NAME = 'waste_review');
SET @s := IF(@c > 0,
    'INSERT IGNORE INTO n_raw_material_loss (id_raw_material, loss_perc, review)
     SELECT id, LEAST(GREATEST(COALESCE(waste_perc, 0), 0), 99.99), waste_review FROM raw_material',
    'INSERT IGNORE INTO n_raw_material_loss (id_raw_material, loss_perc, review)
     SELECT id,
            CASE WHEN waste_perc IS NULL OR waste_perc > 100 OR waste_perc < 0 THEN 0
                 WHEN waste_perc <= 20 THEN waste_perc
                 ELSE 100 - waste_perc END,
            IF(waste_perc IS NULL OR waste_perc <= 20 OR waste_perc > 100, 1, 0)
     FROM raw_material');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
