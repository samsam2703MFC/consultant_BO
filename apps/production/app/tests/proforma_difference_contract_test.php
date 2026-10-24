<?php
declare(strict_types=1);

require __DIR__ . '/../public/vendor/autoload.php';

use App\Services\Document\DifferenceAmountCalculator;
use App\Services\Document\ProformaSnapshotPolicy;

function expectSame(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . ' Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

// Snapshot state: only the existing paid status freezes a proforma.
expectSame(true, ProformaSnapshotPolicy::isPaid(['status' => 'paid']), 'paid proforma must be frozen.');
expectSame(true, ProformaSnapshotPolicy::isPaid(['status' => ' PAID ']), 'status comparison must be normalized.');
expectSame(false, ProformaSnapshotPolicy::isPaid(['status' => 'issued']), 'issued proforma must retain legacy mutability.');
expectSame(false, ProformaSnapshotPolicy::isPaid(['status' => 'cancelled']), 'cancelled proforma is not a received payment.');
expectSame(false, ProformaSnapshotPolicy::isPaid([]), 'missing status is not a received payment.');

// Basic scenario: paid 125.00, shipped 90.00 => 35.00 refund.
expectSame(-3500, DifferenceAmountCalculator::differenceCents('125.00', '90.00'), 'refund scenario must retain paid value.');
// Increased quantity: paid 90.00, shipped 135.00 => 45.00 surcharge.
expectSame(4500, DifferenceAmountCalculator::differenceCents('90.00', '135.00'), 'surcharge scenario must use final WDT value.');
// Equal financial total still exposes no monetary balance; product rows stay separate in the repository.
expectSame(0, DifferenceAmountCalculator::differenceCents('35.00', '35.00'), 'equal totals must not produce a monetary difference.');
// Multiple paid proformas with different prices: stored total, not rounded average price, is authoritative.
expectSame(-3500, DifferenceAmountCalculator::differenceCents('80.00', '45.00'), 'weighted-price scenario must preserve exact totals.');
expectSame('35.00', DifferenceAmountCalculator::formatCents(3500), 'positive amount formatting.');
expectSame('-35.00', DifferenceAmountCalculator::formatCents(-3500), 'negative amount formatting.');

echo "proforma_difference_contract_test: OK\n";
