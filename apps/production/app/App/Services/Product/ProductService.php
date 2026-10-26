<?php
namespace App\Services\Product;

use App\Domain\Product\ProductLogistics;
use App\Exceptions\CustomException;
use App\Repositories\Product\FinalProductRepository;
use App\Repositories\Utils\RecordHistoryRepository;

class ProductService {
    private FinalProductRepository $finalProductRepository;

    public function __construct(FinalProductRepository $finalProductRepository, private RecordHistoryRepository $history) {
        $this->finalProductRepository = $finalProductRepository;
    }

    public function validate($data)
    {
        return empty($this->validationErrors($data));
    }

    /**
     * Błędy danych nowego produktu: [pole => komunikat]; pusta tablica = dane poprawne.
     * Te same reguły co validate() (formularz « Tylko produkt » i kreator „Nowy produkt z recepturą”) ;
     * logistyka = walidacja Karty produktu (ProductLogistics), nowy produkt : wszystkie pola większe od zera.
     * Stan minimalny opcjonalny : puste = brak minimum (produkt na zamówienie).
     */
    public function validationErrors($data): array
    {
        $errors = [];

        if (empty($data['name'])) {
            $errors['name'] = "Wprowadź nazwę";
        }

        $logistics = array_intersect_key((array) $data, ProductLogistics::RANGES);
        $errors += ProductLogistics::messages(ProductLogistics::errors($logistics, [], array_keys(ProductLogistics::RANGES)));

        $shelfLife = trim((string) ($data['shelf_life'] ?? ''));
        if ($shelfLife === '') {
            $errors['shelf_life'] = "Podaj czas trwałości produktu";
        } elseif (!preg_match('/^\d+$/', $shelfLife) || (int) $shelfLife < 1 || (int) $shelfLife > 3650) {
            $errors['shelf_life'] = "Przydatność: liczba dni od 1 do 3650";
        }
        $minimum = trim((string) ($data['stock_minimum'] ?? ''));
        if ($minimum !== '' && (!preg_match('/^\d+$/', $minimum) || (int) $minimum > 1000000)) {
            $errors['stock_minimum'] = "Stan minimalny: liczba kartonów (0 lub więcej) albo puste pole";
        }
        if (empty($data['id_category']) || $data['id_category'] === "-1") {
            $errors['id_category'] = "Wybierz kategorię";
        }

        if (empty($data['id_section']) || $data['id_section'] === "-1") {
            $errors['id_section'] = "Wybierz dział produkcyjny";
        }

        return $errors;
    }

    /**
     * Nowy produkt i od razu jego karta produkcyjna : bez minimum = na zamówienie (MTO), z minimum
     * (kartony) = na zapas (MTS) ze stanem min. karty w sztukach — ta sama reguła minimum co Karta produktu.
     */
    public function insert($data)
    {
        $minimum = trim((string) ($data['stock_minimum'] ?? ''));
        $data['stock_minimum'] = $minimum === '' ? 0 : (int) $minimum;
        $id = $this->finalProductRepository->insert($data);
        if ($id && $id !== true) $this->finalProductRepository->createCard((int) $id, (int) $data['stock_minimum']);
        return $id;
    }

    public function first($id)
    {
        return $this->finalProductRepository->first(['id' => $id]);
    }

    public function getById($id)
    {
        return $this->finalProductRepository->getById($id);
    }

    /** Karta produktu (kreator /workshop/products) otwarta na danym kroku ; krok 3 = Receptura i straty. */
    public function cardUrl(int $id, int $step = 1): string
    {
        return ROOT . '/workshop/products#/product/' . $id . ($step > 1 ? '?step=' . $step : '');
    }

    public function update($id, $data)
    {
        $result = $this->finalProductRepository->update($id, $data);
        // Nowa receptura produktu : koszt surowców karty = koszt tej receptury (jedno źródło kosztu)
        if ($result !== false && !empty($data['id_recipe'])) $this->finalProductRepository->syncMaterialCostFromRecipe((int) $id);
        return $result;
    }

    /** Produkt z historią (zamówienia, dokumenty, magazyn) nie jest usuwany : linie dokumentów zostałyby bez produktu. */
    public function delete($id)
    {
        if ($used = $this->history->product((int) $id)) {
            throw new CustomException('Produkt ma historię (' . RecordHistoryRepository::describe($used)
                . ') i nie może zostać usunięty. Odznacz « Aktywny » w Karcie produktu, aby go ukryć.', 'errors', 409);
        }
        return $this->finalProductRepository->delete($id);
    }

    public function getByIdCategory($idCategory)
    {
        return $this->finalProductRepository->getByIdCategory($idCategory);
    }

    public function getAll($orderBy = "")
    {
        return $this->finalProductRepository->getAll($orderBy);
    }

    public function getFiltered(bool $includeInactive, bool $includeZeroPrice): array
    {
        return $this->finalProductRepository->getAllFiltered($includeInactive, $includeZeroPrice);
    }

    public function getWithoutRecipe()
    {
        return $this->finalProductRepository->getWithoutRecipe();
    }

    public function getWithoutRecipeGrouped(): array
    {
        $rows = $this->finalProductRepository->getWithoutRecipeGroupedBySectionAndCategory();

        $grouped = [];
        foreach ($rows as $row) {
            $sectionKey  = $row['id_section'];
            $categoryKey = $row['id_category'] ?? 0;

            if (!isset($grouped[$sectionKey])) {
                $grouped[$sectionKey] = [
                    'section_name' => $row['section_name'],
                    'categories'   => [],
                ];
            }

            if (!isset($grouped[$sectionKey]['categories'][$categoryKey])) {
                $grouped[$sectionKey]['categories'][$categoryKey] = [
                    'category_name' => $row['category_name'],
                    'products'      => [],
                ];
            }

            $grouped[$sectionKey]['categories'][$categoryKey]['products'][] = [
                'id'   => $row['id'],
                'name' => $row['name'],
            ];
        }

        return $grouped;
    }

    public function getDataByRecipeId($idRecipe)
    {
        return $this->finalProductRepository->getDataByRecipeId($idRecipe);
    }


    public function groupBySection($data)
    {
        $res_data = [];
        foreach ($data as $item) {
            if (!isset($res_data[$item['id_section']])) {
                $res_data[$item['id_section']]['id_section'] = $item['id_section'];
                $res_data[$item['id_section']]['section_name'] = $item['section_name'];
                $res_data[$item['id_section']]['products'] = [];
            }

            array_push($res_data[$item['id_section']]['products'], $item);
        }

        return $res_data;
    }
}