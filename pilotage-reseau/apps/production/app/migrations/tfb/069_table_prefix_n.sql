-- Intégration : les tables créées pendant le développement prennent le préfixe n_ (« nouveau ») à la place
-- de tfb_ (n_prod_group, n_clock_question…) : séparées d'un coup d'œil des tables d'origine de l'ERP, toutes
-- dans la base erp. RENAME TABLE est instantané et garde données, index et clés étrangères.
-- La table des migrations elle-même est renommée par le programme des migrations avant celle-ci.
SET SESSION group_concat_max_len = 1000000;
SET @sql := (SELECT CONCAT('RENAME TABLE ', GROUP_CONCAT(CONCAT('`', TABLE_NAME, '` TO `n_', SUBSTRING(TABLE_NAME, 5), '`') ORDER BY TABLE_NAME SEPARATOR ', '))
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' AND TABLE_NAME LIKE 'tfb\_%');
SET @sql := IFNULL(@sql, 'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
