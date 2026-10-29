<?php
namespace App\Controllers\Web\Product;

use App\Core\Web\BaseController;
use App\Exceptions\CustomException;
use App\Services\Employee\Section\ProductionSectionService;
use App\Services\Material\MaterialService;
use App\Services\Product\Category\ProductCategoryService;
use App\Services\Product\ProductRecipeWizardService;
use App\Services\Recipe\PreparationService;
use App\Services\Recipe\RecipeService;
use App\Services\Recipe\SemiProductService;
use Symfony\Component\HttpFoundation\Request;

/**
 * Kreator „Nowy produkt z recepturą”: tożsamość → opakowanie i wagi → receptura
 * z kosztem szkicu → czynności → podsumowanie i zapis (produkt + receptura razem).
 */
class ProductRecipeWizardController extends BaseController
{
    public function __construct(
        private ProductRecipeWizardService $wizard,
        private RecipeService              $recipeService,
        private ProductCategoryService     $productCategoryService,
        private ProductionSectionService   $productionSectionService,
        private MaterialService            $materialService,
        private SemiProductService         $semiProductService,
        private PreparationService         $preparationService,
        private \App\Services\Workshop\Execution\DevProductService $dev,
        private \App\Repositories\Workshop\RecipeProducerRepository $producers
    )
    {
    }

    public function index()
    {
        $pick = static fn(array $rows, array $keys) => array_map(
            static fn(array $r) => array_intersect_key($r, array_flip($keys)),
            $rows ?: []
        );

        $this->view('product/product/wizard', [
            'categories'   => $pick($this->productCategoryService->findAll(), ['id', 'name']),
            'sections'     => $pick($this->productionSectionService->getAll(), ['id', 'name']),
            'materials'    => $pick($this->materialService->getAll(), ['id', 'name', 'unit_name', 'category_name']),
            'semiProducts' => $pick($this->semiProductService->getAll(), ['id', 'name', 'unit_name']),
            'preparations' => $pick($this->preparationService->getPreparationTypes(), ['id', 'name']),
            // Półprodukt : która grupa go robi (to samo ustawienie co receptura i kalkulacja, tablet zamawia u niej).
            'producerGroups' => $pick($this->producers->groups(), ['id', 'name_pl', 'color']),
            'producerMap'  => (object) $this->producers->groupMap(),
            'preset_category' => (int) ($_GET['category'] ?? 0),
            // « Wprowadź » produktu testowego : kreator wypełniony danymi i recepturą testu.
            'from_test' => $this->testPrefill((int) ($_GET['from_test'] ?? 0)),
            // Zakresy logistyki — ta sama walidacja co Karta produktu (App\Domain\Product\ProductLogistics)
            'logisticsRanges' => \App\Domain\Product\ProductLogistics::RANGES,
            'shelfLife' => \App\Domain\Product\ProductLogistics::SHELF_LIFE,
        ]);
    }

    /**
     * POST /ajax/recipes/draft-cost  {materials:[{id, quantity}], semi_products:[{id, quantity}]}
     * Koszt surowców szkicu receptury na 1 szt. Tylko odczyt.
     */
    public function ajaxDraftCost()
    {
        $payload = $this->getJson(Request::createFromGlobals());
        $read = static function ($lines): array {
            $out = [];
            foreach (is_array($lines) ? $lines : [] as $line) {
                $id = filter_var($line['id'] ?? null, FILTER_VALIDATE_INT);
                $qty = filter_var($line['quantity'] ?? null, FILTER_VALIDATE_FLOAT);
                if ($id === false || $id <= 0) continue;
                $out[$id] = ($qty === false || $qty < 0) ? 0 : $qty;
            }
            return $out;
        };

        try {
            return $this->json($this->recipeService->getDraftCost($read($payload['materials'] ?? []), $read($payload['semi_products'] ?? [])));
        } catch (\Throwable $e) {
            error_log('[product-wizard] draft cost failed: ' . $e->getMessage());
            return $this->json(['message' => 'Nie udało się policzyć kosztu.'], 500);
        }
    }

    /**
     * POST /ajax/products/with-recipe
     * {product:{…}, materials:[{id, quantity}], semi_products:[{id, quantity}], preparations:[{id, duration_second}]}
     */
    public function ajaxCreate()
    {
        $payload = $this->getJson(Request::createFromGlobals());

        $product = $this->wizard->normalizeProduct(is_array($payload['product'] ?? null) ? $payload['product'] : []);
        $errors = $this->wizard->productErrors($product);
        if ($errors) {
            return $this->json(['message' => 'Popraw dane produktu.', 'errors' => $errors], 422);
        }

        $materials = $this->wizard->normalizeLines($payload['materials'] ?? [], 'surowce');
        $semiProducts = $this->wizard->normalizeLines($payload['semi_products'] ?? [], 'półprodukty');
        $preparations = $this->wizard->normalizePreparations($payload['preparations'] ?? []);
        foreach ([$materials, $semiProducts, $preparations] as $part) {
            if (is_string($part)) {
                return $this->json(['message' => $part], 422);
            }
        }
        if (!$materials && !$semiProducts) {
            return $this->json(['message' => 'Dodaj do receptury co najmniej jeden surowiec lub półprodukt.'], 422);
        }
        $check = $this->recipeService->getDraftCost($materials, $semiProducts);
        if ($check['missing']) {
            return $this->json(['message' => 'Część składników nie istnieje (' . implode(', ', $check['missing']) . '). Odśwież stronę.'], 422);
        }

        try {
            $created = $this->wizard->create($product, $materials, $semiProducts, $preparations);
        } catch (CustomException $e) {
            $code = strtok($e->getMessage(), ':');
            error_log('[product-wizard] create failed: ' . $e->getMessage());
            return $this->json(['message' => 'Nie udało się utworzyć produktu z recepturą (' . $code . '). Nic nie zostało zapisane.'], $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 500);
        } catch (\Throwable $e) {
            error_log('[product-wizard] create failed: ' . $e);
            return $this->json(['message' => 'Nie udało się utworzyć produktu z recepturą. Nic nie zostało zapisane.'], 500);
        }

        // Produkt z testu : powiązanie i etap « Wprowadzony » (błąd powiązania nie cofa produktu — komunikat w karcie testu).
        $testId = (int) ($payload['from_test'] ?? 0);
        if ($testId > 0) {
            try {
                $this->dev->launch($testId, ['final_product_id' => (int) $created['id_product']], user('id') ? (int) user('id') : null);
                $created['test_linked'] = true;
            } catch (\Throwable $e) {
                error_log('[product-wizard] test link failed: ' . $e->getMessage());
                $created['test_linked'] = false;
            }
        }

        // Ciąg dalszy w tym samym kreatorze : karta produktu od logistyki (tryb, grupa, etapy i czasy).
        return $this->json($created + ['redirect' => ROOT . '/workshop/products#/product/' . $created['id_product'] . '?step=2']);
    }

    /** Kreator z produktu testowego : pola i linie receptury testu (ilości całkowite jak w kreatorze). */
    private function testPrefill(int $testId): ?array
    {
        if ($testId <= 0) return null;
        $prefill = $this->dev->launchPrefill($testId);
        if (!$prefill) return null;
        $lines = static fn($rows) => array_values(array_map(static fn($r) => ['id' => (int) $r['id'], 'quantity' => max(1, (int) round((float) $r['quantity']))], $rows ?: []));
        $prefill['materials'] = $prefill['id_recipe'] ? $lines($this->recipeService->getRecipeMaterials($prefill['id_recipe'])) : [];
        $prefill['semi_products'] = $prefill['id_recipe'] ? $lines($this->recipeService->getRecipeSFP($prefill['id_recipe'])) : [];
        return $prefill;
    }
}
