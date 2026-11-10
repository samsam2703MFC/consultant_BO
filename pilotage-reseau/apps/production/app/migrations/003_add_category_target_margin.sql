-- Migration: Add target margin to category_product table
-- Date: 2026-02-17
-- Description: Add target_margin_percentage field for category-level profitability analysis

-- Add target margin percentage to category_product table
ALTER TABLE `category_product`
ADD COLUMN `target_margin_percentage` DECIMAL(5,2) NOT NULL DEFAULT 25.00 COMMENT 'Target profit margin percentage for the category';

-- Set initial target margins for existing categories (adjust as needed)
UPDATE `category_product` SET `target_margin_percentage` = 25.00 WHERE `target_margin_percentage` = 0;

-- Verification query
SELECT id, name, target_margin_percentage 
FROM category_product 
ORDER BY name;
