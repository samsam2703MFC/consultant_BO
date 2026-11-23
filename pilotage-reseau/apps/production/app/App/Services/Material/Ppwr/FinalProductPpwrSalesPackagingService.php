<?php

namespace App\Services\Material\Ppwr;

use App\Exceptions\CustomException;
use App\Repositories\Material\Ppwr\FinalProductPpwrSalesPackagingRepository;

class FinalProductPpwrSalesPackagingService
{
    /** Braki kartonu sprzedażowego : kod => etykieta (rejestr kartonów sprzedażowych PPWR i asystent « PPWR i opakowania » [397]). */
    public const ISSUES = [
        'NO_RECIPE' => 'Brak receptury',
        'NO_PACKAGING' => 'Brak opakowania w recepturze',
        'NO_SALES_CARTON' => 'Nie wybrano kartonu sprzedażowego',
        'NO_PPC' => 'Brak PPC produktu',
    ];

    public function __construct(private FinalProductPpwrSalesPackagingRepository $repository)
    {
    }

    public function getAudit(): array
    {
        return array_map(function (array $row): array {
            $issues = array_map(fn(string $code) => self::ISSUES[$code], self::issueCodes($row));
            $row['issues'] = $issues;
            $row['computed_status'] = empty($issues) ? 'COMPLETE' : 'REQUIRES_DATA';
            return $row;
        }, $this->repository->getProductAudit());
    }

    /** Kody braków produktu (kolejność ISSUES) : jedna reguła dla ekranu i asystenta. @return list<string> */
    public static function issueCodes(array $row): array
    {
        $codes = [];
        if (empty($row['id_recipe'])) $codes[] = 'NO_RECIPE';
        if ((int) ($row['packaging_candidate_count'] ?? 0) === 0) $codes[] = 'NO_PACKAGING';
        if (empty($row['id_outer_raw_material'])) $codes[] = 'NO_SALES_CARTON';
        if (empty($row['ppc'])) $codes[] = 'NO_PPC';
        return $codes;
    }

    public function getCandidates(int $productId): array
    {
        $product = $this->repository->getProductById($productId);
        if (!$product || empty($product['active'])) throw new CustomException('Nie znaleziono aktywnego produktu.', 'errors', 404);
        return $this->repository->getCandidates($productId);
    }

    public function save(int $productId, array $payload): array
    {
        $product = $this->repository->getProductById($productId);
        if (!$product || empty($product['active'])) throw new CustomException('Nie znaleziono aktywnego produktu.', 'errors', 404);
        if (!filter_var($product['ppc'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])) {
            throw new CustomException('Uzupełnij poprawne PPC produktu przed wskazaniem kartonu sprzedażowego.', 'errors', 422);
        }

        $outerMaterialId = filter_var($payload['id_outer_raw_material'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!$outerMaterialId || !$this->repository->isCandidateForProduct($productId, (int)$outerMaterialId)) {
            throw new CustomException('Wybierz materiał opakowaniowy występujący w recepturze tego produktu.', 'errors', 422);
        }
        $data = [
            'id_final_product' => $productId,
            'id_outer_raw_material' => (int)$outerMaterialId,
            'sales_unit_name' => 'Karton',
            'verification_status' => 'COMPLETE',
        ];
        $existing = $this->repository->getByProductId($productId);
        if ($existing) $this->repository->update((int)$existing['id'], $data);
        else $this->repository->insert($data);

        return $data;
    }
}
