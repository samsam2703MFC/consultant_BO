<?php
declare(strict_types=1);
/*
 * Zakupy tygodnia (27/10/2026, makiety 1 à 5) :
 *  - ile kupić : potrzeba × (1 + zapas), na stanie do maks. pod minimum, na zamówienie brak do potrzeby, pełne opakowania ;
 *  - zakupy : szkic (jeden na tydzień), 1 do 3 dostawców na pozycję → jedno zapytanie na dostawcę (tylko jego pozycje,
 *    jego język), warunki (proforma, odbiór, podwójna ilość), dostawca bez e-maila blokuje wysyłkę ;
 *  - wysyłka : e-maile tylko w kolejce Komunikacji (nic nie wychodzi), dostawcy zapytani zostają dostawcami pozycji (do 3) ;
 *  - link dostawcy bez logowania : jego pozycje, ceny « 2,10 », minimum (ilość, kwota, opis), błędne pola, link nieznany
 *    i zamknięty ; oferta wpisana ręcznie ;
 *  - oferty : najtańsza ważna ★ (minimum spełnione), podpowiedź « kup do minimum », zamówienie poniżej minimum odrzucone,
 *    zamówienie u wybranych → jedno zamówienie na dostawcę (e-mail w kolejce), oszczędność = (ostatnia − cena) × ilość ;
 *  - wyniki operatora i zespołu, dostawcy pozycji (1–3), zadania Do obsłużenia.
 *
 *   php tests/buy_week_test.php
 *
 * Base : TFB_TEST_DSN (défaut mysql:unix_socket=/run/mysqld/mysqld.sock), TFB_TEST_USER (root), TFB_TEST_PASS (vide) ;
 * structures recopiées depuis TFB_TEST_SOURCE_DB (défaut erp). La base erp_buy_test est recréée puis supprimée.
 */
require_once __DIR__ . '/../public/vendor/autoload.php';
require_once __DIR__ . '/../App/Core/functions.php';

use App\Core\Api\Workshop\ApiContext;
use App\Core\Api\Workshop\ApiException;
use App\Core\Api\Workshop\Translator;
use App\Services\Workshop\Migration\MigrationRunner;
use App\Services\Workshop\Supply\BuyService;

function expectBuy(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException('FAILED: ' . $message);
}

function expectBuyError(callable $fn, string $code, string $message): ApiException
{
    try { $fn(); } catch (ApiException $e) {
        $codes = array_merge([$e->errorCode()], array_column($e->fieldErrors(), 'code'));
        expectBuy(in_array($code, $codes, true), $message . ' (got ' . implode(', ', $codes) . ')');
        return $e;
    }
    throw new RuntimeException('FAILED: ' . $message . ' (no error)');
}

$dsn = getenv('TFB_TEST_DSN') ?: 'mysql:unix_socket=/run/mysqld/mysqld.sock';
$source = getenv('TFB_TEST_SOURCE_DB') ?: 'erp';
$testDb = 'erp_buy_test';
try {
    $pdo = new PDO($dsn . ';charset=utf8mb4', getenv('TFB_TEST_USER') ?: 'root', getenv('TFB_TEST_PASS') ?: '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    echo "buy_week_test: SKIPPED (no database: {$e->getMessage()})\n";
    exit(0);
}

$pdo->exec("DROP DATABASE IF EXISTS {$testDb}");
$pdo->exec("CREATE DATABASE {$testDb} CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
try {
    foreach (['user', 'production_sections', 'production_employee', 'final_product', 'product_recipe', 'product_recipe_connection', 'raw_material', 'unit',
                 'customer_order', 'customer_order_product', 'customer_order_status_type', 'customer_order_changelog', 'carton_stock', 'document_wdt', 'document_wdt_product',
                 'communication_template', 'recipe_competency', 'customer', 'document_order_shipping_difference', 'document_order_shipping_difference_products',
                 'carrier', 'carrier_vehicle', 'category_product', 'flattened_recipe_ingredient', 'category_material', 'erp_company_other_settings', 'supplier',
                 'raw_material_price_changelog', 'cost_month', 'cost_month_employee', 'cost_month_fixed_cost', 'cost_month_logistics', 'cost_media_month',
                 'cost_media_section_allocation', 'cost_media_type', 'communication_message', 'communication_message_attachment', 'communication_email_account',
                 'erp_company_data', 'flattened_recipe_recipe', 'product_preparation_type', 'product_recipe_preparation_connection', 'product_recipe_type',
                 'product_recipe_preparation_step', 'product_recipe_prepare_instruction_connection', 'test_product', 'test_product_status', 'test_product_type',
                 'test_product_stage', 'test_product_changelog', 'complaint', 'final_product_ppwr_sales_packaging', 'carton_dimension', 'palets_delivery',
                 'document_cmr', 'document_wz', 'shipment_log', 'app_notification', 'outbox', 'webhook_outbox_shipments', 'cron_notification_outbox', 'cron_runs',
                 'document_proforma', 'document_invoice', 'cost_month_section', 'cost_logistics_type', 'document_proforma_product', 'ksef_submission',
                 'complaint_product_to_send_log', 'complaint_product_pending', 'carton_stock_complaint'] as $table) {
        $pdo->exec("CREATE TABLE {$testDb}.{$table} LIKE {$source}.{$table}");
    }
    $pdo->exec("USE {$testDb}");
    $pdo->exec("SET SESSION sql_mode = ''");
    $pdo->exec("INSERT INTO user (id, name, surname, displayed_name, email, phone, permission, login, password) VALUES
        (1, 'Magda', 'W', 'Magda W.', 'm@x.test', '1', 0, 'magda', 'x'), (2, 'Piotr', 'K', 'Piotr K.', 'p@x.test', '1', 0, 'piotr', 'x')");
    $pdo->exec("INSERT INTO production_sections (id, name, depreciation, water_cost, power_supply_cost, fuel_cost, other_cost) VALUES (1, 'Piekarnia', 0, 0, 0, 0, 0)");
    $pdo->exec("INSERT INTO category_material (id, name, id_group) VALUES (1, 'Mąki', 0), (2, 'Tłuszcze', 0)");
    $pdo->exec("INSERT INTO unit (id, name) VALUES (1, 'g')");
    // Dostawcy : Młyn (fr), Makro (pl), bez e-maila, nieaktywny, nowy w kategorii mąk.
    $pdo->exec("INSERT INTO supplier (id, name, email, order_lang, is_active) VALUES
        (7, 'Młyn', 'mlyn@example.invalid', 'fr', 1), (8, 'Makro', 'makro@example.invalid', NULL, 1), (9, 'Bez maila', '', NULL, 1),
        (10, 'Stary', 'stary@example.invalid', NULL, 0), (11, 'Polmlek', 'polmlek@example.invalid', 'nl', 1)");
    $pdo->exec("INSERT INTO raw_material (id, name, name_fr, id_unit, id_category, price_per_unit, package_size_in_base_unit, id_current_supplier) VALUES
        (41, 'Mąka T65', 'Farine T65', 1, 1, 0.0024, 25000, 7), (42, 'Masło 82 %', NULL, 1, 2, 0.034, 10000, 8), (43, 'Mąka żytnia', 'Farine de seigle', 1, 1, 0.003, 25000, 11)");
    (new MigrationRunner($pdo, __DIR__ . '/../migrations/tfb'))->apply();
    $pdo->exec("SET SESSION sql_mode = ''");
    $pdo->exec("INSERT INTO erp_company_data (id, wdt_company_name) VALUES (1, 'SL Food')");
    // Ostatnie ceny (kartoteka) : mąka Młyn 2,40 zł/kg, Makro 2,50 zł/kg ; masło Makro 34 zł/kg.
    $pdo->exec("INSERT INTO raw_material_price_changelog (id_supplier, id_raw_material, pack_price, package_size_in_base_unit, source, timestamp) VALUES
        (7, 41, 60.00, 25000, 'manual', NOW() - INTERVAL 20 DAY), (8, 41, 62.50, 25000, 'manual', NOW() - INTERVAL 40 DAY), (8, 42, 340.00, 10000, 'manual', NOW() - INTERVAL 10 DAY)");

    $builder = new DI\ContainerBuilder();
    // Żaden e-mail nie wychodzi : transport testu odrzuca wszystko (wiadomości tylko w kolejce).
    $builder->addDefinitions([PDO::class => $pdo, App\Services\Communication\SmtpTransport::class => new class implements App\Services\Communication\SmtpTransport {
        public function send(array $account, string $to, string $subject, string $body, string $messageId, array $attachments = [], ?string $footer = null): string { throw new RuntimeException('no e-mail in tests'); }
    }]);
    $container = $builder->build();
    $buy = $container->get(BuyService::class);
    $magda = new ApiContext('b1', new Translator('pl'), 1, [], ['prod.supply.write' => true, 'prod.supply.read' => true]);
    $director = new ApiContext('b2', new Translator('fr'), 2, [], ['prod.supply.read' => true, 'prod.finance.read' => true]);

    // --- Ile kupić (bez bazy) : zapas, tryb, pełne opakowania
    $plan = new ReflectionMethod(BuyService::class, 'plan');
    $row = fn(array $r) => $r + ['mode' => 'order', 'stock' => null, 'stock_min' => null, 'stock_max' => null, 'on_order' => 0, 'package_size' => null];
    expectBuy($plan->invoke($buy, $row(['need' => 3000, 'stock' => 500, 'package_size' => 25000]), 5) === 25000.0, 'order mode: 3 000 × 1,05 − 500 → one full sack');
    expectBuy($plan->invoke($buy, $row(['need' => 3000, 'stock' => 500]), 0) === 2500.0, 'order mode without package: missing quantity');
    expectBuy($plan->invoke($buy, $row(['need' => 3000, 'stock' => 4000]), 10) === 0.0, 'order mode: stock covers need + 10 %');
    expectBuy($plan->invoke($buy, $row(['need' => 500, 'stock' => 1000, 'mode' => 'stock', 'stock_min' => 2000, 'stock_max' => 10000, 'package_size' => 1000]), 10) === 10000.0,
        'stock mode under min: refill to max (9 550 → 10 full packs)');
    expectBuy($plan->invoke($buy, $row(['need' => 500, 'stock' => 5000, 'mode' => 'stock', 'stock_min' => 2000, 'stock_max' => 10000]), 10) === 0.0, 'stock mode above min: nothing');
    expectBuy($plan->invoke($buy, $row(['need' => 500, 'stock' => 1000, 'on_order' => 2000, 'mode' => 'stock', 'stock_min' => 2000, 'stock_max' => 10000]), 0) === 0.0, 'goods on order count');

    // --- Tydzień : struktura (bez planu produkcji) i dostawcy pozycji z historii
    $week = $buy->week(null, 10, $magda);
    expectBuy($week['round'] === null && $week['proposal']['safety_pct'] === 10 && is_array($week['products']) && $week['can_write'], 'week: proposal with the chosen safety');
    $seeded = $pdo->query('SELECT id_raw_material, GROUP_CONCAT(id_supplier ORDER BY sort) s FROM n_material_supplier GROUP BY id_raw_material')->fetchAll(PDO::FETCH_KEY_PAIR);
    expectBuy($seeded === ['41' => '7', '42' => '8', '43' => '11'] || $seeded === [41 => '7', 42 => '8', 43 => '11'], 'material suppliers seeded from the current supplier');
    $weekStart = $week['week']['week_start'];

    // --- Krok 1 : szkic z pozycjami (ilość w gramach)
    $round = $buy->create(['week_start' => $weekStart, 'safety_pct' => 5, 'lines' => [['material_id' => 41, 'quantity' => 25000], ['material_id' => 42, 'quantity' => 10000]]], $magda);
    $l41 = array_values(array_filter($round['lines'], fn($l) => $l['material']['id'] === 41))[0];
    $l42 = array_values(array_filter($round['lines'], fn($l) => $l['material']['id'] === 42))[0];
    expectBuy($round['status'] === 'draft' && str_starts_with($round['code'], 'ZT-') && count($round['lines']) === 2 && $round['pay_terms'] === '7' && $round['ask_double'], 'draft created with default terms');
    expectBuy(abs($l41['last']['price'] - 0.0024) < 1e-9 && $l41['last']['supplier']['id'] === 7 && $l41['material']['unit'] === 'kg' && $l41['packages'] === 1, 'cheapest last price and supplier, display unit kg');
    expectBuy(array_column($l41['suppliers'], 'id') === [7] && $l41['assigned'] === 1, 'suppliers of the line before step 2');
    $cands = array_column($l41['candidates'], 'source', 'id');
    expectBuy(($cands[7] ?? null) === 'assigned' && ($cands[8] ?? null) === 'history' && ($cands[11] ?? null) === 'category', 'candidates: assigned, price history, same category (new)');
    expectBuyError(fn() => $buy->create(['week_start' => $weekStart, 'lines' => [['material_id' => 41, 'quantity' => 1]]], $magda), 'BUY_ROUND_EXISTS', 'one open round per week');
    expectBuyError(fn() => $buy->create(['week_start' => $weekStart, 'safety_pct' => 7, 'lines' => []], $magda), 'REQUIRED', 'lines required, safety 0/5/10');

    // --- Krok 2 : dostawcy (1–3) i warunki
    expectBuyError(fn() => $buy->update($round['id'], ['suppliers' => [$l41['id'] => [7, 8, 11, 9]]], $magda), 'BUY_LINE_SUPPLIERS', 'at most 3 suppliers');
    expectBuyError(fn() => $buy->update($round['id'], ['suppliers' => [$l41['id'] => []]], $magda), 'BUY_LINE_SUPPLIERS', 'at least 1 supplier');
    expectBuyError(fn() => $buy->update($round['id'], ['suppliers' => [$l41['id'] => [999]]], $magda), 'REFERENCE_NOT_FOUND', 'unknown supplier');
    expectBuyError(fn() => $buy->update($round['id'], ['pay_terms' => '60'], $magda), 'INVALID_CHOICE', 'payment 7 / 14 / 30 / proforma');
    $round = $buy->update($round['id'], ['suppliers' => [$l41['id'] => [7, 8, 11], $l42['id'] => [8, 9]], 'pay_terms' => 'proforma', 'delivery' => 'pickup', 'ask_double' => false], $magda);
    expectBuy(count($round['requests']) === 4 && $round['pay_terms'] === 'proforma' && $round['delivery'] === 'pickup' && !$round['ask_double'], 'one request per supplier, terms saved');
    $makroReq = array_values(array_filter($round['requests'], fn($q) => $q['supplier']['id'] === 8))[0];
    expectBuy($makroReq['lines'] === 2 && $makroReq['link'] === null, 'Makro asked for both lines; no link before sending');
    expectBuyError(fn() => $buy->send($round['id'], $magda), 'BUY_SUPPLIER_NO_EMAIL', 'supplier without e-mail blocks sending');
    $round = $buy->update($round['id'], ['suppliers' => [$l41['id'] => [7, 8, 11], $l42['id'] => [8]], 'ask_double' => true], $magda);
    expectBuy(count($round['requests']) === 3, 'supplier without e-mail removed');

    // --- Krok 3 : podgląd w języku dostawcy, wysyłka do kolejki
    $pv = $buy->preview($round['id'], 7, $magda);
    expectBuy($pv['language'] === 'fr' && str_contains($pv['subject'], "Demande d'offre SL Food") && str_contains($pv['body'], 'Farine T65 — 25 kg')
        && !str_contains($pv['body'], 'Masło') && str_contains($pv['body'], 'proforma') && str_contains($pv['body'], 'enlèvement') && str_contains($pv['body'], 'double')
        && preg_match('#/offer/[a-f0-9]{48}#', $pv['body']), 'preview: French, only its line, terms and price link');
    $pvNl = $buy->preview($round['id'], 11, $magda);
    expectBuy($pvNl['language'] === 'nl' && str_contains($pvNl['subject'], 'Offerteaanvraag'), 'Dutch supplier gets the Dutch template');
    $round = $buy->send($round['id'], $magda);
    $msgs = $pdo->query("SELECT recipient_email, status, message_type FROM communication_message ORDER BY id")->fetchAll();
    expectBuy($round['status'] === 'sent' && count($msgs) === 3 && !array_filter($msgs, fn($m) => $m['status'] !== 'queued' || $m['message_type'] !== 'buy_request')
        && !array_filter($msgs, fn($m) => !str_ends_with($m['recipient_email'], '@example.invalid')), 'three e-mails queued, none sent');
    $assigned = $pdo->query('SELECT id_raw_material, GROUP_CONCAT(id_supplier ORDER BY sort) s FROM n_material_supplier WHERE id_raw_material IN (41, 42) GROUP BY id_raw_material')->fetchAll(PDO::FETCH_KEY_PAIR);
    expectBuy($assigned[41] === '7,8,11' && $assigned[42] === '8', 'asked suppliers become suppliers of the line (up to 3)');
    expectBuyError(fn() => $buy->update($round['id'], ['ask_double' => false], $magda), 'BUY_ROUND_NOT_DRAFT', 'sent round is frozen');

    // --- Link dostawcy (bez logowania)
    $tokens = $pdo->query("SELECT id_supplier, token FROM n_buy_request WHERE id_round = {$round['id']}")->fetchAll(PDO::FETCH_KEY_PAIR);
    $view = $buy->publicView($tokens[7]);
    expectBuy($view['lang'] === 'fr' && count($view['lines']) === 1 && $view['lines'][0]['name'] === 'Farine T65' && $view['lines'][0]['unit'] === 'kg'
        && (float) $view['lines'][0]['quantity'] === 25.0 && !$view['expired'] && $view['ask_double'], 'supplier page: own line in French, kg');
    expectBuy($pdo->query("SELECT opened_at IS NOT NULL FROM n_buy_request WHERE token = '{$tokens[7]}'")->fetchColumn() == 1, 'link opening recorded');
    $res = $buy->publicSubmit($tokens[7], [$l41['id'] => ['price' => '2,10', 'price_double' => '2,05', 'min_qty' => '1 000', 'min_note' => '1 paleta', 'delivery_date' => date('Y-m-d', strtotime('+2 days'))]]);
    expectBuy($res['saved'] === 1 && !$res['errors'], 'supplier prices saved');
    $res = $buy->publicSubmit($tokens[11], [$l41['id'] => ['price' => 'abc']]);
    expectBuy($res['saved'] === 0 && isset($res['errors'][$l41['id']]), 'wrong number refused');
    $res = $buy->publicSubmit($tokens[11], [$l42['id'] => ['price' => '1']]);
    expectBuy($res['saved'] === 0, 'a line not asked to this supplier is ignored');
    expectBuy($buy->publicView(str_repeat('a', 48)) === null && $buy->publicView('../etc') === null, 'unknown link');
    expectBuyError(fn() => $buy->publicSubmit(str_repeat('b', 48), []), 'BUY_LINK_UNKNOWN', 'unknown link refused');
    $offer = $pdo->query("SELECT * FROM n_mp_offer WHERE id_buy_line = {$l41['id']} AND id_supplier = 7")->fetch();
    expectBuy($offer['source'] === 'weekly' && abs((float) $offer['price_per_base'] - 0.0021) < 1e-9 && abs((float) $offer['price_double_base'] - 0.00205) < 1e-9
        && (float) $offer['min_order_qty'] === 1000.0 && $offer['min_note'] === '1 paleta' && $offer['created_by'] === null, 'offer stored per base unit with minimum');
    // Druga odpowiedź poprawia ofertę (ten sam wiersz).
    $buy->publicSubmit($tokens[7], [$l41['id'] => ['price' => '2,10', 'min_qty' => '1000']]);
    expectBuy((int) $pdo->query("SELECT COUNT(*) FROM n_mp_offer WHERE id_buy_line = {$l41['id']} AND id_supplier = 7")->fetchColumn() === 1, 'resubmission corrects the same offer');

    // --- Oferta wpisana ręcznie (e-mail, telefon)
    expectBuyError(fn() => $buy->addOffer($round['id'], ['line_id' => $l41['id'], 'supplier_id' => 8, 'price' => 0], $magda), 'OUT_OF_RANGE', 'price must be positive');
    $buy->addOffer($round['id'], ['line_id' => $l41['id'], 'supplier_id' => 8, 'price' => 2.3, 'min_value' => 50], $magda);
    $round = $buy->addOffer($round['id'], ['line_id' => $l42['id'], 'supplier_id' => 8, 'price' => 33], $magda);
    $l41 = array_values(array_filter($round['lines'], fn($l) => $l['material']['id'] === 41))[0];
    $l42 = array_values(array_filter($round['lines'], fn($l) => $l['material']['id'] === 42))[0];
    $mlynOffer = array_values(array_filter($l41['offers'], fn($o) => $o['supplier']['id'] === 7))[0];
    $makroOffer = array_values(array_filter($l41['offers'], fn($o) => $o['supplier']['id'] === 8))[0];
    expectBuy($mlynOffer['below_min'] && !$makroOffer['below_min'] && $l41['best_offer_id'] === $makroOffer['id'] && $makroOffer['manual'], 'cheapest valid offer is the best (Młyn under its minimum)');
    expectBuy($l41['hint']['offer_id'] === $mlynOffer['id'] && (float) $l41['hint']['quantity'] === 1000000.0 && abs($l41['hint']['saving'] - 300.0) < 0.01,
        'hint: buy 1 000 kg from Młyn and save (2,40 − 2,10) × 1 000');
    expectBuy(abs($l41['saving'] - 2.5) < 0.01 && $round['totals']['responded'] === 2 && $round['totals']['with_offers'] === 2, 'saving of the best offer: (2,40 − 2,30) × 25 kg');

    // --- Zamówienie u wybranych
    expectBuyError(fn() => $buy->order($round['id'], ['lines' => [['line_id' => $l41['id'], 'offer_id' => $mlynOffer['id'], 'quantity' => 25000]]], $magda), 'BUY_BELOW_MINIMUM', 'quantity under the minimum refused');
    $done = $buy->order($round['id'], ['lines' => [['line_id' => $l41['id'], 'offer_id' => $makroOffer['id'], 'quantity' => 25000], ['line_id' => $l42['id'], 'offer_id' => null, 'quantity' => 10000]]], $magda);
    expectBuy(count($done['orders']) === 1 && $done['orders'][0]['sent'] && $done['status'] === 'closed', 'one order per supplier, e-mailed, round closed');
    $po = $pdo->query("SELECT * FROM n_po WHERE id = {$done['orders'][0]['id']}")->fetch();
    $poLines = $pdo->query("SELECT id_raw_material, quantity, unit_price FROM n_po_line WHERE id_po = {$po['id']} ORDER BY id_raw_material")->fetchAll();
    expectBuy((int) $po['id_supplier'] === 8 && $po['status'] === 'sent' && str_contains((string) $po['note'], 'proforma') && count($poLines) === 2
        && abs((float) $poLines[0]['unit_price'] - 0.0023) < 1e-9 && abs((float) $poLines[1]['unit_price'] - 0.034) < 1e-9, 'purchase order with offer price and terms');
    expectBuy((int) $pdo->query("SELECT COUNT(*) FROM communication_message WHERE message_type = 'supplier_order' AND status = 'queued'")->fetchColumn() === 1, 'order e-mail queued only');
    $decisions = $pdo->query('SELECT id_line, saving FROM n_buy_order ORDER BY id_line')->fetchAll(PDO::FETCH_KEY_PAIR);
    expectBuy(abs((float) $decisions[$l41['id']] - 2.5) < 0.01 && abs((float) $decisions[$l42['id']]) < 0.01, 'savings: 2,50 zł on flour, 0 at the last price');
    $statuses = $pdo->query("SELECT id_supplier, status FROM n_mp_offer WHERE id_buy_line = {$l41['id']}")->fetchAll(PDO::FETCH_KEY_PAIR);
    expectBuy($statuses[8] === 'accepted' && $statuses[7] === 'rejected', 'offer statuses');
    expectBuyError(fn() => $buy->remind($round['id'], $magda), 'BUY_ROUND_NOT_SENT', 'no reminder on a closed round');
    expectBuy($buy->publicView($tokens[7])['expired'], 'link closed with the round');
    expectBuyError(fn() => $buy->publicSubmit($tokens[7], [$l41['id'] => ['price' => '1']]), 'BUY_LINK_EXPIRED', 'no prices after closing');

    // --- Wyniki : operator i zespół (dyrektor)
    $pdo->exec("INSERT INTO n_activity_session (id, id_user, started_at, last_seen_at) VALUES (1, 1, NOW(), NOW())");
    $pdo->exec("INSERT INTO n_activity_view (id_session, id_user, route, path, started_at, last_seen_at, active_s) VALUES
        (1, 1, '/workshop/buy', '/workshop/buy', NOW(), NOW(), 1800), (1, 1, '/today', '/today', NOW(), NOW(), 900)");
    $pdo->exec("INSERT INTO n_user_cost (id_user, hourly_cost) VALUES (1, 50)");
    $mine = $buy->results('month', $magda);
    expectBuy($mine['me']['rounds'] === 1 && $mine['me']['decisions'] === 2 && $mine['me']['successful'] === 1 && abs($mine['me']['savings'] - 2.5) < 0.01
        && abs($mine['me']['hours'] - 0.5) < 1e-6 && abs($mine['me']['cost'] - 25.0) < 0.01 && abs($mine['me']['roi'] - 0.1) < 0.01 && $mine['team'] === null, 'operator results: decisions, successful, time on the wizard only, ROI');
    expectBuy($mine['me']['emails'] === 4 && $mine['me']['offers'] === 3, 'e-mails: 3 requests + 1 order ; 3 offers');
    $team = $buy->results('year', $director);
    expectBuy(count($team['team']) === 1 && $team['team'][0]['user']['id'] === 1 && abs($team['team_total']['savings'] - 2.5) < 0.01 && abs($team['year_savings'] - 2.5) < 0.01, 'director sees the team');
    expectBuyError(fn() => $buy->results('week', $magda), 'INVALID_CHOICE', 'period month / quarter / year');

    // --- Dostawcy pozycji (1–3)
    expectBuyError(fn() => $buy->saveMaterialSuppliers(42, ['supplier_ids' => []], $magda), 'BUY_LINE_SUPPLIERS', 'at least one supplier per line');
    expectBuyError(fn() => $buy->saveMaterialSuppliers(42, ['supplier_ids' => [8, 7, 11, 9]], $magda), 'BUY_LINE_SUPPLIERS', 'at most three');
    expectBuyError(fn() => $buy->saveMaterialSuppliers(42, ['supplier_ids' => [10]], $magda), 'REFERENCE_NOT_FOUND', 'inactive supplier refused');
    $saved = $buy->saveMaterialSuppliers(42, ['supplier_ids' => [8, 11]], $magda);
    expectBuy(array_column($saved['suppliers'], 'id') === [8, 11], 'suppliers of the line saved in order');

    // --- Szkic następnego tygodnia : zadanie, usunięcie
    $next = $buy->create(['week_start' => date('Y-m-d', strtotime($weekStart . ' +7 days')), 'lines' => [['material_id' => 43, 'quantity' => 25000]]], $magda);
    $queue = $buy->queue();
    expectBuy(count($queue['rounds']) === 1 && $queue['rounds'][0]['status'] === 'draft' && $queue['rounds'][0]['line_count'] === 1, 'queue: unsent draft');
    $buy->delete($next['id'], $magda);
    expectBuy((int) $pdo->query("SELECT COUNT(*) FROM n_buy_round WHERE id = {$next['id']}")->fetchColumn() === 0, 'draft deleted');
    expectBuyError(fn() => $buy->delete($round['id'], $magda), 'BUY_ROUND_NOT_DRAFT', 'only a draft can be deleted');
    expectBuy((int) $pdo->query("SELECT COUNT(*) FROM communication_message WHERE status <> 'queued'")->fetchColumn() === 0, 'nothing left the queue');

    echo "buy_week_test: OK\n";
} finally {
    $pdo->exec("DROP DATABASE IF EXISTS {$testDb}");
}
