<?php
/**
 * [407] Feuille d'équipe : clic sur un produit → clients et quantités commandées de la semaine. Mêmes commandes que la
 * colonne « Zamówienie » (ouvertes : en attente sans WDT, hors gratuits), une ligne par commande, triées par départ.
 * Base : TFB_TEST_DSN (défaut mysql:unix_socket=/run/mysqld/mysqld.sock), base erp_team_orders_test recréée puis supprimée.
 */
declare(strict_types=1);
require_once __DIR__ . '/../public/vendor/autoload.php';

use App\Repositories\Workshop\PlanningRepository;

$pdo = new PDO(getenv('TFB_TEST_DSN') ?: 'mysql:unix_socket=/run/mysqld/mysqld.sock', getenv('TFB_TEST_USER') ?: 'root', getenv('TFB_TEST_PASS') ?: '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->exec('DROP DATABASE IF EXISTS erp_team_orders_test'); $pdo->exec('CREATE DATABASE erp_team_orders_test'); $pdo->exec('USE erp_team_orders_test');
$pdo->exec('CREATE TABLE customer (id INT PRIMARY KEY, company VARCHAR(120) NULL, name VARCHAR(80) NULL, surname VARCHAR(80) NULL)');
$pdo->exec('CREATE TABLE customer_order (id INT PRIMARY KEY, id_customer INT NULL, status INT, id_wdt INT NULL, is_freebie TINYINT NULL, expected_shipping_date DATETIME NULL, create_order_date DATETIME NULL)');
$pdo->exec('CREATE TABLE customer_order_product (id INT AUTO_INCREMENT PRIMARY KEY, id_order INT, id_product INT, quantity_carton DECIMAL(10,2))');
$pdo->exec("INSERT INTO customer VALUES (1, 'Sklep A', NULL, NULL), (2, '', 'Jan', 'Testowy'), (3, NULL, NULL, NULL)");
$pdo->exec("INSERT INTO customer_order (id, id_customer, status, id_wdt, is_freebie, expected_shipping_date) VALUES
    (10, 1, 1, NULL, 0, '2026-11-24 00:00:00'), (11, 2, 1, NULL, 0, '2026-11-20 00:00:00'), (12, 3, 1, NULL, NULL, '2026-11-24 00:00:00'),
    (13, 1, 1, 77, 0, '2026-11-24 00:00:00'), (14, 1, 1, NULL, 1, '2026-11-24 00:00:00'), (15, 1, 1, NULL, 0, '2026-11-25 00:00:00'), (16, 1, 2, NULL, 0, '2026-11-24 00:00:00')");
// Produit 5 : 10 (6 + 4 kart., deux lignes), 11 (3), 12 (1) ; exclus : WDT (13), gratuit (14), semaine suivante (15), autre statut (16), autre produit.
$pdo->exec('INSERT INTO customer_order_product (id_order, id_product, quantity_carton) VALUES (10, 5, 6), (10, 5, 4), (10, 6, 9), (11, 5, 3), (12, 5, 1), (13, 5, 50), (14, 5, 2), (15, 5, 7), (16, 5, 8)');

$fail = 0;
$check = function (bool $ok, string $label) use (&$fail) { echo ($ok ? 'ok   ' : 'FAIL ') . $label . "\n"; if (!$ok) $fail++; };
$rows = (new PlanningRepository($pdo))->productOrders(5, '2026-11-18', '2026-11-24');
$check(array_map('intval', array_column($rows, 'customer_order_id')) === [11, 10, 12], 'open orders of the week only (no WDT, no freebie, no next week, no other status), by departure');
$by = array_column($rows, null, 'customer_order_id');
$check((float) $by[10]['cartons'] === 10.0 && (float) $by[11]['cartons'] === 3.0 && (float) $by[12]['cartons'] === 1.0, 'one line per order, product lines summed (6 + 4 = 10 cartons), other products ignored');
$check($by[10]['customer'] === 'Sklep A' && $by[11]['customer'] === 'Jan Testowy' && $by[12]['customer'] === null, 'customer : company, else name + surname, else null (shown as « Klient #id »)');
$open = array_sum(array_map(fn($r) => (float) $r['cartons'], (new PlanningRepository($pdo))->openOrderLines('2026-11-18', '2026-11-24')
    ? array_filter((new PlanningRepository($pdo))->openOrderLines('2026-11-18', '2026-11-24'), fn($r) => (int) $r['id_product'] === 5) : []));
$check($open === 14.0, 'same total as the « Zamówienie » column (openOrderLines) : 14 cartons');

// Câblage : route, OpenAPI, service, écran.
$routes = (string) file_get_contents(__DIR__ . '/../App/Core/Routes/Api/WorkshopApiRoutes.php');
$check(str_contains($routes, "['GET', '/planning/team-sheet/orders', WeekController::class, 'teamSheetOrders', 'prod.plan.read']"), 'route GET /planning/team-sheet/orders (read right of the plan)');
$check(isset((new ReflectionClass(App\Core\Api\Workshop\OpenApiPlanning::class))->getFileName()[0]) && str_contains((string) file_get_contents(__DIR__ . '/../App/Core/Api/Workshop/OpenApiPlanning.php'), "'GET /planning/team-sheet/orders'"), 'documented in OpenAPI');
$check(method_exists(App\Services\Workshop\Planning\TeamSheetService::class, 'orders') && method_exists(App\Services\Workshop\Planning\WeekService::class, 'bounds'), 'TeamSheetService::orders and WeekService::bounds exist');
$js = (string) file_get_contents(__DIR__ . '/../public/assets/js/workshop/teamsheet.js');
$check(str_contains($js, 'data-orders="${r.item_id}"') && str_contains($js, '/planning/team-sheet/orders?item_id='), 'screen : product name clickable, opens the orders window');
foreach (['pl', 'fr'] as $l) {
    $j = json_decode((string) file_get_contents(__DIR__ . "/../public/resources/lang/ui/{$l}/teamsheet.json"), true);
    $check(is_array($j) && !array_diff(['orders_title', 'orders_sub', 'col_customer', 'col_due', 'col_order', 'orders_total', 'orders_empty', 'customer_unknown', 'orders_hint', 'loading'], array_keys($j)), "lang {$l} : keys of the window");
}

$pdo->exec('DROP DATABASE erp_team_orders_test');
echo $fail ? "\n{$fail} FAIL\n" : "\nall ok\n";
exit($fail ? 1 : 0);
