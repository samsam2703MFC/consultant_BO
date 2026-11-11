<?php
declare(strict_types=1);
/*
 * Ceny klientów wg marży [212] : reguła jednej ceny (cel, limit podwyżki, zaokrąglenie w górę, rabat klienta, nigdy
 * niżej) ; na bazie jednorazowej : lista klientów i cennik (aktywni klienci, efekt na miesiąc z WDT), zastosowanie
 * (tylko podwyżki, historia cen, cena zmieniona w międzyczasie zostaje, przerwa między podwyżkami), dostęp i parametry.
 *
 *   php tests/client_prices_test.php
 *
 * Base : TFB_TEST_DSN (défaut mysql:unix_socket=/run/mysqld/mysqld.sock), TFB_TEST_USER (root), TFB_TEST_PASS (vide) ;
 * structures recopiées depuis TFB_TEST_SOURCE_DB (défaut erp, migration 134 appliquée). La base erp_client_prices_test
 * est recréée puis supprimée.
 */
require_once __DIR__ . '/../public/vendor/autoload.php';

use App\Exceptions\CustomException;
use App\Repositories\Finances\ClientPriceRepository;
use App\Services\Finances\ClientPriceService;

function expectCp(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException('FAILED: ' . $message);
}

function expectCpFail(callable $fn, int $code, string $message): void
{
    try {
        $fn();
    } catch (CustomException $e) {
        expectCp((int) $e->getCode() === $code, $message . ' (HTTP ' . $e->getCode() . ' : ' . $e->getMessage() . ')');
        return;
    }
    throw new RuntimeException('FAILED: ' . $message . ' (brak wyjątku)');
}

// --- Reguła (bez bazy) -------------------------------------------------------------------------------------
$P = ['cap_pct' => 8.0, 'round_eur' => 0.05, 'keep_discount' => true, 'interval_days' => 90];
$r = ClientPriceService::rule(25.65, 40.0, 25.65, $P);
expectCp($r['status'] === 'limited' && abs($r['new'] - 27.75) < 0.001 && abs($r['gap_pct'] - 44.1) < 0.05, 'limit +8 % : 25,65 → 27,75 (25,65 × 1,08 = 27,70 w górę do 0,05), do celu +44,1 %');
$r = ClientPriceService::rule(10.0, 10.30, 10.0, $P);
expectCp($r['status'] === 'up' && abs($r['new'] - 10.30) < 0.001 && $r['gap_pct'] === 0.0, 'cel w limicie : 10,00 → 10,30');
$r = ClientPriceService::rule(10.0, 10.31, 10.0, $P);
expectCp(abs($r['new'] - 10.35) < 0.001, 'zaokrąglenie w górę : 10,31 → 10,35');
$r = ClientPriceService::rule(185.75, 20.0, 185.75, $P);
expectCp($r['status'] === 'locked' && $r['new'] === 185.75 && abs($r['target'] - 20.0) < 0.001, 'cel 20 € < obecna 185,75 € : nigdy niżej');
$r = ClientPriceService::rule(12.0, null, 12.0, $P);
expectCp($r['status'] === 'none' && $r['new'] === 12.0 && $r['target'] === null, 'kategoria bez celu : bez zmian');
// Rabat 10 % (18 € przy standardowej 20 €) : cel klienta 22 × 0,9 = 19,80 ; limit 18 × 1,08 = 19,44 → 19,45.
$r = ClientPriceService::rule(18.0, 22.0, 20.0, $P);
expectCp($r['status'] === 'limited' && abs($r['target'] - 19.8) < 0.001 && abs($r['new'] - 19.45) < 0.001, 'rabat zachowany : cel 19,80, nowa 19,45');
$r = ClientPriceService::rule(18.0, 22.0, 20.0, ['keep_discount' => false] + $P);
expectCp(abs($r['target'] - 22.0) < 0.001 && abs($r['new'] - 19.45) < 0.001 && $r['gap_pct'] > 13, 'bez rabatu : cel 22 €');
$r = ClientPriceService::rule(25.0, 22.0, 20.0, $P);
expectCp($r['status'] === 'locked' && $r['new'] === 25.0, 'klient powyżej standardowej : bez mnożenia ceny celu, nie niżej');
$r = ClientPriceService::rule(18.0, 22.0, 20.0, ['round_eur' => 0.10, 'cap_pct' => 4.0] + $P);
expectCp(abs($r['new'] - 18.8) < 0.001, 'limit 4 %, zaokrąglenie 0,10 : 18,72 → 18,80');
mt_srand(212);
for ($i = 0; $i < 2000; $i++) {
    $cur = mt_rand(50, 50000) / 100;
    $tgt = mt_rand(0, 1) ? mt_rand(10, 80000) / 100 : null;
    $std = mt_rand(0, 60000) / 100;
    $p = ['cap_pct' => mt_rand(5, 500) / 10, 'round_eur' => [0.01, 0.05, 0.10, 0.50][mt_rand(0, 3)], 'keep_discount' => (bool) mt_rand(0, 1)];
    $x = ClientPriceService::rule($cur, $tgt, $std, $p);
    expectCp($x['new'] >= $cur, "nigdy niżej : {$cur} → {$x['new']}");
    expectCp($x['new'] <= round($cur * (1 + $p['cap_pct'] / 100), 2) + $p['round_eur'] + 0.001, "najwyżej limit + zaokrąglenie : {$cur} → {$x['new']}");
}

// --- Baza jednorazowa ------------------------------------------------------------------------------------
$dsn = getenv('TFB_TEST_DSN') ?: 'mysql:unix_socket=/run/mysqld/mysqld.sock';
$source = getenv('TFB_TEST_SOURCE_DB') ?: 'erp';
$testDb = 'erp_client_prices_test';
try {
    $pdo = new PDO($dsn . ';charset=utf8mb4', getenv('TFB_TEST_USER') ?: 'root', getenv('TFB_TEST_PASS') ?: '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    echo "client_prices_test: PARTIAL (bez bazy : {$e->getMessage()}) — reguła OK\n";
    exit(0);
}
$pdo->exec("DROP DATABASE IF EXISTS {$testDb}");
$pdo->exec("CREATE DATABASE {$testDb} CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
try {
    foreach (['customer', 'final_product', 'final_product_customer_price', 'n_product_price_change', 'n_prod_param', 'document_wdt', 'document_wdt_product',
                 'n_wdt_superseded', 'erp_company_other_settings', 'production_sections', 'category_product', 'user', 'n_prod_user_role', 'n_user_position',
                 'n_page_access'] as $table) {
        $pdo->exec("CREATE TABLE {$testDb}.{$table} LIKE {$source}.{$table}");
    }
    $pdo->exec("USE {$testDb}");
    $pdo->exec("SET SESSION sql_mode = ''");
    $pdo->exec("INSERT INTO n_prod_param (code, value, default_value, value_type) VALUES ('prices.client_cap_pct', '8', '8', 'decimal'),
        ('prices.client_round_eur', '0.05', '0.05', 'decimal'), ('prices.client_keep_discount', '1', '1', 'bool'), ('prices.client_interval_days', '90', '90', 'int')");
    $pdo->exec("INSERT INTO user (id, login) VALUES (1, 'admin'), (2, 'kasia')");
    $pdo->exec("INSERT INTO customer (id, company, name, surname, active) VALUES (1, 'Alpha SRL', '', '', 1), (2, '', 'Jan', 'Beta', 1), (3, 'Gamma', '', '', 0)");
    $pdo->exec("INSERT INTO final_product (id, name, active, standard_price) VALUES (10, 'Baguette', 1, 25.65), (11, 'Miel', 1, 185.75), (12, 'Sel', 1, 10)");
    $pdo->exec("INSERT INTO final_product_customer_price (id_customer, id_final_product, price, available) VALUES
        (1, 10, 25.65, 1), (1, 11, 185.75, 1), (1, 12, 10.00, 1), (2, 10, 30.00, 1), (2, 12, 9.00, 0), (3, 10, 20.00, 1)");
    // Sprzedaż WDT : Alpha 120 kartonów bagietki w ostatnich 12 miesiącach = 10 / mies.
    $d = date('Y-m-d', strtotime('-2 months'));
    $pdo->exec("INSERT INTO document_wdt (id, id_customer, established_date, currency) VALUES (500, 1, '{$d}', 'EUR')");
    $pdo->exec("INSERT INTO document_wdt_product (id_document_wdt, id_product, name, quantity, price_carton) VALUES (500, 10, 'Baguette', 120, 25.65)");
    // Wynik strony marż (kurs 4,30) : kategoria z celem 30 % (bagietka cel 40 €, miód cel 20 €), kategoria bez celu.
    $result = ['exchange_rate' => 4.30, 'categories' => [
        ['category_id' => 1, 'category_name' => 'Pain', 'target_margin' => 30.0, 'products' => [
            ['product_id' => 10, 'name' => 'Baguette', 'suggested_price_pln' => 40 * 4.30, 'sale_price_eur' => 25.65, 'total_cost_eur' => 28.0],
            ['product_id' => 11, 'name' => 'Miel', 'suggested_price_pln' => 20 * 4.30, 'sale_price_eur' => 185.75, 'total_cost_eur' => 14.0]]],
        ['category_id' => 2, 'category_name' => 'Épicerie', 'target_margin' => 0.0, 'products' => [
            ['product_id' => 12, 'name' => 'Sel', 'suggested_price_pln' => null, 'sale_price_eur' => 10.0, 'total_cost_eur' => 5.0]]],
    ]];

    $builder = new DI\ContainerBuilder();
    $builder->addDefinitions([PDO::class => $pdo]);
    $svc = $builder->build()->get(ClientPriceService::class);

    $o = $svc->overview($result, null);
    $ids = array_column($o['clients'], 'id');
    expectCp($ids === [1, 2], 'klienci : aktywni z cennikiem, największy efekt pierwszy (Gamma nieaktywna pominięta)');
    expectCp($o['clients'][1]['name'] === 'Jan Beta', 'nazwa : firma albo imię i nazwisko');
    expectCp($o['client']['id'] === 1 && $o['client']['prices'] === 3 && $o['client']['changes'] === 1, 'domyślnie Alpha : 3 ceny, 1 do podwyżki');
    $rows = array_column($o['rows'], null, 'id');
    expectCp($rows[10]['status'] === 'limited' && abs($rows[10]['new'] - 27.75) < 0.001 && abs($rows[10]['month_eur'] - 21.0) < 0.001,
        'bagietka : 27,75 €, efekt 2,10 × 10 kart. = +21 € / mies.');
    expectCp($rows[11]['status'] === 'locked' && $rows[11]['delta'] === 0.0 && $rows[12]['status'] === 'none', 'miód : nie niżej ; sól : brak celu');
    expectCp($o['rows'][0]['id'] === 10, 'podwyżki na górze listy');
    expectCp(abs($o['totals']['month_eur'] - 21.0) < 0.001 && $o['totals']['changes'] === 2, 'razem : 2 ceny do zmiany, +21 € / mies.');
    $b = $svc->overview($result, 2);
    expectCp($b['client']['id'] === 2 && count($b['rows']) === 1 && abs($b['rows'][0]['new'] - 32.40) < 0.001 && $b['rows'][0]['month_eur'] === 0.0,
        'Beta : 30,00 → 32,40 (cena niedostępna pominięta), bez sprzedaży efekt 0');

    // Zastosowanie.
    expectCpFail(fn() => $svc->apply($result, 1, [10], 2), 403, 'bez dostępu do marż : 403');
    expectCpFail(fn() => $svc->apply($result, 1, [], 1), 422, 'bez produktów : 422');
    expectCpFail(fn() => $svc->apply($result, 1, [11, 12], 1), 422, 'tylko ceny bez podwyżki : 422');
    $a = $svc->apply($result, 1, [10, 11, 12], 1);
    expectCp($a['applied'] === 1 && abs($a['month_eur'] - 21.0) < 0.001, 'zastosowano 1 cenę, +21 € / mies.');
    $price = fn(int $c, int $p) => (float) $pdo->query("SELECT price FROM final_product_customer_price WHERE id_customer = {$c} AND id_final_product = {$p}")->fetchColumn();
    expectCp(abs($price(1, 10) - 27.75) < 0.001 && abs($price(1, 11) - 185.75) < 0.001 && abs($price(2, 10) - 30.0) < 0.001, 'tylko Alpha / bagietka zmieniona');
    $log = $pdo->query("SELECT id_customer, id_final_product, old_price, new_price, source, changed_by FROM n_product_price_change")->fetchAll();
    expectCp(count($log) === 1 && (float) $log[0]['old_price'] === 25.65 && (float) $log[0]['new_price'] === 27.75 && $log[0]['source'] === 'margin_clients'
        && (int) $log[0]['changed_by'] === 1, 'historia cen : 25,65 → 27,75, margin_clients, kto');
    // Przerwa 90 dni : ta sama cena nie idzie znowu w górę (dwa kliknięcia ≠ dwie podwyżki).
    $o2 = $svc->overview($result, 1);
    $r10 = array_column($o2['rows'], null, 'id')[10];
    expectCp($r10['status'] === 'recent' && $r10['delta'] === 0.0 && $r10['next_at'] === date('Y-m-d', strtotime('+90 days')) && $o2['client']['changes'] === 0,
        'po podwyżce : status « podniesiona », następna za 90 dni');
    expectCpFail(fn() => $svc->apply($result, 1, [10], 1), 422, 'drugie zastosowanie : brak cen do podwyżki');
    $pdo->exec("UPDATE n_product_price_change SET changed_at = DATE_SUB(NOW(), INTERVAL 91 DAY)");
    $r10 = array_column($svc->overview($result, 1)['rows'], null, 'id')[10];
    expectCp($r10['status'] === 'limited' && abs($r10['new'] - 30.0) < 0.001, 'po 91 dniach : następne +8 % (27,75 → 30,00)');

    // Cena zmieniona w międzyczasie zostaje (repozytorium : stara cena musi się zgadzać).
    $repo = new ClientPriceRepository($pdo);
    $done = $repo->raise(2, [10 => ['old' => 29.0, 'new' => 31.0], 12 => ['old' => 9.0, 'new' => 8.0]], 'margin_clients', 1);
    expectCp($done === [] && abs($price(2, 10) - 30.0) < 0.001 && abs($price(2, 12) - 9.0) < 0.001, 'stara cena inna / obniżka / niedostępna : bez zmian');

    // Parametry (administrator).
    expectCpFail(fn() => $svc->saveParams(['cap_pct' => '5', 'round_eur' => '0.05', 'interval_days' => '30'], 2), 403, 'parametry : tylko administrator');
    expectCpFail(fn() => $svc->saveParams(['cap_pct' => '80', 'round_eur' => '0.05', 'interval_days' => '30'], 1), 422, 'limit 80 % : poza zakresem');
    expectCpFail(fn() => $svc->saveParams(['cap_pct' => '5', 'round_eur' => '0.05', 'interval_days' => '400'], 1), 422, 'przerwa 400 dni : poza zakresem');
    $np = $svc->saveParams(['cap_pct' => '5,5', 'round_eur' => '0.10', 'interval_days' => '0', 'keep_discount' => false], 1);
    expectCp($np['cap_pct'] === 5.5 && $np['round_eur'] === 0.1 && $np['interval_days'] === 0 && $np['keep_discount'] === false, 'zapis : 5,5 %, 0,10 €, bez przerwy, bez rabatu');
} finally {
    $pdo->exec("DROP DATABASE IF EXISTS {$testDb}");
}
echo "client_prices_test: OK\n";
