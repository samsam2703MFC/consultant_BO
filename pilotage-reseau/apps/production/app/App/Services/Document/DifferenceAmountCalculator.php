<?php
declare(strict_types=1);

namespace App\Services\Document;

/** Exact cent-based arithmetic for difference-document totals. */
final class DifferenceAmountCalculator
{
    public static function toCents(string|int|float|null $amount): int
    {
        $value = trim((string) ($amount ?? '0'));
        if (!preg_match('/^(-?)(\d+)(?:\.(\d{1,2}))?$/', $value, $matches)) {
            return 0;
        }

        $cents = ((int) $matches[2]) * 100 + (int) str_pad($matches[3] ?? '', 2, '0');
        return $matches[1] === '-' ? -$cents : $cents;
    }

    public static function formatCents(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $cents = abs($cents);
        return sprintf('%s%d.%02d', $sign, intdiv($cents, 100), $cents % 100);
    }

    public static function differenceCents(string|int|float|null $paid, string|int|float|null $shipped): int
    {
        return self::toCents($shipped) - self::toCents($paid);
    }
}
