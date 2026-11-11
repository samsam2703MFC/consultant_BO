-- The controlled packaging_type dictionary replaces the free-text ppwr_format.
-- Run after migrations 043–045 on databases that already contain the column.

SET @ppwr_format_exists := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'raw_material_ppwr_profile'
      AND COLUMN_NAME = 'ppwr_format'
);
SET @ppwr_format_sql := IF(
    @ppwr_format_exists = 1,
    'ALTER TABLE raw_material_ppwr_profile DROP COLUMN ppwr_format',
    'SELECT 1'
);
PREPARE ppwr_format_statement FROM @ppwr_format_sql;
EXECUTE ppwr_format_statement;
DEALLOCATE PREPARE ppwr_format_statement;
