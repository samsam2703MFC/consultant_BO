-- Orientacyjne dane startowe BDO dla wszystkich surowców z raw_material.is_part_of_package = 1.
-- Wymaga wcześniejszego ręcznego wykonania migracji 040_shipment_packaging_bdo.sql.
-- NIE wykonuj automatycznie: wartości należy okresowo potwierdzać z kartami technicznymi/opakowaniami dostawców.
-- Materiały w jednostce g (4183, 4142) celowo mają NULL jako masę jednostkową:
-- ich masa jest już podana w flattened_recipe_ingredient.quantity.

START TRANSACTION;

UPDATE raw_material
SET
    bdo_material_category = CASE id
        WHEN 4295 THEN 'PLASTIC'
        WHEN 4180 THEN 'PLASTIC'
        WHEN 4296 THEN 'PLASTIC'
        WHEN 4007 THEN 'PAPER_CARDBOARD'
        WHEN 4048 THEN 'PAPER_CARDBOARD'
        WHEN 4306 THEN 'PAPER_CARDBOARD'
        WHEN 4239 THEN 'PAPER_CARDBOARD'
        WHEN 4141 THEN 'PLASTIC'
        WHEN 4183 THEN 'PLASTIC'
        WHEN 4191 THEN 'PLASTIC'
        WHEN 4309 THEN 'PLASTIC'
        WHEN 4198 THEN 'PAPER_CARDBOARD'
        WHEN 4055 THEN 'PAPER_CARDBOARD'
        WHEN 4066 THEN 'PAPER_CARDBOARD'
        WHEN 4068 THEN 'PAPER_CARDBOARD'
        WHEN 4079 THEN 'PAPER_CARDBOARD'
        WHEN 4083 THEN 'PAPER_CARDBOARD'
        WHEN 4111 THEN 'PAPER_CARDBOARD'
        WHEN 4069 THEN 'PAPER_CARDBOARD'
        WHEN 4030 THEN 'PAPER_CARDBOARD'
        WHEN 4088 THEN 'PAPER_CARDBOARD'
        WHEN 4047 THEN 'PAPER_CARDBOARD'
        WHEN 4006 THEN 'PAPER_CARDBOARD'
        WHEN 4201 THEN 'PAPER_CARDBOARD'
        WHEN 4093 THEN 'PAPER_CARDBOARD'
        WHEN 4148 THEN 'PAPER_CARDBOARD'
        WHEN 4250 THEN 'PAPER_CARDBOARD'
        WHEN 4084 THEN 'PLASTIC'
        WHEN 4096 THEN 'PAPER_CARDBOARD'
        WHEN 4049 THEN 'PAPER_CARDBOARD'
        WHEN 4051 THEN 'PAPER_CARDBOARD'
        WHEN 4166 THEN 'PAPER_CARDBOARD'
        WHEN 4009 THEN 'PAPER_CARDBOARD'
        WHEN 4303 THEN 'MULTIMATERIAL'
        WHEN 4153 THEN 'PAPER_CARDBOARD'
        WHEN 4154 THEN 'PAPER_CARDBOARD'
        WHEN 4161 THEN 'PAPER_CARDBOARD'
        WHEN 4160 THEN 'PAPER_CARDBOARD'
        WHEN 4159 THEN 'PAPER_CARDBOARD'
        WHEN 4158 THEN 'PAPER_CARDBOARD'
        WHEN 4237 THEN 'PAPER_CARDBOARD'
        WHEN 4238 THEN 'PAPER_CARDBOARD'
        WHEN 4142 THEN 'PLASTIC'
        WHEN 4165 THEN 'PLASTIC'
        WHEN 4092 THEN 'PLASTIC'
        WHEN 4203 THEN 'PLASTIC'
        WHEN 4223 THEN 'MULTIMATERIAL'
        WHEN 4297 THEN 'PLASTIC'
        WHEN 4284 THEN 'PLASTIC'
        WHEN 4197 THEN 'PLASTIC'
        WHEN 4155 THEN 'PLASTIC'
        WHEN 4167 THEN 'PLASTIC'
        WHEN 4044 THEN 'PLASTIC'
        WHEN 4302 THEN 'OTHER'
        WHEN 4080 THEN 'PLASTIC'
        WHEN 4067 THEN 'PLASTIC'
        WHEN 4052 THEN 'PLASTIC'
        WHEN 4070 THEN 'PLASTIC'
        ELSE bdo_material_category
    END,
    packaging_usage_type = 'SINGLE_USE',
    packaging_unit_weight_g = CASE id
        WHEN 4295 THEN 8.0000
        WHEN 4180 THEN 10.0000
        WHEN 4296 THEN 5.0000
        WHEN 4007 THEN 1.0000
        WHEN 4048 THEN 0.4000
        WHEN 4306 THEN 0.3500
        WHEN 4239 THEN 0.6000
        WHEN 4141 THEN 0.1500
        WHEN 4183 THEN NULL
        WHEN 4191 THEN 0.7500
        WHEN 4309 THEN 0.2500
        WHEN 4198 THEN 50.0000
        WHEN 4055 THEN 20.0000
        WHEN 4066 THEN 28.0000
        WHEN 4068 THEN 40.0000
        WHEN 4079 THEN 45.0000
        WHEN 4083 THEN 55.0000
        WHEN 4111 THEN 120.0000
        WHEN 4069 THEN 80.0000
        WHEN 4030 THEN 75.0000
        WHEN 4088 THEN 75.0000
        WHEN 4047 THEN 160.0000
        WHEN 4006 THEN 320.0000
        WHEN 4201 THEN 360.0000
        WHEN 4093 THEN 250.0000
        WHEN 4148 THEN 5.0000
        WHEN 4250 THEN 7.0000
        WHEN 4084 THEN 12.0000
        WHEN 4096 THEN 10.0000
        WHEN 4049 THEN 12.0000
        WHEN 4051 THEN 14.0000
        WHEN 4166 THEN 10.0000
        WHEN 4009 THEN 20.0000
        WHEN 4303 THEN 12.0000
        WHEN 4153 THEN 1.5000
        WHEN 4154 THEN 5.0000
        WHEN 4161 THEN 5.0000
        WHEN 4160 THEN 5.0000
        WHEN 4159 THEN 5.0000
        WHEN 4158 THEN 5.0000
        WHEN 4237 THEN 120.0000
        WHEN 4238 THEN 200.0000
        WHEN 4142 THEN NULL
        WHEN 4165 THEN 1.5000
        WHEN 4092 THEN 2.0000
        WHEN 4203 THEN 2.0000
        WHEN 4223 THEN 7.0000
        WHEN 4297 THEN 2.0000
        WHEN 4284 THEN 4.0000
        WHEN 4197 THEN 3.0000
        WHEN 4155 THEN 8.0000
        WHEN 4167 THEN 2.5000
        WHEN 4044 THEN 3.0000
        WHEN 4302 THEN 1.0000
        WHEN 4080 THEN 5.0000
        WHEN 4067 THEN 4.0000
        WHEN 4052 THEN 5.0000
        WHEN 4070 THEN 6.0000
        ELSE packaging_unit_weight_g
    END
WHERE is_part_of_package = 1;

-- Verify before committing: all 58 current packaging materials should have category and SINGLE_USE;
-- only materials measured in g may have NULL packaging_unit_weight_g.
SELECT rm.id, rm.name, u.name AS unit_name, rm.bdo_material_category,
       rm.packaging_usage_type, rm.packaging_unit_weight_g
FROM raw_material rm
LEFT JOIN unit u ON u.id = rm.id_unit
WHERE rm.is_part_of_package = 1
ORDER BY rm.name;

COMMIT;
