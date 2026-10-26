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

    /** Źródła zmiany ceny (P21) : Ceny surowców, oferta, faktura zakupowa, nowy surowiec. */
    public const SOURCES = ['manual', 'offer', 'invoice', 'new'];

    public function update($idMaterial, $data, string $source = 'manual', ?int $userId = null)
    {
        $result = $this->transactionManager->execute(function () use ($idMaterial, $data, $source, $userId) {
            if ($data['package_size_in_base_unit'] == 0) {
                $data['price_per_unit'] = 0;
            } else {
                // round, nie number_format : od 1000 number_format wstawia przecinek tysięcy do wartości liczbowej
                $data['price_per_unit'] = round((float) $data['pack_price'] / (float) $data['package_size_in_base_unit'], 5);
            }

            $log_data['id_supplier'] = $data['id_current_supplier'];
            $log_data['id_raw_material'] = $data['id_raw_material'];
            $log_data['pack_price'] = $data['pack_price'];
            $log_data['package_size_in_base_unit'] = $data['package_size_in_base_unit'];
            $log_data['source'] = in_array($source, self::SOURCES, true) ? $source : 'manual';
            $log_data['id_user'] = $userId ?? self::currentUserId();

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


    /** Zalogowany użytkownik ERP ; null w API modułu i skryptach (tam helper user() nie jest załadowany). */
    private static function currentUserId(): ?int
    {
        if (!function_exists('user')) return null;
        $id = user('id');
        return $id ? (int) $id : null;
    }

    /** Cena ustalona przy tworzeniu surowca : pierwszy wpis historii (źródło « new »). */
    public function logInitial(int $idMaterial, array $data): void
    {
        if (empty($data['id_current_supplier']) || empty($data['pack_price']) || empty($data['package_size_in_base_unit'])) return;
        $this->materialPriceChangelogRepository->insert([
            'id_supplier' => (int) $data['id_current_supplier'], 'id_raw_material' => $idMaterial, 'pack_price' => $data['pack_price'],
            'package_size_in_base_unit' => $data['package_size_in_base_unit'], 'source' => 'new', 'id_user' => self::currentUserId(),
        ]);
    }

    public function history(int $idMaterial): array
    {
        return $this->materialPriceChangelogRepository->history($idMaterial);
    }
}
