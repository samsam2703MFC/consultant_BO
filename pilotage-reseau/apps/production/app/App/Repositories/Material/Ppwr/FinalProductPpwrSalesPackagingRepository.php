<?php

namespace App\Repositories\Material\Ppwr;

use App\Core\Helpers\BaseRepository;

class FinalProductPpwrSalesPackagingRepository extends BaseRepository
{
    protected string $table = 'final_product_ppwr_sales_packaging';

    protected $allowedColumns = ['id_final_product', 'id_outer_raw_material', 'sales_unit_name', 'verification_status'];

    public function getProductAudit(): array
    {
        return $this->query(
            "SELECT fp.id AS id_final_product, fp.name AS product_name, fp.id_recipe, fp.ppc,
                    config.id AS id_configuration, config.id_outer_raw_material,
                    config.sales_unit_name, config.verification_status,
                    outer_material.name AS outer_material_name,
                    COUNT(DISTINCT package_material.id) AS packaging_candidate_count
             FROM final_product fp
             LEFT JOIN final_product_ppwr_sales_packaging config ON config.id_final_product = fp.id
             LEFT JOIN raw_material outer_material ON outer_material.id = config.id_outer_raw_material
             LEFT JOIN flattened_recipe_ingredient fri ON fri.recipe_id = fp.id_recipe
             LEFT JOIN raw_material package_material ON package_material.id = fri.material_id AND package_material.is_part_of_package = 1
             WHERE fp.active = 1
             GROUP BY fp.id, fp.name, fp.id_recipe, fp.ppc, config.id, config.id_outer_raw_material,
                      config.sales_unit_name, config.verification_status, outer_material.name
             ORDER BY fp.name"
        ) ?: [];
    }

    public function getProductById(int $productId): array
    {
        $rows = $this->query('SELECT id, name, id_recipe, ppc, active FROM final_product WHERE id = :id LIMIT 1', ['id' => $productId]);
        return $rows[0] ?? [];
    }

    public function getCandidates(int $productId): array
    {
        return $this->query(
            "SELECT DISTINCT rm.id AS id_raw_material, rm.name AS material_name, fri.quantity AS quantity_in_recipe,
                    profile.packaging_type, profile.packaging_level,
                    CASE WHEN profile.packaging_type = 'CARTON_BOX' AND profile.packaging_level = 'SALES' THEN 1 ELSE 0 END AS is_recommended
             FROM final_product fp
             INNER JOIN flattened_recipe_ingredient fri ON fri.recipe_id = fp.id_recipe
             INNER JOIN raw_material rm ON rm.id = fri.material_id AND rm.is_part_of_package = 1
             LEFT JOIN raw_material_ppwr_profile profile ON profile.id_raw_material = rm.id
             WHERE fp.id = :product_id
             ORDER BY is_recommended DESC, rm.name",
            ['product_id' => $productId]
        ) ?: [];
    }

    public function isCandidateForProduct(int $productId, int $materialId): bool
    {
        return (bool)$this->query(
            "SELECT 1
             FROM final_product fp
             INNER JOIN flattened_recipe_ingredient fri ON fri.recipe_id = fp.id_recipe
             INNER JOIN raw_material rm ON rm.id = fri.material_id AND rm.is_part_of_package = 1
             WHERE fp.id = :product_id AND rm.id = :material_id
             LIMIT 1",
            ['product_id' => $productId, 'material_id' => $materialId]
        );
    }

    public function getByProductId(int $productId): array
    {
        return $this->first(['id_final_product' => $productId]);
    }
}
