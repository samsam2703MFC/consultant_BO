<?php
namespace App\Services\Product\Test;

use App\Repositories\Product\Test\TestProductRepository;
use App\Repositories\Product\Test\TestProductStatusRepository;

class TestProductStatusService
{
    private TestProductStatusRepository $testProductStatusRepository;

    public function __construct(
        TestProductStatusRepository $testProductStatusRepository
    )
    {
        $this->testProductStatusRepository = $testProductStatusRepository;
    }

    public function findAll()
    {
        return $this->testProductStatusRepository->findAll();
    }
}