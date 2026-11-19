-- [300] Logowanie kodem QR : la personne choisit sa section puis son nom à droite de la page de connexion ; le QR affiché
--   est alors le sien (seul son téléphone le valide, premier scan : son PIN seulement). Rejouable (colonne conditionnelle).
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'n_erp_login_request' AND COLUMN_NAME = 'id_for_employee');
SET @sql := IF(@c = 0, 'ALTER TABLE n_erp_login_request ADD COLUMN id_for_employee INT NULL COMMENT ''personne choisie sur la page de connexion (n_prod_employee.id)'' AFTER user_agent', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
