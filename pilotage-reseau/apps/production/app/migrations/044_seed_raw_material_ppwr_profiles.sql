-- Initial PPWR readiness seed based only on the existing raw_material master data.
-- Requires migration 043_raw_material_ppwr_profiles.sql.
--
-- Important: inferred values are deliberately marked REQUIRES_DATA / NOT_ASSESSED.
-- A material name or its BDO category is not proof of recyclability, recycled
-- content, food-contact status, or PPWR conformity. This migration never updates
-- a profile that has already been entered by a user.

ALTER TABLE raw_material_ppwr_dimension
    MODIFY measurement_source ENUM('SUPPLIER', 'INTERNAL_MEASUREMENT', 'SPECIFICATION', 'LEGACY_DESCRIPTION') NULL;

INSERT INTO raw_material_ppwr_profile (
    id_raw_material,
    packaging_level,
    packaging_type,
    dominant_material,
    food_contact_status,
    recycled_content_status,
    recyclability_status,
    reuse_status,
    minimisation_status,
    verification_status
)
SELECT
    rm.id,
    CASE
        WHEN LOWER(rm.name) LIKE '%stretch%' THEN 'TRANSPORT'
        WHEN LOWER(rm.name) REGEXP '^(karton|pudło)' THEN 'SALES'
        ELSE 'INNER'
    END,
    CASE
        WHEN LOWER(rm.name) REGEXP '^(karton|pudło)' THEN 'CARTON_BOX'
        WHEN LOWER(rm.name) LIKE '%etykiet%' THEN 'LABEL'
        WHEN LOWER(rm.name) LIKE '%obręcz%' THEN 'PAPER_BAND'
        WHEN LOWER(rm.name) LIKE '%stretch%' THEN 'STRETCH_FILM'
        WHEN LOWER(rm.name) LIKE '%folia%' THEN 'FILM'
        WHEN LOWER(rm.name) LIKE '%doypack%' THEN 'DOYPACK'
        WHEN LOWER(rm.name) LIKE '%worek%' OR LOWER(rm.name) LIKE '%worecz%' OR LOWER(rm.name) LIKE '%toreb%' THEN 'BAG'
        WHEN LOWER(rm.name) LIKE '%kubek%' THEN 'CUP_WITH_LID'
        WHEN LOWER(rm.name) LIKE '%tack%' OR LOWER(rm.name) LIKE '%wytłoczk%' THEN 'TRAY'
        WHEN LOWER(rm.name) LIKE '%keksówk%' OR LOWER(rm.name) LIKE '%foremk%' THEN 'MOULD'
        WHEN LOWER(rm.name) LIKE '%papier%' THEN 'WRAPPING_PAPER'
        WHEN LOWER(rm.name) LIKE '%taśma%' THEN 'ADHESIVE_TAPE'
        WHEN LOWER(rm.name) LIKE '%wstążka%' THEN 'RIBBON'
        ELSE 'OTHER'
    END,
    CASE rm.bdo_material_category
        WHEN 'PLASTIC' THEN 'PLASTIC'
        WHEN 'PAPER_CARDBOARD' THEN 'PAPER_CARDBOARD'
        WHEN 'GLASS' THEN 'GLASS'
        WHEN 'ALUMINIUM' THEN 'ALUMINIUM'
        WHEN 'FERROUS_METAL' THEN 'FERROUS_METAL'
        WHEN 'WOOD' THEN 'WOOD'
        WHEN 'MULTIMATERIAL' THEN 'MULTIMATERIAL'
        ELSE 'OTHER'
    END,
    'UNKNOWN',
    CASE WHEN rm.bdo_material_category = 'PLASTIC' THEN 'NOT_ASSESSED' ELSE 'NOT_APPLICABLE' END,
    'NOT_ASSESSED',
    CASE WHEN rm.packaging_usage_type = 'REUSABLE' THEN 'REUSABLE' ELSE 'SINGLE_USE' END,
    'NOT_ASSESSED',
    'REQUIRES_DATA'
FROM raw_material rm
LEFT JOIN raw_material_ppwr_profile existing ON existing.id_raw_material = rm.id
WHERE rm.is_part_of_package = 1
  AND existing.id IS NULL;

-- Dimensions parsed from explicit size expressions in legacy material names.
-- They are not supplier-certified measurements. A missing third dimension means
-- that no volume is inferred, so the PPWR audit still requests verification.
INSERT INTO raw_material_ppwr_dimension (
    id_raw_material_ppwr_profile, length_mm, width_mm, height_mm,
    outer_volume_cm3, measurement_source, is_active
)
SELECT
    profile.id_raw_material_ppwr_profile,
    profile.length_mm,
    profile.width_mm,
    profile.height_mm,
    CASE
        WHEN profile.length_mm IS NOT NULL AND profile.width_mm IS NOT NULL AND profile.height_mm IS NOT NULL
            THEN ROUND(profile.length_mm * profile.width_mm * profile.height_mm / 1000, 3)
        ELSE NULL
    END,
    'LEGACY_DESCRIPTION',
    1
FROM (
    SELECT p.id AS id_raw_material_ppwr_profile, v.length_mm, v.width_mm, v.height_mm
    FROM raw_material_ppwr_profile p
    INNER JOIN (
        SELECT 4006 AS id_raw_material, 590.000 AS length_mm, 390.000 AS width_mm, 210.000 AS height_mm UNION ALL
        SELECT 4007, 100.000, 150.000, NULL UNION ALL
        SELECT 4009, 775.000, 25.000, NULL UNION ALL
        SELECT 4030, 390.000, 195.000, 120.000 UNION ALL
        SELECT 4044, 300.000, 400.000, NULL UNION ALL
        SELECT 4047, 520.000, 260.000, 200.000 UNION ALL
        SELECT 4048, 100.000, 50.000, NULL UNION ALL
        SELECT 4049, 651.000, 25.000, NULL UNION ALL
        SELECT 4051, 651.000, 35.000, NULL UNION ALL
        SELECT 4055, 190.000, 45.000, 380.000 UNION ALL
        SELECT 4066, 190.000, 88.000, 380.000 UNION ALL
        SELECT 4068, 240.000, 240.000, 60.000 UNION ALL
        SELECT 4069, 380.000, 190.000, 132.000 UNION ALL
        SELECT 4079, 280.000, 280.000, 40.000 UNION ALL
        SELECT 4083, 280.000, 280.000, 60.000 UNION ALL
        SELECT 4088, 390.000, 195.000, 120.000 UNION ALL
        SELECT 4093, 596.000, 395.000, 102.000 UNION ALL
        SELECT 4096, 445.000, 20.000, NULL UNION ALL
        SELECT 4111, 310.000, 250.000, 230.000 UNION ALL
        SELECT 4148, 110.000, 65.000, 40.000 UNION ALL
        SELECT 4166, 445.000, 30.000, NULL UNION ALL
        SELECT 4198, 190.000, 132.000, 380.000 UNION ALL
        SELECT 4201, 590.000, 290.000, 340.000 UNION ALL
        SELECT 4237, 310.000, 235.000, 70.000 UNION ALL
        SELECT 4238, 440.000, 290.000, 55.000 UNION ALL
        SELECT 4239, 100.000, 100.000, NULL UNION ALL
        SELECT 4250, 188.000, 85.000, 50.000 UNION ALL
        SELECT 4284, 200.000, 300.000, NULL UNION ALL
        SELECT 4297, 300.000, 250.000, NULL
    ) v ON v.id_raw_material = p.id_raw_material
) profile
LEFT JOIN raw_material_ppwr_dimension current_dimension
    ON current_dimension.id_raw_material_ppwr_profile = profile.id_raw_material_ppwr_profile
   AND current_dimension.is_active = 1
WHERE current_dimension.id IS NULL;

-- Verification query: the expected result is 58 initial PPWR profiles and a
-- non-zero count of profiles still requiring review.
SELECT
    COUNT(*) AS ppwr_profiles,
    SUM(verification_status = 'REQUIRES_DATA') AS profiles_requiring_review
FROM raw_material_ppwr_profile;
