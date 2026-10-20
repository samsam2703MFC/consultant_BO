<?php
namespace App\Services\Employee\Section;

use App\Repositories\Employee\Section\ProductionSectionRepository;

class ProductionSectionService
{
    private ProductionSectionRepository $productionSectionRepository;

    public function __construct(ProductionSectionRepository $productionSectionRepository)
    {
        $this->productionSectionRepository = $productionSectionRepository;
    }

    public function getAll()
    {
        return $this->productionSectionRepository->findAll();
    }

    public function getById($id)
    {
        return $this->productionSectionRepository->first(['id' => $id]);
    }

    /** Koszty stałe działu (zł / miesiąc) : amortyzacja i media stałe, liczone w rentowności i marżach. */
    public const COST_FIELDS = ['depreciation', 'water_cost', 'power_supply_cost', 'fuel_cost', 'other_cost'];

    public function updateCost(int $id, string $field, float $value): bool
    {
        if (!in_array($field, self::COST_FIELDS, true)) return false;
        return $this->productionSectionRepository->update($id, [$field => round($value, 2)]);
    }
}
