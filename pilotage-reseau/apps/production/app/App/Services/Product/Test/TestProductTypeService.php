<?php
namespace App\Services\Product\Test;

use App\Repositories\Product\Test\TestProductRepository;
use App\Repositories\Product\Test\TestProductTypeRepository;

class TestProductTypeService
{
    private TestProductTypeRepository $testProductTypeRepository;

    public function __construct(
        TestProductTypeRepository $testProductTypeRepository
    )
    {
        $this->testProductTypeRepository = $testProductTypeRepository;
    }

    public function findAll()
    {
        return $this->testProductTypeRepository->findAll();
    }
}