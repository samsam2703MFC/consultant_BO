<?php
namespace App\Controllers\Web\Recipe;

use App\Services\Recipe\RecipeCalculationViewService;
use App\Repositories\Workshop\RecipeProducerRepository;
use App\Core\Web\BaseController;
use App\Exceptions\CustomException;
use App\Services\Material\MaterialService;
use App\Services\Product\ProductService;
use App\Services\Recipe\CompositionService;
use App\Services\Recipe\PreparationService;
use App\Services\Recipe\RecipeCompetencyService;
use App\Services\Recipe\RecipeCostRecalculationService;
use App\Services\Recipe\RecipeService;
use App\Services\Recipe\SemiProductService;
use App\Services\Utils\UnitService;

class SemiProductController extends BaseController
{
    private CompositionService $compositionService;
    private MaterialService $materialService;
    private UnitService $unitService;
    private PreparationService $preparationService;
    private RecipeService $recipeService;
    private SemiProductService $semiProductService;

    public function __construct(
        CompositionService $compositionService,
        MaterialService    $materialService,
        UnitService        $unitService,
        PreparationService $preparationService,
        RecipeService      $recipeService,
        SemiProductService $semiProductService,
        private RecipeCostRecalculationService $recipeCostRecalculationService,
        private RecipeCompetencyService $recipeCompetencyService,
        private ProductService $productService,
        private RecipeCalculationViewService $calculationView,
        private RecipeProducerRepository $producers,
        private \App\Services\Recipe\RecipeExtrasService $extras
    )
    {
        $this->compositionService = $compositionService;
        $this->unitService = $unitService;
        $this->materialService = $materialService;
        $this->preparationService = $preparationService;
        $this->recipeService = $recipeService;
        $this->semiProductService = $semiProductService;
    }

    public function overview()
    {
        $data['semi_final_recipes'] = $this->recipeService->getByType(2);
        $data['competencies']       = $this->recipeCompetencyService->getAll();

        $this->view("recipe/semi_product/overview", $data);
    }

    /**
     * Nowy półprodukt. Z Karty produktu (?for_product=) po zapisie przechodzimy do receptury tego produktu
     * z nowym półproduktem na liście (RecipeController::newRecipe, edycja, jeśli receptura już jest).
     */
    public function newSemiProduct()
    {
        $data['units'] = $this->unitService->getSmallUnit();
        $data['materials'] = $this->materialService->getAll();
        $data['preparation_types'] = $this->preparationService->getPreparationTypes();
        $data['compositions'] = $this->compositionService->getAll();
        $data['subrecipes'] = $this->semiProductService->getAll();

        $productId = (int) ($_GET['for_product'] ?? 0);
        $forProduct = $productId > 0 ? ($this->productService->getById($productId) ?: null) : null;
        if ($forProduct) {
            $data['for_product'] = $forProduct;
            $data['card_url'] = $this->productService->cardUrl($productId, 4);
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if ($this->semiProductService->validate($_POST)) {
                $dataToInsert = $_POST;

                $newId = $this->semiProductService->insert($dataToInsert);

                if ($newId) {
                    $this->saveExtras((int) $newId, true);
                    // Okno z kreatora produktu (krok « Receptura ») : półprodukt wraca do kreatora, okno się zamyka.
                    if (!empty($_GET['popup'])) {
                        unset($_SESSION['recipe_saved']);
                        $this->view('recipe/semi_product/popup_done', ['semi' => ['id' => (int) $newId, 'name' => trim((string) ($dataToInsert['name'] ?? '')), 'unit_name' => 'szt']]);
                        return;
                    }
                    header('Location: ' . ROOT . ($forProduct
                        ? '/recipes/new?' . http_build_query(['product' => $productId, 'add_sfp' => (int) $newId])
                        : '/semi-products/' . $newId . '/calculation'));
                    exit;
                }
            } else {
                $data['old_data'] = $_POST;
                $this->errors = $this->semiProductService->errors();
            }
        }

        $data += $this->extras->formData(null, 'semi', $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : null);
        $this->view("recipe/semi_product/new", $data);
    }


    /**
     * POST /ajax/semi-products/quick {name, group_id, materials:[{id, quantity}]} — kreator produktu [201] : półprodukt
     * (1 szt., jak formularz) z surowcami i grupą, która go robi ; ta sama walidacja i zapis (spłaszczenie, koszt).
     */
    public function ajaxQuick()
    {
        $in = $this->getJson(\Symfony\Component\HttpFoundation\Request::createFromGlobals()) ?: [];
        $name = trim((string) ($in['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 255) return $this->json(['message' => 'Nazwa : od 1 do 255 znaków.', 'errors' => ['name' => 'Wprowadź nazwę']], 422);
        $known = array_flip(array_map('intval', array_column($this->materialService->getAll() ?: [], 'id')));
        $materials = [];
        foreach (is_array($in['materials'] ?? null) ? $in['materials'] : [] as $l) {
            $id = filter_var($l['id'] ?? null, FILTER_VALIDATE_INT);
            $qty = filter_var($l['quantity'] ?? null, FILTER_VALIDATE_INT);
            if ($id === false || !isset($known[$id])) return $this->json(['message' => 'Nieznany surowiec.'], 422);
            if ($qty === false || $qty <= 0 || $qty > 2147483647) return $this->json(['message' => 'Ilość surowca : liczba całkowita większa od zera.'], 422);
            if (isset($materials[$id])) return $this->json(['message' => 'Ten sam surowiec występuje dwa razy.'], 422);
            $materials[$id] = ['id' => $id, 'quantity' => $qty];
        }
        if (!$materials) return $this->json(['message' => 'Dodaj co najmniej jeden surowiec.'], 422);
        $group = filter_var($in['group_id'] ?? null, FILTER_VALIDATE_INT);
        if ($group !== false && $group !== null && !$this->producers->groupExists($group)) return $this->json(['message' => 'Nieznana grupa.'], 422);
        $data = ['name' => $name, 'material_data' => $materials];
        if (!$this->semiProductService->validate($data)) return $this->json(['message' => implode(' ', $this->semiProductService->errors())], 422);
        try {
            $newId = (int) $this->semiProductService->insert($data);
            $problems = $group ? $this->extras->save($newId, ['producer_group_id' => (string) $group], true, user('id') ? (int) user('id') : null) : [];
        } catch (\Throwable $e) {
            error_log('[semi-quick] ' . $e->getMessage());
            return $this->json(['message' => 'Nie udało się zapisać półproduktu.'], 500);
        }
        return $this->json(['message' => 'Półprodukt « ' . $name . ' » utworzony.' . ($problems ? ' ' . implode(' ', $problems) : ''),
            'data' => ['id' => $newId, 'name' => $name, 'unit_name' => 'szt', 'group_id' => $group ?: null]], 201);
    }

    public function editSemiProduct($id)
    {
        $data['units'] = $this->unitService->getSmallUnit();
        $data['materials'] = $this->materialService->getAll();
        $data['preparation_types'] = $this->preparationService->getPreparationTypes();
        $data['compositions'] = $this->compositionService->getAll();
        $data['subrecipes'] = $this->semiProductService->getAll();

        $data['row'] = $this->recipeService->getRecipeBasicData($id);
        $data['old_data']['material_data'] = $this->recipeService->getRecipeMaterials($id);
        $data['old_data']['composition_data'] = $this->recipeService->getRecipeCompositions($id);
        $data['old_data']['preparation_type'] = $this->recipeService->getRecipePreparationType($id);
        $data['producer_groups'] = $this->producers->groups();
        $data['producer'] = $this->producers->forRecipes([(int) $id])[(int) $id] ?? null;

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $_POST['id_recipe'] = $id;
            $data['old_data'] = $_POST;

            if ($this->semiProductService->validateEdit($_POST)) {
                $dataToUpdate = $_POST;

                $success = $this->handleAction(function () use ($id, $dataToUpdate) {
                    $this->semiProductService->update($id, $dataToUpdate);
                    return true;
                }, default: false);

                if ($success) {
                    $this->saveExtras((int) $id, false);
                    header('Location: ' . ROOT . '/semi-products/' . $id . '/calculation');
                    exit;
                }
            }
        }

        $data += $this->extras->formData((int) $id, 'semi', $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : null);
        $this->view("recipe/semi_product/edit", $data);
    }

    public function deleteSemiProduct($id)
    {
        $data['row'] = $this->recipeService->getRecipeBasicData($id);

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $success = $this->handleAction(function () use ($id) {
                $this->semiProductService->delete($id);
                return true;
            }, default: false);

            if ($success) {
                header('Location: ' . ROOT . '/semi-products');
                exit;
            }
        }

        $this->view("recipe/semi_product/delete", $data);
    }

    public function calculation($id)
    {
        try {
            $data = $this->calculationView->build((int) $id, 'semi');
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

    /** Zakładki Przygotowanie i Grupa i kompetencje (instrukcja, kompetencje, grupa) po zapisie składu ; wynik na kalkulacji. */
    private function saveExtras(int $recipeId, bool $isNew): void
    {
        $_SESSION['recipe_saved'] = ['id' => $recipeId, 'new' => $isNew, 'problems' => $this->extras->save($recipeId, $_POST, $isNew, user('id') ? (int) user('id') : null)];
    }
}
