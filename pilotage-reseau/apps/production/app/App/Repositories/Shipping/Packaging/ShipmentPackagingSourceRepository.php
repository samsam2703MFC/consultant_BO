<?php

namespace App\Repositories\Shipping\Packaging;

use App\Core\Helpers\BaseRepository;

class ShipmentPackagingSourceRepository extends BaseRepository
{
    protected string $table = 'document_wz_product';

    /**
     * Returns one row per WZ product and flattened packaging ingredient. A product
     * without a recipe or packaging ingredient deliberately remains in the result.
     */
    public function getPackagingInputsByWzId(int $wzId): array
    {
        $sql = "SELECT
                    wzp.id_document_wz,
                    wzp.id_product,
                    wzp.name AS product_name,
                    wzp.quantity AS shipped_cartons,
                    fp.id_recipe, fp.ppc AS product_ppc,
                    fri.material_id,
                    fri.quantity AS units_per_carton,
                    rm.name AS material_name,
                    rm.bdo_material_category,
                    rm.packaging_unit_weight_g,
                    rm.packaging_usage_type,
                    u.name AS unit_name,
                    product_packaging.id_outer_raw_material AS selected_sales_material_id,
                    product_packaging.verification_status AS sales_packaging_verification_status,
                    ppwr.id AS ppwr_profile_id,
                    ppwr.identification_number AS ppwr_identification_number,
                    ppwr.identification_source AS ppwr_identification_source,
                    ppwr.packaging_level AS ppwr_packaging_level,
                    ppwr.packaging_type AS ppwr_packaging_type,
                    ppwr.dominant_material AS ppwr_dominant_material,
                    ppwr.recycled_content_percent AS ppwr_recycled_content_percent,
                    ppwr.recycled_content_status AS ppwr_recycled_content_status,
                    ppwr.recyclability_status AS ppwr_recyclability_status,
                    ppwr.recyclability_grade AS ppwr_recyclability_grade,
                    ppwr.recyclability_assessment_date AS ppwr_recyclability_assessment_date,
                    ppwr.reuse_status AS ppwr_reuse_status,
                    ppwr.target_reuse_cycles AS ppwr_target_reuse_cycles,
                    ppwr.minimisation_status AS ppwr_minimisation_status,
                    ppwr.verification_status AS ppwr_profile_verification_status,
                    ppwr_dimension.outer_volume_cm3 AS ppwr_outer_volume_cm3,
                    ppwr_dimension.inner_volume_cm3 AS ppwr_inner_volume_cm3
                FROM document_wz_product wzp
                LEFT JOIN final_product fp ON fp.id = wzp.id_product
                LEFT JOIN final_product_ppwr_sales_packaging product_packaging ON product_packaging.id_final_product = fp.id
                LEFT JOIN (
                    SELECT fri.recipe_id, fri.material_id, fri.quantity
                    FROM flattened_recipe_ingredient fri
                    INNER JOIN raw_material package_material
                        ON package_material.id = fri.material_id AND package_material.is_part_of_package = 1
                ) fri ON fri.recipe_id = fp.id_recipe
                LEFT JOIN raw_material rm ON rm.id = fri.material_id
                LEFT JOIN unit u ON u.id = rm.id_unit
                LEFT JOIN raw_material_ppwr_profile ppwr ON ppwr.id_raw_material = rm.id
                LEFT JOIN raw_material_ppwr_dimension ppwr_dimension
                    ON ppwr_dimension.id_raw_material_ppwr_profile = ppwr.id AND ppwr_dimension.is_active = 1
                WHERE wzp.id_document_wz = :wz_id
                ORDER BY wzp.name, rm.name";

        return $this->query($sql, ['wz_id' => $wzId]) ?: [];
    }

    public function getDataIssues(): array
    {
        $sql = "SELECT * FROM (
                    SELECT fp.id AS id_product, fp.name AS product_name, NULL AS id_raw_material,
                           NULL AS material_name, NULL AS unit_name, NULL AS bdo_material_category,
                           NULL AS packaging_unit_weight_g, NULL AS packaging_usage_type,
                           'MISSING_RECIPE' AS issue
                    FROM final_product fp
                    WHERE fp.active = 1 AND fp.id_recipe IS NULL

                    UNION ALL

                    SELECT fp.id, fp.name, NULL, NULL, NULL, NULL, NULL, NULL, 'MISSING_PACKAGING'
                    FROM final_product fp
                    WHERE fp.active = 1 AND fp.id_recipe IS NOT NULL
                      AND NOT EXISTS (
                          SELECT 1 FROM flattened_recipe_ingredient fri
                          INNER JOIN raw_material rm ON rm.id = fri.material_id AND rm.is_part_of_package = 1
                          WHERE fri.recipe_id = fp.id_recipe
                      )

                    UNION ALL

                    SELECT fp.id, fp.name, rm.id, rm.name, u.name, rm.bdo_material_category,
                           rm.packaging_unit_weight_g, rm.packaging_usage_type,
                           CASE
                               WHEN rm.bdo_material_category IS NULL THEN 'MISSING_CATEGORY'
                               WHEN rm.packaging_usage_type IS NULL THEN 'MISSING_USAGE_TYPE'
                               WHEN LOWER(u.name) NOT IN ('g', 'gram', 'gramy')
                                 AND (rm.packaging_unit_weight_g IS NULL OR rm.packaging_unit_weight_g <= 0) THEN 'MISSING_WEIGHT'
                               ELSE 'COMPLETE'
                           END
                    FROM final_product fp
                    INNER JOIN flattened_recipe_ingredient fri ON fri.recipe_id = fp.id_recipe
                    INNER JOIN raw_material rm ON rm.id = fri.material_id AND rm.is_part_of_package = 1
                    LEFT JOIN unit u ON u.id = rm.id_unit
                    WHERE fp.active = 1
                ) issues
                WHERE issue <> 'COMPLETE'
                ORDER BY product_name, material_name";

        return $this->query($sql) ?: [];
    }
}
