<?php
namespace App\Services\Material;

use App\Core\TransactionManager;
use App\Exceptions\CustomException;
use App\Repositories\Material\MaterialRepository;
use App\Repositories\Material\Price\MaterialPriceChangelogRepository;
use App\Services\Recipe\RecipeCostRecalculationService;

class MaterialPriceService
{

    public function __construct(
        private MaterialPriceChangelogRepository $materialPriceChangelogRepository,
        private TransactionManager $transactionManager,
        private MaterialRepository $materialRepository,
        private RecipeCostRecalculationService $recipeCostRecalculationService,
    )
    { }

    public function validateUpdate($data)
    {
        $errors = [];

        if(empty($data['id_raw_material']))
        {
            $errors['id_raw_material'] = "Błąd id materiału";
        }

        if(empty($data['id_current_supplier']))
        {
            $errors['id_current_supplier'] = "Wybierz dostawcę";
        }

        if(empty($data['package_size_in_base_unit']) || $data['package_size_in_base_unit'] == 0)
        {
            $errors['package_size_in_base_unit'] = "Wprowadź poprawną wielkość opakowania";
        }

        if(empty($data['id_unit']))
        {
            $errors['id_unit'] = "Wybierz jednostkę";
        }

        if(empty($data['pack_price']) || $data['pack_price'] == 0)
        {
            $errors['pack_price'] = "Wprowadź cenę opakowania";
        }

        if(empty($errors))
        {
            return true;
        }

        return false;
    }

    public function update($idMaterial, $data)
    {
        $result = $this->transactionManager->execute(function () use ($idMaterial, $data) {
            if ($data['package_size_in_base_unit'] == 0) {
                $data['price_per_unit'] = 0;
            } else {
                $data['price_per_unit'] = number_format($data['pack_price'] / $data['package_size_in_base_unit'], 5);
            }

            $log_data['id_supplier'] = $data['id_current_supplier'];
            $log_data['id_raw_material'] = $data['id_raw_material'];
            $log_data['pack_price'] = $data['pack_price'];
            $log_data['package_size_in_base_unit'] = $data['package_size_in_base_unit'];

            unset($data['pack_price']);

            $resUpdate = $this->materialRepository->update($idMaterial, [
                'id_current_supplier' => $data['id_current_supplier'],
                'package_size_in_base_unit' => $data['package_size_in_base_unit'],
                'id_unit' => $data['id_unit'],
                'price_per_unit' => $data['price_per_unit']
            ]);
            if(!$resUpdate){
                throw new CustomException("INSERT_PRICE_ERROR", 'errors', 500);
            }

            $resInsert = $this->materialPriceChangelogRepository->insert($log_data);
            if(!$resInsert){
                throw new CustomException("INSERT_PRICE_CHANGELOG_ERROR", 'errors', 500);
            }
            return true;
        });

        // Po udanej aktualizacji ceny – przelicz koszty wszystkich powiązanych receptur
        if ($result) {
            $this->recipeCostRecalculationService->recalculateForMaterial((int)$idMaterial);
        }

        return $result;
    }

}