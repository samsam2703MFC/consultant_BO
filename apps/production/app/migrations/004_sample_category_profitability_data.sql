-- Sample data for testing Category Profitability Module
-- This script creates test data to demonstrate the module functionality

-- ============================================
-- 1. Set target margins for categories
-- ============================================
UPDATE category_product SET target_margin_percentage = 25.00 WHERE id = 1;
UPDATE category_product SET target_margin_percentage = 30.00 WHERE id = 2;
UPDATE category_product SET target_margin_percentage = 28.00 WHERE id = 3;
UPDATE category_product SET target_margin_percentage = 22.00 WHERE id = 4;

-- ============================================
-- 2. Create sample cost_month if not exists
-- ============================================
INSERT INTO cost_month (id, year, month, status)
SELECT 1, YEAR(DATE_SUB(CURDATE(), INTERVAL 1 MONTH)), MONTH(DATE_SUB(CURDATE(), INTERVAL 1 MONTH)), 'LOCKED'
WHERE NOT EXISTS (SELECT 1 FROM cost_month WHERE id = 1);

-- ============================================
-- 3. Add sample employee costs for sections
-- ============================================
-- Replace section_id values with actual section IDs from your database
-- Example: If you have sections with IDs 1, 2, 3

-- Section 1: Piekarnia (example)
INSERT INTO cost_month_employee (month_id, section_id, name, surname, monthly_cost, is_included)
VALUES 
    (1, 1, 'Jan', 'Kowalski', 5000.00, 1),
    (1, 1, 'Anna', 'Nowak', 4500.00, 1)
ON DUPLICATE KEY UPDATE monthly_cost = VALUES(monthly_cost);

-- Section 2: Cukiernia (example)
INSERT INTO cost_month_employee (month_id, section_id, name, surname, monthly_cost, is_included)
VALUES 
    (1, 2, 'Piotr', 'Wiśniewski', 5500.00, 1),
    (1, 2, 'Maria', 'Wójcik', 4800.00, 1)
ON DUPLICATE KEY UPDATE monthly_cost = VALUES(monthly_cost);

-- ============================================
-- 4. Create sample invoices for previous month
-- ============================================
-- Note: Adjust the dates to match your testing period
-- This example uses last month

SET @prev_month_start = DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 1 MONTH), '%Y-%m-01');
SET @prev_month_end = LAST_DAY(DATE_SUB(CURDATE(), INTERVAL 1 MONTH));

-- Sample invoice 1
INSERT INTO document_invoice (doc_num, id_customer, invoiced_date, consignor_nip, consignee_nip)
SELECT 'INV/2026/001', 1, @prev_month_start, '1234567890', '0987654321'
WHERE NOT EXISTS (SELECT 1 FROM document_invoice WHERE doc_num = 'INV/2026/001')
LIMIT 1;

-- Sample invoice 2
INSERT INTO document_invoice (doc_num, id_customer, invoiced_date, consignor_nip, consignee_nip)
SELECT 'INV/2026/002', 1, DATE_ADD(@prev_month_start, INTERVAL 7 DAY), '1234567890', '0987654321'
WHERE NOT EXISTS (SELECT 1 FROM document_invoice WHERE doc_num = 'INV/2026/002')
LIMIT 1;

-- Sample invoice 3
INSERT INTO document_invoice (doc_num, id_customer, invoiced_date, consignor_nip, consignee_nip)
SELECT 'INV/2026/003', 2, DATE_ADD(@prev_month_start, INTERVAL 14 DAY), '1234567890', '0987654321'
WHERE NOT EXISTS (SELECT 1 FROM document_invoice WHERE doc_num = 'INV/2026/003')
LIMIT 1;

-- ============================================
-- 5. Add invoice line items for products
-- ============================================
-- Note: Replace id_product with actual product IDs from your database
-- This creates sales history for products in different categories

-- Get invoice IDs
SET @invoice1 = (SELECT id FROM document_invoice WHERE doc_num = 'INV/2026/001' LIMIT 1);
SET @invoice2 = (SELECT id FROM document_invoice WHERE doc_num = 'INV/2026/002' LIMIT 1);
SET @invoice3 = (SELECT id FROM document_invoice WHERE doc_num = 'INV/2026/003' LIMIT 1);

-- Invoice 1 - Products from category 1
INSERT INTO document_invoice_product (id_document_invoice, id_product, product_name, quantity, single_price, tva_perc, single_weight, ppc)
SELECT @invoice1, fp.id, fp.name, 100, 12.50, 8, 0.5, 20
FROM final_product fp
WHERE fp.id_category = 1 AND fp.active = 1
LIMIT 1;

INSERT INTO document_invoice_product (id_document_invoice, id_product, product_name, quantity, single_price, tva_perc, single_weight, ppc)
SELECT @invoice1, fp.id, fp.name, 50, 15.00, 8, 0.6, 20
FROM final_product fp
WHERE fp.id_category = 1 AND fp.active = 1 AND fp.id NOT IN (
    SELECT id_product FROM document_invoice_product WHERE id_document_invoice = @invoice1
)
LIMIT 1;

-- Invoice 2 - Products from category 2
INSERT INTO document_invoice_product (id_document_invoice, id_product, product_name, quantity, single_price, tva_perc, single_weight, ppc)
SELECT @invoice2, fp.id, fp.name, 200, 8.00, 8, 0.3, 30
FROM final_product fp
WHERE fp.id_category = 2 AND fp.active = 1
LIMIT 1;

INSERT INTO document_invoice_product (id_document_invoice, id_product, product_name, quantity, single_price, tva_perc, single_weight, ppc)
SELECT @invoice2, fp.id, fp.name, 150, 10.00, 8, 0.4, 25
FROM final_product fp
WHERE fp.id_category = 2 AND fp.active = 1 AND fp.id NOT IN (
    SELECT id_product FROM document_invoice_product WHERE id_document_invoice = @invoice2
)
LIMIT 1;

-- Invoice 3 - Mix of products
INSERT INTO document_invoice_product (id_document_invoice, id_product, product_name, quantity, single_price, tva_perc, single_weight, ppc)
SELECT @invoice3, fp.id, fp.name, 75, 18.00, 8, 0.8, 15
FROM final_product fp
WHERE fp.id_category = 3 AND fp.active = 1
LIMIT 1;

-- ============================================
-- 6. Verification queries
-- ============================================

-- Check categories with target margins
SELECT 
    id, 
    name, 
    target_margin_percentage 
FROM category_product 
ORDER BY name;

-- Check sample sales data
SELECT 
    di.doc_num,
    di.invoiced_date,
    fp.name as product_name,
    cp.name as category_name,
    dip.quantity,
    dip.single_price,
    (dip.quantity * dip.single_price) as total_value
FROM document_invoice_product dip
JOIN document_invoice di ON dip.id_document_invoice = di.id
JOIN final_product fp ON dip.id_product = fp.id
JOIN category_product cp ON fp.id_category = cp.id
WHERE di.invoiced_date >= DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 1 MONTH), '%Y-%m-01')
ORDER BY di.invoiced_date DESC, cp.name;

-- Check employee costs
SELECT 
    ps.name as section_name,
    cme.name,
    cme.surname,
    cme.monthly_cost,
    cme.is_included
FROM cost_month_employee cme
JOIN production_sections ps ON cme.section_id = ps.id
ORDER BY ps.name, cme.surname;

-- Summary by category
SELECT 
    cp.name as category,
    COUNT(DISTINCT fp.id) as products_in_category,
    COUNT(DISTINCT dip.id_document_invoice) as invoices,
    SUM(dip.quantity) as total_quantity,
    SUM(dip.quantity * dip.single_price) as total_value
FROM category_product cp
LEFT JOIN final_product fp ON cp.id = fp.id_category AND fp.active = 1
LEFT JOIN document_invoice_product dip ON fp.id = dip.id_product
LEFT JOIN document_invoice di ON dip.id_document_invoice = di.id 
    AND di.invoiced_date >= DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 1 MONTH), '%Y-%m-01')
GROUP BY cp.id, cp.name
HAVING total_quantity > 0
ORDER BY total_value DESC;
