<?php
declare(strict_types=1);
/*
 * Waga i wysokość palety [213] (PalletLoad, PalletCalculator::measure) : tara + kartony, warstwy wg formatu kartonu,
 * palety mieszane (pełne warstwy jednego produktu na drugim), brak danych, limity produktu / kartonu / parametrów,
 * wnętrze ciężarówki.
 *
 *   php tests/pallet_load_test.php
 */
require_once __DIR__ . '/../public/vendor/autoload.php';

use App\Domain\Logistics\PalletLoad;
use App\Services\Logistics\PalletCalculator;

function expectPl(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException('FAILED: ' . $message);
}

$L = ['max_height_mm' => 1800, 'max_kg' => 1000, 'truck_height_mm' => 2550];
$croissant = ['ppc' => 64, 'weight_g' => 75, 'tare_g' => 380, 'height_mm' => 210, 'cpl' => 10];
$bagietka = ['ppc' => 20, 'weight_g' => 250, 'tare_g' => 500, 'height_mm' => 150, 'cpl' => 8];

$m = PalletLoad::measure([['cartons' => 60] + $croissant], $L);
expectPl($m['kg'] === 335.8 && $m['height_mm'] === 144 + 6 * 210 && $m['layers'] === 6 && $m['known_kg'] && $m['known_height'] && !$m['over_kg'] && !$m['over_height'],
    'pełna paleta : 25 + 60 × 5,18 kg = 335,8 kg ; 6 warstw × 210 + 144 = 1404 mm');
$m = PalletLoad::measure([['cartons' => 25] + $croissant], $L);
expectPl($m['layers'] === 3 && $m['height_mm'] === 144 + 3 * 210, 'niepełna warstwa liczy się jako warstwa (25 kart. / 10 = 3 warstwy)');
// Paleta mieszana (makieta 214) : 30 Croissant (3 warstwy) + 16 Bagietka (2 warstwy) jedna na drugiej.
$m = PalletLoad::measure([['cartons' => 30] + $croissant, ['cartons' => 16] + $bagietka], $L);
expectPl($m['height_mm'] === 144 + 3 * 210 + 2 * 150 && $m['layers'] === 5 && $m['kg'] === round(25 + 30 * 5.18 + 16 * 5.5, 1), 'dwa formaty : warstwy jedna na drugiej, waga sumą');
// Ten sam format dwóch produktów : warstwy wspólne (6 + 6 = 12 kartonów = 2 warstwy po 10, nie 1 + 1).
$m = PalletLoad::measure([['cartons' => 6] + $croissant, ['cartons' => 6, 'ppc' => 10, 'weight_g' => 300] + $croissant], $L);
expectPl($m['layers'] === 2 && $m['height_mm'] === 144 + 420, 'ten sam format : wspólne warstwy');
$m = PalletLoad::measure([['cartons' => 5, 'ppc' => 20, 'weight_g' => 250], ['cartons' => 10] + $croissant], $L);
expectPl(!$m['known_height'] && $m['height_mm'] === 144 + 210 && $m['kg'] === round(25 + 25 + 51.8, 1), 'produkt bez kartonu : wysokość niepełna (oznaczona), waga liczona');
$m = PalletLoad::measure([['cartons' => 5]], $L);
expectPl(!$m['known_kg'] && $m['height_mm'] === null && $m['kg'] === 25.0, 'brak danych : tylko tara, wysokość nieznana');
expectPl(PalletLoad::measure([], $L)['kg'] === 0.0 && PalletLoad::measure([], $L)['height_mm'] === null, 'pusta paleta : 0');
// Limity : najniższy z produktów (karton 300 kg) ; za wysoka paleta i wnętrze ciężarówki.
$m = PalletLoad::measure([['cartons' => 60, 'max_kg' => 300] + $croissant], $L);
expectPl($m['max_kg'] === 300 && $m['over_kg'], 'limit kartonu 300 kg : 335,8 kg za ciężka');
// Limit kartonu wyższy niż parametr (1850 > 1800 mm) : zastępuje parametr — 8 warstw = 1824 mm mieści się.
$m = PalletLoad::measure([['cartons' => 80, 'max_height_mm' => 1850] + $croissant], $L);
expectPl($m['max_height_mm'] === 1850 && $m['height_mm'] === 1824 && !$m['over_height'], 'limit kartonu 1850 mm zastępuje parametr 1800 mm');
$m = PalletLoad::measure([['cartons' => 30, 'max_height_mm' => 1850] + $croissant, ['cartons' => 8] + $bagietka], $L);
expectPl($m['max_height_mm'] === 1800, 'drugi produkt bez własnego limitu : parametr 1800 mm (najniższy)');
$m = PalletLoad::measure([['cartons' => 100] + $croissant], $L);
expectPl($m['height_mm'] === 144 + 10 * 210 && $m['over_height'] && !$m['over_truck'], '10 warstw = 2244 mm > 1800 mm, mieści się w ciężarówce 2550');
$m = PalletLoad::measure([['cartons' => 120] + $croissant], ['max_height_mm' => 3000] + $L);
expectPl($m['over_truck'] && !$m['over_height'], '12 warstw = 2664 mm > wnętrze ciężarówki 2550 mm');
$m = PalletLoad::measure([['cartons' => 60] + $croissant], $L, 'industrial');
expectPl($m['kg'] === 340.8, 'paleta przemysłowa : tara 30 kg');

// Układ planu + pomiar każdej palety.
$types = PalletCalculator::layout([
    ['product_id' => 10, 'product' => 'Croissant', 'cartons' => 70, 'cpp' => 60, 'carton_type_id' => 4500],
    ['product_id' => 11, 'product' => 'Bagietka', 'cartons' => 16, 'cpp' => 40, 'carton_type_id' => 4600],
]);
$m = PalletCalculator::measure($types, [10 => $croissant, 11 => $bagietka], $L);
$loads = [];
foreach ($m['types'] as $t) foreach ($t['pallets'] as $p) $loads[] = $p['load'];
expectPl(count($loads) === 3 && $loads[0]['kg'] === 335.8 && $m['max_height_mm'] === 1404 && $m['over'] === 0
    && $m['kg'] === round(335.8 + (25 + 10 * 5.18) + (25 + 16 * 5.5), 1), 'układ : pełna Croissant, reszta Croissant, Bagietka — waga razem, najwyższa 1404 mm');

echo "pallet_load_test: OK\n";
