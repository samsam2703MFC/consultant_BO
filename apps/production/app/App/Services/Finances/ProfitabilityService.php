<?php
namespace App\Services\Finances;

use App\Repositories\Analysis\ProfitabilityRepository;
use App\Repositories\Company\ErpCompanyOtherSettingsRepository;
use App\Repositories\Document\Wdt\WDTProductRepository;
use App\Repositories\Document\Wdt\WDTRepository;
use App\Repositories\Product\Recipe\RecipeRepository;
use App\Services\Recipe\RecipeService;

class ProfitabilityService
{

    private ProfitabilityRepository $profitabilityRepository;
    private WDTProductRepository $wdtProductRepository;
    private RecipeService $recipeService;
    private ErpCompanyOtherSettingsRepository $erpCompanyOtherSettingsRepository;

    public function __construct(
        ProfitabilityRepository $profitabilityRepository,
        WDTProductRepository $wdtProductRepository,
        RecipeService $recipeService,
        ErpCompanyOtherSettingsRepository $erpCompanyOtherSettingsRepository
    )
    {
        $this->profitabilityRepository = $profitabilityRepository;
        $this->wdtProductRepository = $wdtProductRepository;
        $this->recipeService = $recipeService;
        $this->erpCompanyOtherSettingsRepository = $erpCompanyOtherSettingsRepository;
    }

    public function getRawProductCostBySectionInLastMonth($idSection)
    {
        $products = $this->getSoldProductListBySectionInLastMonth($idSection);
        if (empty($products)) return 0;
        $total_raw_product_cost = 0;

        foreach ($products as &$product) {
            $total_raw_product_cost += $product['total_raw_cost'];
        }

        return $total_raw_product_cost;
    }

    public function getSoldProductListBySectionInLastMonth($id_section)
    {
        $exchange_rate_euro = $this->erpCompanyOtherSettingsRepository->getExchangeRateEuro();

        $products = $this->wdtProductRepository->getSoldDataBySectionInLastMonth($id_section);
        if (empty($products)) return [];

        foreach ($products as &$product) {
            $product ['earned_value_pln'] = $product ['earned_value_euro'] * $exchange_rate_euro;

            if (is_null($product ['id_recipe'])) {
                $product ['raw_cost'] = 0;
                $product ['total_raw_cost'] = 0;
                continue;
            }
            $product ['single_raw_cost'] = $this->recipeService->getRecipeSummary($product ['id_recipe'])['total_ingredient_cost'];
            $product ['total_raw_cost'] = $product ['single_raw_cost'] * $product ['sold_quantity'];
        }

        return $products;
    }

    public function getEarningBySectionInLastMonth($id_section)
    {
        $products = $this->wdtProductRepository->getSoldDataBySectionInLastMonth($id_section);
        $exchange_rate_euro = $this->erpCompanyOtherSettingsRepository->getExchangeRateEuro();

        if (empty($products)) return 0;
        $total_earning = 0;

        foreach ($products as &$product) {
            $total_earning += $product['earned_value_euro'] * $exchange_rate_euro;
        }

        return $total_earning;
    }

    public function getSoldProductListByCustomerInLastMonth($idClient)
    {
        $exchange_rate_euro = $this->erpCompanyOtherSettingsRepository->getExchangeRateEuro();

        $products = $this->wdtProductRepository->getSoldDataByCustomerInLastMonth($idClient);
        if (empty($products)) return [];

        foreach ($products as &$product) {
            $product['earned_value_pln'] = $product['earned_value_euro'] * $exchange_rate_euro;

            if (is_null($product['id_recipe'])) {
                $product['raw_cost'] = 0;
                $product['total_raw_cost'] = 0;
                continue;
            }
            $product['single_raw_cost'] = $this->recipeService->getRecipeSummary($product['id_recipe'])['total_ingredient_cost'];
            $product['total_raw_cost'] = $product['single_raw_cost'] * $product['sold_quantity'];
        }

        return $products;
    }
}