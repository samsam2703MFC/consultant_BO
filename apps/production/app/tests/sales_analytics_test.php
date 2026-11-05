<?php
declare(strict_types=1);
/*
 * [254] Analiza sprzedaży (makiety 32 + 34) : reguły bez bazy — okres i ten sam okres rok wcześniej (bieżący miesiąc
 * do dziś), 29 lutego, oś wykresu, kolor marży działu, etykieta okresu, zmiana w %.
 * Marże działów na bazie testowej : tests/profitability_test.php (range + overview strony).
 *
 *   php tests/sales_analytics_test.php
 */
require_once __DIR__ . '/../public/vendor/autoload.php';

use App\Repositories\Analysis\SalesAnalyticsRepository;
use App\Services\Finances\SalesAnalyticsService as S;

function expectSa(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException('FAILED: ' . $message);
}

$today = new DateTimeImmutable('2026-11-05');

// Okres zamknięty : całe miesiące, rok wcześniej te same miesiące.
$b = S::bounds('2026-01', '2026-10', $today);
expectSa($b['from'] === '2026-01-01' && $b['to'] === '2026-10-31' && !$b['partial'] && !$b['empty'], 'ten rok : sty – paź w całości');
expectSa($b['prev_from'] === '2025-01-01' && $b['prev_to'] === '2025-10-31', 'ten rok : te same miesiące rok wcześniej');

// Bieżący miesiąc : do dziś, rok wcześniej do tego samego dnia (nie cały miesiąc).
$m = S::bounds('2026-11', '2026-11', $today);
expectSa($m['from'] === '2026-11-01' && $m['to'] === '2026-11-05' && $m['partial'], 'ten miesiąc : do dziś');
expectSa($m['prev_from'] === '2025-11-01' && $m['prev_to'] === '2025-11-05', 'ten miesiąc : te same dni rok wcześniej');
expectSa(S::rangeLabel($m) === 'listopad 2026 (do 5.11)', 'etykieta miesiąca w toku : ' . S::rangeLabel($m));
expectSa(S::rangeLabel($b) === 'sty – paź 2026', 'etykieta roku : ' . S::rangeLabel($b));
expectSa(S::rangeLabel(S::bounds('2025-11', '2026-10', $today)) === 'lis 2025 – paź 2026', 'etykieta 12 miesięcy');
expectSa(S::bounds('2026-12', '2026-12', $today)['empty'], 'przyszły miesiąc : pusty okres');
expectSa(S::yearBefore('2028-02-29') === '2027-02-28', '29 lutego → 28 lutego rok wcześniej');
expectSa(SalesAnalyticsRepository::bounds('2026-02', '2026-02') === ['2026-02-01', '2026-02-28'], 'RRRR-MM = cały miesiąc');
expectSa(SalesAnalyticsRepository::bounds('2026-11-01', '2026-11-05') === ['2026-11-01', '2026-11-05'], 'RRRR-MM-DD = dokładne dni');

// Zmiana w % i oś wykresu (okrągły szczyt, kreski od zera).
expectSa(abs(S::change(1055406, 923530) - 14.28) < 0.01 && S::change(5, 0) === null, 'zmiana w % (null bez roku wcześniej)');
$a = S::axis(156176);
expectSa($a['top'] === 200000.0 && $a['ticks'] === [0.0, 50000.0, 100000.0, 150000.0, 200000.0], 'oś 156 tys. → 0…200 tys. co 50 tys. : ' . json_encode($a));
$a = S::axis(43000);
expectSa($a['top'] === 50000.0 && count($a['ticks']) === 6, 'oś 43 tys. → 0…50 tys. co 10 tys. : ' . json_encode($a));
expectSa(S::axis(0)['ticks'] === [0.0], 'oś bez sprzedaży');

// Kolor marży działu : ≥ cel zielony, od MARGIN_LOW do celu pomarańczowy, poniżej czerwony.
expectSa(S::marginLevel(40.6, 40) === 'ok' && S::marginLevel(29.9, 40) === 'mid' && S::marginLevel(0.4, 40) === 'bad'
    && S::marginLevel(-21.7, 40) === 'bad' && S::marginLevel(null, 40) === null, 'poziomy marży');
expectSa(S::monthShort('2026-10') === 'paź' && S::monthLong('2026-01') === 'styczeń', 'nazwy miesięcy');

// Widok : przyciski działów, werdykt, miesiące, produkty, filtr klienta zachowany.
$view = file_get_contents(__DIR__ . '/../App/Views/finances/sales-analytics/overview.twig');
foreach (['sa-secs', 'sa-verdict', 'sa-cols', 'sa-alert', 'data-open-products', 'id="products"', "'&customer_id='", 'section_id='] as $needle) {
    expectSa(str_contains($view, $needle), 'widok zawiera ' . $needle);
}

echo "sales_analytics_test: OK\n";
