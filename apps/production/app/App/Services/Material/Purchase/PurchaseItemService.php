<?php
namespace App\Services\Material\Purchase;

use App\Core\TransactionManager;
use App\Exceptions\CustomException;
use App\Repositories\Material\Purchase\DocumentItemRepository;
use App\Repositories\Material\Purchase\DocumentRepository;

class PurchaseItemService
{
    public function __construct(
        private DocumentItemRepository $documentItemRepository
    ) {}


    public function getByDocumentId($id)
    {
        return $this->documentItemRepository->getByDocumentId($id);
    }
}
