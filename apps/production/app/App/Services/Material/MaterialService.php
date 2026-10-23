<?php
namespace App\Services\Material;

use App\Repositories\Material\MaterialRepository;
use App\Repositories\Material\NutritionRepository;

class MaterialService
{
    /** Wartości odżywcze w 100 g : wymagane przy nowym surowcu, 0 jest poprawną wartością (np. sól w cukrze). */
    private const NUTRITION_ERRORS = [
        'energy_100g'        => "Wprowadź wartość energetyczną",
        'fat_100g'           => "Wprowadź ilość tłuszczu",
        'saturated_fat_100g' => "Wprowadź ilość kwasów tłuszczowych nasyconych",
        'carbohydrates_100g' => "Wprowadź ilość węglowodanów",
        'sugars_100g'        => "Wprowadź ilość cukrów",
        'salt_100g'          => "Wprowadź ilość soli",
        'fiber_100g'         => "Wprowadź ilość błonnika",
        'proteins_100g'      => "Wprowadź ilość białka",
    ];

    /** Pola BDO/PPWR opakowania : puste = NULL (pusty tekst w polu ENUM/DECIMAL blokuje zapis w trybie strict). */
    private const PACKAGING_FIELDS = ['bdo_material_category', 'packaging_usage_type', 'packaging_unit_weight_g'];

    /** @var array<string, string> błędy ostatniej walidacji (do pokazania w formularzu) */
    public array $lastErrors = [];

    private MaterialRepository $materialRepository;

    public function __construct(
        MaterialRepository $materialRepository,
        private NutritionRepository $nutritionRepository
    )
    {
        $this->materialRepository = $materialRepository;
    }

    public function getAll()
    {
        return  $this->materialRepository->getAll();
    }
    
    public function validate($data)
    {
        $errors = [];

        if(empty($data['name']))
        {
            $errors['name'] = "Wprowadź nazwę";
        }


        if(empty($data['id_unit']))
        {
            $errors['id_unit'] = "Wybierz jednostkę";
        }

        if(empty($data['id_category']) || $data['id_category'] === "-1")
        {
            $errors['id_category'] = "Wybierz kategorię";
        }

        if(empty($data['pack_price']))
        {
            $errors['pack_price'] = "Wprowadź cenę";
        }

        if(empty($data['package_size_in_base_unit']))
        {
            $errors['package_size_in_base_unit'] = "Podaj wagę opakowania";
        }

        if(empty($data['id_current_supplier']))
        {
            $errors['id_current_supplier'] = "Wybierz obecnego dostawcę";
        }

        foreach (self::NUTRITION_ERRORS as $field => $message)
        {
            if (self::nutritionValue($data[$field] ?? null) === null) $errors[$field] = $message;
        }

        $this->lastErrors = $errors;
        if(empty($errors))
        {
            return true;
        }

        return false;
    }

    public function validateUpdate($data)
    {
        $errors = [];

        if(empty($data['name']))
        {
            $errors['name'] = "Wprowadź nazwę";
        }


        if(empty($data['id_category']) || $data['id_category'] === "-1")
        {
            $errors['id_category'] = "Wybierz kategorię";
        }

        // Edycja : puste pole = 0, ale wartość ujemna albo nieliczbowa jest błędem
        foreach (self::NUTRITION_ERRORS as $field => $message)
        {
            $raw = $data[$field] ?? '';
            if (trim((string) $raw) !== '' && self::nutritionValue($raw) === null) $errors[$field] = $message;
        }

        $this->lastErrors = $errors;
        if(empty($errors))
        {
            return true;
        }

        return false;
    }

    /** Liczba ≥ 0 (przecinek albo kropka) ; null = brak albo niepoprawna. */
    private static function nutritionValue(mixed $raw): ?float
    {
        if ($raw === null || is_array($raw)) return null;
        $value = str_replace(',', '.', trim((string) $raw));
        if ($value === '' || !is_numeric($value) || (float) $value < 0) return null;
        return (float) $value;
    }

    public function adjustData($data)
    {
        foreach (array_keys(self::NUTRITION_ERRORS) as $field)
        {
            $data[$field] = self::nutritionValue($data[$field] ?? null) ?? 0;
        }
        if(!isset($data['is_part_of_package'])) $data['is_part_of_package'] = 0;
        foreach (self::PACKAGING_FIELDS as $field)
        {
            if (array_key_exists($field, $data) && trim((string) $data[$field]) === '') $data[$field] = null;
        }
        if (isset($data['packaging_unit_weight_g'])) $data['packaging_unit_weight_g'] = str_replace(',', '.', trim((string) $data['packaging_unit_weight_g']));

        return $data;
    }

    public function insert($data)
    {
        // « Wykorzystanie materiału [%] » nie wchodzi do żadnego kosztu (tylko eksport) : pole usunięte z formularzy, kolumna = 100
        if (!isset($data['waste_perc']) || $data['waste_perc'] === '') $data['waste_perc'] = 100;
        $data['price_per_unit'] = $data['pack_price'] / $data['package_size_in_base_unit'];
        $data = $this->adjustData($data);

        return $this->materialRepository->insert($data);
    }

    /** Pełny formularz surowca. Zmienione wartości odżywcze = wpisane ręcznie : znika źródło z internetu. */
    public function update($id, $data)
    {
        $data = $this->adjustData($data);
        $before = $this->materialRepository->getById($id);
        $result = $this->materialRepository->update($id, $data);
        if ($result !== false && is_array($before)) {
            foreach (array_keys(self::NUTRITION_ERRORS) as $field) {
                if (abs((float) ($before[$field] ?? 0) - (float) $data[$field]) > 0.0001) {
                    $this->nutritionRepository->deleteSource((int) $id);
                    break;
                }
            }
        }
        return $result;
    }

    /** Tylko znacznik « część opakowania » : reszta karty surowca (w tym wartości odżywcze) bez zmian. */
    public function setPackagePart(int $id, int $isPartOfPackage)
    {
        return $this->materialRepository->update($id, ['is_part_of_package' => $isPartOfPackage]);
    }

    public function delete($id)
    {
        return $this->materialRepository->delete($id);
    }

    public function getById($id)
    {
        return $this->materialRepository->getById($id);
    }

    public function getByIdCategory($id)
    {
        return $this->materialRepository->getByIdCategory($id);
    }

    public function getAllWithNutritionalValues()
    {
        return $this->materialRepository->getAllWithNutritionalValues();
    }

    public function updateNutritionalValues(int $id, string $field, float $value): bool
    {
        $allowedFields = [
            'energy_100g', 'fat_100g', 'saturated_fat_100g',
            'carbohydrates_100g', 'sugars_100g', 'salt_100g',
            'fiber_100g', 'proteins_100g',
        ];

        if (!in_array($field, $allowedFields, true)) {
            return false;
        }

        return (bool)$this->materialRepository->update($id, [$field => $value]);
    }
}