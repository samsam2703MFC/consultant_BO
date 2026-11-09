ALTER TABLE shipment_packaging_item
    ADD COLUMN ppwr_packaging_units_total DECIMAL(16,6) NULL AFTER packaging_units_total,
    ADD COLUMN ppwr_total_weight_g DECIMAL(16,4) NULL AFTER total_weight_g,
    ADD COLUMN ppwr_calculation_status ENUM('COMPLETE', 'MISSING_RECIPE', 'MISSING_PACKAGING', 'MISSING_WEIGHT', 'MISSING_PROFILE', 'MISSING_IDENTIFIER') NOT NULL DEFAULT 'MISSING_PROFILE' AFTER calculation_status;
