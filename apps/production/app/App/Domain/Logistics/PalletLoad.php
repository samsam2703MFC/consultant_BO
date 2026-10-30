<?php
declare(strict_types=1);

namespace App\Domain\Logistics;

/**
 * Waga i wysokość jednej palety [213] z kartonów na niej (paleta zeskanowana, wpisana na tablecie albo planowana).
 *
 *  - waga = tara palety + Σ kartony × (sztuki × gramatura + waga kartonu) ;
 *  - wysokość = podstawa palety + Σ warstwy × wysokość kartonu ; kartony tego samego formatu (wysokość i kartony na
 *    warstwę) układane razem — warstwy = kartony ÷ kartony na warstwę w górę (pełne warstwy jednego produktu jedna na
 *    drugiej, jak na makiecie palety mieszanej) ;
 *  - limity : najniższe z produktów na palecie (produkt → rodzaj kartonu → parametry) i wnętrze ciężarówki.
 * Brak danych produktu : wynik bez niego, oznaczony (known_kg / known_height = false).
 */
final class PalletLoad
{
    /**
     * @param list<array{cartons: float|int, ppc?: mixed, weight_g?: mixed, tare_g?: mixed, height_mm?: mixed, cpl?: mixed,
     *                   max_height_mm?: mixed, max_kg?: mixed}> $items
     * @param array{max_height_mm: int, max_kg: int, truck_height_mm?: int} $limits domyślne (parametry logistyki)
     * @return array{kg: float, height_mm: ?int, layers: int, known_kg: bool, known_height: bool, max_kg: int, max_height_mm: int,
     *               over_kg: bool, over_height: bool, over_truck: bool}
     */
    public static function measure(array $items, array $limits, string $pallet = 'eur'): array
    {
        $p = PalletLayout::PALLETS[$pallet] ?? PalletLayout::PALLETS['eur'];
        $kg = (float) $p['tare_kg'];
        $knownKg = true;
        $knownHeight = true;
        $groups = [];
        $maxH = (int) $limits['max_height_mm'];
        $maxKg = (int) $limits['max_kg'];
        $any = false;
        foreach ($items as $it) {
            $qty = (float) ($it['cartons'] ?? 0);
            if ($qty <= 0) continue;
            $any = true;
            $ppc = (float) ($it['ppc'] ?? 0);
            $g = (float) ($it['weight_g'] ?? 0);
            if ($ppc > 0 && $g > 0) $kg += $qty * ($ppc * $g + (float) ($it['tare_g'] ?? 0)) / 1000;
            else $knownKg = false;
            $h = (int) ($it['height_mm'] ?? 0);
            $cpl = (int) ($it['cpl'] ?? 0);
            if ($h > 0 && $cpl > 0) {
                $key = $h . '|' . $cpl;
                $groups[$key] ??= ['h' => $h, 'cpl' => $cpl, 'cartons' => 0.0];
                $groups[$key]['cartons'] += $qty;
            } else {
                $knownHeight = false;
            }
            if ((int) ($it['max_height_mm'] ?? 0) > 0) $maxH = min($maxH, (int) $it['max_height_mm']);
            if ((int) ($it['max_kg'] ?? 0) > 0) $maxKg = min($maxKg, (int) $it['max_kg']);
        }
        $layers = 0;
        $height = $groups ? (int) $p['base_mm'] : null;
        foreach ($groups as $gr) {
            $n = (int) ceil($gr['cartons'] / $gr['cpl'] - 1e-9);
            $layers += $n;
            $height += $n * $gr['h'];
        }
        $truck = (int) ($limits['truck_height_mm'] ?? 0);
        $kg = round($kg, 1);
        return [
            'kg' => $any ? $kg : 0.0, 'height_mm' => $height, 'layers' => $layers,
            'known_kg' => $knownKg, 'known_height' => $knownHeight && $height !== null,
            'max_kg' => $maxKg, 'max_height_mm' => $maxH,
            'over_kg' => $any && $kg > $maxKg + 0.05,
            'over_height' => $height !== null && $height > $maxH,
            'over_truck' => $height !== null && $truck > 0 && $height > $truck,
        ];
    }
}
