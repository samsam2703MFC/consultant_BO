<?php

namespace App\Services\Material;

use App\Core\TransactionManager;
use App\Exceptions\CustomException;
use App\Repositories\Material\Allergen\AllergenRepository;

class AllergenService
{
    public function __construct(
        private AllergenRepository $allergenRepository,
        private TransactionManager $transactionManager
    )
    {}

    //napisz tablice $allergensPl z wartościami przetłumaczonymi na język polski. Struktura ma być następująca: id => ['code' => 'code', 'name' => 'nazwa alergenu']. Źródło danych:  [allergens] => Array
    //        (
    //            [0] => Array
    //                (
    //                    [id] => 9
    //                    [code] => celery
    //                )
    //
    //            [1] => Array
    //                (
    //                    [id] => 1
    //                    [code] => cereals_gluten
    //                )
    //
    //            [2] => Array
    //                (
    //                    [id] => 2
    //                    [code] => crustaceans
    //                )
    //
    //            [3] => Array
    //                (
    //                    [id] => 3
    //                    [code] => eggs
    //                )
    //
    //            [4] => Array
    //                (
    //                    [id] => 4
    //                    [code] => fish
    //                )
    //
    //            [5] => Array
    //                (
    //                    [id] => 13
    //                    [code] => lupin
    //                )
    //
    //            [6] => Array
    //                (
    //                    [id] => 7
    //                    [code] => milk
    //                )
    //
    //            [7] => Array
    //                (
    //                    [id] => 14
    //                    [code] => molluscs
    //                )
    //
    //            [8] => Array
    //                (
    //                    [id] => 10
    //                    [code] => mustard
    //                )
    //
    //            [9] => Array
    //                (
    //                    [id] => 8
    //                    [code] => nuts
    //                )
    //
    //            [10] => Array
    //                (
    //                    [id] => 5
    //                    [code] => peanuts
    //                )
    //
    //            [11] => Array
    //                (
    //                    [id] => 11
    //                    [code] => sesame_seeds
    //                )
    //
    //            [12] => Array
    //                (
    //                    [id] => 6
    //                    [code] => soybeans
    //                )
    //
    //            [13] => Array
    //                (
    //                    [id] => 12
    //                    [code] => sulphur_dioxide_sulphites
    //                )
    //
    //        )
    private function translateAllergens(array $allergensPl): array
    {
        $translations = [
            'celery' => 'seler',
            'cereals_gluten' => 'zboża zawierające gluten',
            'crustaceans' => 'skorupiaki',
            'eggs' => 'jaja',
            'fish' => 'ryby',
            'lupin' => 'łubin',
            'milk' => 'mleko',
            'molluscs' => 'mięczaki',
            'mustard' => 'gorczyca',
            'nuts' => 'orzechy',
            'peanuts' => 'orzeszki ziemne',
            'sesame_seeds' => 'nasiona sezamu',
            'soybeans' => 'soja',
            'sulphur_dioxide_sulphites' => 'dwutlenek siarki i siarczyny'
        ];

        $result = [];
        foreach ($allergensPl as $allergen) {
            $code = $allergen['code'];
            $result[$allergen['id']] = [
                'code' => $code,
                'name' => $translations[$code] ?? $code
            ];
        }

        return $result;
    }

    public function getAll(): array
    {
        $allergens = $this->allergenRepository->getAll();
        return $this->translateAllergens($allergens);
    }

    public function getForMaterial(int $materialId): array
    {
        if ($materialId <= 0) {
            return [];
        }

        return $this->allergenRepository->getForMaterial($materialId);
    }

    public function updateForMaterial(int $materialId, array $allergenIds): bool
    {
        if ($materialId <= 0) {
            throw new CustomException('INVALID_MATERIAL_ID', 'errors', 400);
        }

        if (!$this->allergenRepository->materialExists($materialId)) {
            throw new CustomException('MATERIAL_NOT_FOUND', 'errors', 404);
        }

        return $this->transactionManager->execute(function () use ($materialId, $allergenIds) {
            $allergenIds = array_values(array_unique(array_map('intval', $allergenIds)));

            $res = $this->allergenRepository->replaceMaterialConnections($materialId, $allergenIds);
            if ($res === false) {
                throw new CustomException('UPDATE_MATERIAL_ALLERGENS_FAILED', 'errors', 500);
            }

            return true;
        });
    }
}

