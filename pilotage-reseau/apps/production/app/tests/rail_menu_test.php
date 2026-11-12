<?php
declare(strict_types=1);
/*
 * [253] Menu ERP bez dubli (makiety 25–26) : 29 pozycji ([256] Kierownik produkcji w Administracji, [262] Czas pracy, [271] HR, [277] Lista płac), każda strona należy do jednej pozycji (zakładki bez powtórzeń),
 * wszystkie dawne linki railu mają pozycję, menu stanowiska (kolejność, strona otwierana), sesja administratora
 * « jako stanowisko », stanowisko bez menu = całe menu — reguły bez bazy + baza jednorazowa.
 *
 *   php tests/rail_menu_test.php
 */
require_once __DIR__ . '/../public/vendor/autoload.php';

use App\Core\Web\PageTabs;
use App\Core\Web\Rail;
use App\Core\Web\ViewSession;
use App\Services\Organization\PageAccessService;

function expectRail(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException('FAILED: ' . $message);
}

// --- Bez bazy : układ ----------------------------------------------------------------------------------------------------
expectRail(count(Rail::ITEMS) === 29 && Rail::count() === 29, 'menu wspólne : 29 pozycji (było 65 ; [256] + Kierownik produkcji w Administracji, [262] + Czas pracy, [271] + HR, [277] + Lista płac)');
expectRail(Rail::itemFor('/time/month') === 'time' && Rail::itemFor('/time') === 'time' && \App\Core\Web\PageAccess::keyFor('/time/overtime') === 'time', '[262] Czas pracy : pozycja railu i prawo « time »');
expectRail(array_keys(array_filter(array_map(fn($i) => $i[2], Rail::ITEMS), fn($s) => !in_array($s, Rail::SECTIONS, true))) === [], 'sekcje pozycji znane');
foreach (array_keys(PageTabs::GROUPS) as $group) {
    expectRail(isset(Rail::ITEMS[$group]) || isset(Rail::GROUP_PARENT[$group]), "grupa zakładek {$group} należy do pozycji railu");
}
// Bez dubli : każda zakładka (strona) tylko w jednej grupie. Linki « Otwórz tablet… » (nowa karta, poza grupą — PageTabs::forPath
// je pomija) mogą być w kilku grupach : [262] tablet biurowy w Tablety i w Czas pracy.
$seen = [];
foreach (PageTabs::GROUPS as $group => $g) foreach ($g['tabs'] as $t) {
    if (($t[4] ?? '') === 'blank') continue;
    expectRail(!isset($seen[$t[0]]), "strona {$t[0]} w dwóch grupach ({$group}, " . ($seen[$t[0]] ?? '') . ')');
    $seen[$t[0]] = $group;
}
foreach (Rail::ITEMS as $key => $item) {
    expectRail(Rail::itemFor($item[3]) === $key, "strona pozycji {$key} → ta pozycja (" . Rail::itemFor($item[3]) . ')');
    foreach (Rail::groupsOf($key) as $group) foreach (PageTabs::GROUPS[$group]['tabs'] as $t) {
        if (empty($t[4])) expectRail(Rail::itemFor($t[0]) === $key, "zakładka {$t[0]} → pozycja {$key} (" . Rail::itemFor($t[0]) . ')');
    }
}
// Dawne linki railu (przed [253]) : każdy ma pozycję — stare adresy działają i są podświetlone.
$old = ['/client-franchise-networks', '/client-orders', '/client-orders/queue', '/clients', '/communication/payment-reminders', '/cost-calculator', '/dashboard',
    '/dashboard/monthly', '/finances/category-margin', '/finances/clients', '/finances/cost-analysis', '/finances/cost-analysis/section', '/finances/payments-day',
    '/finances/price-wizard', '/finances/product-margin', '/finances/sales-analytics', '/franchise-complaints', '/franchise-products', '/invoice-documents',
    '/logistics/live', '/logistics/loading', '/mail', '/mail/settings', '/material-suppliers', '/materials/all', '/materials/nutritional-values', '/materials/prices',
    '/month-close', '/my-tasks', '/organization/access', '/organization/execution', '/organization/team-tasks', '/price-lists/general', '/production-employees',
    '/products/cartons', '/proforma-documents', '/purchasing', '/purchasing/offers', '/purchasing/supplier-review', '/purchasing/tenders', '/settings', '/staff/budget',
    '/staff/team-bonus', '/today', '/workshop/buy', '/workshop/execution', '/workshop/group-orders', '/workshop/manager', '/workshop/production-days',
    '/workshop/receiving', '/workshop/supply', '/dashboard/results', '/dashboard/groups', '/finances/invoices', '/finances/costs', '/organization/activity',
    '/settings/page-usage', '/communication/distribution', '/communication/templates', '/workshop/workstations', '/workshop/data-sheet', '/client-complaints/to-ship',
    '/audits/product-weights', '/ppwr/reports', '/logistics/plan', '/logistics/week', '/shipments', '/workshop/stock', '/workshop/sheet', '/production/capacity'];
foreach ($old as $p) expectRail(Rail::itemFor($p) !== null, "dawny link {$p} bez pozycji railu");
expectRail(Rail::itemFor('/purchasing/nc') === 'production' && Rail::itemFor('/purchasing/cost-review') === 'materials' && Rail::itemFor('/logistics/live') === 'shipping_day'
    && Rail::itemFor('/mail/settings') === 'mail' && Rail::itemFor('/workshop/manager') === 'bonuses' && Rail::itemFor('/communication/outbox') === 'settings', 'przeniesione strony');
expectRail(Rail::itemFor('/tablet/office') === null, 'tablet : przycisk, nie strona ERP');
// [256] Płace, premie zespołów i moduł kierownika produkcji : Administracja › Płace i premie (tylko administrator), nie Zespół.
$teamTabs = array_column(App\Core\Web\PageTabs::GROUPS['team']['tabs'], 0);
expectRail(Rail::ITEMS['bonuses'][2] === 'Administracja' && Rail::adminOnly('bonuses') && Rail::itemFor('/staff/team-bonus') === 'bonuses'
    && !in_array('/workshop/manager', $teamTabs, true) && !in_array('/staff/team-bonus', $teamTabs, true), 'Płace i premie : Administracja, tylko administrator');
expectRail(App\Core\Web\PageAccess::keyFor('/staff/team-bonus') === 'team_bonus' && App\Core\Web\PageAccess::adminOnly('team_bonus')
    && App\Core\Web\PageAccess::keyFor('/staff/budget') === 'budget' && App\Core\Web\PageAccess::adminOnly('budget'), 'płace i premie : tylko administrator');
// [260] Budżet personelu fusionné dans Pracownicy produkcji (Zespół) : Płace i premie = premie zespołów + kierownik.
expectRail(array_column(App\Core\Web\PageTabs::GROUPS['bonuses']['tabs'], 0) === ['/staff/team-bonus', '/workshop/manager'] && $teamTabs === ['/production-employees']
    && Rail::ITEMS['bonuses'][3] === '/staff/team-bonus' && Rail::ITEMS['bonuses'][0] === 'Płace i premie', 'Płace i premie : premie, kierownik ; Zespół : pracownicy (z płacami)');
expectRail(Rail::itemFor('/my-card') === 'today' && App\Core\Web\PageAccess::keyFor('/my-card') === null, 'Moja karta stanowiska : strona wspólna pod Dzisiaj');
// [255] Magazyn i dostawy surowców pod prawem Produkcja (nie dla wszystkich).
foreach (['/workshop/stock', '/workshop/stock/mts', '/workshop/stock/forecast', '/workshop/stock-movements', '/workshop/deliveries'] as $p) {
    expectRail(App\Core\Web\PageAccess::keyFor($p) === 'production', "{$p} → prawo Produkcja (" . App\Core\Web\PageAccess::keyFor($p) . ')');
}
expectRail(App\Core\Web\PageAccess::keyFor('/workshop/products') === 'product_cards', 'Karty produktów bez zmian');
// Dzisiaj : pozycja « poniżej stanu minimalnego » (→ Magazyn) pominięta, gdy użytkownik nie widzi strony.
$today = (new ReflectionClass(App\Services\Dashboard\TodayService::class))->newInstanceWithoutConstructor();
$todoIn = [['url' => '/workshop/stock', 'positions' => ['LOGISTYKA']], ['url' => '/client-orders', 'positions' => ['ADMINISTRACJA']], ['url' => null, 'positions' => []]];
App\Core\Web\PageAccess::$checker = fn(string $p) => App\Core\Web\PageAccess::keyFor($p) !== 'production';
$todoOut = $today->markTodo($todoIn, 'ADMINISTRACJA', ['ADMINISTRACJA' => 'Administracja']);
expectRail(array_column($todoOut, 'url') === ['/client-orders', null], 'Dzisiaj bez linku do Magazynu dla stanowiska bez prawa Produkcja ' . json_encode(array_column($todoOut, 'url')));
App\Core\Web\PageAccess::$checker = null;
expectRail(count($today->markTodo($todoIn, null, [])) === 3, 'administrator : wszystkie pozycje');
// Uprawnienia pozycji (klucze PageAccess) i pozycje administratora.
expectRail(array_diff(['supply', 'receiving'], Rail::accessKeys('purchasing')) === [] && Rail::accessKeys('today') === [], 'klucze stron pozycji');
expectRail(Rail::adminOnly('access') && !Rail::adminOnly('activity') && !Rail::adminOnly('team'), 'pozycje tylko dla administratora');
// Menu stanowiska : kolejność railu, strona otwierana, podświetlenie przez zakładki.
$menu = Rail::menu(['purchasing' => '/workshop/receiving', 'today' => null, 'production' => '/purchasing/nc']);
$items = array_merge(...array_map(fn($s) => $s['items'], $menu));
expectRail(array_column($items, 'key') === ['today', 'production', 'purchasing'], 'kolejność railu, nie kolejność zapisu');
expectRail($items[2]['href'] === '/workshop/receiving' && in_array('/workshop/buy', $items[2]['also'], true) && !in_array('/workshop/receiving', $items[2]['also'], true), 'strona otwierana + zakładki w podświetleniu');
expectRail(array_column($menu, 'title') === ['', 'Produkcja', 'Zakupy'], 'tylko sekcje z pozycjami');
$view = file_get_contents(__DIR__ . '/../App/Views/page_components/sidebar.twig');
expectRail(str_contains($view, 'rail_menu()') && str_contains($view, 'data-search-open') && str_contains($view, 'rail_all()') && substr_count($view, 'class="sidebar-item') <= 3, 'rail z definicji (bez ręcznej listy)');

// --- Baza jednorazowa : menu stanowisk -----------------------------------------------------------------------------------
$dsn = getenv('TFB_TEST_DSN') ?: 'mysql:unix_socket=/run/mysqld/mysqld.sock';
$source = getenv('TFB_TEST_SOURCE_DB') ?: 'erp';
$testDb = 'erp_rail_menu_test';
try {
    $pdo = new PDO($dsn . ';charset=utf8mb4', getenv('TFB_TEST_USER') ?: 'root', getenv('TFB_TEST_PASS') ?: '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    echo "rail_menu_test: PARTIAL (bez bazy : {$e->getMessage()})\n";
    exit(0);
}
$pdo->exec("DROP DATABASE IF EXISTS {$testDb}");
$pdo->exec("CREATE DATABASE {$testDb} CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
try {
    foreach (['user', 'n_user_position', 'n_position', 'n_prod_user_role', 'n_page_access', 'n_position_home'] as $table) {
        $pdo->exec("CREATE TABLE {$testDb}.{$table} LIKE {$source}.{$table}");
    }
    $pdo->exec("USE {$testDb}");
    $pdo->exec("SET SESSION sql_mode = ''");
    $pdo->exec("INSERT INTO user (id, login, displayed_name) VALUES (1, 'admin', 'Admin'), (2, 'jk', 'J. K.'), (3, 'zk', 'Z. K.')");
    $pdo->exec("INSERT INTO n_prod_user_role (id_user, role_code) VALUES (1, 'prod_admin')");
    $pdo->exec("INSERT INTO n_position (id, code, name_pl, name_fr, kind, sort_order, active) VALUES
        (2, 'JAKOSC', 'Specjalista ds. jakości', 'Qualité', 'erp', 1, 1), (3, 'KIEROWNIK_PRODUKCJI', 'Kierownik produkcji', 'Chef', 'erp', 2, 1)");
    $pdo->exec("INSERT INTO n_user_position (id_user, id_position, since) VALUES (2, 2, '2026-01-01'), (3, 3, '2026-01-01')");
    // Migracja 160 na bazie testowej (tabela + menu domyślne).
    $pdo->exec(file_get_contents(__DIR__ . '/../migrations/tfb/160_position_menu.sql'));
    $builder = new DI\ContainerBuilder();
    $builder->addDefinitions([PDO::class => $pdo]);
    $container = $builder->build();
    $access = fn() => $container->make(PageAccessService::class);
    $_SESSION = [];

    $a = $access();
    expectRail($a->railMenu(1) === null, 'administrator : całe menu');
    expectRail($a->railMenu(2) === ['today' => null, 'production' => '/purchasing/nc', 'purchasing' => '/workshop/receiving', 'materials' => null,
        'suppliers' => '/purchasing/supplier-review', 'products' => null], 'Jakość : 6 pozycji, otwierane na przyjęciach, niezgodnościach, ocenie dostawców ' . json_encode($a->railMenu(2)));
    expectRail(count($a->railMenu(3)) === 11 && array_key_first($a->railMenu(3)) === 'today', 'Kierownik produkcji : 11 pozycji');
    expectRail($a->railMenu(99) === null, 'użytkownik bez stanowiska i bez menu : całe menu (rail ukrywa strony bez uprawnień)');
    // Sesja administratora jako Jakość : menu Jakości.
    $a->switchSession(1, 2);
    expectRail(ViewSession::position() === 2 && $access()->railMenu(1) === $a->railMenu(2), 'sesja administratora « Jakość » : menu stanowiska');
    $a->switchSession(1, null);
    expectRail($access()->railMenu(1) === null, 'pełny widok : znów całe menu');
    // Menu zmienione / usunięte : stanowisko bez wierszy = całe menu.
    $repo = new App\Repositories\Organization\PageAccessRepository($pdo);
    $repo->setMenu(3, ['team_tasks' => null, 'today' => null], 1);
    expectRail($access()->railMenu(3) === ['today' => null, 'team_tasks' => null], 'nowe menu w kolejności railu');
    $repo->setMenu(3, [], 1);
    expectRail($access()->railMenu(3) === null, 'puste menu = całe menu');

    // --- « Uprawnienia i menu » : stany pozycji i zmiany -----------------------------------------------------------------
    $st = $access()->menuStates(2);
    expectRail($st['today'] === 'menu' && $st['production'] === 'none' && $st['access'] === 'admin' && $st['mail'] === 'access', 'Jakość bez uprawnień : Dzisiaj w menu, Produkcja bez dostępu, Poczta poza menu ' . json_encode($st));
    $res = $access()->saveMenuState(2, ['item' => 'warehouse', 'state' => 'menu'], 1);
    expectRail($res['states']['warehouse'] === 'menu' && $res['states']['production'] === 'menu' && in_array('production', $repo->positionGrants(2), true),
        'Magazyn w menu : strony przyznane (klucz wspólny z Produkcją — Produkcja z menu staje się dostępna)');
    expectRail(array_key_exists('warehouse', $access()->railMenu(2)) && $access()->railMenu(2)['production'] === '/purchasing/nc', 'menu : nowa pozycja, strona otwierana innych pozycji zachowana');
    $res = $access()->saveMenuState(2, ['item' => 'warehouse', 'state' => 'access'], 1);
    expectRail($res['states']['warehouse'] === 'access' && !array_key_exists('warehouse', $access()->railMenu(2)), 'Magazyn : dostęp bez pozycji w menu');
    $res = $access()->saveMenuState(2, ['item' => 'warehouse', 'state' => 'none'], 1);
    expectRail($res['states']['warehouse'] === 'none' && $res['states']['production'] === 'none' && $res['side'] === ['Produkcja', 'Ustawienia'], 'brak : strony wspólne odebrane, strona pokazuje Produkcję i Ustawienia (stanowiska robocze) ' . json_encode($res));
    try { $access()->saveMenuState(2, ['item' => 'today', 'state' => 'none'], 1); expectRail(false, 'Dzisiaj nie może być « brak »'); } catch (App\Exceptions\CustomException $e) {}
    try { $access()->saveMenuState(2, ['item' => 'access', 'state' => 'menu'], 1); expectRail(false, 'pozycja administratora'); } catch (App\Exceptions\CustomException $e) {}
    try { $access()->saveMenuState(2, ['item' => 'warehouse', 'state' => 'menu'], 2); expectRail(false, 'tylko administrator'); } catch (App\Exceptions\CustomException $e) {}
    // Stanowisko bez własnego menu : pierwsza zmiana tworzy menu z dostępnych pozycji.
    $repo->setGrants(3, ['production', 'supply'], 1);
    $res = $access()->saveMenuState(3, ['item' => 'mail', 'state' => 'access'], 1);
    expectRail(array_keys($access()->railMenu(3)) === ['today', 'production', 'warehouse', 'purchasing', 'settings'],
        'menu utworzone z dostępnych pozycji bez Poczty ' . json_encode(array_keys($access()->railMenu(3) ?? [])));
    expectRail(!array_key_exists('mail', $access()->railMenu(3)) && $res['states']['mail'] === 'access', 'Poczta poza menu');
    $res = $access()->saveMenuState(3, ['reset' => true], 1);
    expectRail($access()->railMenu(3) === null && $res['states']['mail'] === 'menu', 'całe menu : bez własnego menu');
    $m = $access()->menuMatrix();
    expectRail(array_column($m['columns'], 'name') === ['Specjalista ds. jakości', 'Kierownik produkcji', 'Bez stanowiska'] && count(array_merge(...array_column($m['sections'], 'items'))) === 29,
        'macierz : stanowiska biurowe + bez stanowiska × 29 pozycji');
    echo "rail_menu_test: OK\n";
} finally {
    $_SESSION = [];
    $pdo->exec("DROP DATABASE IF EXISTS {$testDb}");
}
