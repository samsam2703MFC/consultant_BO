<?php
namespace App\Services\Shipping;

use App\Repositories\Stock\Complaint\CartonStockComplaintRepository;

class CartonStockComplaintService
{


    public function __construct(
        private CartonStockComplaintRepository $cartonStockComplaintRepository
    )
    {
    }

    public function getCustomerByShippingDate($date)
    {
        return $this->cartonStockComplaintRepository->getCustomerByShippingDate($date);
    }


}