<?php
declare(strict_types=1);
/*
 * Sprzedawalność w Belgii (marże) : cena fabryczna za sztukę × mnożnik vs cena w sklepie z API panelu.
 * Klucz nazwy i dopasowanie, agregacja cen sklepów (porcje, VAT, mediana), synchronizacja z fałszywym API
 * (bez sieci), powiązania ręczne, werdykty i rezerwa, karta produktu, progi i konto API (administrator),
 * hasło i token zaszyfrowane.
 *
 *   php tests/store_price_test.php
 *
 * Baza : TFB_TEST_DSN (domyślnie mysql:unix_socket=/run/mysqld/mysqld.sock), TFB_TEST_USER (root), TFB_TEST_PASS ;
 * struktury z TFB_TEST_SOURCE_DB (erp, migracje tfb zastosowane). Baza erp_store_price_test tworzona i usuwana.
 */
require_once __DIR__ . '/../public/vendor/autoload.php';

use App\Core\Api\Workshop\WorkshopConfig;
use App\Exceptions\CustomException;
use App\Repositories\Finances\StorePriceRepository;
use App\Repositories\Organization\PageAccessRepository;
use App\Services\Finances\StorePrice\PanelApiClient;
use App\Services\Finances\StorePrice\PanelSecretCipher;
use App\Services\Finances\StorePrice\StorePriceAccountService;
use App\Services\Finances\StorePrice\StorePriceSyncService;
use App\Services\Finances\StorePrice\StoreSellabilityService;
use App\Services\Organization\PageAccessService;

function expectStore(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException('FAILED: ' . $message);
}

function failsWith(callable $fn, int $code, string $message): void
{
    try { $fn(); } catch (CustomException $e) { expectStore((int) $e->getCode() === $code, $message . ' (kod ' . $e->getCode() . ' : ' . $e->getMessage() . ')'); return; }
    throw new RuntimeException('FAILED: ' . $message . ' (brak wyjątku)');
}

/** Fałszywe API panelu : logowanie, sklepy (jeden spoza Belgii, jeden nieaktywny), produkty dostępne ; zapisuje wywołania. */
final class FakePanel extends PanelApiClient
{
    public array $calls = [];
    public array $failShops = [];
    public array $products = [];

    protected function http(string $method, string $url, ?array $body, ?string $token, int $timeout = 20): array
    {
        $this->calls[] = [$method, $url, $body, $token];
        if (str_ends_with($url, '/consultant/auth/login')) {
            return ($body['password'] ?? '') === 'tajne-haslo' && ($body['phone'] ?? '') === '+32470000000'
                ? [200, ['data' => ['access_token' => 'jwt-' . count($this->calls)]]] : [401, ['description' => 'Invalid credentials']];
        }
        if (str_ends_with($url, '/consultant/shops')) {
            return [200, ['data' => [['id' => 1, 'name' => 'Bruxelles'], ['id' => 2, 'name' => 'Namur', 'country_code' => 'BE'], ['id' => 3, 'name' => 'Lille', 'country_code' => 'FR'],
                ['id' => 4, 'name' => 'Zamknięty', 'is_active' => 0], ['id' => 5, 'name' => 'Liège']]]];
        }
        return [404, ['description' => 'not found']];
    }

    protected function httpMany(array $paths, string $token, int $timeout = 30): array
    {
        $out = [];
        foreach ($paths as $k => $path) {
            $this->calls[] = ['GET', $path, null, $token];
            $shop = (int) preg_replace('#^/shops/(\d+)/.*$#', '$1', $path);
            $out[$k] = in_array($shop, $this->failShops, true) ? [500, null] : [200, ['data' => $this->products[$shop] ?? []]];
        }
        return $out;
    }
}

$dsn = getenv('TFB_TEST_DSN') ?: 'mysql:unix_socket=/run/mysqld/mysqld.sock';
$source = getenv('TFB_TEST_SOURCE_DB') ?: 'erp';
$testDb = 'erp_store_price_test';
try {
    $pdo = new PDO($dsn . ';charset=utf8mb4', getenv('TFB_TEST_USER') ?: 'root', getenv('TFB_TEST_PASS') ?: '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    echo "store_price_test: SKIPPED (no database: {$e->getMessage()})\n";
    exit(0);
}

$pdo->exec("DROP DATABASE IF EXISTS {$testDb}");
$pdo->exec("CREATE DATABASE {$testDb} CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
try {
    foreach (['final_product', 'category_product', 'user', 'tfb_store_price_config', 'tfb_store_product', 'tfb_store_product_shop', 'tfb_store_product_link',
                 'tfb_store_price_sync', 'tfb_page_access', 'tfb_user_position', 'tfb_prod_user_role', 'tfb_position'] as $table) {
        $pdo->exec("CREATE TABLE {$testDb}.{$table} LIKE {$source}.{$table}");
    }
    $pdo->exec("USE {$testDb}");
    $pdo->exec("SET SESSION sql_mode = ''");
    $pdo->exec("INSERT INTO tfb_store_price_config (id) VALUES (1)");
    $pdo->exec("INSERT INTO user (id, name, surname, displayed_name, email, phone, permission, login, password) VALUES
        (1, 'Ad', 'Min', 'Admin', 'a@x.test', '1', 0, 'admin', 'x'), (2, 'Jan', 'K', 'Jan K.', 'j@x.test', '2', 0, 'jan', 'x'), (3, 'Ola', 'P', 'Ola P.', 'o@x.test', '3', 0, 'ola', 'x')");
    $pdo->exec("INSERT INTO tfb_user_position (id_user, id_position) VALUES (2, 5), (3, 6)");
    $pdo->exec("INSERT INTO tfb_page_access (id_position, page_key) VALUES (5, 'margins')");
    $pdo->exec("INSERT INTO category_product (id, name, target_margin_percentage) VALUES (1, 'Cake', 30), (2, 'Cookie''s', 30)");
    $pdo->exec("INSERT INTO final_product (id, name, id_category, ppc, standard_price, active) VALUES
        (101, 'Cake Citron - 350 g.', 1, 15, 46.24, 1), (102, 'Cake Chocolat - 350 g.', 1, 15, 58.51, 1), (103, 'Cookie Chocolat Noir', 2, 54, 62.75, 1),
        (104, 'Brioche 400g.(Cru)', 1, 16, 32.72, 1), (105, 'Damier - 100 g.', 2, 24, 47.44, 1), (106, 'Produit sans prix', 2, 10, 0, 1), (107, 'Cake Pomme - 350 g.', 1, 15, 50.09, 1)");

    $config = new WorkshopConfig(['PRODUCTION_JWT_SECRET' => str_repeat('k', 48)]);
    $repo = new StorePriceRepository($pdo);
    $cipher = new PanelSecretCipher($config);
    $api = new FakePanel($repo, $cipher);
    $access = new PageAccessService(new PageAccessRepository($pdo));
    $sync = new StorePriceSyncService($repo, $api);
    $be = new StoreSellabilityService($repo, $access);
    $account = new StorePriceAccountService($repo, $cipher, $api, $access);

    // --- Klucz nazwy : bez gramatur, liczb, akcentów, wariantów ; kolejność słów bez znaczenia
    expectStore(StoreSellabilityService::nameKey('Cake Citron - 350 g.') === 'cake citron', 'name key drops weight and punctuation');
    expectStore(StoreSellabilityService::nameKey('Citron cake') === 'cake citron', 'name key is order independent');
    expectStore(StoreSellabilityService::nameKey('Brioche 400g.(Cru)') === 'brioche', 'name key drops unbaked variant and glued weight');
    expectStore(StoreSellabilityService::nameKey('Pâte à Choux Ind.') === 'choux pate', 'name key folds accents, drops « ind. »');
    expectStore(StoreSellabilityService::nameKey('Entremet 120ø - Tiramisu 4/6 pers.') === 'entremet tiramisu', 'name key drops sizes and portions');
    expectStore(abs(StoreSellabilityService::similarity(['a', 'b', 'c'], ['a', 'b', 'c', 'd']) - 0.75) < 1e-9, 'jaccard similarity');
    $p = ['multiplier' => 2.5, 'limit' => 2.2, 'reserve' => 3.2];
    expectStore(StoreSellabilityService::verdict(2.5, $p) === 'ok' && StoreSellabilityService::verdict(2.49, $p) === 'warn'
        && StoreSellabilityService::verdict(2.2, $p) === 'warn' && StoreSellabilityService::verdict(2.19, $p) === 'bad' && StoreSellabilityService::verdict(null, $p) === 'none', 'verdict thresholds');

    // --- Sklepy : nieaktywny i spoza Belgii pominięte
    expectStore(StorePriceSyncService::shops([['id' => 1, 'name' => 'A'], ['id' => 2, 'country' => 'Belgique', 'name' => 'B'], ['id' => 3, 'country_code' => 'FR'], ['id' => 4, 'active' => 0], ['name' => 'bez id']])
        === [1 => 'A', 2 => 'B'], 'shops: only active Belgian shops with an id');

    // --- Konto z cockpitu konsultanta (wdrożenie) : kopia zaszyfrowana, bez zmian gdy to samo, konto ręczne nie nadpisane
    expectStore($account->importFromCockpit(['phone' => '', 'password' => 'x']) === 'empty', 'cockpit import: no account');
    expectStore($account->importFromCockpit(['phone' => ' 0470 11 22 33 ', 'password' => 'haslo-cockpit']) === 'copied', 'cockpit import: copied');
    $row = $pdo->query('SELECT * FROM tfb_store_price_config WHERE id = 1')->fetch();
    expectStore($row['phone'] === '0470 11 22 33' && $row['source'] === 'cockpit' && $row['base_url'] === 'https://atelierby.tfbuddy.com/api/v1'
        && !str_contains((string) $row['password_enc'], 'haslo') && $cipher->decrypt($row['password_enc']) === 'haslo-cockpit', 'cockpit account stored as is, password encrypted, default API address');
    expectStore($account->importFromCockpit(['phone' => '0470 11 22 33', 'password' => 'haslo-cockpit']) === 'unchanged', 'cockpit import: unchanged');
    expectStore($account->importFromCockpit(['base' => 'https://panel.test/api/v1/', 'phone' => '0470 11 22 33', 'password' => 'nowe']) === 'copied'
        && $cipher->decrypt($pdo->query('SELECT password_enc FROM tfb_store_price_config')->fetchColumn()) === 'nowe'
        && $pdo->query('SELECT base_url FROM tfb_store_price_config')->fetchColumn() === 'https://panel.test/api/v1', 'cockpit import: refreshed when changed in the cockpit');
    $pdo->exec("UPDATE tfb_store_price_config SET phone = NULL, password_enc = NULL, source = NULL, base_url = 'https://atelierby.tfbuddy.com/api/v1'");

    // --- Konto API : tylko administrator, walidacja, hasło zaszyfrowane, puste = bez zmiany
    failsWith(fn() => $account->save(['base_url' => 'https://panel.test/api/v1', 'phone' => '+32470000000', 'password' => 'tajne-haslo'], 2), 403, 'account: non admin refused');
    failsWith(fn() => $account->save(['base_url' => 'http://panel.test/api/v1', 'phone' => '+32470000000', 'password' => 'x'], 1), 422, 'account: https required');
    failsWith(fn() => $account->save(['base_url' => 'https://panel.test/api/v1', 'phone' => 'abc', 'password' => 'x'], 1), 422, 'account: phone digits');
    failsWith(fn() => $account->save(['base_url' => 'https://panel.test/api/v1', 'phone' => '+32470000000', 'password' => ''], 1), 422, 'account: password required first time');
    failsWith(fn() => $sync->sync(1), 422, 'sync refused while the account is not configured');
    $shown = $account->save(['base_url' => 'https://panel.test/api/v1/', 'phone' => '+32 470 00 00 00', 'password' => 'tajne-haslo'], 1);
    $row = $pdo->query('SELECT * FROM tfb_store_price_config WHERE id = 1')->fetch();
    expectStore($row['base_url'] === 'https://panel.test/api/v1' && $row['phone'] === '+32470000000' && $row['source'] === 'manual' && $shown['password_set'] && $shown['password_readable'] && !isset($shown['password']), 'account saved, normalized');
    expectStore($account->importFromCockpit(['phone' => '0470 99', 'password' => 'inne']) === 'manual' && $pdo->query('SELECT phone FROM tfb_store_price_config')->fetchColumn() === '+32470000000', 'cockpit import keeps an account typed in the ERP');
    expectStore(!str_contains((string) $row['password_enc'], 'tajne') && str_starts_with((string) $row['password_enc'], 'p1:') && $cipher->decrypt($row['password_enc']) === 'tajne-haslo', 'password encrypted at rest');
    expectStore((new PanelSecretCipher(new WorkshopConfig(['PRODUCTION_JWT_SECRET' => str_repeat('z', 48)])))->decrypt($row['password_enc']) === null, 'another server secret cannot read the password');
    $account->save(['base_url' => 'https://panel.test/api/v1', 'phone' => '+32470000000', 'password' => ''], 1);
    expectStore($cipher->decrypt($pdo->query('SELECT password_enc FROM tfb_store_price_config')->fetchColumn()) === 'tajne-haslo', 'empty password keeps the saved one');
    $t = $account->test(1);
    expectStore($t['shops'] === 3 && $t['names'] === ['Bruxelles', 'Namur', 'Liège'], 'account test: login and Belgian shops');
    $tok = $pdo->query('SELECT token_enc, token_exp FROM tfb_store_price_config')->fetch();
    expectStore(!str_contains((string) $tok['token_enc'], 'jwt-') && str_starts_with((string) $cipher->decrypt($tok['token_enc']), 'jwt-') && strtotime($tok['token_exp']) > time(), 'token encrypted and cached');
    failsWith(fn() => $account->test(2), 403, 'account test: admin only');

    // --- Synchronizacja : porcje, VAT, mediana, sklep bez odpowiedzi
    $api->products = [
        1 => [['id' => 11, 'base_name' => 'Cake citron', 'portion_price_gross' => 1.06, 'portion_price_net' => 1.00, 'tax_value_percent' => 6, 'is_divisible' => 1, 'portion_size' => 0.125, 'base_category_name' => 'Cakes'],
              ['id' => 12, 'base_name' => 'Cake chocolat', 'portion_price' => 8.48, 'tax_value_percent' => 6],
              ['id' => 13, 'base_name' => 'Cookie chocolat noir', 'portion_price_gross' => 2.70, 'portion_price_net' => 2.5472, 'tax_value_percent' => 6],
              ['id' => 14, 'base_name' => 'Brioche', 'portion_price_gross' => 5.30, 'portion_price_net' => 5.00, 'tax_value_percent' => 6],
              ['id' => 15, 'base_name' => 'Café', 'portion_price_gross' => 2.00, 'is_active' => 0],
              ['id' => 16, 'base_name' => 'Cake citron', 'portion_price_gross' => 99, 'portion_price_net' => 99]],
        2 => [['id' => 21, 'base_name' => 'Citron Cake', 'portion_price_gross' => 8.48, 'portion_price_net' => 8.00, 'tax_value_percent' => 6],
              ['id' => 22, 'base_name' => 'Cookie chocolat noir', 'portion_price_gross' => 2.90, 'portion_price_net' => 2.7358, 'tax_value_percent' => 6],
              ['id' => 23, 'base_name' => 'Pomme cake (tranche)', 'portion_price_gross' => 7.95, 'portion_price_net' => 7.50, 'tax_value_percent' => 6]],
        5 => [['id' => 51, 'base_name' => 'CAKE CITRON', 'portion_price_gross' => 8.90, 'portion_price_net' => 8.3962, 'tax_value_percent' => 6]],
    ];
    $api->failShops = [5];
    $r = $sync->sync(1);
    expectStore($r['status'] === 'partial' && $r['shops'] === 3 && $r['shops_failed'] === 1 && $r['products'] === 5, 'sync: partial when a shop fails (' . json_encode($r) . ')');
    $citron = $repo->storeProductByKey('cake citron');
    expectStore((float) $citron['price_net'] === 8.0 && (float) $citron['price_gross'] === 8.48 && (int) $citron['shops'] === 2 && $citron['category'] === 'Cakes', 'portion price → whole piece, median of shops, first duplicate kept');
    $choco = $repo->storeProductByKey('cake chocolat');
    expectStore(abs((float) $choco['price_net'] - 8.0) < 0.001 && (float) $choco['price_gross'] === 8.48, 'net price derived from gross and VAT');
    expectStore($repo->storeProductByKey('cafe') === null, 'inactive products skipped');
    expectStore(count($repo->shopPrices((int) $citron['id'])) === 2, 'prices per shop stored');
    $last = $repo->lastSync();
    expectStore($last['status'] === 'partial' && $last['run_by_name'] === 'Admin' && str_contains((string) $last['message'], '1 sklep'), 'sync logged');
    foreach ($api->calls as $c) expectStore(!str_contains(json_encode($c), 'tajne') || str_ends_with($c[1], '/consultant/auth/login'), 'password only sent to the login endpoint');

    // --- Ocena : dopasowanie po nazwie, werdykty, maks. cena fabryczna, rezerwa
    $ev = $be->evaluate([101, 102, 103, 104, 105, 106, 107]);
    $c101 = $ev[101];
    expectStore($c101['link'] === 'auto' && abs($c101['piece'] - 3.0827) < 0.001 && $c101['target'] === 7.71 && $c101['coef'] === 2.6 && $c101['verdict'] === 'ok', 'Cake Citron: auto match, ×2.6 sellable');
    expectStore($c101['max_factory'] === 3.2 && $c101['max_carton'] === 48.0 && $c101['delta'] === 0.12 && $c101['target_gross'] === 8.17, 'max factory price, carton, margin and gross target');
    expectStore($ev[102]['coef'] === 2.05 && $ev[102]['verdict'] === 'bad' && $ev[102]['delta'] === -0.7, 'Cake Chocolat: ×2.05 too expensive, −0.70 €/pc');
    expectStore($ev[103]['coef'] === 2.27 && $ev[103]['verdict'] === 'warn', 'Cookie Chocolat Noir: median of 2 shops, ×2.27 borderline');
    expectStore($ev[104]['link'] === 'auto' && $ev[104]['coef'] === 2.44 && $ev[104]['verdict'] === 'warn', 'Brioche (Cru) matched to store Brioche');
    expectStore($ev[105]['store'] === null && $ev[105]['verdict'] === 'none' && $ev[105]['link'] === null, 'product absent from shops');
    expectStore($ev[106]['verdict'] === 'noprice' && $ev[106]['piece'] === null, 'product without factory price');
    expectStore($ev[107]['link'] === 'auto' && $ev[107]['store']['name'] === 'Pomme cake (tranche)' && $ev[107]['coef'] === 2.25 && $ev[107]['verdict'] === 'warn', 'Cake Pomme: similar name matched (« tranche » extra word), ×2.25 borderline');

    // --- Powiązania : ręczne, nie sprzedawany, z powrotem automatyczne ; uprawnienia
    failsWith(fn() => $be->setLink(105, ['mode' => 'manual', 'key' => 'brioche'], 3), 403, 'link: user without margins refused');
    failsWith(fn() => $be->setLink(105, ['mode' => 'manual', 'key' => 'nie ma'], 2), 422, 'link: unknown store product');
    failsWith(fn() => $be->setLink(999, ['mode' => 'auto'], 2), 404, 'link: unknown factory product');
    $l = $be->setLink(105, ['mode' => 'manual', 'key' => 'brioche'], 2);
    expectStore($l['link'] === 'manual' && $l['store']['key'] === 'brioche', 'manual link by a margins user');
    $l = (new StoreSellabilityService($repo, $access))->setLink(101, ['mode' => 'excluded'], 2);
    expectStore($l['link'] === 'excluded' && $l['store'] === null && $l['verdict'] === 'none', 'excluded: not sold in shops, no auto match');
    $l = (new StoreSellabilityService($repo, $access))->setLink(101, ['mode' => 'auto'], 2);
    expectStore($l['link'] === 'auto' && $l['coef'] === 2.6, 'back to automatic match');
    $cands = (new StoreSellabilityService($repo, $access))->candidates(105, '');
    expectStore($cands['mode'] === 'manual' && $cands['current'] === 'brioche' && count($cands['items']) === 5, 'candidates list with current link');
    $cands = (new StoreSellabilityService($repo, $access))->candidates(101, 'citron');
    expectStore(count($cands['items']) === 1 && $cands['items'][0]['key'] === 'cake citron' && $cands['items'][0]['score'] === 1.0, 'candidates search');

    // --- Widok kategorii i karta produktu
    $view = (new StoreSellabilityService($repo, $access))->forCategories(['categories' => [
        ['category_id' => 1, 'products' => [['product_id' => 101], ['product_id' => 102], ['product_id' => 104], ['product_id' => 107]]],
        ['category_id' => 2, 'products' => [['product_id' => 103], ['product_id' => 105], ['product_id' => 106]]]]], 2);
    expectStore($view['categories'][1] === ['ok' => 1, 'warn' => 2, 'bad' => 1, 'none' => 0], 'category counts: Cake ' . json_encode($view['categories'][1]));
    expectStore($view['categories'][2]['warn'] === 1 && $view['categories'][2]['none'] === 1, 'category counts: Cookies ' . json_encode($view['categories'][2]));
    expectStore($view['configured'] && $view['synced']['status'] === 'partial' && !$view['is_admin'] && $view['params']['multiplier'] === 2.5, 'view context');
    $card = (new StoreSellabilityService($repo, $access))->productCard(103, ['kpi' => ['total_cost_per_carton' => 216.0, 'exchange_rate' => 4.0, 'margin_percent' => 20.7]], 1);
    expectStore($card['cost_piece'] === 1.0 && $card['factory_margin'] === 20.7 && count($card['shops']) === 2 && $card['shops'][0]['name'] === 'Bruxelles'
        && $card['shops'][0]['coef'] === 2.19 && $card['shops'][0]['verdict'] === 'bad' && count($card['below']) === 2 && $card['is_admin'], 'product card: cost per piece, shops sorted by coefficient');

    // --- Progi : administrator, kolejność
    failsWith(fn() => $be->saveParams(['multiplier' => '2,6', 'limit' => '2,3', 'reserve' => '3'], 2), 403, 'params: admin only');
    failsWith(fn() => $be->saveParams(['multiplier' => '2,6', 'limit' => '2,8', 'reserve' => '3'], 1), 422, 'params: order limit ≤ multiplier ≤ reserve');
    failsWith(fn() => $be->saveParams(['multiplier' => 'x', 'limit' => '2', 'reserve' => '3'], 1), 422, 'params: numbers');
    expectStore($be->saveParams(['multiplier' => '2,6', 'limit' => '2,3', 'reserve' => '3'], 1) === ['multiplier' => 2.6, 'limit' => 2.3, 'reserve' => 3.0], 'params saved');
    expectStore((new StoreSellabilityService($repo, $access))->evaluate([101])[101]['verdict'] === 'ok' && (new StoreSellabilityService($repo, $access))->evaluate([104])[104]['verdict'] === 'warn', 'verdicts follow the new thresholds');

    // --- Druga synchronizacja : produkt zniknął z panelu → usunięty z cenami ; powiązanie ręczne zostaje
    $api->failShops = [];
    $api->products[2] = [];
    $api->products[5] = [];
    unset($api->products[1][3]);
    $r = $sync->sync(1);
    expectStore($r['status'] === 'ok' && $repo->storeProductByKey('brioche') === null && $repo->storeProductByKey('pomme cake tranche') === null, 'vanished products removed');
    expectStore((int) $pdo->query('SELECT COUNT(*) FROM tfb_store_product_shop s LEFT JOIN tfb_store_product p ON p.id = s.id_store_product WHERE p.id IS NULL')->fetchColumn() === 0, 'no orphan shop prices');
    $ev = (new StoreSellabilityService($repo, $access))->evaluate([105]);
    expectStore($ev[105]['link'] === 'manual' && $ev[105]['store'] === null && $ev[105]['verdict'] === 'none', 'manual link kept, product gone from shops');

    // --- Błędy API : złe hasło, brak sklepów → synchronizacja nieudana, stare ceny zostają
    $before = (int) $pdo->query('SELECT COUNT(*) FROM tfb_store_product')->fetchColumn();
    $pdo->exec('UPDATE tfb_store_price_config SET token_enc = NULL, token_exp = NULL');
    $repo->saveConfig(['password_enc' => $cipher->encrypt('zle')]);
    $bad = new FakePanel($repo, $cipher);
    failsWith(fn() => (new StorePriceSyncService($repo, $bad))->sync(1), 502, 'wrong password: sync fails');
    expectStore($repo->lastSync()['status'] === 'failed' && str_contains((string) $repo->lastSync()['message'], 'HTTP 401') && !str_contains((string) $repo->lastSync()['message'], 'zle'), 'failure logged without the password');
    expectStore((int) $pdo->query('SELECT COUNT(*) FROM tfb_store_product')->fetchColumn() === $before, 'failed sync keeps previous prices');
    expectStore($repo->lastSuccessfulSync()['status'] === 'ok', 'last successful sync still shown');

    echo "store_price_test: OK\n";
} finally {
    $pdo->exec("DROP DATABASE IF EXISTS {$testDb}");
}
