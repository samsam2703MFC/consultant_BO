<?php
declare(strict_types=1);
/*
 * [296] Stany i palety (makieta 53) : magazyn par groupe, parties à date courte, palettes en préparation / prêtes,
 * palettes parties par jour (camion, départ, livraison), composition et couches d'une palette, suivi d'une partie
 * (produit + jour d'étiquette), carton déplacé compté sur sa dernière palette, filtre client, recherche « P-123 ».
 *
 *   php tests/stock_pallets_test.php
 *
 * Base : TFB_TEST_DSN (défaut mysql:unix_socket=/run/mysqld/mysqld.sock), TFB_TEST_USER (root), TFB_TEST_PASS (vide) ;
 * structures recopiées depuis TFB_TEST_SOURCE_DB (défaut erp). La base erp_stock_pallets_test est recréée puis supprimée.
 */
require_once __DIR__ . '/../public/vendor/autoload.php';

use App\Repositories\Logistics\StockPalletRepository;
use App\Services\Logistics\StockPalletService;

function expectSp(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException('FAILED: ' . $message);
}

$dsn = getenv('TFB_TEST_DSN') ?: 'mysql:unix_socket=/run/mysqld/mysqld.sock';
$source = getenv('TFB_TEST_SOURCE_DB') ?: 'erp';
$testDb = 'erp_stock_pallets_test';
try {
    $pdo = new PDO($dsn . ';charset=utf8mb4', getenv('TFB_TEST_USER') ?: 'root', getenv('TFB_TEST_PASS') ?: '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    echo "stock_pallets_test: SKIPPED (no database: {$e->getMessage()})\n";
    exit(0);
}

$pdo->exec("DROP DATABASE IF EXISTS {$testDb}");
$pdo->exec("CREATE DATABASE {$testDb} CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
try {
    foreach (['final_product', 'production_sections', 'carton_stock', 'carton_pallet_connection', 'palets_delivery', 'customer',
                 'n_shipping_assignment', 'carrier_vehicle', 'n_delivery_run', 'n_delivery_stop'] as $table) {
        $pdo->exec("CREATE TABLE {$testDb}.{$table} LIKE {$source}.{$table}");
    }
    $pdo->exec("USE {$testDb}");
    $pdo->exec("SET SESSION sql_mode = ''");
    $pdo->exec("INSERT INTO production_sections (id, name) VALUES (1, 'Piekarnia'), (3, 'Tarty')");
    $pdo->exec("INSERT INTO final_product (id, name, id_section, active) VALUES (900, 'Croissant 60 g', 1, 1), (901, 'Bagietka', 1, 1), (902, 'Tarta cytrynowa', 3, 1), (903, 'Quiche', 3, 1)");
    $pdo->exec("INSERT INTO customer (id, company) VALUES (51, 'Klient A'), (52, 'Klient B'), (53, 'Klient C')");
    $pdo->exec("INSERT INTO carrier_vehicle (id, id_carrier, regis_num) VALUES (7, 1, 'WGM TEST1')");
    // Palettes : 1 en préparation (A, aujourd'hui), 2 prête (B, demain), 3 partie (C, 10.11), 4 partie (A, 10.11), 5 partie il y a 2 mois.
    $pdo->exec("INSERT INTO palets_delivery (id_palet, client, delivery_date, status, id_customer) VALUES
        (1, '', '2026-11-16', 1, 51), (2, '', '2026-11-17', 2, 52), (3, '', '2026-11-10', 3, 53), (4, '', '2026-11-10', 3, 51), (5, '', '2026-09-01', 3, 52)");
    // Partie croissant du 05.11 : 2 au magasin, 1 sur la palette 2 (prête), 3 partis sur 3, 1 parti sur 4 (déplacé de la palette 3).
    $pdo->exec("INSERT INTO carton_stock (id_carton, batch_number, id_product, expiration_date, status, date_add, date_pallet) VALUES
        (1, 10001, 900, '2026-12-20', 1, '2026-11-05', NULL),
        (2, 10001, 900, '2026-12-20', 1, '2026-11-05', NULL),
        (3, 10001, 900, '2026-12-20', 2, '2026-11-05', '2026-11-15 14:00:00'),
        (4, 10001, 900, '2026-12-20', 3, '2026-11-05', '2026-11-09 10:00:00'),
        (5, 10001, 900, '2026-12-20', 3, '2026-11-05', '2026-11-09 10:05:00'),
        (6, 10001, 900, '2026-12-20', 3, '2026-11-05', '2026-11-09 10:10:00'),
        (7, 10001, 900, '2026-12-20', 3, '2026-11-05', '2026-11-09 11:00:00'),
        (8, 10001, 901, '2027-03-01', 2, '2026-11-14', '2026-11-16 08:10:00'),
        (9, 10001, 902, '2027-02-01', 1, '2026-11-12', NULL),
        (10, 10001, 903, '2000-01-01', 2, '2026-11-12', '2026-11-16 08:30:00'),
        (11, 10000, 903, '2026-08-01', 3, '2026-08-25', '2026-08-30 09:00:00')");
    $pdo->exec("INSERT INTO carton_pallet_connection (id_key, id_palet, id_carton) VALUES
        (1, 2, 3), (2, 3, 4), (3, 3, 5), (4, 3, 6), (5, 3, 7), (6, 4, 7), (7, 1, 8), (8, 1, 10), (9, 5, 11)");
    // Tablette chauffeur : camion WGM TEST1 pour C le 10.11, départ 06:40, C livré 15:10.
    $pdo->exec("INSERT INTO n_shipping_assignment (ship_date, id_customer, id_carrier_vehicle) VALUES ('2026-11-10', 53, 7)");
    $pdo->exec("INSERT INTO n_delivery_run (ship_date, id_carrier_vehicle, started_at) VALUES ('2026-11-10', 7, '2026-11-10 06:40:00')");
    $pdo->exec("INSERT INTO n_delivery_stop (ship_date, id_customer, status, recorded_at) VALUES ('2026-11-10', 53, 'delivered', '2026-11-10 15:10:00')");

    $svc = new StockPalletService(new StockPalletRepository($pdo));
    $today = new DateTimeImmutable('2026-11-16');
    $o = $svc->overview('', null, null, null, $today);

    // --- Magazyn
    $groups = array_column($o['groups'], null, 'name');
    expectSp($o['in_store'] === 3 && $groups['Piekarnia']['in_store'] === 2 && $groups['Piekarnia']['on_pallet'] === 2 && $groups['Tarty']['in_store'] === 1,
        'magasin : 3 cartons (Piekarnia 2 + 2 sur palette, Tarty 1)');
    expectSp(abs($groups['Piekarnia']['share'] - 66.7) < 0.1, 'part du magasin par groupe (barre)');
    expectSp(count($o['expiring']) === 0, 'aucune partie à moins de 30 jours (date 2000-01-01 ignorée)');
    $soon = $svc->overview('', null, null, null, new DateTimeImmutable('2026-11-25'));
    expectSp(count($soon['expiring']) === 1 && (int) $soon['expiring'][0]['id_product'] === 900 && $soon['expiring'][0]['made_on'] === '2026-11-05'
        && (int) $soon['expiring'][0]['cartons'] === 3, 'croissant du 05.11 (20.12) signalé à moins de 30 jours : 3 cartons chez nous');

    // --- Palettes chez nous
    expectSp(array_column($o['building'], 'code') === ['P-1'] && $o['building'][0]['cartons'] === 2 && $o['building'][0]['products'] === 2,
        'en préparation : P-1, 2 cartons, 2 produits');
    expectSp($o['building'][0]['first_at'] === '2026-11-16 08:10:00', 'en préparation : premier carton posé');
    expectSp(array_column($o['ready'], 'code') === ['P-2'] && $o['ready'][0]['customer'] === 'Klient B', 'prête : P-2 (Klient B)');
    expectSp($o['building_cartons'] === 2 && $o['ready_cartons'] === 1, 'cartons des colonnes');

    // --- Palettes parties (31 jours) : le 10.11 seulement, camion et départ de la tablette chauffeur
    expectSp($o['shipped']['total'] === 2 && $o['shipped']['customers'] === 2 && count($o['shipped']['days']) === 1, 'parties : 2 palettes, 2 clients, 1 jour (septembre exclu)');
    $d = $o['shipped']['days'][0];
    expectSp($d['date'] === '2026-11-10' && $d['vehicles'] === ['WGM TEST1'] && $d['left_at'] === '2026-11-10 06:40:00', 'jour : camion et heure de départ');
    $byName = array_column($d['customers'], null, 'name');
    expectSp($byName['Klient C']['delivered_at'] === '2026-11-10 15:10:00' && $byName['Klient A']['delivered_at'] === null, 'livraison confirmée chez C');

    // --- Composition d'une palette : carton 7 déplacé de 3 vers 4 compte sur 4
    $p3 = $svc->overview('', 3, null, null, $today)['pallet'];
    expectSp($p3['cartons'] === 3 && $p3['lines'][0]['cartons'] === 3 && $p3['lines'][0]['made_on'] === '2026-11-05', 'P-3 : 3 cartons (le 4e est passé sur P-4)');
    expectSp($p3['vehicle'] === 'WGM TEST1' && array_column($p3['steps'], 'at') === ['2026-11-09 10:00:00', '2026-11-09 10:10:00', '2026-11-10 06:40:00', '2026-11-10 15:10:00'],
        'P-3 : commencée, fermée, départ, livrée');
    $p1 = $svc->pallet(1);
    expectSp($p1['steps'][1]['at'] === null, 'palette en préparation : pas encore fermée');
    $p1lines = array_column($p1['lines'], null, 'name');
    expectSp($p1lines['Quiche']['expires'] === null && $p1lines['Bagietka']['expires'] === '2027-03-01', 'date limite par défaut (2000-01-01) affichée vide');
    expectSp($svc->overview('', 999, null, null, $today)['pallet'] === null, 'palette inconnue : rien');

    // --- Couches : 5 produits + « N innych »
    $lines = [];
    foreach (range(1, 7) as $i) $lines[] = ['id_product' => $i, 'name' => "P{$i}", 'cartons' => 8 - $i];
    $layers = StockPalletService::layers($lines);
    expectSp(count($layers) === 6 && $layers[0]['name'] === 'P1' && $layers[5]['name'] === '2 innych' && $layers[5]['cartons'] === 3, 'couches : 5 + « 2 innych »');
    expectSp(abs(array_sum(array_column($layers, 'pct')) - 100) < 0.5, 'couches : hauteurs = 100 %');

    // --- Recherche : « P-2 » / « 2 » → palette ; texte → filtre client
    expectSp($svc->overview('P-2', null, null, null, $today)['pallet']['id'] === 2 && $svc->overview('2', null, null, null, $today)['pallet']['id'] === 2, 'recherche P-2 et 2');
    $f = $svc->overview('klient b', null, null, null, $today);
    expectSp($f['client'] === 'klient b' && count($f['building']) === 0 && count($f['ready']) === 1 && $f['shipped']['total'] === 0, 'filtre client sur les palettes');

    // --- Suivi de partie : croissant du 05.11
    $t = $svc->overview('', null, 900, '2026-11-05', $today)['trace'];
    expectSp($t['day']['cartons'] === 7 && $t['day']['in_store'] === 2, 'partie : 7 cartons, 2 au magasin');
    $tp = array_column($t['pallets'], null, 'code');
    expectSp(count($tp) === 3, 'partie : 3 palettes');
    expectSp($tp['P-3']['lot_cartons'] === 3 && $tp['P-4']['lot_cartons'] === 1 && $tp['P-2']['lot_cartons'] === 1, 'cartons de la partie par palette (dernière palette)');
    expectSp($tp['P-3']['left_at'] === '2026-11-10 06:40:00' && $tp['P-3']['delivered_at'] === '2026-11-10 15:10:00', 'partie : départ et livraison chez C');
    sort($t['customers']);
    expectSp($t['customers'] === ['Klient A', 'Klient C'], 'partie partie chez A et C (B : palette encore chez nous)');
    $last = $svc->trace(903);
    expectSp($last['made_on'] === '2026-11-12' && count($last['days']) === 2, 'sans jour : le dernier jour de fabrication');
    expectSp($svc->trace(904) === null, 'produit inconnu : rien');
    expectSp($svc->trace(900, '2026-01-01')['day'] === null, 'jour sans cartons : rien à suivre');

    echo "stock_pallets_test: OK\n";
} finally {
    $pdo->exec("DROP DATABASE IF EXISTS {$testDb}");
}
