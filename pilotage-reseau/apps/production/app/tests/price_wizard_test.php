<?php
declare(strict_types=1);
/*
 * Kreator cen [218] : reguła ceny (marża bazowa, współczynnik surowców, nigdy niżej, limit +8 %, zaokrąglenie),
 * na bazie jednorazowej : krok 1 (faktury, marża przed → po, przyczyny), krok 2 (propozycje, reguły kategorii, parametry,
 * dostęp), krok 3 (ceny klientów : za standardową / rabat zachowany, kanały), plan, e-mail « nowe ceny od » z PDF przed
 * datą, zastosowanie w dniu zmiany (cena zmieniona w międzyczasie pominięta, historia, bez drugiego e-maila), strona
 * franczyzy (wysłano / brak klucza / ponowienia), anulowanie. Strona franczyzy : udawany PricelistPusher (nic nie wychodzi).
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
                 'raw_material_price_changelog', 'flattened_recipe_ingredient', 'n_margin_recalc', 'n_margin_recalc_run'] as $table) {
        $pdo->exec("CREATE TABLE {$testDb}.{$table} LIKE {$source}.{$table}");
    }
    $pdo->exec("USE {$testDb}");
    $pdo->exec("SET SESSION sql_mode = ''");
    $pdo->exec("INSERT INTO n_prod_param (code, value, default_value, value_type) VALUES ('prices.client_cap_pct', '8', '8', 'decimal'),
        ('prices.client_round_eur', '0.05', '0.05', 'decimal'), ('prices.client_keep_discount', '1', '1', 'bool'), ('prices.client_interval_days', '90', '90', 'int'),
        ('prices.material_coef_default', '2.5', '2.5', 'decimal'), ('prices.notice_days', '7', '7', 'int')");
    $pdo->exec("INSERT INTO user (id, login) VALUES (1, 'admin'), (2, 'kasia')");
    $pdo->exec("INSERT INTO erp_company_data (id, wdt_company_name) VALUES (1, 'SL Food Test')");
    $pdo->exec("INSERT INTO category_product (id, name, target_margin_percentage) VALUES (1, 'Viennoiserie', 35), (2, 'Pain', 0)");
    $pdo->exec("INSERT INTO final_product (id, name, active, standard_price, id_category, id_recipe) VALUES
        (10, 'Croissant', 1, 44.00, 1, 900), (11, 'Chocolat', 1, 30.00, 1, 901), (12, 'Pain gris', 1, 100.00, 2, NULL), (13, 'Ancien', 0, 20.00, 1, NULL)");
    $pdo->exec("INSERT INTO customer (id, company, name, surname, active, franchisee_uid, email_head, communication_lang, pricelist_version) VALUES
        (1, 'Alpha SRL', 'Anne', 'Alpha', 1, '01HTESTALPHA00000000000000', 'alpha@example.invalid', 'nl', 3),
        (2, 'Beta', 'Bob', 'Beta', 1, NULL, 'beta@example.invalid', 'fr', 0),
        (3, 'Gamma', '', '', 1, '01HTESTGAMMA00000000000000', NULL, 'fr', 0),
        (4, 'Delta', '', '', 0, NULL, 'delta@example.invalid', 'fr', 0)");
    $pdo->exec("INSERT INTO final_product_customer_price (id_customer, id_final_product, price, available) VALUES
        (1, 10, 44.00, 1), (1, 11, 27.00, 1), (1, 12, 100.00, 1), (2, 10, 42.00, 1), (3, 11, 30.00, 1), (4, 10, 44.00, 1)");
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

    // Wynik strony marż (kurs 4,00, PLN) : rogalik koszt surowców 10 €, pełny 30 € ; czekolada 12 / 30 € ; chleb 10 / 40 €.
    $GLOBALS['PW_RESULT'] = ['exchange_rate' => 4.0, 'categories' => [
        ['category_id' => 1, 'category_name' => 'Viennoiserie', 'target_margin' => 35.0, 'products' => [
            ['product_id' => 10, 'name' => 'Croissant', 'foodcost' => 40.0, 'total_cost' => 120.0, 'sale_price_eur' => 44.0, 'sale_price_pln' => 176.0, 'has_data' => true, 'avg_sold_qty_per_month' => 10.0],
            ['product_id' => 11, 'name' => 'Chocolat', 'foodcost' => 48.0, 'total_cost' => 120.0, 'sale_price_eur' => 30.0, 'sale_price_pln' => 120.0, 'has_data' => true, 'avg_sold_qty_per_month' => 2.0]]],
        ['category_id' => 2, 'category_name' => 'Pain', 'target_margin' => 0.0, 'products' => [
            ['product_id' => 12, 'name' => 'Pain gris', 'foodcost' => 40.0, 'total_cost' => 160.0, 'sale_price_eur' => 100.0, 'sale_price_pln' => 400.0, 'has_data' => true, 'avg_sold_qty_per_month' => 0.0]]],
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

    // Krok 2 : propozycje.
    $byId = function (array $prices): array {
        $out = [];
        foreach ($prices['categories'] as $cat) foreach ($cat['products'] as $p) $out[$p['id']] = $p + ['cat' => $cat];
        return $out;
    };
    $pr = $w->prices();
    $p = $byId($pr);
    expectPw($p[10]['status'] === 'up' && abs($p[10]['new'] - 46.20) < 0.001 && abs($p[10]['by_coef'] - 25.0) < 0.001, 'rogalik : 44,00 → 46,20 (marża 35 %), współczynnik domyślny 2,5');
    expectPw($p[11]['status'] === 'cap' && abs($p[11]['new'] - 32.40) < 0.001 && abs($p[11]['max'] - 32.40) < 0.001, 'czekolada : limit +8 % → 32,40');
    expectPw($p[12]['status'] === 'keep' && $p[12]['new'] === 100.0, 'chleb : bez marży bazowej, współczynnik 25 € < 100 € → bez zmian');
    expectPw(!isset($p[13]) && $pr['totals']['up'] === 1 && $pr['totals']['cap'] === 1 && $pr['totals']['keep'] === 1, 'produkt nieaktywny pominięty ; liczby statusów');
    expectPw(abs($p[10]['margin_new'] - 35.1) < 0.1 && abs($p[10]['margin_now'] - 31.8) < 0.1, 'marża rogalika 31,8 % → 35,1 %');

    // Reguły kategorii i parametry.
    expectPwFail(fn() => $w->saveCategories([['id' => 1, 'margin' => 35, 'coef' => 3]], 2), 403, 'bez dostępu do marż : 403');
    expectPwFail(fn() => $w->saveCategories([['id' => 1, 'margin' => 95, 'coef' => '']], 1), 422, 'marża 95 % : 422');
    expectPwFail(fn() => $w->saveCategories([['id' => 1, 'margin' => 35, 'coef' => '0,5']], 1), 422, 'współczynnik 0,5 : 422');
    expectPwFail(fn() => $w->saveCategories([['id' => 99, 'margin' => 35, 'coef' => '']], 1), 404, 'kategoria nieznana : 404');
    expectPw($w->saveCategories([['id' => 1, 'margin' => '35', 'coef' => '5'], ['id' => 2, 'margin' => '0', 'coef' => '']], 1) === 2, 'zapis 2 reguł');
    $p = $byId($w->prices());
    expectPw(abs($p[10]['by_coef'] - 50.0) < 0.001 && $p[10]['status'] === 'cap' && abs($p[10]['new'] - 47.55) < 0.001, 'współczynnik 5 : rogalik cel 50 €, limit → 47,55');
    expectPw($p[10]['cat']['coef'] === 5.0 && $p[12]['cat']['coef'] === null && abs($p[12]['cat']['coef_effective'] - 2.5) < 0.001, 'współczynnik zapisany ; pusty = domyślny 2,5');
    expectPwFail(fn() => $w->saveParams(['cap_pct' => '5'], 2), 403, 'parametry : administrator');
    expectPwFail(fn() => $w->saveParams(['notice_days' => '99'], 1), 422, 'wyprzedzenie 99 dni : 422');
    $np = $w->saveParams(['cap_pct' => '8', 'round_eur' => '0,05', 'material_coef_default' => '2,5', 'notice_days' => '7'], 1);
    expectPw($np['cap_pct'] === 8.0 && $np['notice_days'] === 7 && $np['material_coef_default'] === 2.5, 'parametry zapisane');

    // Krok 3 : ceny klientów i kanały.
    $items = [['product_id' => 10, 'price' => '47,55'], ['product_id' => 11, 'price' => 32.40], ['product_id' => 12, 'price' => '101', 'manual' => true]];
    expectPwFail(fn() => $w->build([['product_id' => 10, 'price' => 48.0]]), 422, 'ponad limit (najwyżej 47,55) : 422');
    expectPwFail(fn() => $w->build([['product_id' => 10, 'price' => 40.0]]), 422, 'tylko niższe ceny : 422');
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
    expectPw(str_contains($pub->cancel($plan2['id'], 1), 'anulowana'), 'anulowano');
    expectPwFail(fn() => $pub->cancel($plan2['id'], 1), 409, 'drugie anulowanie : 409');
    expectPw($pub->run(date('Y-m-d', strtotime('+3 days')))['applied'] === 0 && abs($std(10) - 47.55) < 0.001, 'anulowana : ceny bez zmian');
    $det = $pub->detail($id);
    expectPw(count($det['standard']) === 3 && count($det['clients']) === 2 && (int) array_column($det['clients'], null, 'id_customer')[1]['prices'] === 3, 'szczegóły publikacji');
    $pdf = $pub->clientPdf($id, 1);
    expectPw(str_starts_with(ltrim((string) $pdf['file_string']), '%PDF'), 'PDF cennika z publikacji');

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
