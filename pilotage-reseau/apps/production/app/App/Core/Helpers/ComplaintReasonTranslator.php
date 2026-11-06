<?php

namespace App\Core\Helpers;

/**
 * Tłumaczenie kluczy powodów reklamacji na język polski.
 *
 * Klucze obsługiwane:
 *  - size_or_weight_issue
 *  - broken_packaging
 *  - incorrect_information
 *  - lack_of_conformity
 *  - incorrect_quantity_of_products_in_package
 *  - product_quality
 *  - other
 */
final class ComplaintReasonTranslator
{
    /**
     * @var array<string, string>
     */
    private const TRANSLATIONS = [
        'size_or_weight_issue'                      => 'Problem z rozmiarem lub wagą',
        'broken_packaging'                          => 'Uszkodzone opakowanie',
        'incorrect_information'                     => 'Nieprawidłowe informacje na produkcie',
        'lack_of_conformity'                        => 'Brak zgodności z zamówieniem',
        'incorrect_quantity_of_products_in_package' => 'Nieprawidłowa ilość produktów w opakowaniu',
        'product_quality'                           => 'Jakość produktu',
        'delivery_issue'                            => 'Problem z dostawą',
        'transport_damage'                          => 'Źle zabezpieczony transport',
        'driver_issue'                              => 'Problem z kierowcą',
        'delivery_delay'                            => 'Opóźnienie dostawy',
        'other'                                     => 'Inne',
    ];

    /**
     * Zwraca polskie tłumaczenie klucza powodu reklamacji.
     * Jeśli klucz jest nieznany, zwraca oryginalną wartość.
     *
     * @param string|null $key
     * @param string      $fallback  Wartość zwracana gdy klucz jest null lub pusty
     */
    public static function translate(?string $key, string $fallback = '-'): string
    {
        if ($key === null || $key === '') {
            return $fallback;
        }

        return self::TRANSLATIONS[$key] ?? $key;
    }

    /**
     * Zwraca pełną mapę kluczy => tłumaczeń.
     *
     * @return array<string, string>
     */
    public static function all(): array
    {
        return self::TRANSLATIONS;
    }

    /**
     * Sprawdza, czy dany klucz jest obsługiwany.
     */
    public static function isKnown(?string $key): bool
    {
        return $key !== null && array_key_exists($key, self::TRANSLATIONS);
    }
}
