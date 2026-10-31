<?php

namespace App\Controllers\Web\Recipe;

use App\Services\Recipe\RecipeCalculationViewService;
use App\Core\Route;
use App\Core\Web\BaseController;
use App\Exceptions\CustomException;
use App\Repositories\Product\FinalProductRepository;
use App\Services\Material\MaterialService;
use App\Services\Product\ProductService;
use App\Services\Product\ProductsWithoutRecipePdfBuilder;
use App\Services\Recipe\CompositionService;
use App\Services\Recipe\FlattenedRecipeService;
use App\Services\Recipe\PredefinedRecipeLabelService;
use App\Services\Recipe\PreparationService;
use App\Services\Recipe\RecipeCompetencyService;
use App\Services\Recipe\RecipeExtrasService;
use App\Services\Recipe\RecipeCostRecalculationService;
use App\Services\Recipe\RecipeCostService;
use App\Services\Recipe\RecipeService;
use App\Services\Recipe\SemiProductService;
use App\Services\Utils\UnitService;
use Controller;
use Symfony\Component\HttpFoundation\Response;

/**
 * product class
 */
class RecipeController extends BaseController
{


    public function __construct(
        private CompositionService $compositionService,
        private MaterialService    $materialService,
        private UnitService        $unitService,
        private PreparationService $preparationService,
        private RecipeService      $recipeService,
        private SemiProductService $semiProductService,
        private ProductService     $productService,
        private RecipeCostService $recipeCostService,
        private FlattenedRecipeService $flattenedRecipeService,
        private RecipeCostRecalculationService $recipeCostRecalculationService,
        private PredefinedRecipeLabelService $predefinedRecipeLabelService,
        private RecipeCompetencyService $recipeCompetencyService,
        private ProductsWithoutRecipePdfBuilder $productsWithoutRecipePdfBuilder,
        private RecipeCalculationViewService $calculationView,
        private RecipeExtrasService $extras
    )
    {
    }

    #[Route('GET', '/recipes')]
    public function overview()
    {
        $data['final_recipes']  = $this->recipeService->getFinalRecipes();
        $data['competencies']   = $this->recipeCompetencyService->getAll();

        $this->view("recipe/recipe/overview", $data);
    }

    /**
     * Nowa receptura produktu. Z Karty produktu (krok 3) : ?product= wybiera produkt, ?add_sfp= dopisuje półprodukt
     * utworzony przed chwilą (SemiProductController), a po zapisie wracamy do karty. Bez parametrów jak dotąd.
     */
    public function newRecipe()
    {
        $forProduct = $this->cardProduct();
        if ($forProduct && !empty($forProduct['id_recipe'])) {
            // Produkt ma już recepturę : półprodukt dopisujemy w jej edycji, nie w drugiej recepturze.
            header('Location: ' . ROOT . '/recipes/' . (int) $forProduct['id_recipe'] . '/edit?' . http_build_query(array_filter(['product' => (int) $forProduct['id'], 'add_sfp' => (int) ($_GET['add_sfp'] ?? 0)])));
            exit;
        }

        $data['units'] = $this->unitService->getSmallUnit();
        $data['materials'] = $this->materialService->getAll();
        $data['preparation_types'] = $this->preparationService->getPreparationTypes();
        $data['sfp'] = $this->semiProductService->getAll();
        $data['products_without_recipe'] = $this->productService->getWithoutRecipe();

        if ($forProduct) {
            // Produkt nieaktywny nie jest na liście « bez receptury » : bez niego lista wybrałaby inny produkt.
            if (!in_array((int) $forProduct['id'], array_map('intval', array_column($data['products_without_recipe'], 'id')), true)) {
                array_unshift($data['products_without_recipe'], ['id' => $forProduct['id'], 'name' => $forProduct['name']]);
            }
            $data['for_product'] = $forProduct;
            $data['selected_product'] = (int) $forProduct['id'];
            $data['card_url'] = $this->productService->cardUrl((int) $forProduct['id'], 4);
            $data['old_data']['sfp_data'] = $this->addedSemiProduct([]);
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if ($this->recipeService->validate($_POST)) {
                $dataToInsert = $_POST;

                $newRecipeId = $this->recipeService->insert($dataToInsert);

                if ($newRecipeId) {
                    $this->saveExtras((int) $newRecipeId, true);

                    header('Location: ' . ($forProduct ? $this->productService->cardUrl((int) $dataToInsert['final_product'], 4) : ROOT . '/recipes/' . $newRecipeId . '/calculation'));
                    exit;
                }

            } else {
                $data['old_data'] = $_POST;
                $this->errors = $this->recipeService->errors();
            }
        }

        $data += $this->extras->formData(null, 'product', $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : null);
        $this->view("recipe/recipe/new", $data);
    }

    /** Produkt z Karty produktu (?product=), dla którego powstaje receptura ; null poza tą ścieżką. */
    private function cardProduct(): ?array
    {
        $id = (int) ($_GET['product'] ?? 0);
        $product = $id > 0 ? $this->productService->getById($id) : null;
        return $product ?: null;
    }

    /** Półprodukt właśnie utworzony dla produktu (?add_sfp=) : wiersz do uzupełnienia ilością, jeśli go jeszcze nie ma. */
    private function addedSemiProduct(array $rows): array
    {
        $id = (int) ($_GET['add_sfp'] ?? 0);
        if ($id <= 0 || in_array($id, array_map('intval', array_column($rows, 'id')), true)) return $rows;
        foreach ($this->semiProductService->getAll() as $semi) {
            if ((int) $semi['id'] === $id) {
                $rows[] = ['id' => $id, 'name' => $semi['name'], 'quantity' => '', 'added' => true];
                break;
            }
        }
        return $rows;
    }


    public function editRecipe($id)
    {
        $data['units'] = $this->unitService->getSmallUnit();
        $data['materials'] = $this->materialService->getAll();
        $data['preparation_types'] = $this->preparationService->getPreparationTypes();
        $data['sfp'] = $this->semiProductService->getAll();

        $data['product_data'] = $this->productService->getDataByRecipeId($id);

        $data['old_data']['material_data'] = $this->recipeService->getRecipeMaterials($id);
        $data['old_data']['sfp_data'] = $this->addedSemiProduct($this->recipeService->getRecipeSFP($id));
        $data['old_data']['preparation_type'] = $this->recipeService->getRecipePreparationType($id);

        // Z Karty produktu (?product=) : po zapisie powrót do kroku « Receptura » karty produktu tej receptury.
        $fromCard = !empty($_GET['product']) && !empty($data['product_data']['id']);
        if ($fromCard) {
            $data['for_product'] = $data['product_data'];
            $data['card_url'] = $this->productService->cardUrl((int) $data['product_data']['id'], 4);
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $_POST['id_recipe'] = $id;
            $data['old_data'] = $_POST;

            if ($this->recipeService->validateEdit($_POST)) {
                $dataToUpdate = $_POST;

                $success = $this->handleAction(function () use ($id, $dataToUpdate) {
                    $this->recipeService->update($id, $dataToUpdate);
                    return true;
                }, default: false);

                if ($success) {
                    $this->saveExtras((int) $id, false);
                    header('Location: ' . ($fromCard ? $data['card_url'] : ROOT . '/recipes/' . $id . '/calculation'));
                    exit;
                }
            } else {
                $this->errors = $this->recipeService->errors();
            }
        }

        $data += $this->extras->formData((int) $id, 'product', $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : null);
        $this->view("recipe/recipe/edit", $data);
    }


    public function deleteRecipe($id)
    {
        $data['product_data'] = $this->productService->getDataByRecipeId($id);

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $success = $this->handleAction(function () use ($id) {
                $this->recipeService->delete($id);
                return true;
            }, default: false);

            if ($success) {
                header('Location: ' . ROOT . '/recipes');
                exit;
            }
        }

        $this->view("recipe/recipe/delete", $data);
    }


    public function calculation($id)
    {
        try {
            $data = $this->calculationView->build((int) $id, 'product');
        } catch (CustomException $e) {
            http_response_code(404);
            echo 'Nie znaleziono receptury.';
            return;
        }
        if (($_SESSION['recipe_saved']['id'] ?? null) === (int) $id) {
            $data['saved'] = $_SESSION['recipe_saved'];
            unset($_SESSION['recipe_saved']);
        }
        $this->view('recipe/calculation', $data);
    }



    public function final_recipe($action = null, $id = null)
    {

        if ($action == "new") {

        } else if ($action == "edit") {

        } else if ($action == "delete") {
            $recipeModel->deleteRecipe(['id_recipe' => $id]);

        } else if ($action == "calculation") {

            $data['recipe_calculation_data'] = $recipeModel->getRecipeCalculation($id);
            $data['recipe_price'] = $recipeModel->getRecipeSummary($id)['total_ingredient_cost'];

        } else {

        }
        $data['errors'] = $recipeModel->errors;

        $this->view("recipe/final_recipe", $data);
    }




    public function ajax($target = null, $action = null)
    {
        $userModel = new UserModel();
        if (!$userModel->logged_in())
            redirect('login');

        $resp = false;
        if ($target == "recipe" && $action == "get_tree") {
            $recipeModel = new RecipeModel();

            $data = json_decode(json_encode(json_decode($_POST['id_recipe'])), true);
            $resp['recipe_tree'] = $recipeModel->getDataForBOM(['id_recipe' => $data]);
            $resp['recipe_data'] = $recipeModel->getRecipeBasicData(['id_recipe' => $data]);
        }


        echo json_encode($resp);
    }

    public function ajaxGetProductsWithoutRecipePdf()
    {
        $grouped = $this->productService->getWithoutRecipeGrouped();
        $result  = $this->productsWithoutRecipePdfBuilder->build($grouped);

        $pdfData  = $result['file_string'];
        $filename = htmlspecialchars($result['filename']);

        return new Response(
            $pdfData,
            200,
            [
                'Content-Type'        => 'application/pdf',
                'Content-Disposition' => "attachment; filename=\"{$filename}\"",
                'Content-Length'      => strlen($pdfData),
            ]
        );
    }

    public function ajaxGetRecipeFinalCostsCsv()
    {
        $respDoc = $this->recipeCostService->getRecipeFinalCostsCsv();

        if (!$respDoc) {
            return $this->json($respDoc, $respDoc['code']);
        }

        $pdfData  = $respDoc['file_string'];
        $filename = htmlspecialchars($respDoc['filename']);

        return new Response(
            $pdfData,
            200,
            [
                'Content-Type' => 'text/csv',
                'Content-Disposition' => "attachment; filename={$filename}",
                'Content-Length'      => strlen($pdfData),
            ]
        );
    }

    #[Route('GET', '/ajax/settings/flat-all-recipes')]
    public function ajaxFlatAllRecipes()
    {
        $res = $this->flattenedRecipeService->flatForAll();
        return $this->json($res);
    }

    #[Route('GET', '/ajax/settings/recalc-all-recipes')]
    public function ajaxRecalcAllRecipes()
    {
        $res = $this->recipeCostRecalculationService->recalcForAll();
        return $this->json($res);
    }

    #[Route('GET', '/ajax/settings/rewrite-all-labels')]
    public function ajaxRewriteAllLabels()
    {
        $res = $this->predefinedRecipeLabelService->buildForAllRecipes();
        return $this->json($res);
    }

    /** Zakładki Przygotowanie i Grupa i kompetencje (instrukcja, kompetencje, grupa) po zapisie składu ; wynik na kalkulacji. */
    private function saveExtras(int $recipeId, bool $isNew): void
    {
        $_SESSION['recipe_saved'] = ['id' => $recipeId, 'new' => $isNew, 'problems' => $this->extras->save($recipeId, $_POST, $isNew, user('id') ? (int) user('id') : null)];
    }
}
