
-- Nettoyage de la base 7/7 : 3 tables n_ de fonctions retirées, vides, supprimées (tournées par client remplacées
-- par n_tour_customer, compétences par employé retirées le 100, prévisions remplacées par le plan de la semaine).
-- Une table non vide est gardée.

-- n_customer_route
SET @sql := IF(((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'n_customer_route') = 1),
    'SELECT COUNT(*) INTO @n FROM `n_customer_route`',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql := IF(((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'n_customer_route') = 1 AND @n = 0),
    'DROP TABLE `n_customer_route`',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- n_employee_competency
SET @sql := IF(((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'n_employee_competency') = 1),
    'SELECT COUNT(*) INTO @n FROM `n_employee_competency`',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql := IF(((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'n_employee_competency') = 1 AND @n = 0),
    'DROP TABLE `n_employee_competency`',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- n_prod_forecast
SET @sql := IF(((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'n_prod_forecast') = 1),
    'SELECT COUNT(*) INTO @n FROM `n_prod_forecast`',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql := IF(((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'n_prod_forecast') = 1 AND @n = 0),
    'DROP TABLE `n_prod_forecast`',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

