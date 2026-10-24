-- Sample data for testing Product Profitability Module
-- This script updates existing products with sample profitability parameters

-- Update products with low-volume, high-labor scenario
UPDATE final_product 
SET 
    labor_minutes_per_unit = 15.0,
    hourly_labor_rate = 60.00,
    monthly_planned_quantity = 300,
    target_margin_percentage = 35.00
WHERE id = 1
LIMIT 1;

-- Update products with medium-volume scenario
UPDATE final_product 
SET 
    labor_minutes_per_unit = 8.0,
    hourly_labor_rate = 50.00,
    monthly_planned_quantity = 1000,
    target_margin_percentage = 25.00
WHERE id = 2
LIMIT 1;

-- Update products with high-volume, low-labor scenario
UPDATE final_product 
SET 
    labor_minutes_per_unit = 3.0,
    hourly_labor_rate = 45.00,
    monthly_planned_quantity = 5000,
    target_margin_percentage = 20.00
WHERE id = 3
LIMIT 1;

-- Update additional products with varying parameters
UPDATE final_product 
SET 
    labor_minutes_per_unit = 10.0,
    hourly_labor_rate = 55.00,
    monthly_planned_quantity = 800,
    target_margin_percentage = 28.00
WHERE id IN (4, 5)
LIMIT 2;

-- Set default parameters for remaining active products
UPDATE final_product 
SET 
    labor_minutes_per_unit = 5.0,
    hourly_labor_rate = 50.00,
    monthly_planned_quantity = 500,
    target_margin_percentage = 25.00
WHERE active = 1
AND labor_minutes_per_unit = 0
LIMIT 20;

-- Verify the updates
SELECT 
    id,
    name,
    labor_minutes_per_unit,
    hourly_labor_rate,
    monthly_planned_quantity,
    target_margin_percentage
FROM final_product
WHERE labor_minutes_per_unit > 0
ORDER BY id
LIMIT 10;
