<?php
declare(strict_types=1);
/*
 * Ruchy magazynowe produktów gotowych [158] : stan na początek / koniec okresu, przyjęcia (etykieta kartonu), wydania
 * (karton wysłany, data palety), klient z ostatniej palety kartonu, wartość wg kosztu z karty produktu, filtry, kartony
 * przeterminowane, palety, które czekają, daty niespójne (różnica salda), karta produktu dzień po dniu, partie, CSV.
 *
 *   php tests/stock_ledger_test.php
 *
 * Base : TFB_TEST_DSN (défaut mysql:unix_socket=/run/mysqld/mysqld.sock), TFB_TEST_USER (root), TFB_TEST_PASS (vide) ;
 * structures recopiées depuis TFB_TEST_SOURCE_DB (défaut erp). La base erp_stock_ledger_test est recréée puis supprimée.
 */
require_once __DIR__ . '/../public/vendor/autoload.php';

use App\Exceptions\CustomException;
use App\Repositories\Stock\FinalProduct\StockLedgerRepository;
use App\Services\Stock\StockLedgerService;

function expectSl(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException('FAILED: ' . $message);
}

$dsn = getenv('TFB_TEST_DSN') ?: 'mysql:unix_socket=/run/mysqld/mysqld.sock';
$source = getenv('TFB_TEST_SOURCE_DB') ?: 'erp';
$testDb = 'erp_stock_ledger_test';
try {
    $pdo = new PDO($dsn . ';charset=utf8mb4', getenv('TFB_TEST_USER') ?: 'root', getenv('TFB_TEST_PASS') ?: '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    echo "stock_ledger_test: SKIPPED (no database: {$e->getMessage()})\n";
    exit(0);
}

$pdo->exec("DROP DATABASE IF EXISTS {$testDb}");
$pdo->exec("CREATE DATABASE {$testDb} CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
try {
    foreach (['final_product', 'category_product', 'carton_stock', 'carton_pallet_connection', 'palets_delivery', 'customer'] as $table) {
        $pdo->exec("CREATE TABLE {$testDb}.{$table} LIKE {$source}.{$table}");
    }
    $pdo->exec("USE {$testDb}");
    $pdo->exec("SET SESSION sql_mode = ''");
    $pdo->exec("INSERT INTO category_product (id, name) VALUES (1, 'Viennoiserie'), (2, 'Pain')");
    $pdo->exec("INSERT INTO final_product (id, name, id_category, pre_calculated_raw_price_per_carton, ppc, active) VALUES
        (900, 'Croissant 60 g', 1, 10, 60, 1), (901, 'Chleb żytni', 2, 5, 12, 1), (902, 'Stary produkt', 2, 3, 10, 1)");
    $pdo->exec("INSERT INTO customer (id, company) VALUES (51, 'Klient A'), (52, 'Klient B')");
    // Palety : 1, 2 → A (wysłane), 3 → B (wysłana), 4 → B dostawa 20.10 (stoi), 5 → A dostawa dziś.
    $pdo->exec("INSERT INTO palets_delivery (id_palet, client, delivery_date, status, id_customer) VALUES
        (1, '', '2026-10-05', 3, 51), (2, '', '2026-10-07', 3, 51), (3, '', '2026-10-07', 3, 52), (4, '', '2026-10-20', 1, 52), (5, '', '2026-10-26', 1, 51)");
    // Okres 01.10–20.10.2026, dziś 26.10.2026.
    $pdo->exec("INSERT INTO carton_stock (id_carton, batch_number, id_product, expiration_date, status, date_add, date_pallet) VALUES
        (1, 7000, 900, '2026-11-01', 3, '2026-09-20', '2026-09-25 09:00:00'),
        (2, 7000, 900, '2026-11-01', 3, '2026-09-28', '2026-10-05 10:00:00'),
        (3, 7001, 900, '2026-10-10', 1, '2026-09-29', NULL),
        (4, 7004, 900, '2026-11-20', 3, '2026-10-03', '2026-10-05 12:00:00'),
        (5, 7005, 900, '2026-11-20', 3, '2026-10-03', '2026-10-07 08:00:00'),
        (6, 7002, 900, '2026-12-01', 2, '2026-10-15', '2026-10-19 15:00:00'),
        (7, 7003, 900, '2026-11-30', 1, '2026-10-25', NULL),
        (8, 7008, 900, '2026-12-05', 3, '2026-10-18', '2026-10-22 07:00:00'),
        (9, 7009, 900, '2026-12-05', 3, '2026-10-10', '2026-10-09 07:00:00'),
        (10, 7010, 900, '2026-12-05', 3, '2026-10-22', '2026-10-19 07:00:00'),
        (21, 8001, 901, '2000-01-01', 1, '2026-10-02', NULL),
        (22, 8002, 901, '2026-10-20', 1, '2026-10-02', NULL),
        (23, 8003, 901, '2026-11-10', 2, '2026-10-24', '2026-10-25 10:00:00'),
        (31, 9001, 902, '2026-09-01', 3, '2026-08-01', '2026-08-02 10:00:00')");
    // Karton 5 przełożony z palety 2 (A) na paletę 3 (B) : liczy się ostatnia paleta. Karton 10 bez palety.
    $pdo->exec("INSERT INTO carton_pallet_connection (id_key, id_palet, id_carton) VALUES
        (1, 1, 2), (2, 1, 4), (3, 2, 5), (4, 3, 5), (5, 4, 6), (6, 1, 9), (7, 5, 23), (8, 2, 1), (9, 2, 8)");

    $ledger = new StockLedgerService(new StockLedgerRepository($pdo));
    $today = new DateTimeImmutable('2026-10-26');

    // --- Okres : stan na początek, przyjęcia, wydania, stan na koniec, wartość
    $o = $ledger->overview('2026-10-01', '2026-10-20', null, '', $today);
    $byId = array_column($o['rows'], null, 'id');
    expectSl(array_keys($byId) === [900, 901], 'rows: products with movement or stock, busiest first ; old product without stock left out');
    $c = $byId[900];
    expectSl([$c['opening'], $c['produced'], $c['shipped'], $c['closing']] === [2, 5, 5, 3],
        'croissant: opening 2 (shipped before the period excluded), 5 labelled, 5 shipped (pallet date), 3 at the end');
    expectSl($c['gap'] === 1 && $c['delta'] === 1 && $c['value_closing'] === 30.0 && $c['value_opening'] === 20.0 && $c['category'] === 'Viennoiserie',
        'croissant: carton shipped before its label (after the period) gives a gap of +1 ; value = cartons × card cost');
    $b = $byId[901];
    expectSl([$b['opening'], $b['produced'], $b['shipped'], $b['closing'], $b['gap']] === [0, 2, 0, 2, 0], 'bread: 2 labelled, still in store');
    expectSl($o['totals']['opening'] === 2 && $o['totals']['produced'] === 7 && $o['totals']['shipped'] === 5 && $o['totals']['closing'] === 5
        && abs($o['totals']['value_closing'] - 40.0) < 0.001, 'totals over all products');
    expectSl($o['inconsistent'] === 2, 'inconsistent dates: cartons shipped before their label touching the period');

    // --- Przeterminowane (dziś) i palety, które miały wyjechać
    expectSl($o['expired_total'] === 2 && array_column($o['expired'], 'n', 'id_product') == [900 => 1, 901 => 1],
        'expired: in store or on pallet past the date ; « 2000-01-01 » = no date ; shipped cartons ignored');
    expectSl(count($o['waiting']) === 1 && (int) $o['waiting'][0]['id_customer'] === 52 && (int) $o['waiting'][0]['n'] === 1
        && (int) $o['waiting'][0]['pallets'] === 1 && $o['waiting'][0]['since'] === '2026-10-20' && $o['waiting_total'] === 1,
        'waiting pallets: on a pallet whose delivery date passed (today\'s pallet not yet late)');

    // --- Filtry : kategoria, nazwa bez polskich znaków
    expectSl(array_column($ledger->overview('2026-10-01', '2026-10-20', 2, '', $today)['rows'], 'id') === [901], 'category filter');
    expectSl(array_column($ledger->overview('2026-10-01', '2026-10-20', null, 'ZYTNI', $today)['rows'], 'id') === [901], 'search folds case and Polish letters');
    expectSl($ledger->overview('2026-10-01', '2026-10-20', null, 'brak', $today)['rows'] === [] , 'search without match');

    // --- Okres : domyślny, odwrócony, za długi
    $d = $ledger->overview('bad', null, null, '', $today);
    expectSl($d['from'] === '2026-10-01' && $d['to'] === '2026-10-26', 'default period: this month to today');
    $s = $ledger->overview('2026-10-20', '2026-10-01', null, '', $today);
    expectSl($s['from'] === '2026-10-01' && $s['to'] === '2026-10-20', 'swapped dates are put back in order');
    expectSl($d['presets'][1]['from'] === '2026-09-01' && $d['presets'][1]['to'] === '2026-09-30', 'preset: previous month');
    try {
        $ledger->overview('2024-01-01', '2026-10-20', null, '', $today);
        expectSl(false, 'period over 400 days refused');
    } catch (CustomException $e) {
        expectSl($e->getCode() === 422, 'period over 400 days: 422');
    }

    // --- Karta produktu : dzień po dniu, klienci, saldo, partie
    $p = $ledger->product(900, '2026-10-01', '2026-10-20', $today);
    expectSl($p['opening'] === 2 && $p['produced'] === 5 && $p['shipped'] === 5 && $p['closing'] === 3, 'product card: same figures as the period table');
    $lines = array_column($p['lines'], null, 'day');
    expectSl(array_keys($lines) === ['2026-10-19', '2026-10-18', '2026-10-15', '2026-10-10', '2026-10-09', '2026-10-07', '2026-10-05', '2026-10-03'],
        'product card: one line per day with a movement, newest first');
    expectSl($lines['2026-10-03']['in'] === 2 && $lines['2026-10-03']['batches'] === '7004–7005' && $lines['2026-10-03']['balance'] === 4, 'day in: cartons, batch range, running balance');
    expectSl($lines['2026-10-05']['out'] === 2 && $lines['2026-10-05']['customers'] === [['name' => 'Klient A', 'n' => 2, 'id' => 51]] && $lines['2026-10-05']['balance'] === 2,
        'day out: customer of the pallet');
    expectSl($lines['2026-10-07']['customers'][0]['id'] === 52, 'carton moved to another pallet: customer of the last pallet');
    expectSl($lines['2026-10-19']['customers'][0]['id'] === null && $lines['2026-10-19']['customers'][0]['name'] === 'bez palety klienta'
        && $lines['2026-10-19']['balance'] === 2, 'shipped carton without pallet ; balance before the gap');
    expectSl(array_column($p['batches'], 'batch') === [7001, 7003, 7002] && $p['batches'][0]['expired'] === true && $p['batches'][1]['expired'] === false
        && $p['batches'][2]['on_pallet'] === 1 && $p['in_stock_now'] === 3, 'batches in stock now: oldest expiry first (FEFO), expired flag, store / pallet');
    $bread = $ledger->product(901, '2026-10-01', '2026-10-20', $today);
    expectSl($bread['batches'][0]['expires'] === null && $bread['product']['category'] === 'Pain' && $bread['product']['card_cost'] === 5.0, 'batch without a date ; product header');
    try {
        $ledger->product(999, null, null, $today);
        expectSl(false, 'unknown product refused');
    } catch (CustomException $e) {
        expectSl($e->getCode() === 404, 'unknown product: 404');
    }

    // --- CSV (Excel : BOM, średnik, przecinek)
    $csv = $ledger->csv($o);
    $rows = explode("\n", trim($csv));
    expectSl(str_starts_with($csv, "\xEF\xBB\xBF") && count($rows) === 3 && str_contains($rows[0], '"Stan początek";Przyjęcia;Wydania;"Stan koniec"')
        && $rows[1] === '"Croissant 60 g";Viennoiserie;2;5;5;3;10,00;30,00', 'csv: header, one line per product, comma decimals');

    echo "stock_ledger_test: OK\n";
} finally {
    $pdo->exec("DROP DATABASE IF EXISTS {$testDb}");
}
