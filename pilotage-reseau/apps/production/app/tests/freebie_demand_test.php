<?php
/**
 * [408] Garde-fou (audit du 23.11.2026 : le gratis 1527 « Brownies » manquait dans la feuille d'équipe). Une commande
 * GRATIS est produite, préparée, chargée et expédiée comme les autres (la WDT la ferme avec les autres commandes du
 * client et du jour) : elle compte dans toutes les QUANTITÉS physiques. Elle reste hors des VALEURS et statistiques
 * commerciales (rentabilité, trésorerie, OTIF, prévisions de ventes, clients sans commande, moyennes de commande,
 * document de différences).
 * Contrôle statique de tout App/ : seuls les fichiers de la liste « commerciale » peuvent exclure les gratuits
 * (OpenOrders::quantitySql ou is_freebie = 0) ; les fichiers « physiques » utilisent OpenOrders::demandSql.
 */
declare(strict_types=1);
require_once __DIR__ . '/../public/vendor/autoload.php';

use App\Domain\Client\Order\OpenOrders;

$fail = 0;
$check = function (bool $ok, string $label) use (&$fail) { echo ($ok ? 'ok   ' : 'FAIL ') . $label . "\n"; if (!$ok) $fail++; };

// Règles SQL.
$check(!str_contains(OpenOrders::demandSql('co'), 'is_freebie') && OpenOrders::demandSql('co') === OpenOrders::sql('co'), 'demandSql : open orders, freebies included');
$check(str_contains(OpenOrders::quantitySql('co'), 'is_freebie'), 'quantitySql : values / commercial statistics, freebies excluded');
$check(!str_contains(OpenOrders::loadingSql('co'), 'is_freebie'), 'loadingSql : the freebie is on the same truck (cartons, pallets, weight)');

// Où l'exclusion des gratuits est permise (valeurs, statistiques commerciales, suivi client) — tout le reste compte les gratuits.
$commercial = [
    'App/Domain/Client/Order/OpenOrders.php',
    'App/Repositories/Dashboard/ProfitabilityRepository.php',        // valeur des commandes ouvertes (EUR)
    'App/Repositories/Finances/LiquidityCheckRepository.php',        // valeur des commandes (płynność)
    'App/Repositories/Client/Order/OtifWeekRepository.php',          // OTIF : engagements commerciaux
    'App/Repositories/Client/Order/CustomersNoOrderRepository.php',  // clients sans commande
    'App/Repositories/Client/Order/OrderDayCheckRepository.php',     // moyenne de commande du client
    'App/Repositories/Forecast/ForecastRepository.php',              // historique des ventes (un gratis = renvoi, pas une vente)
    'App/Repositories/Shipping/Difference/OrderShippingDifferenceProductRepository.php', // document de différences
    'App/Repositories/Logistics/DeliveryDelaysRepository.php',       // suivi client : le gratis part avec ses commandes
];
$root = realpath(__DIR__ . '/..');
$excluding = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/App', FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    if ($f->getExtension() !== 'php') continue;
    $src = (string) file_get_contents($f->getPathname());
    if (str_contains($src, 'OpenOrders::quantitySql(') || preg_match('/is_freebie\s*,\s*0\)\s*=\s*0|is_freebie\s*=\s*0/i', $src)) {
        $excluding[] = substr($f->getPathname(), strlen($root) + 1);
    }
}
sort($excluding);
$extra = array_values(array_diff($excluding, $commercial));
$check($extra === [], 'only commercial files exclude freebies' . ($extra ? ' — also : ' . implode(', ', $extra) : ''));

// Les quantités physiques passent par demandSql.
$physical = [
    'App/Repositories/Workshop/PlanningRepository.php' => 2,   // plan de la semaine, feuille d'équipe (+ fenêtre clients)
    'App/Repositories/Workshop/ItemRepository.php' => 2,       // Stan magazynu (zamówione)
    'App/Repositories/Workshop/OutputRepository.php' => 1,     // production vs commandes
    'App/Repositories/Logistics/StockPalletRepository.php' => 2, // stan ogólny + [430] fenêtre « Zamówione »
    'App/Repositories/Dashboard/TodayRepository.php' => 2,     // manquants du jour, produits sous le minimum
];
foreach ($physical as $file => $n) {
    $src = (string) file_get_contents($root . '/' . $file);
    // [429] Lire is_freebie pour l'afficher (part de GRATIS des retards) reste permis ; l'exclure ne l'est pas.
    $check(substr_count($src, 'OpenOrders::demandSql(') === $n && !preg_match('/is_freebie\s*,\s*0\)\s*=\s*0|is_freebie\s*=\s*0|OpenOrders::quantitySql\(/i', $src), "{$file} : {$n} × demandSql, no freebie filter");
}
$check(!str_contains((string) file_get_contents($root . '/App/Repositories/Logistics/LogisticsPlanRepository.php'), 'is_freebie'), 'LogisticsPlanRepository : freebies loaded (pallets / trucks of 13 weeks)');
$check(!str_contains((string) file_get_contents($root . '/App/Repositories/Production/ProductionListRepository.php'), 'is_freebie'), 'old Lista produkcyjna : selected orders, freebies included');
$check(!preg_match("/'freebie'\s*=>\s*'gratis/", (string) file_get_contents($root . '/App/Services/Organization/Wizards/SupplementsDayCheckService.php')), 'Domówienia dnia : no « freebie outside production » state anymore');

echo $fail ? "\n{$fail} FAIL\n" : "\nall ok\n";
exit($fail ? 1 : 0);
