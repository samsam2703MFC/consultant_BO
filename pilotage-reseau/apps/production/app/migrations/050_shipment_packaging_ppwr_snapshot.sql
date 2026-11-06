-- Immutable PPWR context for each packaging item recorded with a shipment.
-- The existing raw material ID remains a reference, while these fields preserve
-- the profile and sales-carton state that applied when the shipment was created.
ALTER TABLE shipment_packaging_item
    ADD COLUMN product_ppc_snapshot INT NULL AFTER product_name_snapshot,
    ADD COLUMN is_sales_packaging_snapshot TINYINT(1) NOT NULL DEFAULT 0 AFTER id_raw_material,
    ADD COLUMN sales_packaging_verification_status_snapshot ENUM('DRAFT', 'REQUIRES_DATA', 'COMPLETE') NULL AFTER is_sales_packaging_snapshot,
    ADD COLUMN ppwr_profile_id_snapshot INT NULL AFTER material_name_snapshot,
    ADD COLUMN ppwr_identification_number_snapshot VARCHAR(255) NULL AFTER ppwr_profile_id_snapshot,
    ADD COLUMN ppwr_identification_source_snapshot ENUM('OWN', 'SUPPLIER') NULL AFTER ppwr_identification_number_snapshot,
    ADD COLUMN ppwr_packaging_level_snapshot ENUM('SALES', 'INNER', 'GROUPED', 'TRANSPORT', 'E_COMMERCE') NULL AFTER ppwr_identification_source_snapshot,
    ADD COLUMN ppwr_packaging_type_snapshot VARCHAR(100) NULL AFTER ppwr_packaging_level_snapshot,
    ADD COLUMN ppwr_dominant_material_snapshot ENUM(
        'PLASTIC', 'PAPER_CARDBOARD', 'GLASS', 'ALUMINIUM', 'FERROUS_METAL',
        'WOOD', 'MULTIMATERIAL', 'OTHER'
    ) NULL AFTER ppwr_packaging_type_snapshot,
    ADD COLUMN ppwr_recycled_content_percent_snapshot DECIMAL(5,2) NULL AFTER ppwr_dominant_material_snapshot,
    ADD COLUMN ppwr_recycled_content_status_snapshot ENUM('NOT_APPLICABLE', 'NOT_ASSESSED', 'DECLARED', 'VERIFIED') NULL AFTER ppwr_recycled_content_percent_snapshot,
    ADD COLUMN ppwr_recyclability_status_snapshot ENUM('NOT_ASSESSED', 'ASSESSED', 'VERIFIED', 'NOT_RECYCLABLE') NULL AFTER ppwr_recycled_content_status_snapshot,
    ADD COLUMN ppwr_recyclability_grade_snapshot ENUM('A', 'B', 'C') NULL AFTER ppwr_recyclability_status_snapshot,
    ADD COLUMN ppwr_recyclability_assessment_date_snapshot DATE NULL AFTER ppwr_recyclability_grade_snapshot,
    ADD COLUMN ppwr_reuse_status_snapshot ENUM('NOT_APPLICABLE', 'SINGLE_USE', 'REUSABLE', 'REFILL') NULL AFTER ppwr_recyclability_assessment_date_snapshot,
    ADD COLUMN ppwr_target_reuse_cycles_snapshot INT NULL AFTER ppwr_reuse_status_snapshot,
    ADD COLUMN ppwr_minimisation_status_snapshot ENUM('NOT_ASSESSED', 'ASSESSED', 'VERIFIED') NULL AFTER ppwr_target_reuse_cycles_snapshot,
    ADD COLUMN ppwr_profile_verification_status_snapshot ENUM('DRAFT', 'REQUIRES_DATA', 'COMPLETE', 'VERIFIED') NULL AFTER ppwr_minimisation_status_snapshot,
    ADD COLUMN ppwr_outer_volume_cm3_snapshot DECIMAL(16,3) NULL AFTER ppwr_profile_verification_status_snapshot,
    ADD COLUMN ppwr_inner_volume_cm3_snapshot DECIMAL(16,3) NULL AFTER ppwr_outer_volume_cm3_snapshot;
