<?php
declare(strict_types=1);

namespace App\Services\Material;

use App\Repositories\Workshop\SupplyRepository;

/**
 * Kreator surowca (audyt 2, lot C) : po utworzeniu surowca (MaterialService::insert — nazwa, zakup, wartości
 * odżywcze, opakowanie) zapisuje alergeny i tryb zaopatrzenia (na zamówienie / na stanie z min. i maks.).
 * Każdy krok osobno : błąd jednego nie cofa surowca, tylko trafia na listę « do uzupełnienia » na karcie.
 */
final class MaterialCreationService
{
    public function __construct(private AllergenService $allergens, private SupplyRepository $supply)
    {
    }

    /** @return list<string> problemy (krok : komunikat) */
    public function complete(int $materialId, array $input, ?int $userId): array
    {
        $problems = [];
        $ids = array_values(array_filter(array_map('intval', (array) ($input['allergens'] ?? []))));
        if ($ids) {
            try {
                $this->allergens->updateForMaterial($materialId, $ids);
            } catch (\Throwable $e) {
                $problems[] = 'Alergeny : ' . $e->getMessage();
            }
        }
        $mode = ($input['supply_mode'] ?? 'order') === 'stock' ? 'stock' : 'order';
        $num = function ($raw): ?float {
            $v = str_replace(',', '.', trim((string) $raw));
            return $v !== '' && is_numeric($v) && (float) $v >= 0 ? (float) $v : null;
        };
        [$min, $max] = [$num($input['stock_min'] ?? ''), $num($input['stock_max'] ?? '')];
        if ($min !== null && $max !== null && $max < $min) {
            $problems[] = 'Zaopatrzenie : stan maks. mniejszy niż min. — nie zapisano min. / maks.';
            [$min, $max] = [null, null];
        }
        if ($mode === 'stock' || $min !== null || $max !== null) {
            try {
                $this->supply->saveSupply($materialId, ['supply_mode' => $mode, 'stock_min' => $min, 'stock_max' => $max, 'updated_by' => $userId]);
            } catch (\Throwable $e) {
                $problems[] = 'Zaopatrzenie : ' . $e->getMessage();
            }
        }
        return $problems;
    }
}
