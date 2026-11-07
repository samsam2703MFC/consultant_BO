<?php

namespace App\Core\Helpers;

/**
 * Prosty mapper jednostek.
 *
 * Mapowanie (EN skróty):
 *  1 => kg
 *  2 => L
 *  3 => g
 *  4 => ml
 *  5 => pcs
 */
final class UnitMapper
{
    /**
     * @var array<int,string>
     */
    private const ID_TO_CODE = [
        1 => 'kg',
        2 => 'L',
        3 => 'g',
        4 => 'ml',
        5 => 'pcs',
    ];

    /**
     * Zwraca skrót jednostki (EN) dla podanego ID.
     *
     * @param int|null $unitId
     */
    public static function codeFromId(?int $unitId): ?string
    {
        if ($unitId === null) {
            return null;
        }

        return self::ID_TO_CODE[$unitId] ?? null;
    }

    /**
     * Zwraca ID jednostki dla podanego skrótu (EN).
     *
     * Uwaga: dopuszcza różne wielkości liter, np. 'l' => 2.
     */
    public static function idFromCode(?string $unitCode): ?int
    {
        if ($unitCode === null) {
            return null;
        }

        $normalized = self::normalizeCode($unitCode);
        if ($normalized === null) {
            return null;
        }

        foreach (self::ID_TO_CODE as $id => $code) {
            if (self::normalizeCode($code) === $normalized) {
                return $id;
            }
        }

        return null;
    }

    /**
     * Zwraca pełną mapę ID => skrót.
     *
     * @return array<int,string>
     */
    public static function idToCodeMap(): array
    {
        return self::ID_TO_CODE;
    }

    public static function isValidId(?int $unitId): bool
    {
        return $unitId !== null && array_key_exists($unitId, self::ID_TO_CODE);
    }

    public static function isValidCode(?string $unitCode): bool
    {
        return self::idFromCode($unitCode) !== null;
    }

    private static function normalizeCode(string $unitCode): ?string
    {
        $unitCode = trim($unitCode);
        if ($unitCode === '') {
            return null;
        }

        // zachowujemy 'L' jako canonical, ale do porównań sprowadzamy do lower
        return mb_strtolower($unitCode);
    }

    /**
     * Zwraca współczynnik przeliczenia ilości na jednostkę "wyświetlaną".
     *
     * Przykład:
     *  - g  => 0.001 (pokazujemy w kg)
     *  - ml => 0.001 (pokazujemy w L)
     *  - kg/L/pcs => 1
     */
    public static function displayFactorFromId(?int $unitId): float
    {
        $code = self::codeFromId($unitId);
        if ($code === null) {
            return 1.0;
        }

        return match (mb_strtolower($code)) {
            'g', 'ml' => 0.001,
            default => 1.0,
        };
    }

    /**
     * Wariant displayFactor: dla g/ml przeliczamy na kg/L tylko jeśli wartość (raw) > 1000.
     * Dla mniejszych wartości zostawiamy "g" / "ml" bez dzielenia.
     */
    public static function conditionalDisplayFactorFromId(?int $unitId, $rawValue): float
    {
        $code = self::codeFromId($unitId);
        if ($code === null) {
            return 1.0;
        }

        $lc = mb_strtolower($code);
        if (($lc === 'g' || $lc === 'ml') && is_numeric($rawValue) && ((float)$rawValue) > 1000) {
            return 0.001;
        }

        return 1.0;
    }

    /**
     * Zwraca kod jednostki w wersji "wyświetlanej" po przeliczeniu.
     *
     * Przykład:
     *  - g  => kg
     *  - ml => L
     */
    public static function displayCodeFromId(?int $unitId): ?string
    {
        $code = self::codeFromId($unitId);
        if ($code === null) {
            return null;
        }

        return match (mb_strtolower($code)) {
            'g' => 'kg',
            'ml' => 'L',
            default => $code,
        };
    }

    /**
     * Zwraca jednostkę "wyświetlaną" zgodnie z zasadą: g/ml -> kg/L tylko jeśli raw > 1000.
     */
    public static function conditionalDisplayCodeFromId(?int $unitId, $rawValue): ?string
    {
        $code = self::codeFromId($unitId);
        if ($code === null) {
            return null;
        }

        $lc = mb_strtolower($code);
        if (($lc === 'g' || $lc === 'ml') && is_numeric($rawValue) && ((float)$rawValue) > 1000) {
            return $lc === 'g' ? 'kg' : 'L';
        }

        return $code;
    }

    /**
     * Przelicza ilość na jednostkę "wyświetlaną" zgodnie z zasadą warunkową (raw > 1000).
     */
    public static function conditionalToDisplayQuantity(?int $unitId, $rawValue): ?float
    {
        if ($rawValue === null || $rawValue === '') {
            return null;
        }
        if (!is_numeric($rawValue)) {
            return null;
        }

        $factor = self::conditionalDisplayFactorFromId($unitId, $rawValue);
        return ((float)$rawValue) * $factor;
    }

    /**
     * Przelicza ilość na jednostkę "wyświetlaną".
     *
     * @param float|int|string|null $value
     */
    public static function toDisplayQuantity(?int $unitId, $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (!is_numeric($value)) {
            return null;
        }

        $factor = self::displayFactorFromId($unitId);
        return ((float)$value) * $factor;
    }

    public static function ceilToPrecision(float $value, int $precision = 3): float
    {
        $factor = 10 ** $precision;
        return ceil($value * $factor) / $factor;
    }

    /**
     * Format ilości z opcjonalnym zaokrągleniem w górę.
     *
     * Uwaga: dla g/ml konwersja /1000 wykonywana jest tylko jeśli raw > 1000.
     */
    public static function formatQuantity(?int $unitId, $value, int $precision = 3, bool $ceil = false): string
    {
        $q = self::conditionalToDisplayQuantity($unitId, $value);
        if ($q === null) {
            return (string)$value;
        }

        if ($ceil) {
            $q = self::ceilToPrecision($q, $precision);
        }

        return self::formatNumber($q, $precision);
    }

    /**
     * Formatowanie "wymagane" do PDF:
     *  - dla g/ml:
     *      - jeśli raw > 1000 -> konwersja do kg/L i ceil do 3 miejsc
     *      - jeśli raw <= 1000 -> zostaje g/ml i ceil do pełnych sztuk (0 miejsc)
     *  - dla pozostałych jednostek -> ceil do 3 miejsc
     */
    public static function formatRequiredQuantity(?int $unitId, $rawValue): string
    {
        $code = self::codeFromId($unitId);
        $lc = $code ? mb_strtolower($code) : null;

        // Czy dla tego rawValue zostaniemy przy g/ml (bez /1000)?
        $displayCode = self::conditionalDisplayCodeFromId($unitId, $rawValue);
        $displayLc = $displayCode ? mb_strtolower($displayCode) : null;

        if (($lc === 'g' || $lc === 'ml') && ($displayLc === 'g' || $displayLc === 'ml')) {
            // zostaje g/ml => zaokrąglamy do pełnych
            return self::formatQuantity($unitId, $rawValue, 0, true);
        }

        // domyślnie (w tym g/ml po konwersji do kg/L)
        return self::formatQuantity($unitId, $rawValue, 2, true);
    }

    public static function formatNumber(float $value, int $precision = 3): string
    {
        // 3 miejsca, ale ucinamy zbędne zera
        $s = number_format($value, $precision, '.', '');
        return rtrim(rtrim($s, '0'), '.');
    }
}
