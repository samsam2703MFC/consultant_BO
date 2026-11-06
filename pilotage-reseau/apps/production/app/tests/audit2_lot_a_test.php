<?php
declare(strict_types=1);
/*
 * Audit 2, lot A : règles de saisie communes, sans base de données.
 *  - produit : 0 opakowanie autorisé à la création (vide = 0), durée de conservation 1–3650 jours partout ;
 *  - client : société et NIP obligatoires, e-mails vérifiés (plusieurs adresses), délai de paiement en jours ;
 *  - paramètres : une seule règle (bornes, alerte urgente ≥ alerte, stock max ≥ min).
 *
 *   php tests/audit2_lot_a_test.php
 */
require_once __DIR__ . '/../public/vendor/autoload.php';

use App\Domain\Params\ParamRules;
use App\Domain\Product\ProductLogistics;
use App\Services\Client\ClientService;
use App\Services\Product\ProductService;

function expectLotA(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException('FAILED: ' . $message);
}

// --- Produit (formulaire « Tylko produkt » et assistant : ProductService::validationErrors)
$products = (new ReflectionClass(ProductService::class))->newInstanceWithoutConstructor();
$product = ['name' => 'Bagietka', 'id_category' => '1', 'id_section' => '1', 'weight' => '250', 'ppc' => '20', 'ppb' => '', 'bpc' => '0',
    'cpl' => '8', 'lpp' => '6', 'cpp' => '48', 'shelf_life' => '90', 'stock_minimum' => ''];
expectLotA($products->validationErrors($product) === [], 'product without packaging (empty / 0) accepted at creation, like the Karta');
expectLotA(isset($products->validationErrors(['weight' => '0'] + $product)['weight']), 'weight still required (> 0)');
expectLotA(isset($products->validationErrors(['ppb' => '-1'] + $product)['ppb']), 'negative packaging refused');
expectLotA(isset($products->validationErrors(['shelf_life' => ''] + $product)['shelf_life']) && isset($products->validationErrors(['shelf_life' => '0'] + $product)['shelf_life'])
    && isset($products->validationErrors(['shelf_life' => '3651'] + $product)['shelf_life']), 'shelf life required, 1–3650 days');
expectLotA(ProductLogistics::SHELF_LIFE === [1, 3650] && !in_array('ppb', ProductLogistics::REQUIRED_NEW, true) && !in_array('bpc', ProductLogistics::REQUIRED_NEW, true)
    && in_array('cpp', ProductLogistics::REQUIRED_NEW, true), 'one rule shared with the Karta (ProductLogistics)');

// --- Client
$clients = (new ReflectionClass(ClientService::class))->newInstanceWithoutConstructor();
$client = ['company' => 'Boulangerie Test', 'tax_number' => 'BE0123456789', 'fac_street' => 'Rue', 'fac_street_num' => '1', 'fac_zip' => '1000',
    'fac_town' => 'Bruxelles', 'id_country' => '2', 'email_head' => 'a@shop.be', 'email_accountant' => 'compta@shop.be; bureau@shop.be', 'payment_termin' => '14'];
expectLotA($clients->validate($client) === [], 'valid client: owner name optional, several accounting addresses');
$e = $clients->validate(['company' => '', 'tax_number' => ''] + $client);
expectLotA(isset($e['company'], $e['tax_number']), 'company and VAT number required');
$e = $clients->validate(['email_salesman' => 'pas-une-adresse', 'payment_termin' => '400'] + $client);
expectLotA(isset($e['email_salesman']) && str_contains($e['email_salesman'], 'pas-une-adresse') && isset($e['payment_termin']), 'e-mails checked, payment term in days 0–365');
expectLotA($clients->validate(['payment_termin' => ''] + $client) === [] && ClientService::invalidEmails('a@b.pl, x') === ['x'], 'empty payment term allowed ; invalid address listed');

// --- Paramètres
expectLotA(ParamRules::rangeError('alerts.cost_due_day', '29') !== null && ParamRules::rangeError('alerts.cost_due_day', '10') === null
    && ParamRules::rangeError('stock.suggest_max_weeks', '2,5') === null && ParamRules::rangeError('unknown.code', 'x') === null, 'ranges per code');
$current = fn(string $code) => ['alerts.complaint_days' => '3', 'alerts.complaint_escalate_days' => '7', 'stock.suggest_min_weeks' => '1', 'stock.suggest_max_weeks' => '3'][$code] ?? null;
expectLotA(array_keys(ParamRules::orderErrors(['alerts.complaint_escalate_days' => '2'], $current)) === ['alerts.complaint_escalate_days'], 'urgent alert before the alert refused');
expectLotA(array_keys(ParamRules::orderErrors(['alerts.complaint_days' => '8'], $current)) === ['alerts.complaint_days'], 'alert moved after the urgent one refused');
expectLotA(ParamRules::orderErrors(['alerts.complaint_days' => '8', 'alerts.complaint_escalate_days' => '10'], $current) === [], 'both changed together: final state checked');
expectLotA(ParamRules::orderErrors(['stock.suggest_max_weeks' => '0,5'], $current)['stock.suggest_max_weeks'][0] === 'PARAM_MAX_BELOW_MIN', 'stock max below min refused');

echo "audit2_lot_a_test: OK\n";
