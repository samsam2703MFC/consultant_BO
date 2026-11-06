<?php
declare(strict_types=1);
/*
 * Premie zespołów : próg osiągnięty (od minimum), następny próg, dni robocze do końca miesiąca.
 *
 *   php tests/team_bonus_test.php
 */
require_once __DIR__ . '/../public/vendor/autoload.php';

use App\Services\Staff\TeamBonusService as B;

function expectBonus(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException('FAILED: ' . $message);
}

$tiers = [['from' => 30000.0, 'amount' => 300.0], ['from' => 35000.0, 'amount' => 600.0], ['from' => 40000.0, 'amount' => 1000.0]];
expectBonus(B::tierFor(29999.0, 30000, $tiers) === null, 'below the minimum: no bonus');
expectBonus(B::tierFor(30000.0, 30000, $tiers)['amount'] === 300.0, 'exactly the first tier');
expectBonus(B::tierFor(37500.0, 30000, $tiers)['index'] === 1, 'between tiers: the lower one');
expectBonus(B::tierFor(52000.0, 30000, $tiers)['amount'] === 1000.0, 'above the last tier: the last one');
expectBonus(B::tierFor(36000.0, 36000, $tiers)['amount'] === 600.0, 'minimum above a tier: that tier still counts once the minimum is reached');
expectBonus(B::tierFor(35500.0, 36000, $tiers) === null, 'minimum above a tier: nothing under the minimum');
expectBonus(B::tierFor(null, 30000, $tiers) === null, 'no sales per person: no bonus');
expectBonus(B::nextTier(31800.0, 30000, $tiers)['from'] === 35000.0, 'next tier above the projection');
expectBonus(B::nextTier(41000.0, 30000, $tiers) === null, 'top tier reached: no next tier');
expectBonus(B::nextTier(null, 30000, $tiers)['from'] === 30000.0, 'no projection: first tier');
expectBonus(B::nextTier(10000.0, 32000, $tiers)['from'] === 35000.0, 'tiers under the minimum are skipped');
expectBonus(B::workingDaysAfter(new DateTimeImmutable('2026-10-27')) === 3, 'Tue 27/10/2026: Wed, Thu, Fri left');
expectBonus(B::workingDaysAfter(new DateTimeImmutable('2026-10-30')) === 0, 'Fri 30/10/2026: weekend left');
expectBonus(B::workingDaysAfter(new DateTimeImmutable('2026-02-01')) === 20, 'Sun 01/02/2026: 20 working days left');

echo "team_bonus_test: OK\n";
