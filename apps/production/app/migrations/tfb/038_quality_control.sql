-- Tablet produkcji, zakładka « Kontrola jakości » : każda partia dnia sprawdzona — OK albo niezgodność
-- (wpis w rejestrze niezgodności ERP tfb_nc, źródło « production »), kto i kiedy ; niezgodności zgłoszone
-- z tabletu pamiętają grupę (lista na tablecie grupy).

SET @col_exists := (SELECT COUNT(*) FROM information_schema.COLUMNS
                    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tfb_prod_output' AND COLUMN_NAME = 'qc_status');
SET @sql := IF(@col_exists = 0, 'ALTER TABLE tfb_prod_output
    ADD COLUMN qc_status ENUM(''ok'',''nc'') NULL AFTER note,
    ADD COLUMN qc_at DATETIME NULL AFTER qc_status,
    ADD COLUMN qc_employee INT NULL AFTER qc_at,
    ADD COLUMN qc_note VARCHAR(255) NULL AFTER qc_employee,
    ADD COLUMN id_nc INT NULL AFTER qc_note', 'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists := (SELECT COUNT(*) FROM information_schema.COLUMNS
                    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tfb_nc' AND COLUMN_NAME = 'id_prod_group');
SET @sql := IF(@col_exists = 0, 'ALTER TABLE tfb_nc ADD COLUMN id_prod_group INT NULL AFTER id_final_product, ADD KEY ix_tfb_nc_prod_group (id_prod_group)', 'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
