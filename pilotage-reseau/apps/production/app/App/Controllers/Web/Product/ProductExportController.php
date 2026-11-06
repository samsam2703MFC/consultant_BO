<?php

namespace App\Controllers\Web\Product;

use App\Core\Web\BaseController;
use App\Services\Product\ProductExportService;
use App\Repositories\Settings\LanguageRepository;

class ProductExportController extends BaseController
{
    private ProductExportService $productExportService;
    private LanguageRepository $languageRepository;

    public function __construct(
        ProductExportService $productExportService,
        LanguageRepository $languageRepository
    ) {
        $this->productExportService = $productExportService;
        $this->languageRepository = $languageRepository;
    }

    /**
     * Display export panel
     */
    public function index()
    {
        $products = $this->productExportService->getAllProductsForSelection();
        $languages = $this->languageRepository->findActive();

        $this->view('product/export', [
            'products' => $products,
            'languages' => $languages
        ]);
    }

    /**
     * AJAX: Export products to JSON
     */
    public function export()
    {
        header('Content-Type: application/json');

        try {
            $input = json_decode(file_get_contents('php://input'), true);

            if (!$input) {
                echo json_encode([
                    'ok' => false,
                    'error' => 'Nieprawidłowe dane wejściowe'
                ]);
                return;
            }

            $productIds = $input['product_ids'] ?? [];
            $langCode = $input['lang_code'] ?? 'pl';

            if (empty($productIds) || !is_array($productIds)) {
                echo json_encode([
                    'ok' => false,
                    'error' => 'Nie wybrano produktów do eksportu'
                ]);
                return;
            }

            $result = $this->productExportService->exportProducts($productIds, $langCode);

            if ($result['success']) {
                echo json_encode([
                    'ok' => true,
                    'data' => $result['data'],
                    'description' => 'Eksport zakończony pomyślnie'
                ]);
            } else {
                echo json_encode([
                    'ok' => false,
                    'error' => $result['error']
                ]);
            }
        } catch (\Exception $e) {
            echo json_encode([
                'ok' => false,
                'error' => 'Błąd eksportu: ' . $e->getMessage()
            ]);
        }
    }
}

