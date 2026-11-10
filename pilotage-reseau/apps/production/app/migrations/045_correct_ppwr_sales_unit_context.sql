-- Correct PPWR seed data for the company's sales model:
-- one carton is always one purchased sales unit, irrespective of the number of
-- products inside it. This migration is intended for databases where migration
-- 044 was already executed.
--
-- INNER is an internal operational role: a component of the sold carton, not a
-- separate sales unit. GROUPED remains available for a future package that joins
-- multiple independently sold cartons.

ALTER TABLE raw_material_ppwr_profile
    MODIFY packaging_level ENUM('SALES', 'INNER', 'GROUPED', 'TRANSPORT', 'E_COMMERCE') NULL;

UPDATE raw_material_ppwr_profile profile
INNER JOIN raw_material material ON material.id = profile.id_raw_material
SET profile.packaging_level = CASE
    WHEN LOWER(material.name) LIKE '%stretch%' THEN 'TRANSPORT'
    WHEN LOWER(material.name) REGEXP '^(karton|pudło)' THEN 'SALES'
    ELSE 'INNER'
END,
profile.verification_status = CASE
    WHEN profile.verification_status = 'VERIFIED' THEN 'VERIFIED'
    ELSE 'REQUIRES_DATA'
END
WHERE material.is_part_of_package = 1;

-- Verification query. Cartons and boxes should be SALES; only stretch should be
-- TRANSPORT in the currently seeded material list.
SELECT
    packaging_level,
    COUNT(*) AS material_count
FROM raw_material_ppwr_profile
GROUP BY packaging_level
ORDER BY packaging_level;
