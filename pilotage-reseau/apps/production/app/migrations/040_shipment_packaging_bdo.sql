-- BDO / WDT packaging evidence. Execute manually once in the application database.
-- This migration is intentionally NOT executed by the application.

ALTER TABLE raw_material
    ADD COLUMN bdo_material_category ENUM(
        'PLASTIC', 'ALUMINIUM', 'FERROUS_METAL', 'PAPER_CARDBOARD',
        'GLASS', 'WOOD', 'MULTIMATERIAL', 'OTHER'
    ) NULL AFTER is_part_of_package,
    ADD COLUMN packaging_unit_weight_g DECIMAL(12,4) NULL AFTER bdo_material_category,
    ADD COLUMN packaging_usage_type ENUM('SINGLE_USE', 'REUSABLE') NULL AFTER packaging_unit_weight_g;

CREATE TABLE shipment_packaging_record (
    id INT NOT NULL AUTO_INCREMENT,
    id_shipment_log INT NOT NULL,
    status ENUM('GENERATED', 'REQUIRES_DATA', 'CONFIRMED') NOT NULL,
    generated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    confirmed_at TIMESTAMP NULL DEFAULT NULL,
    id_employee_generated INT NULL,
    id_employee_confirmed INT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_shipment_packaging_record_shipment (id_shipment_log),
    KEY idx_shipment_packaging_record_status (status),
    CONSTRAINT fk_shipment_packaging_record_shipment
        FOREIGN KEY (id_shipment_log) REFERENCES shipment_log (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE shipment_packaging_item (
    id BIGINT NOT NULL AUTO_INCREMENT,
    id_shipment_packaging_record INT NOT NULL,
    id_document_wz INT NOT NULL,
    id_product INT NOT NULL,
    product_name_snapshot VARCHAR(255) NOT NULL,
    id_raw_material INT NULL,
    material_name_snapshot VARCHAR(255) NULL,
    bdo_material_category_snapshot ENUM(
        'PLASTIC', 'ALUMINIUM', 'FERROUS_METAL', 'PAPER_CARDBOARD',
        'GLASS', 'WOOD', 'MULTIMATERIAL', 'OTHER'
    ) NULL,
    packaging_usage_type_snapshot ENUM('SINGLE_USE', 'REUSABLE') NULL,
    source_unit_snapshot VARCHAR(32) NULL,
    units_per_carton_snapshot DECIMAL(14,6) NULL,
    shipped_cartons INT NOT NULL,
    packaging_units_total DECIMAL(16,6) NULL,
    unit_weight_g_snapshot DECIMAL(12,4) NULL,
    total_weight_g DECIMAL(16,4) NULL,
    calculation_status ENUM('COMPLETE', 'MISSING_RECIPE', 'MISSING_PACKAGING', 'MISSING_CATEGORY', 'MISSING_WEIGHT') NOT NULL,
    PRIMARY KEY (id),
    KEY idx_shipment_packaging_item_record (id_shipment_packaging_record),
    KEY idx_shipment_packaging_item_category (bdo_material_category_snapshot),
    CONSTRAINT fk_shipment_packaging_item_record
        FOREIGN KEY (id_shipment_packaging_record) REFERENCES shipment_packaging_record (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
