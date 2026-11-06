<?php

namespace App\Controllers\Web\Material;

use App\Core\Web\BaseController;
use App\Services\Material\MaterialExportService;
use App\Repositories\Settings\LanguageRepository;

class MaterialExportController extends BaseController
{
    private MaterialExportService $materialExportService;
    private LanguageRepository $languageRepository;

    public function __construct(
        MaterialExportService $materialExportService,
        LanguageRepository $languageRepository
    ) {
        $this->materialExportService = $materialExportService;
        $this->languageRepository = $languageRepository;
    }

    /**
     * Display export panel
     */
    public function index()
    {
        $materials = $this->materialExportService->getAllMaterialsForSelection();
        $languages = $this->languageRepository->findActive();

        $this->view('material/export', [
            'materials' => $materials,
            'languages' => $languages
        ]);
    }

    /**
     * AJAX: Export materials to JSON
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

            $materialIds = $input['material_ids'] ?? [];
            $langCode = $input['lang_code'] ?? 'pl';

            if (empty($materialIds) || !is_array($materialIds)) {
                echo json_encode([
                    'ok' => false,
                    'error' => 'Nie wybrano surowców do eksportu'
                ]);
                return;
            }

            $result = $this->materialExportService->exportMaterials($materialIds, $langCode);

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


