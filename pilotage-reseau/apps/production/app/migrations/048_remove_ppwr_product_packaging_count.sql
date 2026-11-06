-- final_product.ppc is the only source of truth for pieces in a sales carton.
-- This is conditional because migration 047 may already have been applied.
SET @ppwr_product_count_exists := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'final_product_ppwr_sales_packaging'
      AND COLUMN_NAME = 'products_per_sales_unit'
);
SET @ppwr_product_count_sql := IF(
    @ppwr_product_count_exists = 1,
    'ALTER TABLE final_product_ppwr_sales_packaging DROP COLUMN products_per_sales_unit',
    'SELECT 1'
);
PREPARE ppwr_product_count_statement FROM @ppwr_product_count_sql;
EXECUTE ppwr_product_count_statement;
DEALLOCATE PREPARE ppwr_product_count_statement;
