<?php

namespace App\Services\Material;

use App\Repositories\Material\MaterialExportRepository;
use App\Repositories\Settings\LanguageRepository;

class MaterialExportService
{
    private MaterialExportRepository $materialExportRepository;
    private LanguageRepository $languageRepository;

    public function __construct(
        MaterialExportRepository $materialExportRepository,
        LanguageRepository $languageRepository
    ) {
        $this->materialExportRepository = $materialExportRepository;
        $this->languageRepository = $languageRepository;
    }

    /**
     * Get all materials for selection in export panel
     */
    public function getAllMaterialsForSelection(): array
    {
        return $this->materialExportRepository->getAllMaterialsSimple();
    }

    /**
     * Export materials data as JSON
     */
    public function exportMaterials(array $materialIds, string $langCode): array
    {
        if (empty($materialIds)) {
            return [
                'success' => false,
                'error' => 'Nie wybrano żadnych surowców do eksportu'
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

        // Get materials data
        $materials = $this->materialExportRepository->getMaterialsForExport($materialIds, $langCode);

        if (empty($materials)) {
            return [
                'success' => false,
                'error' => 'Nie znaleziono surowców do eksportu'
            ];
        }

        // Format data for export
        $exportData = [
            'export_info' => [
                'timestamp' => date('Y-m-d H:i:s'),
                'language' => $langCode,
                'language_name' => $language['name'] ?? $langCode,
                'total_materials' => count($materials)
            ],
            'materials' => array_map(function($material) {
                return [
                    'id' => (int)$material['id'],
                    'name' => $material['translated_name'] ?? $material['default_name'],
                    'default_name' => $material['default_name'],
                    'category' => $material['category_name'] ?? 'Brak kategorii',
                    'unit' => $material['unit_name'],
                    'package_size' => (float)$material['package_size_in_base_unit'],
                    'price_per_unit' => (float)$material['price_per_unit'],
                    'waste_percentage' => (float)$material['waste_perc'],
                    'nutritional_values_per_100g' => [
                        'energy' => (float)($material['energy_100g'] ?? 0),
                        'fat' => (float)($material['fat_100g'] ?? 0),
                        'saturated_fat' => (float)($material['saturated_fat_100g'] ?? 0),
                        'carbohydrates' => (float)($material['carbohydrates_100g'] ?? 0),
                        'sugars' => (float)($material['sugars_100g'] ?? 0),
                        'fiber' => (float)($material['fiber_100g'] ?? 0),
                        'proteins' => (float)($material['proteins_100g'] ?? 0),
                        'salt' => (float)($material['salt_100g'] ?? 0)
                    ],
                    'allergens' => $material['allergens'] ?? ''
                ];
            }, $materials)
        ];

        return [
            'success' => true,
            'data' => $exportData
        ];
    }
}


