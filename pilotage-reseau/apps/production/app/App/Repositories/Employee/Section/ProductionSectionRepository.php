<?php
namespace App\Repositories\Employee\Section;


use App\Core\Helpers\BaseRepository;
use Model;

/**
 * Production sections model class
 */
class ProductionSectionRepository extends BaseRepository
{

    protected string $table = 'production_sections';

    protected $allowedColumns = [
        'id',
        'name',
        'depreciation',
        'water_cost',
        'power_supply_cost',
        'fuel_cost',
        'other_cost'
    ];

    public function getWithChangelog()
    {
        $sql = "SELECT DISTINCT ps.* FROM `production_sections` ps 
                INNER JOIN test_product tp 
                    ON ps.id = tp.id_section 
                INNER JOIN test_product_changelog tpc 
                    ON tp.id = tpc.id_test_product;";
        return $this->query($sql);
    }

    public function getSectionsWithCosts()
    {
        $productionSectionsModel = new ProductionSectionRepository();
        $sections = $productionSectionsModel->findAll();

        foreach ($sections as &$section) {

        }
    }


}