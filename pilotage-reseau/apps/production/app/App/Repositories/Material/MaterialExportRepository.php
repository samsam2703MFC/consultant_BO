<?php

namespace App\Repositories\Material;

use App\Core\Helpers\BaseRepository;

class MaterialExportRepository extends BaseRepository
{
    protected string $table = 'raw_material';

    /**
     * Get materials with aliases and allergens for export
     */
    public function getMaterialsForExport(array $materialIds, string $langCode): array
    {
        $placeholders = implode(',', array_fill(0, count($materialIds), '?'));

        $sql = "SELECT
                    rm.id,
                    rm.name AS default_name,
                    COALESCE(rma.alias, rm.name) AS translated_name,
                    rm.id_unit,
                    u.name AS unit_name,
                    rm.id_category,
                    cm.name AS category_name,
                    rm.package_size_in_base_unit,
                    rm.price_per_unit,
                    rm.energy_100g,
                    rm.fat_100g,
                    rm.saturated_fat_100g,
                    rm.carbohydrates_100g,
                    rm.sugars_100g,
                    rm.fiber_100g,
                    rm.proteins_100g,
                    rm.salt_100g,
                    rm.waste_perc,
                    GROUP_CONCAT(
                        DISTINCT COALESCE(aa.alias, a.code)
                        ORDER BY a.code
                        SEPARATOR ', '
                    ) AS allergens
                FROM raw_material rm
                INNER JOIN unit u ON rm.id_unit = u.id
                LEFT JOIN category_material cm ON rm.id_category = cm.id
                LEFT JOIN raw_material_alias rma 
                    ON rm.id = rma.material_id 
                    AND rma.lang_code = ?
                    AND rma.alias_type = 'product_label'
                LEFT JOIN material_allergen_connection mac ON rm.id = mac.material_id
                LEFT JOIN allergen a ON mac.allergen_id = a.id
                LEFT JOIN allergen_alias aa 
                    ON a.id = aa.allergen_id 
                    AND aa.lang_code = ?
                    AND aa.alias_type = 'product_label'
                WHERE rm.id IN ($placeholders)
                    AND rm.is_part_of_package = 0
                GROUP BY rm.id
                ORDER BY rm.name";

        $params = array_merge([$langCode, $langCode], $materialIds);

        return $this->query($sql, $params);
    }

    /**
     * Get all materials (simplified list for selection)
     */
    public function getAllMaterialsSimple(): array
    {
        $sql = "SELECT
                    rm.id,
                    rm.name,
                    rm.id_category,
                    cm.name AS category_name
                FROM raw_material rm
                LEFT JOIN category_material cm ON rm.id_category = cm.id
                WHERE rm.is_part_of_package = 0
                ORDER BY rm.name";

        return $this->query($sql);
    }
}

