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
use App\Repositories\Dashboard\ProfitabilityRepository;
use App\Repositories\Workshop\ParamRepository;
use App\Services\Dashboard\ProfitabilityService;

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
    foreach (['production_sections', 'tfb_section_target', 'tfb_prod_param', 'cost_month', 'cost_month_employee', 'cost_media_type',
                 'cost_media_month', 'cost_media_section_allocation', 'cost_month_fixed_cost', 'erp_company_other_settings', 'document_wdt',
                 'document_wdt_product', 'final_product', 'customer_order', 'customer_order_product'] as $table) {
        $pdo->exec("CREATE TABLE {$testDb}.{$table} LIKE {$source}.{$table}");
    }
    $pdo->exec("USE {$testDb}");
    $pdo->exec("SET SESSION sql_mode = ''");

    // Kurs 4 PLN/EUR ; Piekarnia (cel 20 %, amortyzacja 400 zł) i dział wspólny Pakowanie.
    $pdo->exec("INSERT INTO erp_company_other_settings (id, exchange_rate_euro) VALUES (1, 4.00)");
    $pdo->exec("INSERT INTO production_sections (id, name, depreciation, water_cost, power_supply_cost, fuel_cost, other_cost) VALUES
        (1, 'Piekarnia', 400, 0, 0, 0, 0), (2, 'Pakowanie', 0, 0, 0, 0, 0), (3, 'Pusty', 0, 0, 0, 0, 0)");
    $pdo->exec("INSERT INTO tfb_section_target (id_section, staff_pct_target, margin_pct_target, is_shared) VALUES (1, 20, NULL, 0), (2, NULL, NULL, 1)");
    $pdo->exec("INSERT INTO tfb_prod_param (code, value, default_value, value_type) VALUES
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

    $service = new ProfitabilityService(new ProfitabilityRepository($pdo), new ParamRepository($pdo));
    $today = new DateTimeImmutable('2026-10-19');

    // --- Miesiąc zamknięty
    $sep = $service->overview('2026-09', $today);
    expectProfit($sep['period']['current'] === false && $sep['warnings'] === [], 'wrzesień : okres zamknięty, bez ostrzeżeń');
    expectProfit(count($sep['groups']) === 1 && $sep['groups'][0]['name'] === 'Piekarnia', 'jedna grupa produktów (wspólny i pusty dział pominięte)');
    $g = $sep['groups'][0];
    near($g['revenue'], 5000, 'sprzedaż grupy');
    near($g['staff'], 2000, 'personel grupy 8 000 zł / 4 (pracownik wyłączony pominięty)');
    near($g['staff_pct'], 40, 'personel / sprzedaż grupy');
    near($g['staff_target'], 20, 'cel grupy z tfb_section_target');
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
    $row = $pdo->query('SELECT staff_pct_target, margin_pct_target FROM tfb_section_target WHERE id_section = 1')->fetch();
    expectProfit($row['staff_pct_target'] === null && (float) $row['margin_pct_target'] === 12.5, 'pusty cel = cel ogólny, przecinek przyjęty');
    $service->saveTarget(3, ['staff_pct_target' => '30'], 1);
    expectProfit((float) $pdo->query('SELECT staff_pct_target FROM tfb_section_target WHERE id_section = 3')->fetchColumn() === 30.0, 'cel działu bez wiersza tworzy wiersz');
    $service->saveGeneralTargets(['profit.staff_target_pct' => '30'], 1);
    $after = (new ProfitabilityService(new ProfitabilityRepository($pdo), new ParamRepository($pdo)))->overview('2026-09', $today);
    near($after['groups'][0]['staff_target'], 30, 'grupa bez własnego celu bierze nowy cel ogólny');
    near($after['groups'][0]['missing'], 2000 / 0.3 - 5000, 'brakująca sprzedaż przeliczona z nowym celem');

    echo "profitability_test: OK\n";
} finally {
    $pdo->exec("DROP DATABASE IF EXISTS {$testDb}");
}
