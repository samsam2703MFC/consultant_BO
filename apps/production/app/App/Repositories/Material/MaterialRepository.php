<?php
namespace App\Repositories\Material;

use App\Core\Helpers\BaseRepository;

/**
 * User class
 */
class MaterialRepository extends BaseRepository
{

    protected string $table = 'raw_material';

    protected $allowedColumns = [
        'name',
        'waste_perc',
        'id_unit',
        'id_category',
        'id_current_supplier',
        'package_size_in_base_unit',
        'price_per_unit',
        'energy_100g',
        'fat_100g',
        'saturated_fat_100g',
        'carbohydrates_100g',
        'sugars_100g',
        'salt_100g',
        'fiber_100g',
        'proteins_100g',
        'vat_rate',
        'is_part_of_package',
        'bdo_material_category',
        'packaging_unit_weight_g',
        'packaging_usage_type'
    ];

    protected $fetchAllowedColumns = [
        'name',
        'waste_perc',
        'id_unit',
        'id_category',
        'single_weight',
        'ppc',
        'id_current_supplier',
        'vat_rate',
        'is_part_of_package',
        'bdo_material_category',
        'packaging_unit_weight_g',
        'packaging_usage_type'
    ];

    public function getAll()
    {
        $sql = "SELECT 
                    rm.id, 
                    rm.name,
                    rm.waste_perc,
                    rm.id_unit,
                    rm.id_category,
                    rm.package_size_in_base_unit,
                    rm.vat_rate,
                    rm.is_part_of_package,
                    rm.bdo_material_category,
                    rm.packaging_unit_weight_g,
                    rm.packaging_usage_type,
                    TRUNCATE((rm.price_per_unit*1000),2) as price_per_1000,
                    TRUNCATE(rm.package_size_in_base_unit*rm.price_per_unit,2) as pack_price,
                    rm.id_current_supplier,
                    unit.name as unit_name,
                    supp.name as supplier_name,
                    cm.name as category_name
                FROM raw_material rm
                LEFT JOIN supplier supp
                    ON rm.id_current_supplier = supp.id 
                INNER JOIN unit
                    ON rm.id_unit = unit.id
                LEFT JOIN category_material cm
                    ON rm.id_category = cm.id
                    ORDER BY name";
        return $this->query($sql);
    }

    public function getByUnit($data)
    {
        $sql = "SELECT 
                    rm.id,
                    rm.name,
                    rm.name_fr,
                    rm.waste_perc,
                    rm.id_unit,
                    rm.id_category,
                    rm.id_current_supplier,
                    rm.package_size_in_base_unit,
                    rm.price_per_unit,
                    TRIM(TRUNCATE(rm.package_size_in_base_unit * rm.price_per_unit, 2))+0 as pack_price,
                    TRIM(rm.energy_100g)+0 as energy_100g,
                    TRIM(rm.fat_100g)+0 as fat_100g,
                    TRIM(rm.saturated_fat_100g)+0 as saturated_fat_100g,
                    TRIM(rm.carbohydrates_100g)+0 as carbohydrates_100g,
                    TRIM(rm.sugars_100g)+0 as sugars_100g,
                    TRIM(rm.salt_100g)+0 as salt_100g,
                    TRIM(rm.fiber_100g)+0 as fiber_100g,
                    TRIM(rm.proteins_100g)+0 as proteins_100g,            
                    unit.name as unit_name,
                    supp.name as supplier_name
                FROM raw_material rm
                LEFT JOIN supplier supp
                    ON rm.id_current_supplier = supp.id 
                INNER JOIN unit
                    ON rm.id_unit = unit.id
                WHERE rm.id_unit = :id_unit
                    ORDER BY name";
        return $this->query($sql, $data);
    }

    public function getWithPcsUnit()
    {
        return $this->getByUnit(['id_unit' => 5]);
    }

    public function  getById($id)
    {
        $sql = "SELECT 
                    rm.id,
                    rm.name,
                    rm.name_fr,
                    rm.waste_perc,
                    rm.id_unit,
                    rm.id_category,
                    rm.id_current_supplier,
                    rm.package_size_in_base_unit,
                    rm.price_per_unit,
                    TRIM(TRUNCATE(rm.package_size_in_base_unit * rm.price_per_unit, 2))+0 as pack_price,
                    TRIM(rm.energy_100g)+0 as energy_100g,
                    TRIM(rm.fat_100g)+0 as fat_100g,
                    TRIM(rm.saturated_fat_100g)+0 as saturated_fat_100g,
                    TRIM(rm.carbohydrates_100g)+0 as carbohydrates_100g,
                    TRIM(rm.sugars_100g)+0 as sugars_100g,
                    TRIM(rm.salt_100g)+0 as salt_100g,
                    TRIM(rm.fiber_100g)+0 as fiber_100g,
                    TRIM(rm.proteins_100g)+0 as proteins_100g,            
                    unit.name as unit_name,
                    supp.name as supplier_name,
                    rm.is_part_of_package,
                    rm.bdo_material_category,
                    rm.packaging_unit_weight_g,
                    rm.packaging_usage_type
                FROM raw_material rm
                LEFT JOIN supplier supp
                    ON rm.id_current_supplier = supp.id 
                INNER JOIN unit
                    ON rm.id_unit = unit.id
                WHERE rm.id = :id";
        $res = $this->query($sql, ['id' => $id]);
        if(!$res) return false;
        return $res[0];
    }

    public function getAllWithNutritionalValues()
    {
        $sql = "SELECT
                    rm.id,
                    rm.name,
                    IFNULL(TRIM(rm.energy_100g)+0, 0)          AS energy_100g,
                    IFNULL(TRIM(rm.fat_100g)+0, 0)             AS fat_100g,
                    IFNULL(TRIM(rm.saturated_fat_100g)+0, 0)   AS saturated_fat_100g,
                    IFNULL(TRIM(rm.carbohydrates_100g)+0, 0)   AS carbohydrates_100g,
                    IFNULL(TRIM(rm.sugars_100g)+0, 0)          AS sugars_100g,
                    IFNULL(TRIM(rm.salt_100g)+0, 0)            AS salt_100g,
                    IFNULL(TRIM(rm.fiber_100g)+0, 0)           AS fiber_100g,
                    IFNULL(TRIM(rm.proteins_100g)+0, 0)        AS proteins_100g
                FROM raw_material rm
                WHERE rm.is_part_of_package = 0
                ORDER BY rm.name";
        return $this->query($sql);
    }

    public function getByIdCategory($id)
    {
        $sql = "SELECT
                    rm.id,
                    rm.name,
                    rm.id_category,
                    rm.id_current_supplier, 
                    sp.name as supplier_name,
                    rm.package_size_in_base_unit
                FROM raw_material rm
                LEFT JOIN supplier sp
                    ON rm.id_current_supplier = rm.id
                WHERE id_category = :id_category
                ORDER BY name";
        return $this->query($sql, ['id_category' => $id]);
    }





    public function insertMaterial($data)
    {
        if($data['package_size_in_base_unit'] == 0){
            $data['price_per_unit'] = 0;
        } else {
            $data['price_per_unit'] = number_format($data['pack_price'] / $data['package_size_in_base_unit'], 5);
        }

        /** remove unwanted data **/
        if(!empty($this->allowedColumns))
        {
            foreach ($data as $key => $value) {

                if(!in_array($key, $this->allowedColumns))
                {
                    unset($data[$key]);
                }
            }
        }


        $sql = "INSERT INTO raw_material ( 
                          `name`,
                          `name_fr`,
                          `waste_perc`, 
                          `id_unit`, 
                          `id_category`,
                          `id_current_supplier`, 
                          `package_size_in_base_unit`, 
                          `price_per_unit`, 
                          `energy_100g`, 
                          `fat_100g`, 
                          `saturated_fat_100g`, 
                          `carbohydrates_100g`, 
                          `sugars_100g`,
                          `salt_100g`, 
                          `fiber_100g`, 
                          `proteins_100g`) VALUES (
                          
                          :name,
                          '',
                          :waste_perc, 
                          :id_unit, 
                          :id_category,
                          :id_current_supplier, 
                          :package_size_in_base_unit, 
                          :price_per_unit, 
                          :energy_100g, 
                          :fat_100g, 
                          :saturated_fat_100g, 
                          :carbohydrates_100g, 
                          :sugars_100g,
                          :salt_100g, 
                          :fiber_100g, 
                          :proteins_100g)";
        return $this->query($sql, $data);
    }


}
