<?php
namespace App\Controllers\Web\Production;


use App\Core\Web\BaseController;
use App\Services\Employee\ProductionEmployeeService;
use App\Services\Employee\Section\ProductionSectionService;
use App\Services\Finances\ProfitabilityService;
use App\Services\Product\ProductService;
use App\Services\Utils\ErpCompanyOtherSettingsService;
use DateInterval;
use DateTime;

class ProductionSectionController extends BaseController
{
    private ProductionSectionService $productionSectionService;
    private ProductionEmployeeService $productionEmployeeService;
    private ProductService $productService;
    private ProfitabilityService $profitabilityService;
    private ErpCompanyOtherSettingsService $erpCompanyOtherSettingsModel;


    public function __construct(
        ProductionSectionService $productionSectionService,
        ProductionEmployeeService $productionEmployeeService,
        ProductService $productService,
        ProfitabilityService $profitabilityService,
        ErpCompanyOtherSettingsService $erpCompanyOtherSettingsModel
    )
    {
        $this->productionSectionService = $productionSectionService;
        $this->productionEmployeeService = $productionEmployeeService;
        $this->productService = $productService;
        $this->profitabilityService = $profitabilityService;
        $this->erpCompanyOtherSettingsModel = $erpCompanyOtherSettingsModel;
    }

    public function profitability()
    {
        $products = $this->productService->getAll();
        $data['final_products_grouped_by_section'] = $this->productService->groupBySection($products);

        $data['total_employee_cost'] = 0;
        $data['total_cost_without_employee'] = 0;
        $data['total_earning'] = 0;
        $data['total_sum'] = 0;
        $data['total_material_cost'] = 0;
        $data['total_depreciation_cost'] = 0;
        $data['total_water_cost'] = 0;
        $data['total_power_supply_cost'] = 0;
        $data['total_fuel_cost'] = 0;
        $data['total_other_cost'] = 0;

        $data['exchange_rate_euro'] = $this->erpCompanyOtherSettingsModel->getById(1)['exchange_rate_euro'];
//            show($data); exit();

        foreach ($data['final_products_grouped_by_section'] as &$section){

            $section_data = $this->productionSectionService->getById($section['id_section']);
            $section['depreciation'] = $section_data['depreciation'];
            $section['water_cost'] = $section_data['water_cost'];
            $section['power_supply_cost'] = $section_data['power_supply_cost'];
            $section['fuel_cost'] = $section_data['fuel_cost'];
            $section['other_cost'] = $section_data['other_cost'];
            $section['employee_cost'] = round($this->productionEmployeeService->getCostBySection($section['id_section']),2);
            $section['material_cost'] = round($this->profitabilityService->getRawProductCostBySectionInLastMonth($section['id_section']), 2);
            $section['cost_without_employee'] = round(($section['material_cost'] + $section_data['depreciation'] + $section_data['water_cost'] + $section_data['power_supply_cost'] + $section_data['fuel_cost'] + $section_data['other_cost']), 2);
            $section['earning'] = round($this->profitabilityService->getEarningBySectionInLastMonth($section['id_section']), 2);


            $data['total_material_cost'] += $section['material_cost'];
            $data['total_depreciation_cost'] += $section_data['depreciation'];
            $data['total_water_cost'] += $section_data['water_cost'];
            $data['total_power_supply_cost'] += $section_data['power_supply_cost'];
            $data['total_fuel_cost'] += $section_data['fuel_cost'];
            $data['total_other_cost'] += $section_data['other_cost'];



            $data['total_employee_cost'] += $section['employee_cost'];
            $data['total_cost_without_employee'] += $section['cost_without_employee'];
            $data['total_earning'] += $section['earning'];

            $data['category_with_products'] = [];
            $data['quantity_of_sold_carton'] = 0;

            $products_for_summary_calc = $this->profitabilityService->getSoldProductListBySectionInLastMonth($section['id_section']);

            if(!empty($products_for_summary_calc)){
                foreach ($products_for_summary_calc as $product_for_summary_calc){
                    $data['quantity_of_sold_carton'] += $product_for_summary_calc['sold_quantity'];
                }
            }

        }

        $this->view("finances/production_section/profitability", $data);
    }

    public function profitabilityDetails($idSection)
    {
        $currentDate = new DateTime();
        $lastMonth = $currentDate->sub(new DateInterval('P1M'));
        $data['last_month_year'] = $lastMonth->format('m-Y');

        $data['total_raw_price_zl'] = 0;
        $data['total_income_zl'] = 0;
        $data['total_employee_cost'] = 0;
        $data['total_revenue'] = 0;
        $data['total_costs'] = 0;
        $data['total_marge'] = 0;


        $section_data = $this->productionSectionService->getById($idSection);
        $data['total_depreciation'] = $section_data['depreciation'];
        $data['total_water_cost'] = $section_data['water_cost'];
        $data['total_power_supply_cost'] = $section_data['power_supply_cost'];
        $data['total_fuel_cost'] = $section_data['fuel_cost'];
        $data['total_other_cost'] = $section_data['other_cost'];
        $data['total_employee_cost'] = round($this->productionEmployeeService->getCostBySection($idSection),2);
        $data['total_material_cost'] = round($this->profitabilityService->getRawProductCostBySectionInLastMonth($idSection), 2);
        $data['total_cost_without_employee_and_material'] = round(($section_data['depreciation'] + $section_data['water_cost'] + $section_data['power_supply_cost'] + $section_data['fuel_cost'] + $section_data['other_cost']), 2);
        $data['total_earning'] = round($this->profitabilityService->getEarningBySectionInLastMonth($idSection), 2);
        $data['total_cost'] = $data['total_employee_cost'] + $data['total_material_cost'] + $data['total_cost_without_employee_and_material'];
        $data['quota_margin'] = $data['total_earning'] - $data['total_cost'];

        $data['percentage_margin'] = $data['total_earning'] > 0 ? number_format((($data['quota_margin']/$data['total_earning'])*100),2) : 0;

        $products = $this->profitabilityService->getSoldProductListBySectionInLastMonth($idSection);
        $data['category_with_products'] = [];
        $data['quantity_of_sold_carton'] = 0;

        if(!empty($products)){
            foreach ($products as $product){
                if(!isset($data['category_with_products'][$product['id_category']])){
                    $data['category_with_products'][$product['id_category']]['id'] = $product['id_category'];
                    $data['category_with_products'][$product['id_category']]['name'] = $product['category_name'];
                    $data['category_with_products'][$product['id_category']]['total_cost'] = 0;
                    $data['category_with_products'][$product['id_category']]['total_earn'] = 0;
                    $data['category_with_products'][$product['id_category']]['products'] = [];
                }
                $data['category_with_products'][$product['id_category']]['total_cost'] += $product['total_raw_cost'];
                $data['category_with_products'][$product['id_category']]['total_earn'] += $product['earned_value_pln'];

                array_push($data['category_with_products'][$product['id_category']]['products'], $product);
                $data['quantity_of_sold_carton'] += $product['sold_quantity'];
            }

            usort($data['category_with_products'], function ($a, $b) {
                return strcmp($a['name'], $b['name']);
            });

        }

        $this->view("finances/production_section/profitability_details", $data);
    }
}