-- Migration: Add profitability fields to final_product table
-- Date: 2026-02-17
-- Description: Add labor cost and planning fields for product profitability MVP

-- Add profitability-related fields to final_product table
ALTER TABLE `final_product`
ADD COLUMN `labor_minutes_per_unit` DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT 'Minutes required to produce one unit',
ADD COLUMN `hourly_labor_rate` DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT 'Labor cost in PLN per hour',
ADD COLUMN `monthly_planned_quantity` INT(11) NOT NULL DEFAULT 0 COMMENT 'Planned monthly production quantity',
ADD COLUMN `target_margin_percentage` DECIMAL(5,2) NOT NULL DEFAULT 25.00 COMMENT 'Target profit margin percentage for warnings';

-- Create product_profitability_settings table for company-wide defaults
CREATE TABLE IF NOT EXISTS `product_profitability_settings` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `default_hourly_labor_rate` DECIMAL(10,2) NOT NULL DEFAULT 50.00 COMMENT 'Default labor rate in PLN/hour',
  `default_target_margin` DECIMAL(5,2) NOT NULL DEFAULT 25.00 COMMENT 'Default target margin percentage',
  `warning_margin_threshold` DECIMAL(5,2) NOT NULL DEFAULT 15.00 COMMENT 'Margin below this shows warning',
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Insert default settings
INSERT INTO `product_profitability_settings` 
  (`id`, `default_hourly_labor_rate`, `default_target_margin`, `warning_margin_threshold`)
VALUES 
  (1, 50.00, 25.00, 15.00)
ON DUPLICATE KEY UPDATE 
  `default_hourly_labor_rate` = VALUES(`default_hourly_labor_rate`);
