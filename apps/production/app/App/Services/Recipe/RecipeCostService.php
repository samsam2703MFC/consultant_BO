<?php
namespace App\Services\Recipe;

use App\Csv\Recipe\RecipeCostCsvBuilder;
use App\Repositories\Product\FinalProductRepository;
use App\Repositories\Product\Recipe\RecipeRepository;

class RecipeCostService
{

    public function __construct(
        private RecipeCostCsvBuilder $recipeCostCsvBuilder,
        private RecipeService $recipeService,
        private FinalProductRepository $productRepository
    )
    {
    }

    public function getRecipeFinalCostsCsv()
    {
        $data = $this->recipeService->getAllWithPrice();

        if(empty($data)){
            return null;
        }

        foreach ($data as &$recipe){
            $supplementaryData = $this->productRepository->getSupplementaryDataForRecipeCostCsv($recipe['id']);
            $recipe['cost'] = str_replace(".", ",", $recipe['raw_price']);
            $recipe['berdiff_price'] = str_replace(".", ",", $supplementaryData['berdiff_price']);
            $recipe['max_price'] = str_replace(".", ",", $supplementaryData['max_price']);
            $recipe['ppc'] = $supplementaryData['ppc'];
        }

        return $this->recipeCostCsvBuilder->build($data);
    }
}