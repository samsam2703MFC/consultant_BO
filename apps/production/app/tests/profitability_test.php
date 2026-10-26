<?php
declare(strict_types=1);
/*
 * Page « Rentowność » : calculs sur une base jetable avec des chiffres connus.
 * Ratio personnel / ventes, CA nécessaire et manquant (€ et %), marge du groupe, résultat de
 * production et CA pour l'objectif de résultat, mois en cours au prorata, mois de coûts manquant,
 * commandes ouvertes, validation des objectifs.
 *
 *   php tests/profitability_test.php
 *
 * Base : TFB_TEST_DSN (défaut mysql:unix_socket=/run/mysqld/mysqld.sock), TFB_TEST_USER (root),
 * TFB_TEST_PASS (vide) ; structures recopiées depuis TFB_TEST_SOURCE_DB (défaut erp, migrations
 * tfb appliquées). La base erp_profit_test est recréée puis supprimée.
 */
require_once __DIR__ . '/../public/vendor/autoload.php';

use App\Exceptions\CustomException;
use App\Repositories\Dashboard\GrowthActionRepository;
use App\Repositories\Dashboard\ProfitabilityRepository;
use App\Services\Dashboard\GrowthActionService;
use App\Repositories\Workshop\ParamRepository;
use App\Services\Dashboard\ProfitabilityService;
use App\Services\Dashboard\GroupWeekProfitService;
use App\Repositories\Workshop\ExecutionRepository;
use App\Repositories\Workshop\PlanningRepository;
use App\Repositories\Analysis\WdtSalesRepository;
use App\Repositories\Production\Cost\SectionUnitCostRepository;
use App\Services\Production\Cost\SectionUnitCostService;
use App\Repositories\Staff\StaffRepository;
use App\Services\Staff\StaffBudgetService;

function expectProfit(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException('FAILED: ' . $message);
}

function near(?float $actual, float $expected, string $message): void
{
    expectProfit($actual !== null && abs($actual - $expected) < 0.01, $message . ' (attendu ' . $expected . ', obtenu ' . var_export($actual, true) . ')');
}

$dsn = getenv('TFB_TEST_DSN') ?: 'mysql:unix_socket=/run/mysqld/mysqld.sock';
$source = getenv('TFB_TEST_SOURCE_DB') ?: 'erp';
$testDb = 'erp_profit_test';
try {
    $pdo = new PDO($dsn . ';charset=utf8mb4', getenv('TFB_TEST_USER') ?: 'root', getenv('TFB_TEST_PASS') ?: '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    echo "profitability_test: SKIPPED (no database: {$e->getMessage()})\n";
    exit(0);
}

$pdo->exec("DROP DATABASE IF EXISTS {$testDb}");
$pdo->exec("CREATE DATABASE {$testDb} CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
try {
    foreach (['production_sections', 'n_section_target', 'n_prod_param', 'cost_month', 'cost_month_employee', 'cost_media_type',
                 'cost_media_month', 'cost_media_section_allocation', 'cost_month_fixed_cost', 'cost_month_logistics', 'cost_month_section', 'erp_company_other_settings', 'document_wdt',
                 'document_wdt_product', 'final_product', 'customer_order', 'customer_order_product', 'n_growth_action', 'user', 'category_product',
                 'production_employee', 'n_staff_pay', 'n_prod_employee', 'recipe_competency', 'recipe_competency_connection',
                 'flattened_recipe_ingredient', 'n_recipe_version', 'raw_material', 'category_material', 'supplier', 'raw_material_price_changelog', 'unit', 'n_wdt_superseded',
                 'n_store_price_config', 'n_store_product', 'n_store_product_shop', 'n_store_product_link', 'n_store_price_sync',
                 'carton_stock', 'n_prod_group', 'n_prod_workstation', 'n_prod_item', 'n_prod_routing_step', 'n_prod_routing_alternative', 'n_prod_clock'] as $table) {
        $pdo->exec("CREATE TABLE {$testDb}.{$table} LIKE {$source}.{$table}");
    }
    $pdo->exec("USE {$testDb}");
    $pdo->exec("SET SESSION sql_mode = ''");

    // Kurs 4 PLN/EUR ; Piekarnia (cel 20 %, amortyzacja 400 zł) i dział wspólny Pakowanie.
    $pdo->exec("INSERT INTO erp_company_other_settings (id, exchange_rate_euro) VALUES (1, 4.00)");
    $pdo->exec("INSERT INTO production_sections (id, name, depreciation, water_cost, power_supply_cost, fuel_cost, other_cost) VALUES
        (1, 'Piekarnia', 400, 0, 0, 0, 0), (2, 'Pakowanie', 0, 0, 0, 0, 0), (3, 'Pusty', 0, 0, 0, 0, 0)");
    $pdo->exec("INSERT INTO n_section_target (id_section, staff_pct_target, margin_pct_target, is_shared) VALUES (1, 20, NULL, 0), (2, NULL, NULL, 1)");
    $pdo->exec("INSERT INTO n_prod_param (code, value, default_value, value_type) VALUES
        ('profit.staff_target_pct', '25', '25', 'decimal'), ('profit.total_staff_target_pct', '40', '35', 'decimal'),
        ('profit.section_margin_target_pct', '30', '30', 'decimal'), ('profit.margin_target_pct', '10', '10', 'decimal')");

    // Wrzesień 2026 : personel 8 000 zł + 4 000 zł (wspólny), koszty stałe 2 000 zł.
    $pdo->exec("INSERT INTO cost_month (id, year, month, status) VALUES (1, 2026, 9, 'OPEN')");
    $pdo->exec("INSERT INTO cost_month_employee (month_id, section_id, name, surname, monthly_cost, is_included) VALUES
        (1, 1, 'A', 'A', 5000, 1), (1, 1, 'B', 'B', 3000, 1), (1, 2, 'C', 'C', 4000, 1), (1, 1, 'Wyłączony', 'X', 9999, 0)");
    $pdo->exec("INSERT INTO cost_month_fixed_cost (month_id, name, amount, category) VALUES (1, 'Czynsz', 2000, 'RENT')");

    // Produkt Piekarni : surowce 40 zł / karton. WDT : 100 kartonów × 50 € we wrześniu, 20 × 50 € w październiku.
    $pdo->exec("INSERT INTO final_product (id, name, id_section, pre_calculated_raw_price_per_carton, active) VALUES (1, 'Chleb', 1, 40, 1)");
    $pdo->exec("INSERT INTO document_wdt (id, doc_num, established_date, currency) VALUES (1, 'W/1', '2026-09-10', 'eur'), (2, 'W/2', '2026-10-05', 'eur')");
    $pdo->exec("INSERT INTO document_wdt_product (id_document_wdt, id_product, name, quantity, price_carton) VALUES (1, 1, 'Chleb', 100, 50), (2, 1, 'Chleb', 20, 50)");
    // Zamówienie na 25.10 jeszcze bez WDT : 30 × 50 € ; zamówienie wysłane i zarchiwizowane nie liczą się.
    $pdo->exec("INSERT INTO customer_order (id, id_customer, status, expected_shipping_date, id_wdt) VALUES (1, 1, 1, '2026-10-25', NULL), (2, 1, 5, '2026-10-26', NULL), (3, 1, 3, '2026-10-05', 2)");
    $pdo->exec("INSERT INTO customer_order_product (id_order, id_product, quantity_carton, purchase_price) VALUES (1, 1, 30, 50), (2, 1, 99, 50), (3, 1, 20, 50)");

    $service = new ProfitabilityService(new ProfitabilityRepository($pdo), new ParamRepository($pdo), new SectionUnitCostService(new SectionUnitCostRepository($pdo, new WdtSalesRepository($pdo))));
    $today = new DateTimeImmutable('2026-10-19');

    // --- Miesiąc zamknięty
    $sep = $service->overview('2026-09', $today);
    expectProfit($sep['period']['current'] === false && $sep['warnings'] === [], 'wrzesień : okres zamknięty, bez ostrzeżeń');
    expectProfit(count($sep['groups']) === 1 && $sep['groups'][0]['name'] === 'Piekarnia', 'jedna grupa produktów (wspólny i pusty dział pominięte)');
    $g = $sep['groups'][0];
    near($g['revenue'], 5000, 'sprzedaż grupy');
    near($g['staff'], 2000, 'personel grupy 8 000 zł / 4 (pracownik wyłączony pominięty)');
    near($g['staff_pct'], 40, 'personel / sprzedaż grupy');
    near($g['staff_target'], 20, 'cel grupy z n_section_target');
    near($g['needed'], 10000, 'sprzedaż potrzebna = personel ÷ cel');
    near($g['missing'], 5000, 'brakująca sprzedaż');
    near($g['missing_pct'], 100, 'brakująca sprzedaż w % sprzedaży');
    near($g['materials'], 1000, 'surowce 100 × 40 zł / 4');
    near($g['contribution'], 1900, 'marża grupy = 5000 − 1000 − 2000 − 100 (amortyzacja)');
    near($g['margin_pct'], 38, 'marża grupy w %');
    near($g['margin_target'], 30, 'cel marży grupy z parametru ogólnego');
    expectProfit($g['ok'] === false, 'grupa poza celem');
    $t = $sep['totals'];
    near($sep['shared']['staff'], 1000, 'personel działów wspólnych');
    near($t['staff_pct'], 60, 'personel całej firmy / sprzedaż');
    near($t['staff_needed'], 7500, 'sprzedaż potrzebna dla celu 40 %');
    near($t['staff_missing'], 2500, 'brakująca sprzedaż firmy');
    near($t['staff_missing_pct'], 50, 'brakująca sprzedaż firmy w %');
    near($t['fixed'], 500, 'koszty stałe firmy');
    near($t['result'], 400, 'wynik = 5000 − 1000 − 3000 − 100 − 500');
    near($t['margin_pct'], 8, 'wynik w %');
    near($t['margin_needed'], 3600 / 0.7, 'sprzedaż dla wyniku 10 % = koszty stałe ÷ (1 − 20 % surowców − 10 %)');
    near($t['margin_missing'], 3600 / 0.7 - 5000, 'brakująca sprzedaż do celu wyniku');
    expectProfit($t['margin_ok'] === false && $t['margin_reachable'] === true, 'cel wyniku osiągalny, jeszcze nieosiągnięty');
    // Sprzedaż na osobę : osoby wliczone z kalkulatora kosztów (wyłączona pominięta).
    near($g['people'], 2, 'Piekarnia : 2 osoby');
    near($g['revenue_per_person'], 2500, 'sprzedaż grupy na osobę 5000 / 2');
    near($sep['shared']['people'], 1, 'dział wspólny : 1 osoba');
    near($t['people'], 3, 'cała firma : 3 osoby');
    near($t['revenue_per_person'], 5000 / 3, 'sprzedaż na osobę w całej firmie');
    near($t['revenue_per_person_production'], 2500, 'sprzedaż na osobę w produkcji');
    // Marża brutto (po surowcach) i działy wspólne : jeden wiersz na dział.
    near($g['gross'], 4000, 'marża brutto grupy = 5000 − 1000');
    near($g['gross_pct'], 80, 'marża brutto grupy w %');
    near($t['gross'], 4000, 'marża brutto firmy');
    expectProfit(count($sep['shared']['sections']) === 1 && $sep['shared']['sections'][0]['name'] === 'Pakowanie', 'dział wspólny w osobnym wierszu');
    near($sep['shared']['sections'][0]['staff'], 1000, 'personel działu wspólnego');
    near($sep['shared']['sections'][0]['staff_share'], 20, 'personel działu wspólnego w % sprzedaży firmy');
    near($sep['shared']['sections'][0]['people'], 1, 'osoby działu wspólnego');

    // --- Bieżący miesiąc : koszty października nie są wprowadzone → wrzesień, personel proporcjonalnie (19/31)
    $oct = $service->overview('month', $today);
    expectProfit($oct['period']['current'] === true && $oct['period']['end'] === '2026-10-31', 'bieżący miesiąc do 31.10');
    expectProfit(count($oct['warnings']) === 1 && str_contains($oct['warnings'][0], 'październik 2026 → przyjęto wrzesień 2026'), 'ostrzeżenie o brakujących kosztach');
    $g = $oct['groups'][0];
    near($g['revenue'], 1000, 'sprzedaż października do dziś');
    near($g['staff'], 2000 * 19 / 31, 'personel proporcjonalnie do upływu dni');
    near($g['needed'], 10000, 'sprzedaż potrzebna na cały miesiąc');
    near($g['missing'], 9000, 'do sprzedania do końca miesiąca');
    near($g['open'], 1500, 'otwarte zamówienia bez WDT (zarchiwizowane i wysłane pominięte)');
    near($g['missing_after_open'], 7500, 'do znalezienia po zamówieniach');
    near($oct['totals']['projection'], 1000 * 31 / 19, 'tempo sprzedaży na koniec miesiąca');
    expectProfit(count($oct['trend']) === 6 && $oct['trend'][5]['current'] === true, 'trend 6 miesięcy kończy się bieżącym');
    near($g['people'], 2, 'bieżący miesiąc : 2 osoby (koszty września)');
    near($g['revenue_per_person'], 1000 * 31 / 19 / 2, 'bieżący miesiąc : tempo sprzedaży na osobę');
    near($oct['trend'][4]['groups'][1]['revenue_per_person'], 2500, 'trend : wrzesień, Piekarnia na osobę');
    near($oct['trend'][4]['revenue_per_person'], 5000 / 3, 'trend : wrzesień, cała firma na osobę');
    // Tableau 18 mois : du plus récent au plus ancien ; mois sans coûts estimés (osoby z września).
    $pp = $oct['per_person'];
    expectProfit($pp['count'] === 18 && $pp['months'][0]['ym'] === '2026-10' && $pp['months'][17]['ym'] === '2025-05', '18 mois, du plus récent');
    expectProfit($pp['real'] === 1, 'un seul mois avec coûts saisis');
    expectProfit($pp['months'][3]['estimated_from'] === 'wrzesień 2026' && $pp['months'][1]['estimated_from'] === null, 'mois estimés : source affichée');
    expectProfit(count($pp['groups']) === 1 && $pp['groups'][0]['name'] === 'Piekarnia', 'groupes du tableau');
    near($pp['groups'][0]['best'], 2500, 'meilleur mois de la Piekarnia (mois en cours exclu)');
    near($pp['groups'][0]['average'], 6000 / (2 * 17 + 2 * 19 / 31), 'moyenne : ventes ÷ mois-personnes');
    near($pp['average'], 6000 / (3 * 17 + 3 * 19 / 31), 'moyenne de la société');

    // --- Brak kosztów przed pierwszym miesiącem : przyjęty najwcześniejszy znany
    $q = $service->overview('3m', $today);
    expectProfit(array_keys($q['period']['months']) === ['2026-07', '2026-08', '2026-09'], '3 pełne miesiące');
    expectProfit(str_contains($q['warnings'][0], 'lipiec 2026, sierpień 2026 → przyjęto wrzesień 2026'), 'wcześniejsze miesiące biorą pierwszy znany');
    near($q['groups'][0]['staff'], 6000, 'personel za 3 miesiące');

    // --- Cele : walidacja i powrót do celu ogólnego
    $refused = function (callable $fn): bool {
        try { $fn(); return false; } catch (CustomException) { return true; }
    };
    expectProfit($refused(fn() => $service->saveTarget(1, ['staff_pct_target' => '0'], 1)), 'cel 0 % odrzucony (dzielimy przez cel)');
    expectProfit($refused(fn() => $service->saveTarget(1, ['staff_pct_target' => 'abc'], 1)), 'cel nieliczbowy odrzucony');
    expectProfit($refused(fn() => $service->saveTarget(99, ['staff_pct_target' => '20'], 1)), 'nieznany dział odrzucony');
    expectProfit($refused(fn() => $service->saveGeneralTargets(['profit.total_staff_target_pct' => '150'], 1)), 'cel ogólny > 100 % odrzucony');
    $service->saveTarget(1, ['staff_pct_target' => '', 'margin_pct_target' => '12,5'], 1);
    $row = $pdo->query('SELECT staff_pct_target, margin_pct_target FROM n_section_target WHERE id_section = 1')->fetch();
    expectProfit($row['staff_pct_target'] === null && (float) $row['margin_pct_target'] === 12.5, 'pusty cel = cel ogólny, przecinek przyjęty');
    $service->saveTarget(3, ['staff_pct_target' => '30'], 1);
    expectProfit((float) $pdo->query('SELECT staff_pct_target FROM n_section_target WHERE id_section = 3')->fetchColumn() === 30.0, 'cel działu bez wiersza tworzy wiersz');
    $service->saveGeneralTargets(['profit.staff_target_pct' => '30'], 1);
    $after = (new ProfitabilityService(new ProfitabilityRepository($pdo), new ParamRepository($pdo), new SectionUnitCostService(new SectionUnitCostRepository($pdo, new WdtSalesRepository($pdo)))))->overview('2026-09', $today);
    near($after['groups'][0]['staff_target'], 30, 'grupa bez własnego celu bierze nowy cel ogólny');
    near($after['groups'][0]['missing'], 2000 / 0.3 - 5000, 'brakująca sprzedaż przeliczona z nowym celem');


    // --- Actions de développement du CA : calcul, validation, situation « si développées »
    $m = GrowthActionService::metrics(['pieces_per_shop_month' => 200, 'shops' => 5, 'selling_price_eur' => 0.8, 'target_margin_pct' => 30, 'investment_eur' => 2400], 36);
    near($m['revenue_month'], 800, 'CA = 200 pièces × 5 magasins × 0,80 €');
    near($m['margin_month'], 240, 'marge = CA × 30 %');
    near($m['payback_months'], 10, 'retour = 2 400 € ÷ 240 €/mois');
    near($m['roi_12'], 20, 'ROI 12 mois = (240 × 12 − 2 400) ÷ 2 400');
    near($m['roi_horizon'], 260, 'ROI 36 mois');
    $noMargin = GrowthActionService::metrics(['pieces_per_shop_month' => 10, 'shops' => 1, 'selling_price_eur' => 1, 'target_margin_pct' => 0, 'investment_eur' => 100], 36);
    expectProfit($noMargin['payback_months'] === null, 'no margin, no payback');
    expectProfit(GrowthActionService::metrics(['pieces_per_shop_month' => 1, 'shops' => 1, 'selling_price_eur' => 1, 'target_margin_pct' => 10, 'investment_eur' => 0])['roi_12'] === null, 'no investment, no ROI');

    $pdo->exec("INSERT INTO user (id, name, surname, displayed_name, email, phone, permission, login, password) VALUES (1, 'A', 'B', 'Anna B.', 'a@x.test', '1', 0, 'anna', 'x')");
    $pdo->exec("INSERT INTO final_product (id, name, id_section, ppc, standard_price, pre_calculated_raw_price_per_carton, active) VALUES (2, 'Bagietka', 1, 20, 16, 16, 1), (3, 'Pudełko', 2, 10, 5, 4, 1)");
    $growth = new GrowthActionService(new GrowthActionRepository($pdo), new ProfitabilityService(new ProfitabilityRepository($pdo), new ParamRepository($pdo), new SectionUnitCostService(new SectionUnitCostRepository($pdo, new WdtSalesRepository($pdo)))), new ProfitabilityRepository($pdo), new ParamRepository($pdo),
        new \App\Services\Finances\StorePrice\StoreSellabilityService(new \App\Repositories\Finances\StorePriceRepository($pdo), new \App\Services\Organization\PageAccessService(new \App\Repositories\Organization\PageAccessRepository($pdo))));
    $fields = function (callable $fn): array {
        try { $fn(); } catch (App\Core\Api\Workshop\ApiException $e) { return array_column($e->fieldErrors(), 'code', 'field'); }
        return [];
    };
    $errors = $fields(fn() => $growth->save(['section_id' => 1, 'title' => 'X', 'pieces_per_shop_month' => -1, 'shops' => 0, 'selling_price_eur' => 1, 'target_margin_pct' => 30], null, 1));
    expectProfit(($errors['final_product_id'] ?? '') === 'PRODUCT_REQUIRED' && isset($errors['pieces_per_shop_month'], $errors['shops']), 'product required, volumes checked');
    $errors = $fields(fn() => $growth->save(['section_id' => 1, 'title' => 'X', 'final_product_id' => 3, 'pieces_per_shop_month' => 1, 'shops' => 1, 'selling_price_eur' => 1, 'target_margin_pct' => 30], null, 1));
    expectProfit(($errors['final_product_id'] ?? '') === 'PRODUCT_NOT_IN_GROUP', 'product of another group refused');
    $a1 = $growth->save(['section_id' => 1, 'title' => 'Bagietka do sieci', 'final_product_id' => 2, 'pieces_per_shop_month' => 500, 'shops' => 4,
        'selling_price_eur' => 0.8, 'target_margin_pct' => 35, 'investment_eur' => 1000, 'start_month' => '2026-11', 'owner_user' => 1], null, 1);
    expectProfit($a1['product'] === 'Bagietka' && $a1['status'] === 'idea' && abs($a1['metrics']['revenue_month'] - 1600) < 0.01, 'action on an existing product');
    $a2 = $growth->save(['section_id' => 1, 'title' => 'Nowy chleb', 'product_name' => 'Chleb orkiszowy', 'pieces_per_shop_month' => 100, 'shops' => 5,
        'selling_price_eur' => 2, 'target_margin_pct' => 40, 'investment_eur' => 0, 'status' => 'planned'], null, 1);
    expectProfit($a2['is_new_product'] && $a2['product'] === 'Chleb orkiszowy', 'action on a new product');
    $a3 = $growth->save(['section_id' => 1, 'title' => 'Porzucone', 'product_name' => 'X', 'pieces_per_shop_month' => 1000, 'shops' => 5,
        'selling_price_eur' => 1, 'target_margin_pct' => 10, 'status' => 'dropped'], null, 1);
    $updated = $growth->save(['final_product_id' => 2], $a2['id'], 1);
    expectProfit(!$updated['is_new_product'] && $updated['product'] === 'Bagietka', 'switching to an existing product clears the new-product name');

    // Septembre : CA 5 000 €, personnel 2 000 € (objectif 30 % après la modification des objectifs plus haut).
    $g = $growth->group(1, '2026-09');
    $c = $g['context'];
    near($c['revenue_month'], 5000, 'monthly revenue of the group');
    near($c['needed_month'], 2000 / 0.3, 'revenue needed for the staff target');
    near($c['actions_revenue_month'], 1600 + 1000, 'actions add revenue (dropped one left out)');
    near($c['revenue_if'], 7600, 'revenue if developed');
    near($c['staff_pct_if'], 2000 / 7600 * 100, 'staff ratio if developed');
    near($c['gap_after'], 0, 'target reached with the actions');
    near($c['revenue_by_status']['dropped'], 5000, 'dropped action still listed by status');
    expectProfit(count($g['actions']) === 3 && $g['actions'][0]['status'] === 'planned' && $g['defaults']['shops'] >= 1, 'actions listed, active ones first');
    expectProfit($g['products'][0]['name'] === 'Bagietka' && abs($g['products'][0]['price_piece_eur'] - 0.8) < 1e-9 && abs($g['products'][0]['material_piece_eur'] - 0.2) < 1e-9, 'group products with price and material cost per piece');
    $summary = $growth->summaryBySection();
    expectProfit($summary[1]['count'] === 2 && abs($summary[1]['revenue_month'] - 2600) < 0.01, 'summary per group for the table');


    // --- Budżet personelu : minimum + pula premii (% sprzedaży działu) ÷ aktywne osoby z premią
    $pdo->exec("INSERT INTO n_prod_param (code, value, default_value, value_type) VALUES ('pay.bonus_pool_pct', '4', '5', 'decimal')");
    $pdo->exec("INSERT INTO production_employee (id, name, surname, cost, id_section) VALUES
        (1, 'Ala', 'A', 5000, 1), (2, 'Bartek', 'B', 3000, 1), (3, 'Cezary', 'C', 4000, 2), (4, 'Dorota', 'D', 2000, 1)");
    $staffRepo = new StaffRepository($pdo);
    // Une requête = un service : ProfitabilityService garde les sections lues pendant la requête.
    $newBudget = fn() => new StaffBudgetService(new ProfitabilityService(new ProfitabilityRepository($pdo), new ParamRepository($pdo), new SectionUnitCostService(new SectionUnitCostRepository($pdo, new WdtSalesRepository($pdo)))), $staffRepo, new ParamRepository($pdo), new App\Repositories\Production\Cost\CostMonthEmployeeRepository($pdo));
    $budgetService = $newBudget();
    $budgetService->savePay(1, ['base_monthly' => '3 600,00'], 1);
    $budgetService->savePay(3, ['base_monthly' => '3000'], 1);
    $budgetService->savePay(4, ['active' => false], 1);
    expectProfit($refused(fn() => $budgetService->savePay(1, ['base_monthly' => '-1'], 1)), 'ujemne minimum odrzucone');
    expectProfit($refused(fn() => $budgetService->savePay(99, ['base_monthly' => '1'], 1)), 'nieznany pracownik odrzucony');
    expectProfit($budgetService->savePay(2, ['base_monthly' => '3500'], 1)['warning'] !== null, 'minimum wyższe niż koszt sygnalizowane');
    $budgetService->savePay(2, ['base_monthly' => ''], 1);
    expectProfit($pdo->query('SELECT base_monthly FROM n_staff_pay WHERE id_production_employee = 2')->fetchColumn() === null, 'puste minimum = nie podano');

    $b = $budgetService->overview('2026-09');
    $p = $b['sections'][1];
    expectProfit($p['active'] === 2 && $p['eligible'] === 2 && $p['no_base'] === 1, 'Piekarnia : 2 aktywne osoby z premią (nieobecna pominięta), 1 bez minimum');
    near($p['base_month_pln'], 6600, 'minimum 3 600 + cały koszt osoby bez minimum 3 000');
    near($p['base'], 1650, 'minimum w EUR (kurs 4)');
    near($p['pool'], 200, 'pula premii 4 % × 5 000 €');
    near($p['per_person_pln'], 400, 'premia na osobę 100 € = 400 zł');
    near($p['per_person_month_pln'], 400, 'premia miesięczna na osobę');
    near($p['budget'], 1850, 'budżet personelu = minimum + pula');
    near($p['budget_pct'], 37, 'budżet / sprzedaż');
    near($p['needed'], 1650 / 0.26, 'sprzedaż do celu 30 % = minimum ÷ (30 % − 4 %)');
    near($p['per_person_at_target_pln'], 1650 / 0.26 * 0.04 / 2 * 4, 'premia na osobę przy celu');
    near($p['above_min_pln'], 1400, 'obecnie ponad minimum (osoby z podanym minimum)');
    $rows = array_column($p['employees'], null, 'id');
    near($rows[1]['model_pln'], 4000, 'osoba : minimum 3 600 + premia 400');
    expectProfit($rows[4]['bonus_pln'] === null && $rows[4]['active'] === false, 'nieobecna osoba bez premii');
    $shared = $b['sections'][2];
    expectProfit($shared['shared'] && $shared['average'], 'dział wspólny bez własnego % : średnia premia');
    near($shared['pool'], 100, 'średnia premia działów produkcyjnych na osobę (200 € ÷ 2) × 1 osoba');
    expectProfit(!isset($b['sections'][3]), 'dział bez ludzi i sprzedaży pominięty');
    near($b['totals']['budget'], 1850 + 750 + 100, 'budżet personelu firmy');
    expectProfit(count(array_filter($b['notices'], fn($n) => str_contains($n, 'nie jest podane dla 1 os.'))) === 1, 'brak minimum sygnalizowany');

    $service->saveTarget(2, ['bonus_pct' => '1'], 1);
    $service->saveTarget(1, ['bonus_pct' => '30'], 1);
    $b = $newBudget()->overview('2026-09');
    near($b['sections'][2]['pool'], 50, 'dział wspólny z własnym % : % sprzedaży całej firmy');
    expectProfit($b['sections'][1]['reachable'] === false && $b['sections'][1]['needed'] === null, 'premie ≥ cel kosztu personelu : cel nieosiągalny');
    expectProfit($refused(fn() => $service->saveTarget(1, ['bonus_pct' => '100'], 1)), 'premia 100 % odrzucona');
    $service->saveTarget(1, ['bonus_pct' => ''], 1);
    expectProfit($refused(fn() => $budgetService->saveDefaultPct('abc', 1)), '% ogólny nieliczbowy odrzucony');
    $budgetService->saveDefaultPct('6', 1);
    near($newBudget()->overview('2026-09')['sections'][1]['pool'], 300, 'nowy % ogólny');

    // --- Zakupy miesiąca : receptura × kartony, ceny z końca miesiąca, kategorie i dostawcy
    // Sierpień : 10 kartonów Rogala (receptura : 1000 g mąki + 200 g masła) i 5 kartonów Tarty bez receptury (8 zł / karton).
    $pdo->exec("INSERT INTO unit (id, name) VALUES (3, 'g')");
    $pdo->exec("INSERT INTO category_material (id, id_group, name) VALUES (105, 0, 'Mąka')");
    $pdo->exec("INSERT INTO supplier (id, name, phone, email, is_active) VALUES (1, 'Młyn A', '', '', 1), (2, 'Mleczarnia B', '', '', 1), (3, 'Młyn C', '', '', 1)");
    // Mąka : dziś 0,003 zł/g u C ; 1.08 cena 0,002 zł/g u A, 2.10 zmiana na 0,003 u C. Masło : kategoria 10 nie istnieje, bez historii cen.
    $pdo->exec("INSERT INTO raw_material (id, name, id_unit, id_category, id_current_supplier, price_per_unit) VALUES (100, 'Mąka T55', 3, 105, 3, 0.003), (101, 'Masło', 3, 10, 2, 0.02)");
    $pdo->exec("INSERT INTO raw_material_price_changelog (id_supplier, id_raw_material, pack_price, package_size_in_base_unit, timestamp) VALUES
        (1, 100, 50, 25000, '2026-08-01 08:00:00'), (3, 100, 75, 25000, '2026-10-02 08:00:00')");
    $pdo->exec("INSERT INTO flattened_recipe_ingredient (recipe_id, material_id, quantity) VALUES (910, 100, 1000), (910, 101, 200)");
    $pdo->exec("INSERT INTO final_product (id, name, id_section, id_recipe, pre_calculated_raw_price_per_carton, active) VALUES (902, 'Rogal', 1, 910, 99, 1), (903, 'Tarta', 1, NULL, 8, 1)");
    $pdo->exec("INSERT INTO document_wdt (id, doc_num, established_date, currency) VALUES (900, 'W/900', '2026-08-20', 'eur')");
    $pdo->exec("INSERT INTO document_wdt_product (id_document_wdt, id_product, name, quantity, price_carton) VALUES (900, 902, 'Rogal', 10, 30), (900, 903, 'Tarta', 5, 20)");
    // Rogal : kategoria Viennoiserie, 64 szt. × 75 g w kartonie ; Tarta bez kategorii.
    $pdo->exec("INSERT INTO category_product (id, name, target_margin_percentage) VALUES (77, 'Viennoiserie', 0)");
    $pdo->exec("UPDATE final_product SET id_category = 77, ppc = 64, weight = 75 WHERE id = 902");
    $aug = (new ProfitabilityService(new ProfitabilityRepository($pdo), new ParamRepository($pdo), new SectionUnitCostService(new SectionUnitCostRepository($pdo, new WdtSalesRepository($pdo)))))->overview('2026-08', $today);
    $buy = $aug['purchases'];
    // Co wyprodukowały grupy : Piekarnia → Viennoiserie (Rogal) i Bez kategorii (Tarta).
    $made = $aug['production'];
    expectProfit(count($made['groups']) === 1 && $made['groups'][0]['name'] === 'Piekarnia', 'produkcja : jedna grupa w sierpniu');
    $mg = $made['groups'][0];
    near($mg['revenue'], 400, 'produkcja grupy : 10 × 30 € + 5 × 20 €');
    near($mg['cartons'], 15, 'produkcja grupy : kartony');
    near($mg['materials'], 25, 'produkcja grupy : surowce = zakupy');
    expectProfit(array_column($mg['categories'], 'name') === ['Viennoiserie', 'Bez kategorii'] && $mg['products'] === 2, 'kategorie według sprzedaży');
    $vi = $mg['categories'][0];
    near($vi['pct'], 75, 'udział kategorii w grupie');
    $rogal = $vi['products'][0];
    expectProfit($rogal['name'] === 'Rogal', 'produkt w kategorii');
    near($rogal['pieces'], 640, 'sztuki = kartony × sztuk w kartonie');
    near($rogal['kg'], 48, 'waga = sztuki × 75 g');
    near($rogal['materials'], 15, 'surowce produktu z receptury (60 zł / 4)');
    near($rogal['margin_pct'], 95, 'marża po surowcach');
    near($rogal['price_carton'], 30, 'cena za karton');
    near($mg['categories'][1]['products'][0]['materials'], 10, 'produkt bez receptury : koszt z karty');
    // Rogal : 10 × 1000 g × 0,002 (cena z sierpnia) = 20 zł + 10 × 200 g × 0,02 = 40 zł ; Tarta 5 × 8 zł = 40 zł ; razem 100 zł = 25 €.
    near($aug['totals']['materials'], 25, 'surowce sierpnia = receptury po cenach z sierpnia + produkt bez receptury');
    near($buy['total'], 25, 'zakupy = ta sama kwota co surowce w rachunku');
    $cats = array_column($buy['categories'], null, 'name');
    $sups = array_column($buy['suppliers'], null, 'name');
    near($cats['Mąka']['amount'], 5, 'kategoria Mąka : 20 zł (cena z sierpnia, nie dzisiejsza)');
    near($cats['Bez kategorii']['amount'], 10, 'masło z nieistniejącą kategorią : bez kategorii');
    near($sups['Młyn A']['amount'], 5, 'dostawca mąki z sierpnia (historia), nie obecny');
    expectProfit(!isset($sups['Młyn C']), 'dostawca od października nie liczy się w sierpniu');
    near($sups['Mleczarnia B']['amount'], 10, 'masło u obecnego dostawcy (bez historii)');
    $last = end($buy['categories']);
    expectProfit(!empty($last['no_recipe']) && abs($last['amount'] - 10) < 0.01 && $buy['no_recipe_products'] === 1, 'produkty bez receptury na końcu listy, koszt z karty');
    near($buy['uncategorised'], 10, 'kwota bez kategorii sygnalizowana');
    near($buy['card_total'], (10 * 99 + 5 * 8) / 4, 'koszt według kart produktów (porównanie)');
    expectProfit(abs($cats['Mąka']['items'][0]['qty'] - 10000) < 0.001 && $cats['Mąka']['items'][0]['unit'] === 'g', 'ilość surowca : 10 kartonów × 1000 g');
    near(array_sum(array_column($buy['categories'], 'pct')), 100, 'udziały kategorii = 100 %');
    $oct2 = (new ProfitabilityService(new ProfitabilityRepository($pdo), new ParamRepository($pdo), new SectionUnitCostService(new SectionUnitCostRepository($pdo, new WdtSalesRepository($pdo)))))->overview('month', $today);
    near($oct2['totals']['materials'], 200, 'październik : Chleb bez receptury, koszt z karty (20 × 40 zł / 4)');

    // --- Rentowność grup : tydzień i miesiąc (tydzień od poniedziałku, dziś 19.10 18:00).
    // Pointowanie Piekarni : 6.10 (4 h) i 19.10 (8 h) ; 3 kartony z etykietą 6.10 ; cobot na 2. etapie (połowa czasu marszruty).
    $pdo->exec("INSERT INTO n_prod_group (id, code, name_pl, name_fr, color, sort_order, id_section, active) VALUES (1, 'PIE', 'Piekarnia', 'Boulangerie', '#8D1D2C', 1, 1, 1)");
    $pdo->exec("INSERT INTO production_employee (id, name, surname, cost, id_section) VALUES (90, 'Jan', 'Test', 0, 1)");
    $pdo->exec("INSERT INTO n_prod_employee (id, id_production_employee, id_primary_group, active) VALUES (90, 90, 1, 1)");
    $pdo->exec("INSERT INTO n_prod_clock (id_employee, clock_in, clock_out, source) VALUES (90, '2026-10-06 06:00:00', '2026-10-06 10:00:00', 'manual'), (90, '2026-10-19 06:00:00', '2026-10-19 14:00:00', 'manual')");
    $pdo->exec("INSERT INTO carton_stock (id_carton, batch_number, id_product, status, date_add) VALUES (1, 'L1', 1, 1, '2026-10-06'), (2, 'L1', 1, 1, '2026-10-06'), (3, 'L1', 1, 1, '2026-10-06')");
    $pdo->exec("INSERT INTO n_prod_workstation (id, code, id_group, name_pl, name_fr, type, robot_mode, operator_share, capacity_per_pass, active) VALUES (5, 'COBOT', 1, 'Cobot', 'Cobot', 'robot', 'collaboratif', 0.25, 1, 1)");
    $pdo->exec("INSERT INTO n_prod_item (id, item_type, id_final_product, base_unit) VALUES (7, 'product', 1, 'szt')");
    $pdo->exec("INSERT INTO n_prod_routing_step (id, id_item, step_no, name_pl, name_fr, id_workstation, fixed_time_s, variable_time_s, capacity_per_pass, operators_required) VALUES
        (70, 7, 1, 'Ręcznie', 'Main', 5, 0, 1, 1, 1), (71, 7, 2, 'Cobot', 'Cobot', 5, 0, 1, 1, 1)");
    $pdo->exec("INSERT INTO n_prod_routing_alternative (id_step, id_workstation, fixed_time_s, variable_time_s, capacity_per_pass, priority) VALUES (71, 5, 0, 1, 1, 1)");
    $weekly = (new GroupWeekProfitService(new ProfitabilityService(new ProfitabilityRepository($pdo), new ParamRepository($pdo), new SectionUnitCostService(new SectionUnitCostRepository($pdo, new WdtSalesRepository($pdo)))),
        new ProfitabilityRepository($pdo), new ExecutionRepository($pdo), new PlanningRepository($pdo), new ParamRepository($pdo)))->overview(new DateTimeImmutable('2026-10-19 18:00:00'));
    expectProfit(count($weekly['weeks']) === 12 && $weekly['weeks'][11]['from'] === '2026-10-19' && $weekly['weeks'][0]['from'] === '2026-08-03', '12 tygodni od poniedziałku, ostatni = bieżący');
    expectProfit(count($weekly['groups']) === 1 && $weekly['groups'][0]['name'] === 'Piekarnia', 'jedna grupa (dział wspólny pominięty)');
    $pg = $weekly['groups'][0];
    $series = array_column(array_map(fn($w, $s) => $s + ['from' => $w['from']], $weekly['weeks'], $pg['series']), null, 'from');
    near($series['2026-10-05']['revenue'], 1000, 'tydzień 5.10 : sprzedaż 20 × 50 €');
    near($series['2026-10-05']['materials'], 200, 'tydzień 5.10 : surowce wg wskaźnika miesiąca (20 × 40 zł / 4)');
    near($series['2026-10-05']['staff'], 2000 * 7 / 31, 'tydzień 5.10 : personel = miesiąc × 7 / 31 dni');
    near($series['2026-10-05']['margin'], 1000 - 200 - 2000 * 7 / 31 - 100 * 7 / 31, 'tydzień 5.10 : marża = sprzedaż − surowce − personel − amortyzacja');
    near($series['2026-10-05']['hours'], 4, 'tydzień 5.10 : 4 h pointowania');
    near($series['2026-10-05']['hours_pct'], 100, 'tydzień 5.10 : 100 % godzin wszystkich grup');
    expectProfit($series['2026-10-05']['cartons'] === 3, 'tydzień 5.10 : 3 kartony z etykietą');
    near($series['2026-10-05']['production'], 150, 'wartość produkcji : 3 × średnia cena kartonu 50 €');
    near($series['2026-09-07']['margin'], 5000 - 1000 - 2000 * 7 / 30 - 100 * 7 / 30, 'tydzień 7.09 : marża (wrzesień, 30 dni)');
    near($pg['week']['hours'], 8, 'bieżący tydzień : 8 h');
    near($pg['week']['revenue'], 0, 'bieżący tydzień : bez sprzedaży');
    near($pg['week']['margin'], -(2000 + 100) / 31, 'bieżący tydzień (1 dzień) : koszty dnia');
    near($pg['month']['hours'], 12, 'miesiąc : 12 h');
    near($pg['month']['margin'], 1000 - 200 - (2000 + 100) * 19 / 31, 'miesiąc do 19.10 : koszty × 19 / 31');
    expectProfit($pg['cobot'] !== null && $pg['cobot']['names'] === ['Cobot'], 'cobot w procesie grupy');
    near($pg['cobot']['share_pct'], 50, 'cobot : połowa czasu marszruty');
    expectProfit(count(array_filter($weekly['notes'], fn($n) => str_starts_with($n, 'Brak wprowadzonych kosztów miesięcznych'))) === 1, 'brak kosztów miesięcy : jedno ostrzeżenie dla wszystkich tygodni');

    // Duplikat WDT oznaczony w n_wdt_superseded : sprzedaż liczona raz.
    $pdo->exec("INSERT INTO document_wdt (id, doc_num, established_date, currency) VALUES (950, 'W/1', '2026-09-10', 'eur')");
    $pdo->exec("INSERT INTO document_wdt_product (id_document_wdt, id_product, name, quantity, price_carton) VALUES (950, 1, 'Chleb', 100, 50)");
    $fresh = fn() => new ProfitabilityService(new ProfitabilityRepository($pdo), new ParamRepository($pdo), new SectionUnitCostService(new SectionUnitCostRepository($pdo, new WdtSalesRepository($pdo))));
    near($fresh()->overview('2026-09', $today)['totals']['revenue'], 10000, 'duplikat bez oznaczenia : liczony dwa razy');
    $pdo->exec("INSERT INTO n_wdt_superseded (id_document_wdt, superseded_by, reason) VALUES (950, 1, 'test')");
    $dedup = $fresh()->overview('2026-09', $today);
    near($dedup['totals']['revenue'], 5000, 'duplikat oznaczony : sprzedaż liczona raz');
    near($dedup['production']['groups'][0]['cartons'], 100, 'duplikat oznaczony : produkcja liczona raz');

    echo "profitability_test: OK\n";
} finally {
    $pdo->exec("DROP DATABASE IF EXISTS {$testDb}");
}
