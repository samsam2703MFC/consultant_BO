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
}
