<?php
declare(strict_types=1);
/*
 * Prognoza 13 tygodni [220] : podpowiedź produktu z nazwy i daty gam (bez bazy) ; na bazie jednorazowej z fałszywym API panelu
 * (tylko GET) : odczyt gam (sezonowe, całoroczne, pominięte), sprzedaż sklepów tydzień po tygodniu z pamięcią tygodni
 * zamkniętych i ponowieniem nieudanych ; prognoza (tydzień bieżący = zamówienia, większe z zamówień i prognozy, N-1 × trend,
 * produkty sezonowe : sprzedaż w sklepach N-1 z panelu, brak tygodnia → wysyłki ERP, produkt gamy sprzedawany cały rok =
 * całoroczny), trend z panelu, palety, godziny i zdolność (święta PL), zakupy (receptura, stan, czas dostawy z oferty, opakowania),
 * pieniądze na produkcję (wydatki : kartony × surowce, płace i media działu, wspólne działy produkcji ; bez amortyzacji, kosztów
 * stałych i Administracji ; logistyka osobno), połączenia produktów gamy
 * (ręczne, pominięte, podpowiedź), zatwierdzenie (zadanie tygodnia zalicza się samo) i cofnięcie.
 *
 *   php tests/forecast_test.php
 *
 * Base : TFB_TEST_DSN (défaut mysql:unix_socket=/run/mysqld/mysqld.sock), TFB_TEST_USER (root), TFB_TEST_PASS (vide) ;
 * structures recopiées depuis TFB_TEST_SOURCE_DB (défaut erp, migration 141 appliquée). La base erp_forecast_test est
 * recréée puis supprimée. Dates fixes : prognoza od poniedziałku 2.11.2026 (tydz. 45).
 */
require_once __DIR__ . '/../public/vendor/autoload.php';

use App\Core\Api\Workshop\WorkshopConfig;
use App\Exceptions\CustomException;
use App\Repositories\Organization\PositionRepository;
use App\Services\Finances\StorePrice\PanelApiClient;
use App\Services\Forecast\ForecastService;
use App\Services\Forecast\PanelSeasonService;
use App\Services\Forecast\SeasonMatcher;

function expectFc(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException('FAILED: ' . $message);
}

function expectFcFail(callable $fn, int $code, string $message): void
{
    try {
        $fn();
    } catch (CustomException $e) {
        expectFc((int) $e->getCode() === $code, $message . ' (HTTP ' . $e->getCode() . ' : ' . $e->getMessage() . ')');
        return;
    }
    throw new RuntimeException('FAILED: ' . $message . ' (brak wyjątku)');
}

// --- Bez bazy : podpowiedź z nazwy, daty gam -----------------------------------------------------------------
$cands = array_map(fn($r) => ['id' => $r[0], 'name' => $r[1], 'active' => $r[2], 'tokens' => SeasonMatcher::tokens($r[1])], [
    [1, 'Cougnou sucre – 200 g (cru)', true], [2, 'Cougnou sucre – 100 g (cru)', false], [3, 'Cougnou sucre & chocolat – 200 g (cru)', true],
    [4, 'Galette Frangipanne - 21 ? (Crue)', true], [5, "Sapin de Noël en pain d'épices – 350 g", true], [6, 'Frangipane – ⌀ 22 cm', true],
]);
$best = SeasonMatcher::best('Cougnou - Sucre', $cands);
expectFc($best[0]['id'] === 1 && $best[0]['score'] === 1.0 && $best[1]['id'] === 2, 'podpowiedź : remis słów → produkt aktywny pierwszy');
expectFc(SeasonMatcher::best('Galette Frangipane', $cands)[0]['id'] === 4, 'podpowiedź : « frangipane » = « frangipanne » (prawie równe słowa)');
expectFc(SeasonMatcher::best("Sapin de Noel en pain d'épice", $cands)[0]['score'] === 1.0, 'podpowiedź : bez akcentów, « épice » = « épices »');
expectFc(SeasonMatcher::best('Crêpe 1 pièce', $cands) === [], 'podpowiedź : brak podobnego produktu');
expectFc(SeasonMatcher::tokens('Bûche Chocolat - 4/6 personnes') === ['buche', 'chocolat'], 'słowa : bez liczb i słów pomocniczych');
// Inwestycje w kosztach stałych [223] : kredyt, leasing, raty, środki trwałe (nazwa) albo kategoria ; nie czynsz ani środki czystości.
expectFc(ForecastService::isInvestment('Kredyt - Leasing', 'RENT') && ForecastService::isInvestment('Rata kredytu') && ForecastService::isInvestment('Środki trwałe')
    && ForecastService::isInvestment('Piec', 'investment') && !ForecastService::isInvestment('Rent', 'RENT') && !ForecastService::isInvestment('Srodki Czystosci', 'MAINTENANCE')
    && !ForecastService::isInvestment('Ratownik') && !ForecastService::isInvestment('Auto - 1', 'LOGISTICS'), 'inwestycje w kosztach stałych : po nazwie albo kategorii');
$occ = SeasonMatcher::occurrences(['is_recurring' => 1, 'from_md' => 1018, 'to_md' => 115], '2026-11-02', '2027-01-31');
expectFc(count($occ) === 1 && $occ[0] === ['from' => '2026-10-18', 'to' => '2027-01-15', 'n1_from' => '2025-10-18', 'n1_to' => '2026-01-15'], 'gama przez Nowy Rok : w tym roku i rok temu');
expectFc(SeasonMatcher::occurrences(['is_recurring' => 1, 'from_md' => 301, 'to_md' => 501], '2026-11-02', '2027-01-31') === [], 'gama poza oknem : brak');
expectFc(SeasonMatcher::occurrences(['is_recurring' => 0, 'start_date' => '2026-12-01', 'end_date' => '2026-12-24', 'from_md' => 1201, 'to_md' => 1224], '2026-11-02', '2027-01-31')[0]['n1_from'] === null,
    'gama jednorazowa : bez roku wcześniej');
expectFc(SeasonMatcher::spanDays(['is_recurring' => 1, 'from_md' => 101, 'to_md' => 1231]) === 365 && SeasonMatcher::spanDays(['is_recurring' => 1, 'from_md' => 104, 'to_md' => 103]) === 365
    && SeasonMatcher::spanDays(['is_recurring' => 1, 'from_md' => 1018, 'to_md' => 115]) === 90, 'długość gamy : całoroczne 365 dni, Noël 90 dni');

// --- Fałszywe API panelu : tylko GET, zapis ścieżek --------------------------------------------------------------
final class FakeForecastPanel extends PanelApiClient
{
    public array $paths = [];
    public array $fail = [];
    public array $sales = [];
    public array $data = [];

    public function configured(): bool { return true; }

    public function get(string $path): mixed
    {
        $this->paths[] = $path;
        $base = explode('?', $path)[0];
        if (!array_key_exists($base, $this->data) && !str_contains($path, 'raw-material-sales')) throw new CustomException('API panelu : GET ' . $path . ' → HTTP 404.', 'errors', 502);
        if (str_contains($path, 'raw-material-sales-all-shops')) {
            parse_str((string) parse_url($path, PHP_URL_QUERY), $q);
            if (in_array($q['from'] ?? '', $this->fail, true)) throw new CustomException('timeout', 'errors', 502);
            return array_map(fn($sku, $n) => ['catalog_product_sku' => (string) $sku, 'total_sold_quantity' => $n], array_keys($this->sales[$q['from']] ?? []), $this->sales[$q['from']] ?? []);
        }
        return $this->data[$base];
    }

    public function getMany(array $paths): array
    {
        $out = [];
        foreach ($paths as $k => $p) { try { $out[$k] = $this->get($p); } catch (Throwable) { $out[$k] = null; } }
        return $out;
    }
}

// --- Baza jednorazowa ------------------------------------------------------------------------------------------
$dsn = getenv('TFB_TEST_DSN') ?: 'mysql:unix_socket=/run/mysqld/mysqld.sock';
$source = getenv('TFB_TEST_SOURCE_DB') ?: 'erp';
$testDb = 'erp_forecast_test';
try {
    $pdo = new PDO($dsn . ';charset=utf8mb4', getenv('TFB_TEST_USER') ?: 'root', getenv('TFB_TEST_PASS') ?: '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    echo "forecast_test: PARTIAL (bez bazy : {$e->getMessage()}) — część bez bazy OK\n";
    exit(0);
}
$pdo->exec("DROP DATABASE IF EXISTS {$testDb}");
$pdo->exec("CREATE DATABASE {$testDb} CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
try {
    foreach (['customer', 'customer_order', 'customer_order_product', 'final_product', 'category_product', 'product_recipe', 'n_prod_item', 'n_prod_product_settings',
                 'n_prod_group', 'n_prod_routing_step', 'n_prod_routing_alternative', 'n_prod_workstation', 'n_stock_period', 'n_stock_period_item', 'n_prod_param',
                 'n_prod_employee', 'user', 'raw_material', 'unit', 'category_material', 'supplier', 'n_mp_supply', 'n_mp_count', 'n_po', 'n_po_line', 'n_po_receipt',
                 'carton_stock', 'flattened_recipe_ingredient', 'n_mp_offer', 'n_forecast_run', 'n_forecast_panel_read', 'n_forecast_panel_link',
                 'cost_month', 'cost_month_employee', 'cost_media_month', 'cost_media_section_allocation', 'cost_month_fixed_cost', 'cost_month_logistics',
                 'cost_month_section', 'production_sections', 'n_section_target', 'document_wdt', 'document_wdt_product', 'n_wdt_superseded', 'erp_company_other_settings'] as $table) {
        $pdo->exec("CREATE TABLE {$testDb}.{$table} LIKE {$source}.{$table}");
    }
    $pdo->exec("USE {$testDb}");
    $pdo->exec("SET SESSION sql_mode = ''");
    $pdo->exec("INSERT INTO n_prod_param (code, value, default_value, value_type) VALUES ('forecast.weeks', '13', '13', 'int'), ('forecast.trend_weeks', '8', '8', 'int'),
        ('forecast.trend_min', '0.5', '0.5', 'decimal'), ('forecast.trend_max', '2', '2', 'decimal'), ('forecast.capacity_hours', '0', '0', 'decimal'),
        ('forecast.lead_days', '7', '7', 'int'), ('week.fte_hours', '40', '40', 'decimal'), ('logistics.plan_departure_country', 'PL', 'PL', 'string')");
    $pdo->exec("INSERT INTO user (id, login, displayed_name) VALUES (5, 'kierownik', 'Anna Kierownik')");
    $pdo->exec("INSERT INTO customer (id, company) VALUES (1, 'Alfa s.a.')");
    $pdo->exec("INSERT INTO category_product (id, name) VALUES (1, 'Pieczywo')");
    $pdo->exec("INSERT INTO n_prod_employee (id, id_production_employee, active) VALUES (1, 101, 1), (2, 102, 1), (3, 103, 0)");
    // 1 Bagietka (całoroczna, 10 szt., 10 kart./pal., receptura 100) ; 2 Cougnou (sezon Noël, 42 szt.) ; 3 Spéculoos (w gamie
    // Saint-Nicolas, ale sprzedawany cały rok) ; 4 Buche praline (podpowiedź z nazwy) ; 5 Bûche chocolat 4/6 (połączenie ręczne).
    $pdo->exec("INSERT INTO final_product (id, name, id_category, ppc, cpl, lpp, cpp, active, id_recipe) VALUES
        (1, 'Bagietka', 1, 10, 5, 2, 10, 1, 100), (2, 'Cougnou sucre – 200 g (cru)', 1, 42, 5, 2, 20, 1, NULL), (3, 'Spéculoos Saint-Nicolas', 1, 24, 1, 1, 1, 1, NULL),
        (4, 'Buche Chocolat & Praline - 16 cm', 1, 12, 5, 2, 40, 0, NULL), (5, 'Bûche cacao 4/6', 1, 6, 5, 2, 40, 1, NULL)");
    // Wydatki na karton [221–222] : miesiąc zamknięty — płace Piekarni 10 000 zł ÷ 1 000 kartonów = 10 zł, Pakowanie / wysyłka
    // (dział wspólny) 3 000 zł = 3 zł na karton firmy ; poza wydatkami : inwestycje [223] = amortyzacja 1 000 zł + « Kredyt -
    // Leasing » 1 500 zł, pozostałe koszty stałe 3 500 zł, Administracja 4 000 zł ; logistyka 2 000 zł = 2 zł (osobno).
    // Bagietka : surowce 8 zł → 21 zł ; bez działu : 3 zł.
    $pdo->exec("INSERT INTO production_sections (id, name, depreciation) VALUES (1, 'Piekarnia', 1000), (2, 'Pakowanie/wysyłka', 0), (3, 'Administracja', 0)");
    $pdo->exec("INSERT INTO n_section_target (id_section, is_shared) VALUES (2, 1), (3, 1)");
    $pdo->exec("INSERT INTO cost_month_employee (month_id, section_id, name, surname, monthly_cost, is_included) VALUES (1, 2, 'Ewa', 'Test', 3000, 1), (1, 3, 'Olga', 'Test', 4000, 1)");
    $pdo->exec("UPDATE final_product SET id_section = 1, pre_calculated_raw_price_per_carton = 8 WHERE id = 1");
    $pdo->exec("INSERT INTO cost_month (id, year, month, status) VALUES (1, 2026, 9, 'LOCKED'), (2, 2026, 10, 'OPEN')");
    $pdo->exec("INSERT INTO cost_month_employee (month_id, section_id, name, surname, monthly_cost, is_included) VALUES (1, 1, 'Jan', 'Test', 10000, 1), (2, 1, 'Jan', 'Test', 99999, 1)");
    $pdo->exec("INSERT INTO cost_month_section (month_id, section_id, cartons_qty) VALUES (1, 1, 1000)");
    $pdo->exec("INSERT INTO cost_month_fixed_cost (month_id, name, category, amount) VALUES (1, 'Rent', 'RENT', 3500), (1, 'Kredyt - Leasing', 'RENT', 1500)");
    $pdo->exec("INSERT INTO cost_month_logistics (month_id, amount) VALUES (1, 2000)");
    $pdo->exec("INSERT INTO erp_company_other_settings (id, exchange_rate_euro) VALUES (1, 4.3)");
    $pdo->exec("INSERT INTO n_prod_param (code, value, default_value, value_type) VALUES ('forecast.cost_months', '1', '1', 'int')");
    $pdo->exec("INSERT INTO n_prod_item (id, item_type, id_final_product) VALUES (11, 'product', 1), (12, 'product', 2), (13, 'product', 3), (14, 'product', 4), (15, 'product', 5)");
    // Bagietka : partia 100 szt., 2 osoby × 30 min = 1 h → 0,1 h na karton ; pozostałe bez czasu (szacunek średnią).
    $pdo->exec("INSERT INTO n_prod_product_settings (id_item, mode, batch_multiple, batch_people, batch_minutes) VALUES (11, 'MTS', 100, 2, 30), (12, 'MTO', NULL, NULL, NULL),
        (13, 'MTO', NULL, NULL, NULL), (14, 'MTO', NULL, NULL, NULL), (15, 'MTO', NULL, NULL, NULL)");
    // Mąka : g (wyświetlane kg), 2 zł / kg, worek 25 kg, dostawca z ofertą 14 dni ; 1 kg na karton Bagietki ; inwentaryzacja 50 kg.
    $pdo->exec("INSERT INTO unit (id, name) VALUES (1, 'g')");
    $pdo->exec("INSERT INTO supplier (id, name, phone, email, is_active) VALUES (7, 'Młyn', '', '', 1)");
    $pdo->exec("INSERT INTO raw_material (id, name, id_unit, price_per_unit, package_size_in_base_unit, id_current_supplier) VALUES (1, 'Mąka', 1, 0.002, 25000, 7)");
    $pdo->exec("INSERT INTO flattened_recipe_ingredient (recipe_id, material_id, quantity) VALUES (100, 1, 1000)");
    $pdo->exec("INSERT INTO n_mp_count (id_raw_material, count_date, quantity) VALUES (1, '2026-10-30', 50000)");
    $pdo->exec("INSERT INTO n_mp_offer (id_supplier, id_raw_material, pack_price, pack_size_base, price_per_base, valid_from, lead_time_days, created_at) VALUES (7, 1, 50, 25000, 0.002, '2026-10-01', 14, '2026-10-01 10:00:00')");

    $order = 0;
    $add = function (string $date, array $lines, int $status = 3) use ($pdo, &$order) {
        $order++;
        $pdo->exec("INSERT INTO customer_order (id, id_customer, status, expected_shipping_date, create_order_date) VALUES ({$order}, 1, {$status}, '{$date}', '{$date}')");
        foreach ($lines as $p => $n) $pdo->exec("INSERT INTO customer_order_product (id_order, id_product, quantity_carton, expected_quantity_carton) VALUES ({$order}, {$p}, {$n}, {$n})");
    };
    $today = '2026-11-02';
    // Ostatnie 8 tygodni : Bagietka 20 kart. / tydz., Spéculoos 5 (poza gamą Saint-Nicolas) ; te same tygodnie rok temu : Bagietka 16.
    for ($i = 1; $i <= 8; $i++) {
        $m = date('Y-m-d', strtotime("{$today} -{$i} weeks"));
        $add(date('Y-m-d', strtotime("{$m} +1 day")), [1 => 20, 3 => 5]);
        $add(date('Y-m-d', strtotime(App\Services\Logistics\LogisticsPlanService::lastYear($m) . ' +1 day')), [1 => 16]);
    }
    // Rok temu w oknie : Bagietka 16 / tydz., w tygodniach 51 i 52 — 32 ; Cougnou wysłany w tyg. 15.12.2025 : 10 kartonów.
    for ($m = '2025-11-03'; $m <= '2026-02-02'; $m = date('Y-m-d', strtotime("{$m} +7 days"))) $add(date('Y-m-d', strtotime("{$m} +1 day")), [1 => in_array($m, ['2025-12-15', '2025-12-22'], true) ? 32 : 16]);
    $add('2025-12-16', [2 => 10]);
    // Zamówienia : tydzień bieżący (Bagietka 25), tydzień 2 (50 > prognoza), tydzień 3 (5 < prognoza).
    $add('2026-11-03', [1 => 25], 2);
    $add('2026-11-17', [1 => 50], 1);
    $add('2026-11-24', [1 => 5], 1);
    $add('2026-11-25', [1 => 7], 4); // anulowane — nie liczy się

    $panel = (new ReflectionClass(FakeForecastPanel::class))->newInstanceWithoutConstructor();
    $panel->data = [
        '/product-availability-periods' => ['data' => [
            ['id' => 14, 'name' => '🥖 Gamme Standard', 'is_recurring' => 1, 'is_active' => 1, 'from_md' => 101, 'to_md' => 1231],
            ['id' => 19, 'name' => 'Gamme Bureau', 'is_recurring' => 1, 'is_active' => 1, 'from_md' => 101, 'to_md' => 101],
            ['id' => 8, 'name' => 'Noël & Nouvel An', 'is_recurring' => 1, 'is_active' => 1, 'from_md' => 1018, 'to_md' => 115],
            ['id' => 9, 'name' => '🎅 Saint-Nicolas', 'is_recurring' => 1, 'is_active' => 1, 'from_md' => 1018, 'to_md' => 1210],
            ['id' => 4, 'name' => 'Printanière', 'is_recurring' => 1, 'is_active' => 0, 'from_md' => 301, 'to_md' => 501]]],
        '/product-availability-periods/14/products' => [['id' => 41009, 'name' => 'Pain Bagietka', 'available' => 1]],
        '/product-availability-periods/19/products' => [['id' => 41010, 'name' => 'Café', 'available' => 1]],
        '/product-availability-periods/8/products' => [['id' => 41001, 'name' => 'Cougnou - Sucre', 'available' => 1], ['id' => 41007, 'name' => 'Bûche Chocolat - 4/6 personnes', 'available' => 1],
            ['id' => 41020, 'name' => 'Crêpe 1 pièce', 'available' => 1], ['id' => 41009, 'name' => 'Pain Bagietka', 'available' => 1], ['id' => 41030, 'name' => 'Cougnou Nature', 'available' => 0]],
        '/product-availability-periods/9/products' => [['id' => 41021, 'name' => 'Spéculoos Saint-Nicolas - 100g.', 'available' => 1]],
        '/material-suppliers' => [['id' => 2, 'name' => 'Bruyerre S.A.'], ['id' => 1, 'name' => 'SLFood']],
        '/material-suppliers/1/catalog/products' => [['sku' => '1'], ['sku' => '2'], ['sku' => '3'], ['sku' => '4'], ['sku' => '5']],
    ];
    // Sprzedaż sklepów : ostatnie 8 tygodni 1000 szt., rok temu 800 (trend 1,25) ; Cougnou w tyg. 8.12.2025 : 840 szt. = 20 kart. ;
    // tydzień 15.12.2025 nie odczytany (błąd) → wysyłki ERP.
    for ($i = 1; $i <= 8; $i++) {
        $m = date('Y-m-d', strtotime("{$today} -{$i} weeks"));
        $panel->sales[$m] = ['1' => 1000];
        $panel->sales[App\Services\Logistics\LogisticsPlanService::lastYear($m)] = ['1' => 800];
    }
    $panel->sales['2025-12-08'] = ['2' => 840, '1' => 300];
    $panel->fail = ['2025-12-15'];

    $builder = new DI\ContainerBuilder();
    $builder->addDefinitions([PDO::class => $pdo, PanelApiClient::class => $panel,
        WorkshopConfig::class => new WorkshopConfig(['PRODUCTION_JWT_SECRET' => str_repeat('s', 48), 'API_BASE_URL' => 'https://erp.test'])]);
    $c = $builder->build();
    $seasons = $c->get(PanelSeasonService::class);
    $fc = $c->get(ForecastService::class);
    $now = strtotime('2026-11-02 08:00:00');

    // --- Odczyt panelu --------------------------------------------------------------------------------------------
    $read = $seasons->refresh(5, $now);
    $byId = array_column($read['periods'], null, 'id');
    expectFc(!isset($byId[4]) && $byId[14]['kind'] === 'always' && $byId[19]['kind'] === 'skip' && $byId[8]['kind'] === 'season' && $byId[9]['kind'] === 'season',
        'gamy : nieaktywna pominięta, Standard całoroczna, Bureau (1 dzień) pominięta, Noël i Saint-Nicolas sezonowe');
    expectFc(!in_array(41030, $byId[8]['products'], true) && $read['always'] === [41009] && $read['catalog'] === ['1', '2', '3', '4', '5'] && $read['supplier']['id'] === 1,
        'gamy : produkt niedostępny pominięty, produkty całoroczne, katalog SL Food (SKU)');
    expectFc(isset($read['sales']['2025-12-08']) && (float) $read['sales']['2025-12-08']['skus']['2'] === 840.0 && in_array('2025-12-15', $read['sales_failed'], true),
        'sprzedaż : tydzień gamy rok temu odczytany, tydzień z błędem do ponowienia');
    foreach ($panel->paths as $p) expectFc(!preg_match('/^(POST|PATCH|PUT|DELETE)/i', $p), 'panel : tylko odczyt');
    $calls = count(array_filter($panel->paths, fn($p) => str_contains($p, 'raw-material-sales')));
    expectFc($calls === 27, 'sprzedaż : 8 tygodni + 8 rok temu + 11 innych tygodni gam rok temu (' . $calls . ')');
    $panel->paths = [];
    $seasons->refresh(5, $now + 60);
    $again = array_values(array_filter($panel->paths, fn($p) => str_contains($p, 'raw-material-sales')));
    expectFc(count($again) === 1 && str_contains($again[0], 'from=2025-12-15'), 'drugi odczyt : tylko tydzień nieudany (reszta z pamięci) ; ' . json_encode($again));
    $last = $seasons->last($now + 120);
    expectFc($last !== null && !$last['stale'] && $seasons->last(strtotime('2026-11-09 07:00:00'))['stale'], 'odczyt : świeży do poniedziałku 6:00');
    $panel->fail = ['2025-12-15']; // zostaje nieudany : wysyłki ERP w tym tygodniu

    // --- Prognoza -----------------------------------------------------------------------------------------------
    $f = $fc->page($today, $now);
    $W = $f['weeks'];
    expectFc(count($W) === 13 && $W[0]['week_start'] === '2026-11-02' && $W[12]['week_start'] === '2027-01-25', 'prognoza : 13 tygodni od bieżącego poniedziałku');
    expectFc($f['trend']['source'] === 'panel' && $f['trend']['value'] === 1.25 && $f['trend']['used'] === 8, 'trend sieci z panelu : 8 000 ÷ 6 400 = 1,25');
    $rows = array_column($f['products'], null, 'product_id');
    $b = $rows[1];
    expectFc($b['kind'] === 'regular' && $b['avg'] === 20.0 && $b['hours_per_carton'] === 0.1, 'Bagietka : całoroczna, 20 kart. / tydz., 0,1 h / karton');
    expectFc($b['values'][0] === 25 && $b['sources'][0] === 'orders', 'tydzień bieżący : zamówienia (25)');
    expectFc($b['values'][1] === 20 && $b['sources'][1] === 'n1', 'tydzień 2 : 20 × kształt N-1 1,0');
    expectFc($b['values'][2] === 50 && $b['sources'][2] === 'orders' && $b['values'][3] === 20 && $b['sources'][3] === 'n1', 'zamówienie większe od prognozy wygrywa, mniejsze nie ; anulowane pomijane');
    $i51 = array_search('2026-12-14', array_column($W, 'week_start'), true);
    // N-1 tyg. 50, 51, 52 = 16, 32, 32 → kształt (16 + 32 + 32) ÷ 3 ÷ 16 = 1,667 ; 20 × 1,667 = 33.
    expectFc($W[$i51]['shape'] === 1.67 && $b['values'][$i51] === 33, 'kształt N-1 (±1 tydzień) : tydz. 51 = 33 (' . $b['values'][$i51] . ')');
    $s = $rows[3];
    expectFc($s['kind'] === 'regular' && $s['all_year_in'] === ['Saint-Nicolas'], 'Spéculoos : w gamie Saint-Nicolas, ale sprzedawany cały rok → całoroczny');
    expectFc(!isset($rows[41009]) && $rows[2]['kind'] === 'season', 'produkt gamy całorocznej nie jest sezonowy ; Cougnou sezonowy');
    $cg = $rows[2]['values'];
    $i50 = array_search('2026-12-07', array_column($W, 'week_start'), true);
    // Tydzień gamy k = 8 (13.10.2025 + 8 tyg. = 8.12.2025) : 840 szt. ÷ 42 = 20 kart. × 1,25 = 25 ; k = 9 (15.12) : brak odczytu → ERP 10 × 1,25 = 12,5 → 13.
    expectFc($cg[$i50] === 25 && $rows[2]['sources'][$i50] === 'season' && $cg[$i51] === 13, 'Cougnou : N-1 z panelu × trend (25), tydzień bez odczytu z ERP (13) ; ' . json_encode($cg));
    $last2 = array_search('2027-01-25', array_column($W, 'week_start'), true);
    expectFc($cg[$last2] === 0 && $rows[2]['sources'][$last2] === 'none', 'poza gamą (po 15.01) : 0');
    expectFc($W[$i50]['season_cartons'] === 25 && $W[$i50]['cartons'] === $b['values'][$i50] + 25 + $s['values'][$i50], 'suma tygodnia : kartony i sezonowe');
    // Godziny : Bagietka 0,1 h / karton, inne produkty średnią ważoną (0,1) ; zdolność 2 pracowników × 40 h, tydz. 11.11 (święto) 32 h.
    $i46 = 1 + 0;
    expectFc($f['capacity']['hours'] === 80.0 && $f['capacity']['people'] === 2 && $W[1]['capacity'] === 64.0 && $W[1]['off'][0]['date'] === '2026-11-11',
        'zdolność : 2 × 40 h, tydzień ze świętem 11.11 → 64 h');
    expectFc(abs($W[$i50]['hours'] - $W[$i50]['cartons'] * 0.1) < 0.05 && $W[$i50]['estimated_hours'] > 0, 'godziny : znane + szacunek średnią');
    expectFc($f['totals']['peak_week'] !== (int) date('W', strtotime($today)), 'szczyt : bez tygodnia bieżącego');
    // Palety : Bagietka 10 kart. / pal., Spéculoos « 1 / 1 / 1 » = brak danych.
    expectFc($s['cpp'] === null && abs($W[1]['pallets'] - (20 / 10)) < 0.01 && $W[1]['no_cpp'] === 1, 'palety : karta 1/1/1 bez palet');

    // --- Pieniądze na produkcję [221] ----------------------------------------------------------------------------------
    expectFc($b['cost_per_carton'] === ['raw' => 8.0, 'labor' => 10.0, 'media' => 0.0, 'shared' => 3.0, 'total' => 21.0, 'section' => true],
        'wydatki na karton Bagietki : surowce 8 + płace 10 + Pakowanie 3 = 21 zł, bez amortyzacji, kosztów stałych i Administracji (miesiąc zamknięty) ; ' . json_encode($b['cost_per_carton']));
    expectFc($rows[2]['cost_per_carton']['total'] === 3.0 && !$rows[2]['cost_per_carton']['section'], 'produkt bez działu i bez kosztu surowców : tylko wspólne działy produkcji');
    expectFc($f['cost']['shared_sections'] === ['Pakowanie/wysyłka'] && $f['cost']['admin_sections'] === ['Administracja']
        && abs($f['cost']['excluded']['fixed'] - $f['totals']['cartons'] * 3.5) < 0.01 && abs($f['cost']['excluded']['admin'] - $f['totals']['cartons'] * 4) < 0.01
        && abs($f['cost']['excluded']['depreciation'] - $f['totals']['cartons'] * 1) < 0.01 && abs($f['cost']['excluded']['financing'] - $f['totals']['cartons'] * 1.5) < 0.01
        && abs($f['cost']['excluded']['investment'] - $f['totals']['cartons'] * 2.5) < 0.01,
        'poza wydatkami (informacja) : inwestycje (amortyzacja + kredyt i leasing), pozostałe koszty stałe, Administracja ; ' . json_encode($f['cost']['excluded']));
    $w = $W[$i50];
    $expect = 0.0;
    foreach ($f['products'] as $r) $expect += $r['values'][$i50] * $r['cost_per_carton']['total'];
    expectFc(abs($w['cost'] - $expect) < 0.01 && abs($w['cost'] - ($w['cost_raw'] + $w['cost_labor'] + $w['cost_media'] + $w['cost_shared'])) < 0.01,
        'tydzień : pieniądze = kartony × koszt kartonu (suma składników)');
    expectFc(abs($f['cost']['total'] - array_sum(array_column($W, 'cost'))) < 0.05 && abs($f['cost']['logistics'] - $f['totals']['cartons'] * 2) < 0.01
        && $f['cost']['months'] === 1 && $f['cost']['no_raw'] >= 1 && $f['cost']['no_section'] >= 1, 'suma 13 tygodni, logistyka osobno (2 zł / karton), produkty bez kosztu policzone');

    // --- Gamy (zakładka 2) i połączenia -----------------------------------------------------------------------------
    $noel = array_values(array_filter($f['seasons'], fn($x) => $x['id'] === 8))[0];
    $by = array_column($noel['products'], null, 'panel_id');
    expectFc($by[41001]['status'] === 'auto' && $by[41001]['product_id'] === 2 && $by[41001]['n1_pieces'] === 840 && $by[41001]['n1_shipped'] === 10,
        'Noël : Cougnou z podpowiedzi, sprzedane w sklepach N-1 840 szt., wysłane 10 kart.');
    expectFc($by[41007]['status'] === 'auto' && $by[41007]['product_id'] === 4 && $by[41020]['status'] === 'none' && !isset($by[41009]), 'Noël : Bûche → podpowiedź (Praline), Crêpe bez pary, Bagietka (całoroczna) pominięta');
    $nicolas = array_values(array_filter($f['seasons'], fn($x) => $x['id'] === 9))[0];
    expectFc($nicolas['name'] === 'Saint-Nicolas', 'nazwa gamy bez emoji');
    $f2 = $fc->link(41007, ['mode' => 'product', 'product_id' => 5], 5, $today, $now);
    $by = array_column(array_values(array_filter($f2['seasons'], fn($x) => $x['id'] === 8))[0]['products'], null, 'panel_id');
    expectFc($by[41007]['status'] === 'linked' && $by[41007]['product_id'] === 5, 'połączenie ręczne : Bûche → nasz produkt 5');
    $f2 = $fc->link(41020, ['mode' => 'ignore'], 5, $today, $now);
    $by = array_column(array_values(array_filter($f2['seasons'], fn($x) => $x['id'] === 8))[0]['products'], null, 'panel_id');
    expectFc($by[41020]['status'] === 'ignored' && $f2['panel_products'] === [], 'nie od nas : pominięty, brak produktów bez pary');
    $f2 = $fc->link(41020, ['mode' => 'auto'], 5, $today, $now);
    expectFc(count($f2['panel_products']) === 1, 'przywróć : znów bez pary');
    expectFcFail(fn() => $fc->link(41007, ['mode' => 'product', 'product_id' => 999], 5, $today, $now), 422, 'połączenie : nieistniejący produkt');
    expectFcFail(fn() => $fc->link(99999, ['mode' => 'ignore'], 5, $today, $now), 404, 'połączenie : produkt spoza gam');

    // --- Zakupy 13 tygodni ------------------------------------------------------------------------------------------
    $m = array_column($f['purchases']['rows'], null, 'id')[1];
    $need = array_sum(array_map(fn($v) => $v * 1000, $b['values']));
    expectFc(abs($m['need'] - $need) < 0.01 && $m['stock'] === 50000.0 && $m['unit'] === 'kg' && $m['factor'] === 1000, 'Mąka : potrzeba = kartony Bagietki × 1 kg, stan 50 kg');
    // Brak w tygodniu 3 (25 + 20 + 50 > 50 kg → tydz. 2 ; 25 + 20 = 45 ≤ 50) ; zamówić do = 16.11 − 14 dni = 2.11 (teraz).
    expectFc($m['short_week'] === 2 && $m['order_by'] === '2026-11-02' && $m['lead_days'] === 14 && $m['lead_known'] && $m['urgency'] === 'now', 'Mąka : brak od tyg. 3, zamówić do 2.11 (oferta 14 dni)');
    $packs = (int) ceil(($need - 50000) / 25000);
    expectFc($m['packages'] === $packs && $m['to_buy'] === $packs * 25000.0 && abs($m['value'] - $packs * 50) < 0.01, 'Mąka : do kupienia w pełnych workach 25 kg × 2 zł / kg');
    expectFc($f['purchases']['no_recipe'] !== [] && in_array('Cougnou sucre – 200 g (cru)', $f['purchases']['no_recipe'], true), 'zakupy : produkty bez receptury wymienione');

    // --- Zatwierdzenie (zadanie tygodnia) i cofnięcie ---------------------------------------------------------------
    $pos = $c->get(PositionRepository::class);
    expectFc(!$pos->autoCondition('forecast_approved', '2026-11-02', '2026-11-09'), 'zadanie : przed zatwierdzeniem niezaliczone');
    expectFcFail(fn() => $fc->approve(5, str_repeat('x', 300), $today, $now), 422, 'zatwierdzenie : uwaga za długa');
    $a = $fc->approve(5, 'OK na święta', $today, $now);
    expectFc($a['approval']['by'] === 'Anna Kierownik' && $a['approval']['cartons'] === $a['totals']['cartons'] && !$a['approval']['changed'] && $a['approval']['note'] === 'OK na święta',
        'zatwierdzenie : kto, kartony, uwaga');
    expectFc($pos->autoCondition('forecast_approved', '2026-11-02', '2026-11-09'), 'zadanie : zalicza się samo po zatwierdzeniu');
    $run = $pdo->query("SELECT * FROM n_forecast_run WHERE week_start = '2026-11-02'")->fetch();
    $payload = json_decode($run['payload'], true);
    expectFc(abs((float) $run['production_cost'] - $a['cost']['total']) < 0.01 && $a['runs'][0]['production_cost'] === (float) $run['production_cost'], 'zatwierdzenie : pieniądze na produkcję zapisane');
    expectFc((int) $run['cartons'] === $a['totals']['cartons'] && (float) $run['trend'] === 1.25 && count($payload['weeks']) === 13 && count($payload['products']) === count($a['products']),
        'zatwierdzenie : zdjęcie liczb (13 tygodni, produkty, trend)');
    $fc->approve(5, null, $today, $now + 30);
    expectFc((int) $pdo->query('SELECT COUNT(*) FROM n_forecast_run')->fetchColumn() === 1, 'ponowne zatwierdzenie : nadpisuje ten sam tydzień');
    $add('2026-12-01', [1 => 400], 1);
    expectFc($fc->page($today, $now)['approval']['changed'], 'po zmianie zamówień : « liczby zmieniły się od zatwierdzenia »');
    $r = $fc->reopen($today, $now);
    expectFc($r['approval'] === null && !$pos->autoCondition('forecast_approved', '2026-11-02', '2026-11-09'), 'cofnięcie : zadanie znów do zrobienia');

    // --- Odczyt nieudany : błąd zapisany, poprzedni odczyt zostaje -----------------------------------------------------
    $panel->data['/material-suppliers'] = [['id' => 2, 'name' => 'Bruyerre S.A.']];
    expectFcFail(fn() => $seasons->refresh(5, $now + 3600), 502, 'odczyt : brak SL Food w panelu');
    $p = $fc->page($today, $now + 3700);
    expectFc($p['panel_status']['error'] !== null && $p['panel_status']['read_at'] !== null && count($p['seasons']) === 2, 'błąd odczytu widoczny, gamy z poprzedniego odczytu');
} finally {
    $pdo->exec("DROP DATABASE IF EXISTS {$testDb}");
}

echo "forecast_test: OK\n";
