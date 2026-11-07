-- Rozszerza import reklamacji z tfbuddy/api o reklamacje niezwiązane z produktem.
-- Wykonać po sprawdzeniu kopii zapasowej bazy; migracja nie usuwa danych.
ALTER TABLE complaint
    ADD COLUMN complaint_uuid CHAR(36) NULL AFTER id,
    ADD COLUMN complaint_type ENUM('PRODUCT', 'NON_PRODUCT') NOT NULL DEFAULT 'PRODUCT' AFTER complaint_key,
    ADD UNIQUE KEY uq_complaint_uuid (complaint_uuid),
    ADD KEY idx_complaint_type_status (complaint_type, acceptance_status),
    MODIFY COLUMN id_order INT NULL,
    MODIFY COLUMN invoice_num VARCHAR(255) NULL,
    MODIFY COLUMN id_product INT NULL,
    MODIFY COLUMN product_quantity DECIMAL(12,3) NULL,
    MODIFY COLUMN single_purchase_price DECIMAL(12,3) NULL;
