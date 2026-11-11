<?php
namespace App\Services\Material;

use App\Core\TransactionManager;
use App\Exceptions\CustomException;
use App\Repositories\Material\MaterialRepository;
use App\Repositories\Material\Price\MaterialPriceChangelogRepository;
use App\Repositories\Workshop\ParamRepository;
use App\Services\Recipe\RecipeCostRecalculationService;

class MaterialPriceService
{

    public function __construct(
        private MaterialPriceChangelogRepository $materialPriceChangelogRepository,
        private TransactionManager $transactionManager,
        private MaterialRepository $materialRepository,
        private RecipeCostRecalculationService $recipeCostRecalculationService,
        private ParamRepository $params,
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
    public const SOURCES = ['manual', 'offer', 'invoice', 'new', 'unit'];

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


    /**
     * Nowa jednostka surowca (27/10/2026, formularz przypisania faktury) : jednostka, opakowanie i cena już przeliczone
     * przez wywołującego ; wpis w historii cen (źródło unit) gdy jest dostawca, koszty receptur przeliczone.
     */
    public function changeUnit(int $idMaterial, int $unitId, int $packageSize, float $pricePerUnit, ?int $supplierId, ?int $userId): void
    {
        $this->transactionManager->execute(function () use ($idMaterial, $unitId, $packageSize, $pricePerUnit, $supplierId, $userId) {
            $this->materialRepository->update($idMaterial, ['id_unit' => $unitId, 'package_size_in_base_unit' => $packageSize, 'price_per_unit' => round($pricePerUnit, 5)]);
            if ($supplierId !== null) {
                $this->materialPriceChangelogRepository->insert(['id_supplier' => $supplierId, 'id_raw_material' => $idMaterial, 'pack_price' => round($pricePerUnit * $packageSize, 2),
                    'package_size_in_base_unit' => $packageSize, 'source' => 'unit', 'id_user' => $userId ?? self::currentUserId()]);
            }
            return true;
        });
        $this->recipeCostRecalculationService->recalculateForMaterial($idMaterial);
    }

    /**
     * Cena zatwierdzona z faktury zakupowej albo z zaakceptowanej oferty (27/10/2026) : cena bazowa — z niej liczony koszt
     * receptur — to najwyższa zatwierdzona cena, nie najnowsza. Wyższa cena (podwyżka zatwierdzona na fakturze, oferta
     * zaakceptowana) zostaje ceną bazową ; niższa jej nie obniża (prices.cost_max_months = 0, domyślnie). Z N > 0 cena bazowa
     * starsza niż N miesięcy spada do najwyższej zatwierdzonej ceny z tego okresu (faktury, oferty), nie wyżej niż obecna.
     * Dostawca ceny zostaje bieżącym dostawcą także bez zmiany ceny bazowej. Ręczna zmiana w Ceny surowców (update, źródło manual)
     * ustawia cenę bazową wprost.
     * @return array{applied: bool, held: bool, price: float, previous: float}
     */
    public function applyValidated(int $idMaterial, array $data, string $source, ?int $userId = null): array
    {
        $package = (float) $data['package_size_in_base_unit'];
        $new = $package > 0 ? round((float) $data['pack_price'] / $package, 5) : 0.0;
        $material = $this->materialRepository->getById($idMaterial) ?: [];
        $current = (float) ($material['price_per_unit'] ?? 0);
        if ($new <= 0 || $current <= 0 || $new >= $current - 1e-9) {
            $this->update($idMaterial, $data, $source, $userId);
            return ['applied' => true, 'held' => false, 'price' => $new, 'previous' => $current];
        }
        $months = max(0, (int) $this->params->get('prices.cost_max_months', '0'));
        $since = date('Y-m-d', strtotime("-{$months} months"));
        $last = $this->materialPriceChangelogRepository->lastChange($idMaterial);
        if ($months === 0 || ($last !== null && substr((string) $last['timestamp'], 0, 10) >= $since)) {
            $this->holdBase($idMaterial, $data);
            return ['applied' => false, 'held' => true, 'price' => $current, 'previous' => $current];
        }
        // Cena bazowa starsza niż okres : najwyższa zatwierdzona cena z okresu (w cenie kartoteki : opakowanie, 2 miejsca).
        $best = ['price' => $new, 'id_supplier' => (int) $data['id_current_supplier']];
        foreach ($this->materialPriceChangelogRepository->validatedPrices($idMaterial, $since) as $p) {
            $price = round(round($p['price'] * $package, 2) / $package, 5);
            if ($price > $best['price'] && $price <= $current + 1e-9) $best = ['price' => $price, 'id_supplier' => $p['id_supplier'] ?? $best['id_supplier']];
        }
        if ($best['price'] >= $current - 1e-9) {
            $this->holdBase($idMaterial, $data);
            return ['applied' => false, 'held' => true, 'price' => $current, 'previous' => $current];
        }
        $this->update($idMaterial, ['pack_price' => round($best['price'] * $package, 2), 'id_current_supplier' => $best['id_supplier']] + $data, $source, $userId);
        return ['applied' => true, 'held' => false, 'price' => $best['price'], 'previous' => $current];
    }

    /** Cena bazowa bez zmian (bez historii, bez przeliczenia receptur) ; dostawca faktury / zwycięskiej oferty zostaje bieżącym (zamówienia). */
    private function holdBase(int $idMaterial, array $data): void
    {
        if (!empty($data['id_current_supplier'])) $this->materialRepository->update($idMaterial, ['id_current_supplier' => (int) $data['id_current_supplier']]);
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
