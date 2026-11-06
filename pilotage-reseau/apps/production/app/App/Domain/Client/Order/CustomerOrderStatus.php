<?php

namespace App\Domain\Client\Order;

/**
 * Canonical status dictionary for customer_order.status.
 * Status 4 exists in legacy data but its business meaning has not been defined.
 */
final class CustomerOrderStatus
{
    public const PENDING = 1;
    public const COMPLETED = 2;
    public const IN_TRANSIT = 3;
    public const UNDEFINED_LEGACY = 4;
    public const ARCHIVED = 5;

    public static function labels(): array
    {
        return [
            self::PENDING => 'Oczekujące',
            self::COMPLETED => 'Zrealizowane',
            self::IN_TRANSIT => 'W transporcie',
            self::UNDEFINED_LEGACY => 'Nieokreślony status historyczny',
            self::ARCHIVED => 'Zarchiwizowane',
        ];
    }

    public static function archivalValues(): array
    {
        return [self::COMPLETED, self::IN_TRANSIT, self::ARCHIVED];
    }
}