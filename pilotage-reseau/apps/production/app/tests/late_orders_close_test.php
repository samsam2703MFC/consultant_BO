<?php
/**
 * [442] Commandes en retard qui ne se ferment jamais (la WDT ne ferme que les commandes du jour exact) :
 * 1. Stan ogólny › « Zaległe » : fermeture en lot (wysłane / zarchiwizowane), droit aux commandes clients ;
 * 2. « Wyślij dzień » : les commandes en retard encore ouvertes du client sont proposées et, cochées, fermées avec la WDT.
 * Seules les commandes ouvertes (sans WDT, statut Oczekujące) et datées avant le jour donné changent ; journal par commande.
 * Base : TFB_TEST_DSN (défaut mysql:unix_socket=/run/mysqld/mysqld.sock), base erp_late_close_test recréée puis supprimée.
 */
declare(strict_types=1);
require_once __DIR__ . '/../public/vendor/autoload.php';

use App\Domain\Client\Order\CustomerOrderStatus as S;
use App\Repositories\Client\Order\OrderChangelogRepository;
use App\Repositories\Client\Order\OrderRepository;
use App\Services\Client\Order\InTransitPusher;
use App\Services\Client\Order\OrderFulfilmentService;

$pdo = new PDO(getenv('TFB_TEST_DSN') ?: 'mysql:unix_socket=/run/mysqld/mysqld.sock', getenv('TFB_TEST_USER') ?: 'root', getenv('TFB_TEST_PASS') ?: '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->exec('DROP DATABASE IF EXISTS erp_late_close_test'); $pdo->exec('CREATE DATABASE erp_late_close_test'); $pdo->exec('USE erp_late_close_test');
$pdo->exec('CREATE TABLE customer_order (id INT PRIMARY KEY, status INT, id_wdt INT NULL, is_freebie TINYINT NULL, expected_shipping_date DATETIME NULL, id_customer INT NULL,
    order_type VARCHAR(12) NULL, reference VARCHAR(40) NULL, update_status_date DATETIME NULL)');
$pdo->exec('CREATE TABLE customer_order_product (id INT AUTO_INCREMENT PRIMARY KEY, id_order INT, id_product INT, quantity_carton DECIMAL(10,2))');
$pdo->exec('CREATE TABLE customer_order_changelog (id INT AUTO_INCREMENT PRIMARY KEY, id_order INT, id_user INT NULL, action_type VARCHAR(20), source VARCHAR(20), changed_fields TEXT)');
// Client 1 : 10 et 11 en retard ouvertes (11 GRATIS), 12 déjà sous WDT, 13 déjà archivée, 14 le jour même, 15 plus tard, 16 sans date ; client 2 : 20 en retard.
$pdo->exec("INSERT INTO customer_order (id, status, id_wdt, is_freebie, expected_shipping_date, id_customer, reference) VALUES
    (10, 1, NULL, 0, '2026-11-10 00:00:00', 1, 'PO-10'), (11, 1, NULL, 1, '2026-11-18 00:00:00', 1, NULL), (12, 1, 77, 0, '2026-11-12 00:00:00', 1, NULL),
    (13, 5, NULL, 0, '2026-11-12 00:00:00', 1, NULL), (14, 1, NULL, 0, '2026-11-25 00:00:00', 1, NULL), (15, 1, NULL, 0, '2026-12-02 00:00:00', 1, NULL),
    (16, 1, NULL, 0, NULL, 1, NULL), (20, 1, NULL, 0, '2026-11-10 00:00:00', 2, NULL)");
$pdo->exec('INSERT INTO customer_order_product (id_order, id_product, quantity_carton) VALUES (10, 1, 5), (10, 2, 3), (11, 1, 2), (14, 1, 9)');

$fail = 0;
$check = function (bool $ok, string $label) use (&$fail) { echo ($ok ? 'ok   ' : 'FAIL ') . $label . "\n"; if (!$ok) $fail++; };
$orders = new OrderRepository($pdo);
$svc = new OrderFulfilmentService($orders, new OrderChangelogRepository($pdo), (new ReflectionClass(InTransitPusher::class))->newInstanceWithoutConstructor());
$status = fn(int $id) => (int) $pdo->query("SELECT status FROM customer_order WHERE id = {$id}")->fetchColumn();

// 2. Wyślij dzień 25.11 pour le client 1 : propositions.
$late = $orders->lateOpenOrders(1, '2026-11-25');
$check(array_column($late, 'id') === [10, 11] && (float) $late[0]['cartons'] === 8.0 && (int) $late[1]['is_freebie'] === 1 && $late[0]['reference'] === 'PO-10' && $late[0]['shipping_date'] === '2026-11-10',
    'wizard : late open orders of the customer only (10 : 8 kart., 11 GRATIS) ; WDT / archived / same day / later / undated left out');
$closed = $svc->closeLate([10, 14, 20, 12], 'shipped', '2026-11-25', 1, 'Wyślij dzień 2026-11-25 : test');
$check($closed === [10] && $status(10) === S::IN_TRANSIT && $status(14) === S::PENDING && $status(20) === S::PENDING && $status(12) === S::PENDING,
    'with the WDT : only the late open order of this customer is closed (same day 14 stays for the WDT, other customer 20 untouched)');
$log = $pdo->query('SELECT id_order, changed_fields FROM customer_order_changelog')->fetchAll();
$check(count($log) === 1 && (int) $log[0]['id_order'] === 10 && str_contains($log[0]['changed_fields'], 'Wyślij dzień 2026-11-25') && str_contains($log[0]['changed_fields'], '"status":3'),
    'order history : one entry with the reason');
$id = (int) $pdo->query('SELECT id_wdt IS NULL FROM customer_order WHERE id = 10')->fetchColumn();
$check($id === 1, 'late order not attached to the WDT (difference document of the day unchanged)');

// 1. Stan ogólny : lot, tous clients, avant le début de la semaine (23.11).
$check($svc->closeLate([11, 20, 13, 14, 16], 'archived', '2026-11-23', null, 'Stan ogólny : test') === [11, 20] && $status(11) === S::ARCHIVED && $status(20) === S::ARCHIVED
    && $status(13) === S::ARCHIVED && $status(14) === S::PENDING && $status(16) === S::PENDING, 'bulk archive : 11 and 20 ; already archived / this week / undated skipped');
$check($svc->closeLate([10, 11], 'shipped', '2026-11-23', null, 'x') === [] && $svc->closeLate([], 'shipped', '2026-11-23', null, 'x') === [], 'already closed : nothing changes twice');
$err = null; try { $svc->closeLate([15], 'delete', '2026-12-31', null, 'x'); } catch (App\Exceptions\CustomException $e) { $err = $e->getCode(); }
$check($err === 422 && $status(15) === S::PENDING, 'unknown action : 422, nothing changed');
$check($orders->lateOpenIds(['1; DROP', 0, -3, '15'], '2026-12-31') === [15], 'ids sanitised (int > 0)');

// Branchements (contrôle statique).
$src = fn(string $f) => (string) file_get_contents(__DIR__ . '/../' . $f);
$ship = $src('App/Services/Shipping/ShippingService.php');
$check(str_contains($ship, "\$this->fulfilment->closeLate(\$data['late_order_ids'], 'shipped'") && strpos($ship, 'closeLate(') > strpos($ship, 'markShippedByWdt('), 'ShippingService : late_order_ids closed after the WDT, in the same transaction');
$wiz = $src('App/Views/shipping/wizard.twig');
$check(str_contains($wiz, 'late_order_ids: c.late ? [...c.late] : []') && str_contains($wiz, 'p.late_orders') && str_contains($src('App/Controllers/Web/Shipping/ShippingController.php'), "'late_orders'"),
    'wizard : late orders shown with checkboxes (unchecked) and sent with the shipment');
$ctl = $src('App/Controllers/Web/Logistics/StockPalletController.php');
$check(str_contains($ctl, "canSee(\$this->userId(), '/client-orders')") && str_contains($ctl, '$this->stock->weekStart()')
    && str_contains($src('App/Core/Routes/Logistics/LogisticsRoutes.php'), "'POST', '/ajax/logistics/stock/orders/close'"), 'Stan ogólny endpoint : client orders right, before = production week start');
$js = $src('public/assets/js/logistics/stock-general.js');
$check(str_contains($js, '/ajax/logistics/stock/orders/close') && str_contains($js, 'window.confirm(') && str_contains($js, 'data-late-close="archived"'), 'modal : checkboxes, two buttons, confirmation');

$pdo->exec('DROP DATABASE erp_late_close_test');
echo $fail ? "\n{$fail} FAIL\n" : "\nall ok\n";
exit($fail ? 1 : 0);
