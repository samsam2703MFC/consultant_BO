<?php

namespace App\Services\Production;

use App\Core\TransactionManager;
use App\Exceptions\CustomException;
use App\Repositories\Client\Order\OrderRepository;
use App\Repositories\Document\DocumentRepository;
use App\Repositories\Employee\Section\ProductionSectionRepository;
use App\Repositories\Production\ProductionListRepository;
use App\Repositories\Production\ProductionRepository;
use App\Services\Employee\Section\ProductionSectionService;

class ProductionService
{
    private ProductionRepository $productionRepository;
    private OrderRepository $orderRepository;
    private TransactionManager $transactionManager;
    private ProductionListRepository $productionListRepository;
    private ProductionSectionRepository $productionSectionRepository;

    public function __construct(
        ProductionRepository        $productionRepository,
        OrderRepository             $orderRepository,
        ProductionListRepository    $productionListRepository,
        TransactionManager          $transactionManager,
        ProductionSectionRepository $productionSectionRepository
    )
    {
        $this->productionRepository = $productionRepository;
        $this->orderRepository = $orderRepository;
        $this->productionListRepository = $productionListRepository;
        $this->transactionManager = $transactionManager;
        $this->productionSectionRepository = $productionSectionRepository;
    }



}