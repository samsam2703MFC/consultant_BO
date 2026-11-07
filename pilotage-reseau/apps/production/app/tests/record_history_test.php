<?php
declare(strict_types=1);
/*
 * Usuwanie z historią : produkt, klient lub surowiec z zamówieniami, dokumentami czy recepturami nie jest usuwany
 * (komunikat z liczbami) ; bez historii usuwanie działa jak dotąd.
 *
 *   php tests/record_history_test.php
 *
 * Base : TFB_TEST_DSN (défaut mysql:unix_socket=/run/mysqld/mysqld.sock), TFB_TEST_USER (root),
 * TFB_TEST_PASS (vide) ; structures recopiées depuis TFB_TEST_SOURCE_DB (défaut erp). La base
 * erp_history_test est recréée puis supprimée.
 */
require_once __DIR__ . '/../public/vendor/autoload.php';

use App\Exceptions\CustomException;
use App\Repositories\Material\MaterialRepository;
use App\Repositories\Material\NutritionRepository;
use App\Repositories\Product\FinalProductRepository;
use App\Repositories\Utils\RecordHistoryRepository;
use App\Services\Material\MaterialService;
use App\Services\Product\ProductService;

function expectHistory(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException('FAILED: ' . $message);
}

$dsn = getenv('TFB_TEST_DSN') ?: 'mysql:unix_socket=/run/mysqld/mysqld.sock';
$source = getenv('TFB_TEST_SOURCE_DB') ?: 'erp';
$testDb = 'erp_history_test';
try {
    $pdo = new PDO($dsn . ';charset=utf8mb4', getenv('TFB_TEST_USER') ?: 'root', getenv('TFB_TEST_PASS') ?: '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    echo "record_history_test: SKIPPED (no database: {$e->getMessage()})\n";
    exit(0);
}

$pdo->exec("DROP DATABASE IF EXISTS {$testDb}");
$pdo->exec("CREATE DATABASE {$testDb} CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
try {
    // complaint, document_invoice… absentes : la requête est ignorée, sans bloquer.
    foreach (['final_product', 'raw_material', 'customer_order', 'customer_order_product', 'document_wdt', 'document_wdt_product',
                 'carton_stock', 'product_recipe_connection', 'n_po_line'] as $table) {
        $pdo->exec("CREATE TABLE {$testDb}.{$table} LIKE {$source}.{$table}");
    }
    $pdo->exec("USE {$testDb}");
    $pdo->exec("SET SESSION sql_mode = ''"); // fixtures : colonnes non utilisées ici à leur valeur implicite
    $pdo->exec("INSERT INTO final_product (id, name, id_category, active) VALUES (1, 'Croissant', 1, 1), (2, 'Nowy', 1, 1)");
    $pdo->exec("INSERT INTO customer_order_product (id_order, id_product, quantity_carton) VALUES (10, 1, 5), (11, 1, 3)");
    $pdo->exec("INSERT INTO document_wdt_product (id_document_wdt, id_product, name, quantity, price_carton) VALUES (7, 1, 'Croissant', 5, 40)");
    $pdo->exec("INSERT INTO carton_stock (id_carton, batch_number, id_product, status, date_add) VALUES (1, 1, 1, 1, CURDATE())");
    $pdo->exec("INSERT INTO raw_material (id, name) VALUES (100, 'Mąka'), (101, 'Sól')");
    $pdo->exec("INSERT INTO product_recipe_connection (parent_recipe_id, child_recipe_id, child_ingredient_id, quantity) VALUES (5, NULL, 100, 1000)");

    $history = new RecordHistoryRepository($pdo);
    expectHistory($history->product(1) === ['zamówienia' => 2, 'WDT' => 1, 'kartony w magazynie' => 1], 'historia produktu : zamówienia, WDT, magazyn');
    expectHistory($history->product(2) === [], 'nowy produkt bez historii');
    expectHistory(RecordHistoryRepository::describe(['zamówienia' => 2, 'WDT' => 1]) === '2 zamówienia, 1 WDT', 'opis do komunikatu');

    $refused = function (callable $fn, string $needle): bool {
        try { $fn(); return false; } catch (CustomException $e) { return str_contains($e->getMessage(), $needle); }
    };
    $products = new ProductService(new FinalProductRepository($pdo), $history);
    expectHistory($refused(fn() => $products->delete(1), '2 zamówienia, 1 WDT, 1 kartony w magazynie'), 'produkt z historią nie jest usuwany');
    expectHistory((int) $pdo->query('SELECT COUNT(*) FROM final_product WHERE id = 1')->fetchColumn() === 1, 'produkt z historią zostaje');
    $products->delete(2);
    expectHistory((int) $pdo->query('SELECT COUNT(*) FROM final_product WHERE id = 2')->fetchColumn() === 0, 'produkt bez historii usunięty');

    $materials = new MaterialService(new MaterialRepository($pdo), new NutritionRepository($pdo), $history);
    expectHistory($refused(fn() => $materials->delete(100), '1 receptury'), 'surowiec w recepturze nie jest usuwany');
    $materials->delete(101);
    expectHistory((int) $pdo->query('SELECT COUNT(*) FROM raw_material WHERE id = 101')->fetchColumn() === 0, 'surowiec bez użycia usunięty');

    // Klient : te same zapytania (zamówienia, WDT) — liczby bez usuwania.
    $pdo->exec("INSERT INTO customer_order (id, id_customer) VALUES (10, 3)");
    $pdo->exec("INSERT INTO document_wdt (id, doc_num, id_customer) VALUES (7, 'W/7', 3)");
    expectHistory($history->customer(3) === ['zamówienia' => 1, 'WDT' => 1], 'historia klienta : zamówienia i WDT');
    expectHistory($history->customer(4) === [], 'klient bez historii');
} finally {
    $pdo->exec("DROP DATABASE IF EXISTS {$testDb}");
}

echo "record_history_test: OK\n";
