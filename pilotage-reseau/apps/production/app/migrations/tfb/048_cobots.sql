-- Coboty : robot współpracujący (tryb « collaboratif ») pracuje obok ludzi — jedna osoba pilnuje kilku cobotów.
-- operator_share = część czasu jednej osoby na cobota (np. 0,25 = 1 osoba na 4 coboty) : godziny pracy etapu
-- na cobocie = czas etapu × operator_share. Etap marszruty przechodzi na cobota przez stanowisko alternatywne
-- (tfb_prod_routing_alternative) z własną kadencją.
--
-- MySQL 8 : ograniczenie CHECK trybu robota zdejmowane na czas zmiany ENUM i zakładane ponownie ; kolumny
-- dodawane warunkowo (bez ADD COLUMN IF NOT EXISTS).

SET @ck := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tfb_prod_workstation' AND CONSTRAINT_NAME = 'ck_tfb_workstation_robot');
SET @sql := IF(@ck > 0, 'ALTER TABLE tfb_prod_workstation DROP CONSTRAINT ck_tfb_workstation_robot', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

ALTER TABLE tfb_prod_workstation MODIFY COLUMN robot_mode ENUM('autonome','avec_operateur','collaboratif') NULL;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tfb_prod_workstation' AND COLUMN_NAME = 'operator_share');
SET @sql := IF(@col = 0, 'ALTER TABLE tfb_prod_workstation ADD COLUMN operator_share DECIMAL(4,2) NULL COMMENT ''Cobot : część czasu jednej osoby (0,25 = 1 osoba na 4 coboty)'' AFTER robot_mode', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

ALTER TABLE tfb_prod_workstation ADD CONSTRAINT ck_tfb_workstation_robot CHECK (
    ((type = 'robot') = (robot_mode IS NOT NULL))
    AND ((robot_mode = 'collaboratif') = (operator_share IS NOT NULL))
    AND (operator_share IS NULL OR (operator_share > 0 AND operator_share <= 1))
);
