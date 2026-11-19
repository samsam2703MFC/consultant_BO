<?php
declare(strict_types=1);
/*
 * Kreator cen [218] : reguła ceny (marża bazowa, współczynnik surowców, nigdy niżej, limit +8 %, zaokrąglenie),
 * na bazie jednorazowej : krok 1 (faktury, marża przed → po, przyczyny), krok 2 (propozycje, reguły kategorii, parametry,
 * dostęp), krok 3 (ceny klientów : za standardową / rabat zachowany, kanały), plan, e-mail « nowe ceny od » z PDF przed
 * datą, zastosowanie w dniu zmiany (cena zmieniona w międzyczasie pominięta, historia, bez drugiego e-maila), strona
 * franczyzy (wysłano / brak klucza / ponowienia), anulowanie. Strona franczyzy : udawany PricelistPusher (nic nie wychodzi).
 * [351] Ścieżka cen : reguła ±3 % (podwyżka, obniżka, w progu, limit +8 % / −8 %, cena celu jako dolna granica), baza
 * kosztu (inicjalizacja z n_margin_recalc < 60 dni albo koszt dziś, aktualizacja po planie, przywrócenie po anulowaniu),
 * build() z obniżką (ceny klientów : za standardową / ten sam %), « Wyślij teraz » (kolejka, bez wysyłki), zadanie
 * czwartkowe price_path_done (markDone, TaskWizards).
 *
 *   php tests/price_wizard_test.php
 *
 * Base : TFB_TEST_DSN (défaut mysql:unix_socket=/run/mysqld/mysqld.sock), TFB_TEST_USER (root), TFB_TEST_PASS (vide) ;
 * structures recopiées depuis TFB_TEST_SOURCE_DB (défaut erp, migration 138 appliquée). La base erp_price_wizard_test
 * est recréée puis supprimée.
 */
require_once __DIR__ . '/../public/vendor/autoload.php';

use App\Exceptions\CustomException;
use App\Services\Finances\PricelistPusher;
use App\Services\Finances\PricePublicationService;
use App\Services\Finances\PriceWizardService;
use App\Services\Finances\ProductMarginService;

function expectPw(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException('FAILED: ' . $message);
}

function expectPwFail(callable $fn, int $code, string $message): void
{
    try {
        $fn();
    } catch (CustomException $e) {
        expectPw((int) $e->getCode() === $code, $message . ' (HTTP ' . $e->getCode() . ' : ' . $e->getMessage() . ')');
        return;
    }
    throw new RuntimeException('FAILED: ' . $message . ' (brak wyjątku)');
}

// --- Reguła (bez bazy) -------------------------------------------------------------------------------------
$P = ['cap_pct' => 8.0, 'round_eur' => 0.05];
$r = PriceWizardService::rule(10.0, 30.0, 44.0, 35.0, 2.5, $P);
expectPw($r['status'] === 'up' && abs($r['by_margin'] - 46.15) < 0.001 && abs($r['by_coef'] - 25.0) < 0.001 && abs($r['new'] - 46.20) < 0.001,
    'marża 35 % : 30 ÷ 0,65 = 46,15 > 10 × 2,5 → 46,20 (w górę do 0,05), w limicie');
$r = PriceWizardService::rule(12.0, 30.0, 30.0, 35.0, 2.5, $P);
expectPw($r['status'] === 'cap' && abs($r['new'] - 32.40) < 0.001 && abs($r['gap_pct'] - 42.45) < 0.06, 'limit +8 % : 30,00 → 32,40, do celu +42,5 %');
$r = PriceWizardService::rule(20.0, 25.0, 50.0, 10.0, 3.0, $P);
expectPw($r['status'] === 'cap' && abs($r['target'] - 60.0) < 0.001 && abs($r['new'] - 54.0) < 0.001, 'współczynnik wyżej niż marża : cel 20 × 3 = 60, limit 54,00');
$r = PriceWizardService::rule(10.0, 40.0, 100.0, 0.0, 2.5, $P);
expectPw($r['status'] === 'keep' && $r['new'] === 100.0 && $r['by_margin'] === null, 'cel 25 € < 100 € : nigdy niżej (bez marży bazowej)');
$r = PriceWizardService::rule(10.0, 40.0, 50.0, 0.0, null, $P);
expectPw($r['status'] === 'none' && $r['new'] === 50.0, 'bez marży i współczynnika : bez zmian');
expectPw(abs(PriceWizardService::maxPrice(25.65, $P) - 27.75) < 0.001, 'najwyższa cena : 25,65 × 1,08 = 27,70 → 27,75');
$d = PriceWizardService::defaultDate(7);
expectPw(date('N', strtotime($d)) === '1' && $d >= date('Y-m-d', strtotime('+7 days')) && $d < date('Y-m-d', strtotime('+14 days')), 'domyślna data : poniedziałek ≥ 7 dni');
mt_srand(218);
for ($i = 0; $i < 3000; $i++) {
    $cur = mt_rand(50, 50000) / 100;
    $p = ['cap_pct' => mt_rand(5, 300) / 10, 'round_eur' => [0.01, 0.05, 0.10, 0.50][mt_rand(0, 3)]];
    $x = PriceWizardService::rule(mt_rand(0, 20000) / 100, mt_rand(0, 40000) / 100, $cur, (float) mt_rand(0, 80), mt_rand(0, 1) ? mt_rand(10, 60) / 10 : null, $p);
    expectPw($x['new'] >= $cur, "nigdy niżej : {$cur} → {$x['new']}");
    expectPw($x['new'] <= PriceWizardService::maxPrice($cur, $p) + 0.001, "najwyżej limit (zaokrąglony) : {$cur} → {$x['new']}");
}

// --- [351] Reguła ścieżki cen (bez bazy) : koszt surowców dziś vs przy ostatnim cenniku, próg ±3 % ---------------
$Q = ['cap_pct' => 8.0, 'cut_pct' => 8.0, 'up_pct' => 3.0, 'down_pct' => 3.0, 'round_eur' => 0.05];
$r = PriceWizardService::pathRule(44.0, 40.0, 124.0, 44.0, null, $Q);
expectPw($r['status'] === 'up' && abs($r['cost_pct'] - 10.0) < 0.001 && abs($r['new'] - 45.50) < 0.001,
    'koszt +10 % : 44 × 124 ÷ 120 = 45,47 → 45,50 (w górę, marża % na koszcie pełnym zachowana)');
$r = PriceWizardService::pathRule(60.0, 48.0, 132.0, 30.0, null, $Q);
expectPw($r['status'] === 'cap' && $r['limited'] && abs($r['new'] - 32.40) < 0.001 && abs($r['gap_pct'] - 1.9) < 0.001, 'koszt +25 % : 33,00 > limit +8 % → 32,40, reszta +1,9 %');
$r = PriceWizardService::pathRule(36.0, 40.0, 156.0, 100.0, 22.5, $Q);
expectPw($r['status'] === 'down' && abs($r['cost_pct'] + 10.0) < 0.001 && abs($r['new'] - 97.50) < 0.001 && !$r['limited'], 'koszt −10 % : 100 × 156 ÷ 160 = 97,50 (obniżka)');
$r = PriceWizardService::pathRule(36.0, 38.0, 156.0, 100.0, null, $Q);
expectPw($r['status'] === 'down' && abs($r['new'] - 98.70) < 0.001, 'obniżka w dół do 0,05 : 100 × 156 ÷ 158 = 98,73 → 98,70');
$r = PriceWizardService::pathRule(25.0, 50.0, 100.0, 50.0, null, $Q);
expectPw($r['status'] === 'down' && $r['limited'] && abs($r['new'] - 46.00) < 0.001, 'koszt −50 % : 40,00 < limit −8 % → 46,00');
$r = PriceWizardService::pathRule(36.0, 40.0, 76.0, 30.0, 29.23, $Q);
expectPw($r['status'] === 'down' && $r['held'] === 'target' && abs($r['new'] - 29.25) < 0.001, 'obniżka nie poniżej ceny celu : 28,50 → 29,25');
$r = PriceWizardService::pathRule(36.0, 40.0, 76.0, 25.0, 29.23, $Q);
expectPw($r['status'] === 'keep' && $r['held'] === 'target' && $r['new'] === 25.0, 'cena celu ≥ obecna : bez obniżki');
$r = PriceWizardService::pathRule(20.4, 20.0, 60.0, 20.0, null, $Q);
expectPw($r['status'] === 'keep' && $r['held'] === null && abs($r['cost_pct'] - 2.0) < 0.001 && $r['new'] === 20.0, 'koszt +2 % : w progu ±3 %, bez zmian');
$r = PriceWizardService::pathRule(19.4, 20.0, 60.0, 20.0, null, $Q);
expectPw($r['status'] === 'down', 'koszt −3 % : na progu → obniżka');
$r = PriceWizardService::pathRule(20.0, 0.0, 60.0, 20.0, null, $Q);
expectPw($r['status'] === 'none', 'bez kosztu bazowego : none');
expectPw(abs(PriceWizardService::minPrice(25.65, $Q) - 23.60) < 0.001 && abs(PriceWizardService::roundDown(23.598, 0.05) - 23.55) < 0.001, 'najniższa cena 25,65 × 0,92 = 23,598 → 23,60 (w limicie)');
mt_srand(351);
for ($i = 0; $i < 3000; $i++) {
    $cur = mt_rand(50, 50000) / 100;
    $q = ['cap_pct' => mt_rand(5, 300) / 10, 'cut_pct' => mt_rand(5, 300) / 10, 'up_pct' => mt_rand(5, 100) / 10, 'down_pct' => mt_rand(5, 100) / 10,
        'round_eur' => [0.01, 0.05, 0.10, 0.50][mt_rand(0, 3)]];
    $base = mt_rand(100, 20000) / 100;
    $x = PriceWizardService::pathRule($base * mt_rand(40, 160) / 100, $base, mt_rand(0, 40000) / 100, $cur, mt_rand(0, 1) ? mt_rand(100, 60000) / 100 : null, $q);
    if (in_array($x['status'], ['up', 'cap'], true)) expectPw($x['new'] > $cur && $x['new'] <= PriceWizardService::maxPrice($cur, $q) + 0.001, "podwyżka w limicie : {$cur} → {$x['new']}");
    elseif ($x['status'] === 'down') expectPw($x['new'] < $cur && $x['new'] >= PriceWizardService::minPrice($cur, $q) - 0.001 && $x['cost_pct'] <= -$q['down_pct'] + 0.05, "obniżka w limicie : {$cur} → {$x['new']}");
    else expectPw(abs($x['new'] - $cur) < 0.0001, "bez zmian : {$cur}");
    if ($x['status'] === 'keep' && $x['held'] === null) expectPw($x['cost_pct'] > -$q['down_pct'] - 0.05 && $x['cost_pct'] < $q['up_pct'] + 0.05, 'w progu');
}

// --- Baza jednorazowa ------------------------------------------------------------------------------------
$dsn = getenv('TFB_TEST_DSN') ?: 'mysql:unix_socket=/run/mysqld/mysqld.sock';
$source = getenv('TFB_TEST_SOURCE_DB') ?: 'erp';
$testDb = 'erp_price_wizard_test';
try {
    $pdo = new PDO($dsn . ';charset=utf8mb4', getenv('TFB_TEST_USER') ?: 'root', getenv('TFB_TEST_PASS') ?: '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    echo "price_wizard_test: PARTIAL (bez bazy : {$e->getMessage()}) — reguła OK\n";
    exit(0);
}
$pdo->exec("DROP DATABASE IF EXISTS {$testDb}");
$pdo->exec("CREATE DATABASE {$testDb} CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
try {
    foreach (['customer', 'final_product', 'final_product_customer_price', 'n_product_price_change', 'n_prod_param', 'document_wdt', 'document_wdt_product',
                 'n_wdt_superseded', 'erp_company_other_settings', 'production_sections', 'category_product', 'user', 'n_prod_user_role', 'n_user_position', 'n_page_access',
                 'n_category_price_rule', 'n_price_publication', 'n_price_publication_item', 'n_price_publication_client',
                 'n_store_price_config', 'n_store_product', 'n_store_product_link', 'n_store_product_shop', 'n_store_price_sync',
                 'n_doc_distribution', 'n_doc_distribution_moment', 'communication_template', 'communication_message', 'communication_message_attachment',
                 'communication_email_account', 'erp_company_data', 'n_price_list_state', 'n_inv', 'n_inv_line', 'raw_material',
                 'raw_material_price_changelog', 'flattened_recipe_ingredient', 'n_margin_recalc', 'n_margin_recalc_run',
                 'n_price_cost_baseline', 'n_wizard_done'] as $table) {
        $pdo->exec("CREATE TABLE {$testDb}.{$table} LIKE {$source}.{$table}");
    }
    $pdo->exec("USE {$testDb}");
    $pdo->exec("SET SESSION sql_mode = ''");
    $pdo->exec("INSERT INTO n_prod_param (code, value, default_value, value_type) VALUES ('prices.client_cap_pct', '8', '8', 'decimal'),
        ('prices.client_round_eur', '0.05', '0.05', 'decimal'), ('prices.client_keep_discount', '1', '1', 'bool'), ('prices.client_interval_days', '90', '90', 'int'),
        ('prices.material_coef_default', '2.5', '2.5', 'decimal'), ('prices.notice_days', '7', '7', 'int'),
        ('prices.cost_up_pct', '3', '3', 'decimal'), ('prices.cost_down_pct', '3', '3', 'decimal'), ('prices.client_cut_pct', '8', '8', 'decimal')");
    $pdo->exec("INSERT INTO user (id, login) VALUES (1, 'admin'), (2, 'kasia')");
    $pdo->exec("INSERT INTO erp_company_data (id, wdt_company_name) VALUES (1, 'SL Food Test')");
    $pdo->exec("INSERT INTO category_product (id, name, target_margin_percentage) VALUES (1, 'Viennoiserie', 35), (2, 'Pain', 0)");
    $pdo->exec("INSERT INTO final_product (id, name, active, standard_price, id_category, id_recipe) VALUES
        (10, 'Croissant', 1, 44.00, 1, 900), (11, 'Chocolat', 1, 30.00, 1, 901), (12, 'Pain gris', 1, 100.00, 2, 902), (13, 'Ancien', 0, 20.00, 1, NULL),
        (14, 'Brioche', 1, 20.00, 1, NULL), (15, 'Tarte', 1, 50.00, 2, NULL), (16, 'Pain choco', 1, 30.00, 1, NULL), (17, 'Pain raisin', 1, 25.00, 1, NULL)");
    $pdo->exec("INSERT INTO customer (id, company, name, surname, active, franchisee_uid, email_head, communication_lang, pricelist_version) VALUES
        (1, 'Alpha SRL', 'Anne', 'Alpha', 1, '01HTESTALPHA00000000000000', 'alpha@example.invalid', 'nl', 3),
        (2, 'Beta', 'Bob', 'Beta', 1, NULL, 'beta@example.invalid', 'fr', 0),
        (3, 'Gamma', '', '', 1, '01HTESTGAMMA00000000000000', NULL, 'fr', 0),
        (4, 'Delta', '', '', 0, NULL, 'delta@example.invalid', 'fr', 0)");
    $pdo->exec("INSERT INTO final_product_customer_price (id_customer, id_final_product, price, available) VALUES
        (1, 10, 44.00, 1), (1, 11, 27.00, 1), (1, 12, 100.00, 1), (2, 10, 42.00, 1), (3, 11, 30.00, 1), (4, 10, 44.00, 1), (1, 15, 50.00, 1), (2, 15, 45.00, 1)");
    $pdo->exec("INSERT INTO n_doc_distribution (id_customer, doc_key, mail_to) VALUES (0, 'price', 'head'), (1, 'price', 'head'), (2, 'price', 'head'), (3, 'price', 'head')");
    $pdo->exec("INSERT INTO n_doc_distribution_moment (doc_key, moment) VALUES ('price', 'price_list_changed')");
    foreach (['fr' => 'Nouveaux prix à partir du {{ prices.valid_from }} — {{ client.company }}', 'nl' => 'Nieuwe prijzen vanaf {{ prices.valid_from }} — {{ client.company }}'] as $lang => $subject) {
        $pdo->prepare("INSERT INTO communication_template (template_type, language_code, name, subject_template, body_template, is_default, is_active, created_by)
            VALUES ('price_change_notice', ?, 'test', ?, 'Bonjour {{ client.contact_name }}, {{ prices.changed }} produits. {{ company.name }}', 1, 1, 'test')")->execute([$lang, $subject]);
    }
    // Sprzedaż WDT : Alpha 120 kartonów rogalików w 12 miesiącach = 10 / mies.
    $dd = date('Y-m-d', strtotime('-2 months'));
    $pdo->exec("INSERT INTO document_wdt (id, id_customer, established_date, currency) VALUES (500, 1, '{$dd}', 'EUR')");
    $pdo->exec("INSERT INTO document_wdt_product (id_document_wdt, id_product, name, quantity, price_carton) VALUES (500, 10, 'Croissant', 120, 44)");

    // Wynik strony marż (kurs 4,00, PLN) [351] : koszt surowców / pełny kartonu dziś ; baza (ostatni cennik) w n_price_cost_baseline.
    $prod = fn(int $id, string $name, float $food, float $full, float $cur, float $qty) => ['product_id' => $id, 'name' => $name, 'foodcost' => $food,
        'total_cost' => $full, 'sale_price_eur' => $cur, 'sale_price_pln' => $cur * 4, 'has_data' => true, 'avg_sold_qty_per_month' => $qty];
    $GLOBALS['PW_RESULT'] = ['exchange_rate' => 4.0, 'categories' => [
        ['category_id' => 1, 'category_name' => 'Viennoiserie', 'target_margin' => 35.0, 'products' => [
            $prod(10, 'Croissant', 44.0, 124.0, 44.0, 10.0), $prod(11, 'Chocolat', 60.0, 132.0, 30.0, 2.0), $prod(14, 'Brioche', 20.4, 60.0, 20.0, 1.0),
            $prod(16, 'Pain choco', 36.0, 76.0, 30.0, 1.0), $prod(17, 'Pain raisin', 36.0, 76.0, 25.0, 1.0)]],
        ['category_id' => 2, 'category_name' => 'Pain', 'target_margin' => 0.0, 'products' => [
            $prod(12, 'Pain gris', 36.0, 156.0, 100.0, 0.0), $prod(15, 'Tarte', 25.0, 100.0, 50.0, 3.0)]],
    ]];
    $margins = new class extends ProductMarginService {
        public function __construct() {}
        public function calculateCategoryOverview(int $requestedMonths = 12): array
        {
            $r = $GLOBALS['PW_RESULT'];
            foreach ($r['categories'] as &$c) $c['target_margin'] = (float) $GLOBALS['PW_DB']->query('SELECT target_margin_percentage FROM category_product WHERE id = ' . (int) $c['category_id'])->fetchColumn();
            return $r;
        }
    };
    $GLOBALS['PW_DB'] = $pdo;
    $pusher = new class extends PricelistPusher {
        public array $calls = [];
        public array $mode = [];
        public function __construct() {}
        public function pushPriceList($idClient)
        {
            $this->calls[] = (int) $idClient;
            $m = $this->mode[(int) $idClient] ?? 'ok';
            if ($m === 'nokey') throw new CustomException('MISSING_CLIENT_CREDENTIALS', 'errors', 500);
            return $m === 'ok';
        }
    };
    $builder = new DI\ContainerBuilder();
    $builder->addDefinitions([PDO::class => $pdo, ProductMarginService::class => $margins, PricelistPusher::class => $pusher,
        App\Services\Communication\SmtpTransport::class => new class implements App\Services\Communication\SmtpTransport {
            public function send(array $account, string $to, string $subject, string $body, string $messageId, array $attachments = [], ?string $footer = null): string { throw new RuntimeException('no e-mail in tests'); }
        }]);
    $c = $builder->build();
    $w = $c->get(PriceWizardService::class);
    $pub = $c->get(PricePublicationService::class);

    // Krok 2 [351] : baza kosztu. Chleb : przeliczenie 5 dni temu (40 → 36 zł) — baza 40 z datą przeliczenia wcześniejszego ;
    // bułki 16 i 17 : przeliczenie 90 dni temu — za stare, baza = koszt dziś. Przyczyna chleba : morele −18 % (FV 2026/11/77).
    $t20 = date('Y-m-d H:i:s', strtotime('-20 days'));
    $t5 = date('Y-m-d H:i:s', strtotime('-5 days'));
    $t90 = date('Y-m-d H:i:s', strtotime('-90 days'));
    $pdo->exec("INSERT INTO n_margin_recalc_run (run_at, run_by, recipes, products, changed) VALUES ('{$t20}', 1, 40, 7, 0), ('{$t5}', 1, 40, 7, 1)");
    $pdo->exec("INSERT INTO n_margin_recalc (id_product, old_cost_carton, new_cost_carton, ppc, recalculated_at, recalculated_by) VALUES
        (12, 40, 36, 10, '{$t5}', 1), (16, 40, 36, 10, '{$t90}', 1), (17, 40, 36, 10, '{$t90}', 1)");
    $pdo->exec("INSERT INTO raw_material (id, name) VALUES (503, 'Morele')");
    $pdo->exec("INSERT INTO raw_material_price_changelog (id_supplier, id_raw_material, pack_price, package_size_in_base_unit, timestamp) VALUES
        (1, 503, 100, 1000, DATE_SUB(NOW(), INTERVAL 40 DAY)), (1, 503, 82, 1000, DATE_SUB(NOW(), INTERVAL 12 DAY))");
    $pdo->exec("INSERT INTO flattened_recipe_ingredient (recipe_id, material_id, quantity) VALUES (902, 503, 1)");
    $pdo->exec("INSERT INTO n_inv (id, kind, status, number, prices_imported_at) VALUES (650, 'goods', 'accepted', 'FV 2026/11/77', DATE_SUB(NOW(), INTERVAL 12 DAY))");
    $pdo->exec("INSERT INTO n_inv_line (id, id_inv, id_raw_material, check_status) VALUES (60, 650, 503, 'checked')");
    $baseOf = fn(int $pid) => $pdo->query("SELECT * FROM n_price_cost_baseline WHERE id_product = {$pid}")->fetch() ?: null;
    $byId = fn(array $prices): array => array_column($prices['products'], null, 'id');

    $pr = $w->prices();
    $p = $byId($pr);
    expectPw((int) $pdo->query('SELECT COUNT(*) FROM n_price_cost_baseline')->fetchColumn() === 7 && !isset($p[13]), 'baza zapisana dla 7 produktów z ceną (nieaktywny pominięty)');
    expectPw(abs((float) $baseOf(12)['food_cost'] - 40.0) < 0.0001 && $baseOf(12)['set_at'] === $t20 && $baseOf(12)['id_publication'] === null, 'chleb : baza z n_margin_recalc (< 60 dni), data = przeliczenie wcześniejsze');
    expectPw(abs((float) $baseOf(16)['food_cost'] - 36.0) < 0.0001 && abs((float) $baseOf(10)['food_cost'] - 44.0) < 0.0001, 'przeliczenie 90 dni temu / brak : baza = koszt dziś');
    expectPw($p[12]['status'] === 'down' && abs($p[12]['cost_pct'] + 10.0) < 0.001 && abs($p[12]['new'] - 97.50) < 0.001, 'chleb : koszt −10 % → obniżka 97,50');
    expectPw(count($p[12]['reasons']) === 1 && $p[12]['reasons'][0]['name'] === 'Morele' && abs($p[12]['reasons'][0]['pct'] + 18.0) < 0.01
        && $p[12]['reasons'][0]['invoice'] === 'FV 2026/11/77', 'przyczyna : morele −18 %, FV 2026/11/77');
    expectPw($p[10]['status'] === 'keep' && $p[16]['status'] === 'keep' && $pr['totals']['down'] === 1, 'pozostałe : baza = dziś → bez zmian');
    // Baza nie zmienia się przy kolejnym wejściu (zapis tylko raz).
    $pdo->exec("UPDATE n_price_cost_baseline SET food_cost = 40 WHERE id_product IN (10, 16, 17)");
    $pdo->exec("UPDATE n_price_cost_baseline SET food_cost = 48 WHERE id_product = 11");
    $pdo->exec("UPDATE n_price_cost_baseline SET food_cost = 20 WHERE id_product = 14");
    $pdo->exec("UPDATE n_price_cost_baseline SET food_cost = 50 WHERE id_product = 15");
    $pr = $w->prices();
    $p = $byId($pr);
    expectPw($p[10]['status'] === 'up' && abs($p[10]['cost_pct'] - 10.0) < 0.001 && abs($p[10]['new'] - 45.50) < 0.001 && abs($p[10]['food_base'] - 40.0) < 0.001,
        'rogalik : koszt +10 % (40 → 44 zł) → podwyżka 45,50');
    expectPw(abs($p[10]['margin_before'] - 77.3) < 0.05 && abs($p[10]['margin_after'] - 75.8) < 0.05, 'marża na surowcach 77,3 % → 75,8 %');
    expectPw($p[11]['status'] === 'cap' && $p[11]['limited'] && abs($p[11]['new'] - 32.40) < 0.001 && abs($p[11]['max'] - 32.40) < 0.001, 'czekolada : koszt +25 % → limit +8 % (32,40)');
    expectPw($p[14]['status'] === 'keep' && $p[14]['held'] === null && abs($p[14]['cost_pct'] - 2.0) < 0.001, 'brioszka : koszt +2 % — w progu, bez zmian');
    expectPw($p[15]['status'] === 'down' && $p[15]['limited'] && abs($p[15]['new'] - 46.00) < 0.001 && abs($p[15]['min'] - 46.00) < 0.001, 'tarta : koszt −50 % → limit −8 % (46,00)');
    expectPw($p[16]['status'] === 'down' && $p[16]['held'] === 'target' && abs($p[16]['new'] - 29.25) < 0.001 && abs($p[16]['target'] - 29.23) < 0.001, 'pain choco : obniżka do ceny celu 29,25');
    expectPw($p[17]['status'] === 'keep' && $p[17]['held'] === 'target' && $p[17]['new'] === 25.0, 'pain raisin : cena celu wyżej niż obecna — bez obniżki');
    $tt = $pr['totals'];
    expectPw($tt['up'] === 1 && $tt['cap'] === 1 && $tt['down'] === 3 && $tt['keep'] === 2 && $tt['held'] === 1 && $tt['products'] === 7, 'liczby : 2 ▲, 3 ▼, 2 bez zmian');
    expectPw(abs($tt['up_pct'] - 5.7) < 0.05 && abs($tt['down_pct'] + 4.3) < 0.05, 'średnio : ▲ +5,7 %, ▼ −4,3 %');
    expectPw(array_column($pr['products'], 'id')[0] === 11 && $pr['products'][2]['status'] === 'down', 'kolejność : podwyżki (największa zmiana kosztu najpierw), potem obniżki');

    // Reguły kategorii (cena celu = dolna granica obniżki) i parametry.
    expectPwFail(fn() => $w->saveCategories([['id' => 1, 'margin' => 35, 'coef' => 3]], 2), 403, 'bez dostępu do marż : 403');
    expectPwFail(fn() => $w->saveCategories([['id' => 1, 'margin' => 95, 'coef' => '']], 1), 422, 'marża 95 % : 422');
    expectPwFail(fn() => $w->saveCategories([['id' => 1, 'margin' => 35, 'coef' => '0,5']], 1), 422, 'współczynnik 0,5 : 422');
    expectPwFail(fn() => $w->saveCategories([['id' => 99, 'margin' => 35, 'coef' => '']], 1), 404, 'kategoria nieznana : 404');
    expectPw($w->saveCategories([['id' => 1, 'margin' => '35', 'coef' => '5'], ['id' => 2, 'margin' => '0', 'coef' => '']], 1) === 2, 'zapis 2 reguł');
    $pr = $w->prices();
    $p = $byId($pr);
    $cats = array_column($pr['categories'], null, 'id');
    expectPw(abs($p[16]['target'] - 45.0) < 0.001 && $p[16]['status'] === 'keep' && $p[16]['held'] === 'target', 'współczynnik 5 : cena celu 9 × 5 = 45 € > 30 € → bez obniżki');
    expectPw($cats[1]['coef'] === 5.0 && $cats[2]['coef'] === null && abs($cats[2]['coef_effective'] - 2.5) < 0.001 && $cats[1]['products'] === 5, 'współczynnik zapisany ; pusty = domyślny 2,5');
    expectPwFail(fn() => $w->saveParams(['cap_pct' => '5'], 2), 403, 'parametry : administrator');
    expectPwFail(fn() => $w->saveParams(['notice_days' => '99'], 1), 422, 'wyprzedzenie 99 dni : 422');
    expectPwFail(fn() => $w->saveParams(['up_pct' => '0,1'], 1), 422, 'próg wzrostu 0,1 % : 422 (0,5–50)');
    expectPwFail(fn() => $w->saveParams(['cut_pct' => '60'], 1), 422, 'maks. obniżka 60 % : 422 (0,5–50)');
    $np = $w->saveParams(['up_pct' => '12'], 1);
    expectPw($np['up_pct'] === 12.0 && $byId($w->prices())[10]['status'] === 'keep', 'próg wzrostu 12 % : rogalik (+10 %) w progu');
    $np = $w->saveParams(['cap_pct' => '8', 'cut_pct' => '8', 'up_pct' => '3', 'down_pct' => '3,0', 'round_eur' => '0,05', 'material_coef_default' => '2,5', 'notice_days' => '7'], 1);
    expectPw($np['cap_pct'] === 8.0 && $np['cut_pct'] === 8.0 && $np['up_pct'] === 3.0 && $np['down_pct'] === 3.0 && $np['notice_days'] === 7 && $np['material_coef_default'] === 2.5, 'parametry zapisane');

    // Zadanie czwartkowe : « Sprawdzone — bez zmian w tym tygodniu ».
    expectPw($w->reviewedThisWeek() === null, 'jeszcze nie sprawdzone w tym tygodniu');
    expectPwFail(fn() => $w->markReviewed(2), 403, 'bez dostępu : 403');
    $rv = $w->markReviewed(1);
    expectPw(str_contains((string) $rv['summary'], 'bez zmian') && (int) $pdo->query("SELECT COUNT(*) FROM n_wizard_done WHERE code = 'price_path_done' AND day = CURDATE()")->fetchColumn() === 1,
        'markDone price_path_done (bez zmian)');
    [$wFrom, $wTo] = PriceWizardService::week();
    $tw = $c->get(App\Services\Organization\TaskWizards::class);
    expectPw($tw->state('price_path_done', $wFrom . ' 00:00:00', $wTo . ' 00:00:00', $wFrom)['done'] === true, 'TaskWizards : zadanie tygodnia zaliczone');
    expectPw($tw->state('price_path_done', date('Y-m-d', strtotime($wFrom . ' -7 days')) . ' 00:00:00', $wFrom . ' 00:00:00', $wFrom)['done'] === false, 'TaskWizards : poprzedni tydzień nie');

    // Krok 3 : ceny klientów i kanały.
    $items = [['product_id' => 10, 'price' => '47,55'], ['product_id' => 11, 'price' => 32.40], ['product_id' => 12, 'price' => '101', 'manual' => true]];
    expectPwFail(fn() => $w->build([['product_id' => 10, 'price' => 48.0]]), 422, 'ponad limit (najwyżej 47,55) : 422');
    expectPwFail(fn() => $w->build([['product_id' => 10, 'price' => 40.0]]), 422, 'obniżka −9,1 % (ponad −8 %) : 422');
    expectPwFail(fn() => $w->build([['product_id' => 10, 'price' => 44.0]]), 422, 'cena równa obecnej : nic do zmiany, 422');
    expectPwFail(fn() => $w->build([['product_id' => 77, 'price' => 40.0]]), 422, 'produkt nieznany : 422');
    $b = $w->build($items);
    $ci = [];
    foreach ($b['items'] as $i) $ci[$i['id_customer'] . ':' . $i['id_product']] = $i;
    expectPw(count($b['standard']) === 3 && count($b['items']) === 5 && !isset($ci['4:10']), '3 ceny standardowe, 5 cen klientów (klient nieaktywny pominięty)');
    expectPw(abs($ci['1:10']['new'] - 47.55) < 0.001 && $ci['1:10']['follower'] && abs($ci['1:12']['new'] - 101.0) < 0.001, 'Alpha : ceny standardowe idą za nową (47,55 ; 101)');
    expectPw(abs($ci['1:11']['new'] - 29.20) < 0.001 && !$ci['1:11']['follower'], 'Alpha czekolada 27,00 (rabat 10 %) : +8 % → 29,20');
    expectPw(abs($ci['2:10']['new'] - 45.40) < 0.001, 'Beta 42,00 : × 47,55 / 44 = 45,39, limit 45,36 → 45,40');
    expectPw(abs($ci['3:11']['new'] - 32.40) < 0.001, 'Gamma : za standardową 32,40');
    $cl = array_column($b['clients'], null, 'id');
    expectPw($b['clients'][0]['id'] === 1 && $cl[1]['franchise'] && $cl[1]['email'] === 'alpha@example.invalid' && $cl[1]['lang'] === 'nl', 'Alpha : franczyza, e-mail z dystrybucji, NL, pierwsza');
    expectPw($cl[3]['franchise'] && $cl[3]['email'] === '' && !$cl[2]['franchise'] && $cl[2]['email'] === 'beta@example.invalid', 'Gamma bez adresu ; Beta tylko e-mail');
    expectPw(abs($cl[1]['month_eur'] - 35.5) < 0.001 && $b['totals']['franchise'] === 2 && $b['totals']['emails'] === 2, 'efekt Alpha 3,55 × 10 = 35,50 € / mies.');

    // Plan.
    $date = date('Y-m-d', strtotime('+10 days'));
    expectPwFail(fn() => $w->plan(['date' => date('Y-m-d', strtotime('-1 day')), 'items' => $items], 1), 422, 'data w przeszłości : 422');
    expectPwFail(fn() => $w->plan(['date' => date('Y-m-d', strtotime('+400 days')), 'items' => $items], 1), 422, 'data za ponad rok : 422');
    expectPwFail(fn() => $w->plan(['date' => $date, 'items' => $items], 2), 403, 'plan bez dostępu : 403');
    $plan = $w->plan(['date' => $date, 'items' => $items, 'exclude' => [2], 'notify' => true], 1);
    $id = $plan['id'];
    expectPw($plan['products'] === 3 && $plan['client_prices'] === 4 && $plan['clients'] === 2 && $plan['emails'] === 1 && $plan['sites'] === 2,
        'plan : 3 standardowe, 4 ceny klientów (Beta wyłączona), e-mail 1, strona 2');
    expectPw($plan['notice_date'] === date('Y-m-d', strtotime($date . ' -7 days')), 'e-mail 7 dni przed');
    expectPw((int) $pdo->query("SELECT manual FROM n_price_publication_item WHERE id_publication = {$id} AND id_product = 12 AND id_customer = 0")->fetchColumn() === 1, 'cena ręczna oznaczona');
    // [351] Baza opublikowanych = koszt dziś (z publikacją) ; nieopublikowane zostają przy starej bazie ; zadanie zaliczone.
    expectPw(abs((float) $baseOf(10)['food_cost'] - 44.0) < 0.0001 && (int) $baseOf(10)['id_publication'] === $id && abs((float) $baseOf(10)['prev_food_cost'] - 40.0) < 0.0001
        && abs((float) $baseOf(11)['food_cost'] - 60.0) < 0.0001 && abs((float) $baseOf(12)['food_cost'] - 36.0) < 0.0001, 'baza 10, 11, 12 = koszt dziś po planie');
    expectPw(abs((float) $baseOf(15)['food_cost'] - 50.0) < 0.0001 && $baseOf(15)['id_publication'] === null && abs((float) $baseOf(14)['food_cost'] - 20.0) < 0.0001,
        'tarta (odznaczona) i brioszka (w progu) : baza bez zmian — zmiana kosztu się kumuluje');
    $done = $pdo->query("SELECT summary FROM n_wizard_done WHERE code = 'price_path_done' AND day = CURDATE()")->fetchColumn();
    expectPw(str_contains((string) $done, 'cennik od ' . date('d.m', strtotime($date))) && str_contains((string) $done, '3 ▲'), 'markDone price_path_done : cennik zaplanowany (' . $done . ')');
    $ps = $w->pathStatus();
    expectPw($ps['publication']['id'] === $id && $ps['publication']['emails'] === 1 && $ps['publication']['emails_done'] === 0 && $ps['reviewed'] !== null, 'pasek ścieżki : publikacja tygodnia, 1 e-mail czeka');
    expectPw(abs($byId($w->prices())[10]['cost_pct']) < 0.001, 'rogalik po planie : koszt = baza (0 %)');
    $p = $byId($w->prices());
    expectPw($p[10]['status'] === 'planned' && $p[10]['planned']['date'] === $date, 'produkt zaplanowany : niewybieralny');
    expectPwFail(fn() => $w->build([['product_id' => 10, 'price' => 46.0]]), 409, 'druga zmiana tego samego produktu : 409');

    // Dispatcher : przed datą e-maila nic ; w dniu e-maila e-mail z PDF (Alpha, NL).
    $run = $pub->run(date('Y-m-d'));
    expectPw($run === ['notices' => 0, 'applied' => 0, 'sites' => 0], 'dziś : nic do zrobienia');
    $run = $pub->run(date('Y-m-d', strtotime($date . ' -7 days')));
    expectPw($run['notices'] === 1 && $run['applied'] === 0, 'dzień e-maila : 1 e-mail');
    $msg = $pdo->query("SELECT * FROM communication_message WHERE message_type = 'price_change_notice'")->fetchAll();
    expectPw(count($msg) === 1 && $msg[0]['recipient_email'] === 'alpha@example.invalid' && (int) $msg[0]['id_customer'] === 1 && $msg[0]['status'] === 'queued'
        && str_contains($msg[0]['subject_text'], 'Nieuwe prijzen vanaf ' . date('d/m/Y', strtotime($date))), 'e-mail NL do Alpha, w kolejce, data w temacie');
    expectPw(str_contains($msg[0]['body_text'], '3 produits') && str_contains($msg[0]['body_text'], 'SL Food Test'), 'treść : liczba produktów, firma');
    $att = $pdo->query("SELECT filename, content FROM communication_message_attachment WHERE id_message = {$msg[0]['id']}")->fetch();
    expectPw($att && str_starts_with(ltrim($att['content']), '%PDF') && str_contains($att['filename'], str_replace('-', '', $date)), 'załącznik : cennik PDF od daty');
    $cs = array_column($pdo->query("SELECT * FROM n_price_publication_client WHERE id_publication = {$id}")->fetchAll(), null, 'id_customer');
    expectPw($cs[1]['email_status'] === 'queued' && (int) $cs[1]['id_message'] === (int) $msg[0]['id'] && $cs[3]['email_status'] === null && !(int) $cs[3]['email'], 'stan e-maili klientów');
    expectPw($pub->run(date('Y-m-d', strtotime($date . ' -6 days')))['notices'] === 0, 'e-mail tylko raz');

    // W międzyczasie : cena Gammy zmieniona ręcznie → pominięta w dniu zmiany.
    $pdo->exec("UPDATE final_product_customer_price SET price = 31.00 WHERE id_customer = 3 AND id_final_product = 11");
    $run = $pub->run($date);
    expectPw($run['applied'] === 1 && $run['sites'] === 2, 'dzień zmiany : zastosowano, 2 strony');
    $std = fn(int $pid) => (float) $pdo->query("SELECT standard_price FROM final_product WHERE id = {$pid}")->fetchColumn();
    $price = fn(int $cid, int $pid) => (float) $pdo->query("SELECT price FROM final_product_customer_price WHERE id_customer = {$cid} AND id_final_product = {$pid}")->fetchColumn();
    expectPw(abs($std(10) - 47.55) < 0.001 && abs($std(11) - 32.40) < 0.001 && abs($std(12) - 101.0) < 0.001, 'ceny standardowe zmienione');
    expectPw(abs($price(1, 10) - 47.55) < 0.001 && abs($price(1, 11) - 29.20) < 0.001 && abs($price(1, 12) - 101.0) < 0.001, 'ceny Alpha zmienione');
    expectPw(abs($price(2, 10) - 42.0) < 0.001 && abs($price(3, 11) - 31.0) < 0.001 && abs($price(4, 10) - 44.0) < 0.001, 'Beta wyłączona, Gamma zmieniona w międzyczasie, Delta nieaktywna : bez zmian');
    $pubRow = $pdo->query("SELECT * FROM n_price_publication WHERE id = {$id}")->fetch();
    expectPw($pubRow['status'] === 'applied' && $pubRow['applied_at'] !== null, 'publikacja zastosowana');
    expectPw((int) $pdo->query("SELECT COUNT(*) FROM n_price_publication_item WHERE id_publication = {$id} AND status = 'skipped'")->fetchColumn() === 1, '1 pozycja pominięta');
    $log = $pdo->query("SELECT COUNT(*) AS n, MIN(source) AS s, MIN(changed_by) AS u FROM n_product_price_change")->fetch();
    expectPw((int) $log['n'] === 6 && $log['s'] === 'price_wizard' && (int) $log['u'] === 1, 'historia : 3 standardowe + 3 Alpha, price_wizard, autor');
    $hash = $pdo->query("SELECT hash FROM n_price_list_state WHERE id_customer = 1")->fetchColumn();
    expectPw($hash !== false && $hash === $c->get(App\Services\Communication\MailEventSource::class)->priceHash(1), 'Alpha (e-mail wysłany) : nowy cennik znany — bez drugiego e-maila');
    expectPw($pdo->query("SELECT COUNT(*) FROM n_price_list_state WHERE id_customer = 3")->fetchColumn() == 0, 'Gamma (bez e-maila) : zwykły e-mail « nowy cennik » zostaje');
    expectPw($pusher->calls === [1, 3], 'strona : Alpha i Gamma');
    $cs = array_column($pdo->query("SELECT * FROM n_price_publication_client WHERE id_publication = {$id}")->fetchAll(), null, 'id_customer');
    expectPw($cs[1]['site_status'] === 'sent' && (int) $cs[1]['site_version'] === 3 && $cs[3]['site_status'] === 'sent', 'strona potwierdzona (wersja cennika)');
    expectPw($pub->run(date('Y-m-d', strtotime($date . ' +1 day'))) === ['notices' => 0, 'applied' => 0, 'sites' => 0], 'drugi przebieg : nic');

    // Strona : brak klucza, ponowienia, błąd po 12 próbach.
    $pusher->mode = [1 => 'nokey'];
    expectPw($pub->pushSite($id, 1, 0) === 'no_site', 'brak klucza webhooka : no_site');
    $pusher->mode = [1 => 'fail'];
    expectPw($pub->pushSite($id, 1, 0) === 'retry' && $pub->pushSite($id, 1, 11) === 'failed', 'błąd : ponowienie, po 12 próbach failed');

    // Anulowanie.
    $b2 = $w->build([['product_id' => 10, 'price' => 48.00]]);
    expectPw(abs($b2['standard'][0]['old'] - 47.55) < 0.001, 'nowa publikacja liczy od nowej ceny');
    $plan2 = $w->plan(['date' => date('Y-m-d', strtotime('+3 days')), 'items' => [['product_id' => 10, 'price' => 48.00]], 'notify' => false], 1);
    $pubs = $pub->publications();
    expectPw($pubs[0]['id'] == $plan2['id'] && $pubs[0]['status'] === 'planned' && (int) $pubs[0]['emails'] === 0, 'lista : zaplanowana najpierw, bez e-maili');
    expectPw(abs((float) $baseOf(10)['food_cost'] - 44.0) < 0.0001 && (int) $baseOf(10)['id_publication'] === (int) $plan2['id'], 'druga publikacja : baza z nową publikacją');
    expectPw(str_contains($pub->cancel($plan2['id'], 1), 'anulowana'), 'anulowano');
    expectPw((int) $baseOf(10)['id_publication'] === $id && abs((float) $baseOf(10)['food_cost'] - 44.0) < 0.0001 && $baseOf(10)['prev_food_cost'] === null, 'anulowanie : poprzednia baza wraca');
    expectPwFail(fn() => $pub->cancel($plan2['id'], 1), 409, 'drugie anulowanie : 409');
    expectPw($pub->run(date('Y-m-d', strtotime('+3 days')))['applied'] === 0 && abs($std(10) - 47.55) < 0.001, 'anulowana : ceny bez zmian');
    $det = $pub->detail($id);
    expectPw(count($det['standard']) === 3 && count($det['clients']) === 2 && (int) array_column($det['clients'], null, 'id_customer')[1]['prices'] === 3, 'szczegóły publikacji');
    $pdf = $pub->clientPdf($id, 1);
    expectPw(str_starts_with(ltrim((string) $pdf['file_string']), '%PDF'), 'PDF cennika z publikacji');

    // [351] Obniżka : tarta 50 → 47,50 (−5 %). Alpha (cena = standardowa) idzie za nią, Beta (45,00, rabat 10 %) −5 % → 42,75 (w dół).
    expectPwFail(fn() => $w->build([['product_id' => 15, 'price' => '45,99']]), 422, 'tarta 45,99 < 46,00 (−8 %) : 422');
    expectPw(count($w->build([['product_id' => 15, 'price' => 46.0]])['standard']) === 1, 'tarta 46,00 (−8 %) : przyjęta');
    $bd = $w->build([['product_id' => 15, 'price' => '47,50']]);
    $ci = array_column(array_map(fn($i) => $i + ['k' => $i['id_customer'] . ':' . $i['id_product']], $bd['items']), null, 'k');
    expectPw(abs($bd['standard'][0]['new'] - 47.5) < 0.001 && $bd['totals']['down'] === 1 && $bd['totals']['up'] === 0 && abs($bd['totals']['avg_pct'] + 5.0) < 0.001, 'obniżka standardowa −5 %');
    expectPw(abs($ci['1:15']['new'] - 47.50) < 0.001 && $ci['1:15']['follower'] && abs($ci['2:15']['new'] - 42.75) < 0.001 && !$ci['2:15']['follower'], 'ceny klientów : za standardową 47,50 ; wynegocjowana −5 % → 42,75');
    $clb = array_column($bd['clients'], null, 'id');
    expectPw(abs($clb[1]['avg_pct'] + 5.0) < 0.001 && $clb[1]['month_eur'] === 0.0 && $bd['totals']['month_eur'] <= 0, 'efekt / mies. ≤ 0 przy obniżce');
    $date3 = date('Y-m-d', strtotime('+20 days'));
    $plan3 = $w->plan(['date' => $date3, 'items' => [['product_id' => 15, 'price' => '47,50']], 'notify' => true], 1);
    expectPw($plan3['down'] === 1 && $plan3['emails'] === 2 && abs((float) $baseOf(15)['food_cost'] - 25.0) < 0.0001, 'plan obniżki : 2 e-maile, baza tarty = koszt dziś');
    // « Wyślij teraz » : e-maile od razu do kolejki (bez wysyłki : SmtpTransport w teście rzuca wyjątek, kolejka nie wysyła).
    $before = (int) $pdo->query("SELECT COUNT(*) FROM communication_message WHERE message_type = 'price_change_notice'")->fetchColumn();
    $sn = $pub->sendNow($plan3['id']);
    $msgs = $pdo->query("SELECT * FROM communication_message WHERE message_type = 'price_change_notice' ORDER BY id DESC LIMIT 2")->fetchAll();
    expectPw($sn['queued'] === 2 && (int) $pdo->query("SELECT COUNT(*) FROM communication_message WHERE message_type = 'price_change_notice'")->fetchColumn() === $before + 2
        && $msgs[0]['status'] === 'queued', 'Wyślij teraz : 2 e-maile w kolejce');
    expectPw(count(array_filter($msgs, fn($m) => str_contains($m['subject_text'], date('d/m/Y', strtotime($date3))) && !preg_match('/hausse|augment|podwyżk|verhoging/iu', $m['subject_text'] . $m['body_text']))) === 2,
        'treść neutralna (« nouveaux prix / nieuwe prijzen »), bez « hausse »');
    expectPwFail(fn() => $pub->sendNow($plan3['id']), 409, 'drugie « Wyślij teraz » : 409');
    expectPw($pub->run(date('Y-m-d', strtotime($date3 . ' -7 days')))['notices'] === 0, 'dispatcher : e-maile już wysłane, nic więcej');
    $det3 = array_column($pub->detail($plan3['id'])['clients'], null, 'id_customer');
    expectPw($det3[1]['lang'] === 'nl' && $det3[1]['address'] === 'alpha@example.invalid' && $det3[2]['email_status'] === 'queued', 'krok 4 : język i adres klienta, stan');
    expectPw(str_contains($pub->cancel($plan3['id'], 1), '2 klientów dostało już e-mail') && abs((float) $baseOf(15)['food_cost'] - 50.0) < 0.0001, 'anulowanie obniżki : ostrzeżenie o e-mailach, baza tarty wraca (50)');
    $pdo->exec("DELETE FROM n_margin_recalc");
    $pdo->exec("DELETE FROM n_margin_recalc_run");
    $GLOBALS['PW_RESULT']['categories'][0]['products'][0]['total_cost'] = 120.0;

    // Krok 1 : faktury, przeliczenie, marża przed → po, przyczyny.
    $pdo->exec("INSERT INTO n_inv (id, kind, status) VALUES (700, 'goods', 'open'), (701, 'goods', 'accepted')");
    $pdo->exec("INSERT INTO n_inv_line (id, id_inv, check_status) VALUES (1, 700, NULL), (2, 700, 'checked'), (3, 701, NULL)");
    $prev = date('Y-m-d H:i:s', strtotime('-10 days'));
    $last = date('Y-m-d H:i:s', strtotime('-1 day'));
    $pdo->exec("INSERT INTO n_margin_recalc_run (run_at, run_by, recipes, products, changed) VALUES ('{$prev}', 1, 40, 3, 0), ('{$last}', 1, 41, 3, 1)");
    $pdo->exec("INSERT INTO n_margin_recalc (id_product, old_cost_carton, new_cost_carton, ppc, recalculated_at, recalculated_by) VALUES (10, 116, 120, 12, '{$last}', 1), (11, 100, 100, 10, '{$prev}', 1)");
    $pdo->exec("INSERT INTO raw_material (id, name) VALUES (501, 'Masło 82 %'), (502, 'Mąka')");
    $pdo->exec("INSERT INTO raw_material_price_changelog (id_supplier, id_raw_material, pack_price, package_size_in_base_unit, timestamp) VALUES
        (1, 501, 100, 1000, DATE_SUB(NOW(), INTERVAL 30 DAY)), (1, 501, 110, 1000, DATE_SUB(NOW(), INTERVAL 5 DAY)), (1, 502, 50, 1000, DATE_SUB(NOW(), INTERVAL 30 DAY))");
    $pdo->exec("INSERT INTO flattened_recipe_ingredient (recipe_id, material_id, quantity) VALUES (900, 501, 1), (900, 502, 2)");
    $k = $w->costs();
    expectPw($k['invoices'] === ['invoices' => 1, 'todo' => 1], 'faktury : 1 otwarta, 1 pozycja do sprawdzenia');
    expectPw($k['window']['pending'] === false && $k['last_run']['run_at'] === $last && $k['materials']['up'] === 1 && $k['materials']['down'] === 0, 'okno : ostatnie przeliczenie, 1 surowiec w górę');
    $cat = array_column($k['categories'], null, 'name')['Viennoiserie'];
    expectPw($cat['changed'] === 1 && abs($cat['month_eur'] + 10.0) < 0.001, 'Viennoiserie : 1 zmiana, −4 zł × 10 kart. ÷ 4 = −10 € / mies.');
    $prod = $k['products'][0];
    expectPw($prod['id'] === 10 && abs($prod['margin_before'] - 34.1) < 0.1 && abs($prod['margin_after'] - 31.8) < 0.1 && abs($prod['pct'] - 3.4) < 0.1, 'rogalik : marża 34,1 → 31,8 %, koszt +3,4 %');
    expectPw(count($prod['reasons']) === 1 && $prod['reasons'][0]['name'] === 'Masło 82 %' && abs($prod['reasons'][0]['pct'] - 10.0) < 0.01, 'przyczyna : masło +10 %');
    expectPw($k['totals']['changed'] === 1 && abs($k['totals']['month_eur'] + 10.0) < 0.001, 'razem : 1 produkt, −10 € / mies.');
    $pdo->exec("INSERT INTO raw_material_price_changelog (id_supplier, id_raw_material, pack_price, package_size_in_base_unit, timestamp) VALUES (1, 502, 55, 1000, NOW())");
    $k = $w->costs();
    expectPw($k['window']['pending'] === true && $k['materials']['up'] === 1 && $k['materials']['list'][0]['name'] === 'Mąka', 'cena po przeliczeniu : « przelicz koszty »');
} finally {
    $pdo->exec("DROP DATABASE IF EXISTS {$testDb}");
}
echo "price_wizard_test: OK\n";
