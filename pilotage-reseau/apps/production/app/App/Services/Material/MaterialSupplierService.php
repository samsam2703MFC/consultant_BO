<?php
namespace App\Services\Material;

use App\Repositories\Material\MaterialRepository;
use App\Repositories\Supplier\MaterialSupplierRepository;

class MaterialSupplierService
{
    private MaterialSupplierRepository $supplierRepository;

    public function __construct(
        MaterialSupplierRepository $supplierRepository
    )
    {
        $this->supplierRepository = $supplierRepository;
    }

    public function getAll()
    {
        return  $this->supplierRepository->findAll();
    }


}