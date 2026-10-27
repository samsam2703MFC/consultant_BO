<?php
declare(strict_types=1);

require __DIR__ . '/../public/vendor/autoload.php';

use App\Services\Document\ProformaContentComparator;

function expectOrderProforma(bool $expected, bool $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message);
    }
}

$order = [
    ['id_product' => 10, 'quantity_carton' => 10, 'purchase_price' => '35.00'],
    ['id_product' => 20, 'quantity_carton' => 2, 'purchase_price' => '45.00'],
];
$snapshot = [
    ['id_product' => 10, 'quantity' => 10, 'price_per_carton' => '35.0000'],
    ['id_product' => 20, 'quantity' => 2, 'price_per_carton' => '45'],
];

expectOrderProforma(false, ProformaContentComparator::differs($order, $snapshot), 'Identical order and snapshot must not be stale.');
expectOrderProforma(false, ProformaContentComparator::quantitiesDiffer($order, $snapshot), 'Identical quantities must not be marked as different.');
$order[0]['quantity_carton'] = 15;
expectOrderProforma(true, ProformaContentComparator::differs($order, $snapshot), 'Quantity change must make an unpaid proforma stale.');
expectOrderProforma(true, ProformaContentComparator::quantitiesDiffer($order, $snapshot), 'Quantity change must be shown in the order list.');
$order[0]['quantity_carton'] = 10;
$order[1]['purchase_price'] = '46.00';
expectOrderProforma(true, ProformaContentComparator::differs($order, $snapshot), 'Transaction price change must make an unpaid proforma stale.');
expectOrderProforma(false, ProformaContentComparator::differs($order, [
    ['id_product' => 10, 'quantity' => 10, 'price_per_carton' => 0],
    ['id_product' => 20, 'quantity' => 2, 'price_per_carton' => 0],
], true), 'Freebie comparison must use zero document prices.');

echo "order_proforma_edit_contract_test: OK\n";
