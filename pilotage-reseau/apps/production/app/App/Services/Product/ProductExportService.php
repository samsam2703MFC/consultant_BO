<?php

namespace App\Services\Product;

use App\Repositories\Product\ProductExportRepository;
use App\Repositories\Settings\LanguageRepository;

class ProductExportService
{
    private ProductExportRepository $productExportRepository;
    private LanguageRepository $languageRepository;

    public function __construct(
        ProductExportRepository $productExportRepository,
        LanguageRepository $languageRepository
    ) {
        $this->productExportRepository = $productExportRepository;
        $this->languageRepository = $languageRepository;
    }

    /**
     * Get all products for selection in export panel
     */
    public function getAllProductsForSelection(): array
    {
        return $this->productExportRepository->getAllProductsSimple();
    }

    /**
     * Export products data as JSON
     */
    public function exportProducts(array $productIds, string $langCode): array
    {
        if (empty($productIds)) {
            return [
                'success' => false,
                'error' => 'Nie wybrano żadnych produktów do eksportu'
            ];
        }

        // Validate language
        $language = $this->languageRepository->findByCode($langCode);
        if (empty($language)) {
            return [
                'success' => false,
                'error' => 'Nieprawidłowy kod języka'
            ];
        }

        // Get products data
        $products = $this->productExportRepository->getProductsForExport($productIds, $langCode);

        if (empty($products)) {
            return [
                'success' => false,
                'error' => 'Nie znaleziono produktów do eksportu'
            ];
        }

        // Format data for export
        $exportData = [
            'export_info' => [
                'timestamp' => date('Y-m-d H:i:s'),
                'language' => $langCode,
                'language_name' => $language['name'] ?? $langCode,
                'total_products' => count($products)
            ],
            'products' => array_map(function($product) {
                $formattedProduct = [
                    'sku' => (int)$product['id'],
                    'name' => $product['name'],
                    'category_id' => (int)$product['id_category'],
                    'category_name' => $product['category_name'] ?? 'Brak kategorii',
                    'active' => (bool)$product['active'],
                    'weight_grams' => (int)$product['weight'],
                    'weight_unit' => 'g',
                    'package_size' => (int)$product['ppc'],
                    'shelf_life_days' => (int)$product['shelf_life'],
                    'storage_info' => $product['storage_info_label'] ?? '',
                    'preparation_info' => $product['prepare_info_label'] ?? '',
                ];

                // Add composition and allergens only if product has recipe
                if ($product['id_recipe']) {
                    // Build ingredients string (comma separated, ordered by quantity DESC)
                    $ingredientsList = array_map(function($ing) {
                        return $ing['material_name'];
                    }, $product['ingredients']);

                    $formattedProduct['composition'] = implode(', ', $ingredientsList);

                    // Build allergens array
                    $allergensList = array_map(function($allergen) {
                        return [
                            'code' => $allergen['code'],
                            'name' => $allergen['allergen_name']
                        ];
                    }, $product['allergens']);

                    $formattedProduct['allergens'] = $allergensList;

                    // Also add allergens as comma-separated string for convenience
                    $allergensNames = array_map(function($a) {
                        return $a['allergen_name'];
                    }, $product['allergens']);

                    $formattedProduct['allergens_text'] = implode(', ', $allergensNames);
                } else {
                    $formattedProduct['composition'] = null;
                    $formattedProduct['allergens'] = [];
                    $formattedProduct['allergens_text'] = '';
                }

                return $formattedProduct;
            }, $products)
        ];

        return [
            'success' => true,
            'data' => $exportData
        ];
    }
}

