-- [209] Produkty testowe : usunięcie 22 starych propozycji (2023–2024) na prośbę (30/10/2026).
-- Tylko status « Propozycja » (1), dopasowanie kategoria + nazwa (bez spacji na brzegach), bez kart / prób / zdjęć
-- z nowego CRM (n_dev_*). Kopia przed usunięciem : n_test_product_removed, n_test_product_changelog_removed
-- (przywrócenie = INSERT z powrotem). Rejouable ; bez tabel ERP (bazy testowe) nic nie robi.
SET @ok := (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME IN ('test_product', 'test_product_changelog', 'category_product', 'n_dev_card', 'n_dev_product', 'n_dev_trial', 'n_dev_product_photo')) = 7;

DROP TEMPORARY TABLE IF EXISTS tmp_tp_remove;
CREATE TEMPORARY TABLE tmp_tp_remove (category VARCHAR(100) NOT NULL, name VARCHAR(255) NOT NULL) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
INSERT INTO tmp_tp_remove (category, name) VALUES
    ('Boulangerie', 'Brioche Tressée - 3 Sortes'),
    ('Boulangerie', 'Baguette 10 Cereales'),
    ('Boulangerie', 'Social Media Integration'),
    ('Cake au yaourt', 'Mini-Cake Maracuja Chocolat'),
    ('Cake au yaourt', 'Mini-Cake Pamplemousse'),
    ('Cookie''s', 'Cookie Caramel & Gros Sel'),
    ('Fond à garnir', 'Insert pour les croutes'),
    ('Plats Preparés', 'Ravioli'),
    ('Plats Preparés', 'Pulpet Drobiowy - Chicken Ball'),
    ('Plats Preparés', 'Moussaka'),
    ('Plats Preparés', 'Croutons'),
    ('Plats Preparés', 'Salade d''Hiver'),
    ('Plats Preparés', 'Boulettes'),
    ('Plats Preparés', 'Lasagne'),
    ('Plats Preparés', 'Assortiment de Soupes'),
    ('Quiches', 'Quiche légère aux poireaux, thon et moutarde'),
    ('Quiches', 'Quiche Butternut, Chèvre & Tomate Cerises'),
    ('Quiches', 'Quiche au Boulettes Butternut rôties & Tomates'),
    ('Quiches', 'Quiche Szparagi'),
    ('Quiches', 'Quiche Courgette - Ricotte - Poivre Vert'),
    ('Viennoiseries', 'Crookie'),
    ('Viennoiseries', 'Double Chocolat Viennoiserie');

DROP TEMPORARY TABLE IF EXISTS tmp_tp_ids;
CREATE TEMPORARY TABLE tmp_tp_ids (id INT NOT NULL PRIMARY KEY);
SET @sql := IF(@ok, 'INSERT IGNORE INTO tmp_tp_ids (id)
    SELECT tp.id FROM test_product tp
    JOIN category_product c ON c.id = tp.id_category
    JOIN tmp_tp_remove r ON r.category = TRIM(c.name) AND r.name = TRIM(tp.name)
    WHERE tp.id_status = 1
      AND NOT EXISTS (SELECT 1 FROM n_dev_card x WHERE x.id_test_product = tp.id)
      AND NOT EXISTS (SELECT 1 FROM n_dev_product x WHERE x.id_test_product = tp.id)
      AND NOT EXISTS (SELECT 1 FROM n_dev_trial x WHERE x.id_test_product = tp.id)
      AND NOT EXISTS (SELECT 1 FROM n_dev_product_photo x WHERE x.id_test_product = tp.id)', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(@ok, 'CREATE TABLE IF NOT EXISTS n_test_product_removed LIKE test_product', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF(@ok, 'CREATE TABLE IF NOT EXISTS n_test_product_changelog_removed LIKE test_product_changelog', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(@ok, 'INSERT IGNORE INTO n_test_product_removed SELECT tp.* FROM test_product tp JOIN tmp_tp_ids i ON i.id = tp.id', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF(@ok, 'INSERT IGNORE INTO n_test_product_changelog_removed SELECT ch.* FROM test_product_changelog ch JOIN tmp_tp_ids i ON i.id = ch.id_test_product', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(@ok, 'DELETE ch FROM test_product_changelog ch JOIN tmp_tp_ids i ON i.id = ch.id_test_product', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF(@ok, 'DELETE tp FROM test_product tp JOIN tmp_tp_ids i ON i.id = tp.id', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

DROP TEMPORARY TABLE IF EXISTS tmp_tp_ids;
DROP TEMPORARY TABLE IF EXISTS tmp_tp_remove;
