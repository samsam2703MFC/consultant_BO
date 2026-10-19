<?php
namespace App\Services\Product;

use App\Core\TransactionManager;
use App\Exceptions\CustomException;
use App\Services\Recipe\RecipeService;

/**
 * Kreator „Nowy produkt z recepturą”: produkt i jego receptura w jednej transakcji.
 *
 * Używa istniejącego zapisu: ProductService::insert, potem RecipeService::insert
 * (receptura, składniki, czynności, final_product.id_recipe oraz kroki „po zatwierdzeniu”:
 * spłaszczenie, etykieta, koszt). Całość działa w transakcji zewnętrznej — transakcja
 * receptury staje się SAVEPOINT-em (TransactionManager), a jej kroki końcowe biegną przed
 * COMMIT-em. Błąd na którymkolwiek etapie cofa też produkt: nie zostaje produkt bez receptury.
 */
class ProductRecipeWizardService
{
    private const PRODUCT_FIELDS = ['name', 'id_category', 'id_section', 'label_type', 'weight', 'ppc', 'ppb', 'bpc', 'cpl', 'lpp', 'cpp', 'shelf_life', 'stock_minimum'];
    private const POSITIVE_INT_FIELDS = ['weight', 'ppc', 'ppb', 'bpc', 'cpl', 'lpp', 'cpp', 'shelf_life', 'stock_minimum'];

    public function __construct(
        private ProductService     $productService,
        private RecipeService      $recipeService,
        private TransactionManager $transactionManager
    )
    {
    }

    /** Dane produktu jako tekst (tak jak z formularza), tylko znane pola. */
    public function normalizeProduct(array $raw): array
    {
        $out = [];
        foreach (self::PRODUCT_FIELDS as $field) {
            $value = $raw[$field] ?? '';
            $out[$field] = is_scalar($value) ? trim((string) $value) : '';
        }
        $out['label_type'] = $out['label_type'] === '1' ? '1' : '0';
        return $out;
    }

    /**
     * Błędy produktu: reguły ProductService::validate oraz liczby całkowite > 0
     * (kolumny final_product są typu INT).
     */
    public function productErrors(array $product): array
    {
        $errors = $this->productService->validationErrors($product);
        foreach (self::POSITIVE_INT_FIELDS as $field) {
            if (!isset($errors[$field]) && $product[$field] !== '' && !preg_match('/^[1-9]\d{0,8}$/', $product[$field])) {
                $errors[$field] = 'Podaj liczbę całkowitą większą od zera';
            }
        }
        foreach (['id_category', 'id_section'] as $field) {
            if (!isset($errors[$field]) && !preg_match('/^[1-9]\d*$/', $product[$field])) {
                $errors[$field] = 'Wybierz z listy';
            }
        }
        if (mb_strlen($product['name']) > 255) {
            $errors['name'] = 'Nazwa może mieć najwyżej 255 znaków';
        }
        return $errors;
    }

    /**
     * Linie receptury [{id, quantity}] => [id => ilość], ilości całkowite > 0
     * (product_recipe_connection.quantity jest typu INT).
     *
     * @return array<int,int>|string tablica albo komunikat błędu
     */
    public function normalizeLines($raw, string $label): array|string
    {
        if ($raw === null || $raw === []) return [];
        if (!is_array($raw)) return 'Nieprawidłowe dane: ' . $label;
        $lines = [];
        foreach ($raw as $line) {
            $id = filter_var($line['id'] ?? null, FILTER_VALIDATE_INT);
            $qty = filter_var($line['quantity'] ?? null, FILTER_VALIDATE_INT);
            if ($id === false || $id <= 0) return 'Nieprawidłowy identyfikator: ' . $label;
            if ($qty === false || $qty <= 0 || $qty > 2147483647) return 'Ilość musi być liczbą całkowitą większą od zera: ' . $label;
            if (isset($lines[$id])) return 'Ta sama pozycja występuje dwa razy: ' . $label;
            $lines[$id] = $qty;
        }
        return $lines;
    }

    /** Czynności [{id, duration_second}] => format RecipeService (preparation_type). */
    public function normalizePreparations($raw): array|string
    {
        if ($raw === null || $raw === []) return [];
        if (!is_array($raw)) return 'Nieprawidłowe dane czynności';
        $out = [];
        foreach ($raw as $line) {
            $id = filter_var($line['id'] ?? null, FILTER_VALIDATE_INT);
            $seconds = filter_var($line['duration_second'] ?? null, FILTER_VALIDATE_INT);
            if ($id === false || $id <= 0) return 'Nieprawidłowa czynność';
            if ($seconds === false || $seconds < 0 || $seconds > 2147483647) return 'Czas czynności musi być liczbą sekund ≥ 0';
            if (isset($out[$id])) return 'Ta sama czynność występuje dwa razy';
            $out[$id] = ['id_preparation' => $id, 'duration_second' => $seconds];
        }
        return $out;
    }

    /**
     * Tworzy produkt i recepturę razem.
     *
     * @param array<int,int> $materials    id surowca => ilość
     * @param array<int,int> $semiProducts id półproduktu => ilość
     * @return array{id_product:int, id_recipe:int}
     */
    public function create(array $product, array $materials, array $semiProducts, array $preparations): array
    {
        return $this->transactionManager->execute(function () use ($product, $materials, $semiProducts, $preparations) {
            $idProduct = $this->productService->insert($product);
            if (!$idProduct || $idProduct === true) {
                throw new CustomException('INSERT_PRODUCT_ERROR', 'errors', 500);
            }

            $recipeData = [
                'final_product'    => (int) $idProduct,
                'material_data'    => [],
                'sfp_data'         => [],
                'preparation_type' => $preparations,
            ];
            foreach ($materials as $id => $qty) {
                $recipeData['material_data'][$id] = ['id' => $id, 'quantity' => $qty];
            }
            foreach ($semiProducts as $id => $qty) {
                $recipeData['sfp_data'][$id] = ['id' => $id, 'quantity' => $qty];
            }
            foreach (['material_data', 'sfp_data', 'preparation_type'] as $key) {
                if (empty($recipeData[$key])) unset($recipeData[$key]);
            }
            if (!$this->recipeService->validate($recipeData)) {
                throw new CustomException('RECIPE_WITHOUT_INGREDIENTS', 'errors', 422);
            }

            $idRecipe = $this->recipeService->insert($recipeData);
            if (!$idRecipe) {
                throw new CustomException('INSERT_RECIPE_ERROR', 'errors', 500);
            }

            return ['id_product' => (int) $idProduct, 'id_recipe' => (int) $idRecipe];
        });
    }
}
