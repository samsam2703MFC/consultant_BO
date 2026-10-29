-- Identifiers used in PPWR technical documentation. The packaging weight stays
-- in raw_material.packaging_unit_weight_g as the shared BDO/PPWR source of truth.
SET @ppwr_identification_number_exists := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'raw_material_ppwr_profile'
      AND COLUMN_NAME = 'identification_number'
);
SET @ppwr_identification_number_sql := IF(
    @ppwr_identification_number_exists = 0,
    'ALTER TABLE raw_material_ppwr_profile ADD COLUMN identification_number VARCHAR(255) NULL AFTER id_raw_material',
    'SELECT 1'
);
PREPARE ppwr_identification_number_statement FROM @ppwr_identification_number_sql;
EXECUTE ppwr_identification_number_statement;
DEALLOCATE PREPARE ppwr_identification_number_statement;

SET @ppwr_identification_source_exists := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'raw_material_ppwr_profile'
      AND COLUMN_NAME = 'identification_source'
);
SET @ppwr_identification_source_sql := IF(
    @ppwr_identification_source_exists = 0,
    'ALTER TABLE raw_material_ppwr_profile ADD COLUMN identification_source ENUM(''OWN'', ''SUPPLIER'') NULL AFTER identification_number',
    'SELECT 1'
);
PREPARE ppwr_identification_source_statement FROM @ppwr_identification_source_sql;
EXECUTE ppwr_identification_source_statement;
DEALLOCATE PREPARE ppwr_identification_source_statement;
