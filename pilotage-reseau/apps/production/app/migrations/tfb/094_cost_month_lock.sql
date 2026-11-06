-- Audit 2, lot D (P17, une seule fiche salaire) : le calculateur de coûts lit les salaires du Budżet personelu tant que
-- le mois est ouvert et les fige au verrouillage. locked_at / locked_by = quand et par qui le mois a été fermé
-- (assistant « Zamknięcie miesiąca », rapport mensuel). Conditionnel et rejouable (MySQL 8 / MariaDB).

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cost_month' AND COLUMN_NAME = 'locked_at') = 0,
    'ALTER TABLE cost_month
        ADD COLUMN locked_at DATETIME NULL AFTER status,
        ADD COLUMN locked_by INT NULL AFTER locked_at',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
