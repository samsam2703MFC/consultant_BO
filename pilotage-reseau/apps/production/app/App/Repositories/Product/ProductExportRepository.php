<?php

namespace App\Repositories\Product;

use App\Core\Helpers\BaseRepository;

class ProductExportRepository extends BaseRepository
{
    protected string $table = 'final_product';

    /**
     * Get all products (simplified list for selection)
     */
    public function getAllProductsSimple(): array
    {
        $sql = "SELECT
                    fp.id,
                    fp.name,
                    fp.id_category,
                    cp.name AS category_name,
                    fp.active,
                    fp.id_recipe
                FROM final_product fp
                LEFT JOIN category_product cp ON fp.id_category = cp.id
                ORDER BY fp.name";

        return $this->query($sql);
    }

    /**
     * Get products with full export data including ingredients and allergens
     */
    public function getProductsForExport(array $productIds, string $langCode): array
    {
        $placeholders = implode(',', array_fill(0, count($productIds), '?'));

        $sql = "SELECT
                    fp.id,
                    fp.name,
                    fp.id_category,
                    cp.name AS category_name,
                    fp.id_recipe,
                    fp.weight,
                    fp.ppc,
                    fp.active,
                    fp.shelf_life,
                    fp.storage_info_label,
                    fp.prepare_info_label
                FROM final_product fp
                LEFT JOIN category_product cp ON fp.id_category = cp.id
                WHERE fp.id IN ($placeholders)
                ORDER BY fp.name";

        $params = $productIds;
        $products = $this->query($sql, $params);

        // For each product with recipe, get ingredients and allergens
        foreach ($products as &$product) {
            if ($product['id_recipe']) {
                // Get ingredients ordered by quantity DESC
                $product['ingredients'] = $this->getProductIngredients($product['id_recipe'], $langCode);

                // Get allergens
                $product['allergens'] = $this->getProductAllergens($product['id_recipe'], $langCode);
            } else {
                $product['ingredients'] = [];
                $product['allergens'] = [];
            }
        }

        return $products;
    }

    /**
     * Get product ingredients ordered by quantity (DESC) for label composition
     */
    private function getProductIngredients(int $recipeId, string $langCode): array
    {
        $sql = "SELECT
                    fri.material_id,
                    fri.quantity,
                    COALESCE(rma.alias, rm.name) AS material_name
                FROM flattened_recipe_ingredient fri
                INNER JOIN raw_material rm ON fri.material_id = rm.id
                LEFT JOIN raw_material_alias rma 
                    ON rm.id = rma.material_id 
                    AND rma.lang_code = ?
                    AND rma.alias_type = 'product_label'
                WHERE fri.recipe_id = ?
                    AND rm.is_part_of_package = 0
                ORDER BY fri.quantity DESC";

        return $this->query($sql, [$langCode, $recipeId]);
    }

    /**
     * Get unique allergens for product (from all ingredients)
     */
    private function getProductAllergens(int $recipeId, string $langCode): array
    {
        $sql = "SELECT DISTINCT
                    a.id,
                    a.code,
                    COALESCE(aa.alias, a.code) AS allergen_name
                FROM flattened_recipe_ingredient fri
                INNER JOIN raw_material rm ON fri.material_id = rm.id
                INNER JOIN material_allergen_connection mac ON rm.id = mac.material_id
                INNER JOIN allergen a ON mac.allergen_id = a.id
                LEFT JOIN allergen_alias aa 
                    ON a.id = aa.allergen_id 
                    AND aa.lang_code = ?
                    AND aa.alias_type = 'product_label'
                WHERE fri.recipe_id = ?
                    AND rm.is_part_of_package = 0
                ORDER BY a.code";

        return $this->query($sql, [$langCode, $recipeId]);
    }
}

