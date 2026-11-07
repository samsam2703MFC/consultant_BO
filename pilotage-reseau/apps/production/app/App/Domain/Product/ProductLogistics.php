<?php
declare(strict_types=1);

namespace App\Domain\Product;

/**
 * Jedna walidacja logistyki produktu (final_product) dla Karty produktu, Załadunku dnia, Wag produktów BDO,
 * kreatora « Nowy produkt z recepturą » i formularza « Tylko produkt » :
 *  - liczby całkowite w zakresach RANGES (kolumny INT NOT NULL ; 0 = jeszcze nie podano dla opakowania i palety) ;
 *  - paleta : nigdy więcej kartonów niż warstwy × kartony na warstwę ;
 *  - pola wymagane przez formularz ($required, np. nowy produkt : wszystkie ; Załadunek : paleta) : większe od zera.
 * Błąd = [kod, parametry] — kody jak w API warsztatu (NOT_INTEGER, OUT_OF_RANGE, PALLET_OVER_LAYERS, REQUIRED).
 */
final class ProductLogistics
{
    /** pole => [min, max] */
    public const RANGES = [
        'weight' => [1, 100000],
        'ppc' => [1, 100000],
        'ppb' => [0, 100000],
        'bpc' => [0, 100000],
        'cpl' => [0, 1000],
        'lpp' => [0, 100],
        'cpp' => [0, 10000],
    ];

    /** Pola wymagane (> 0) dla nowego produktu : wszystko poza opakowaniem (0 = bez opakowania, jak w Karcie). */
    public const REQUIRED_NEW = ['weight', 'ppc', 'cpl', 'lpp', 'cpp'];

    /** Przydatność do spożycia (dni) : wymagana wszędzie (nowy produkt, Karta, API) w tym zakresie. */
    public const SHELF_LIFE = [1, 3650];

    public const LABELS = [
        'weight' => 'Waga produktu (g)',
        'ppc' => 'Sztuk w kartonie',
        'ppb' => 'Sztuk w opakowaniu',
        'bpc' => 'Opakowań w kartonie',
        'cpl' => 'Kartony na warstwę',
        'lpp' => 'Warstwy na palecie',
        'cpp' => 'Kartony na paletę',
    ];

    /**
     * Błędy pól $values (tylko podane pola ; pozostałe wartości z $current) ; $required = pola, które formularz
     * wymaga większe od zera (podane albo bieżące).
     * @return array<string, array{0: string, 1: array}> pole => [kod, parametry]
     */
    public static function errors(array $values, array $current = [], array $required = []): array
    {
        $errors = [];
        $clean = [];
        foreach (self::RANGES as $field => [$min, $max]) {
            $complete = in_array($field, $required, true);
            if (!array_key_exists($field, $values)) {
                if ($complete && (int) ($current[$field] ?? 0) <= 0) $errors[$field] = ['REQUIRED', []];
                continue;
            }
            $raw = $values[$field];
            $int = is_int($raw) ? $raw : (is_string($raw) && preg_match('/^\s*-?\d+\s*$/', $raw) ? (int) $raw : null);
            if ($int === null) {
                $errors[$field] = [is_string($raw) && trim($raw) === '' ? 'REQUIRED' : 'NOT_INTEGER', []];
                continue;
            }
            if ($int < ($complete ? max(1, $min) : $min) || $int > $max) {
                $errors[$field] = ['OUT_OF_RANGE', ['min' => $complete ? max(1, $min) : $min, 'max' => $max]];
                continue;
            }
            $clean[$field] = $int;
        }
        $final = fn(string $f) => (int) ($clean[$f] ?? $current[$f] ?? 0);
        $over = self::palletMax($final('cpl'), $final('lpp'), $final('cpp'));
        if ($over !== null && !isset($errors['cpp']) && (isset($clean['cpp']) || isset($clean['cpl']) || isset($clean['lpp']))) {
            $errors['cpp'] = ['PALLET_OVER_LAYERS', ['max' => $over]];
        }
        return $errors;
    }

    /** Największa liczba kartonów na palecie, gdy podana ją przekracza (inaczej null). */
    public static function palletMax(int $cpl, int $lpp, int $cpp): ?int
    {
        return $cpl > 0 && $lpp > 0 && $cpp > $cpl * $lpp ? $cpl * $lpp : null;
    }

    /** Komunikaty po polsku : pole => tekst (strony ERP). */
    public static function messages(array $errors): array
    {
        $out = [];
        foreach ($errors as $field => [$code, $params]) {
            $label = self::LABELS[$field] ?? $field;
            $out[$field] = match ($code) {
                'REQUIRED' => "{$label}: podaj wartość",
                'NOT_INTEGER' => "{$label}: liczba całkowita",
                'OUT_OF_RANGE' => "{$label}: liczba całkowita od {$params['min']} do {$params['max']}",
                'PALLET_OVER_LAYERS' => "{$label}: najwyżej {$params['max']} (warstwy × kartony na warstwę)",
                default => "{$label}: nieprawidłowa wartość",
            };
        }
        return $out;
    }
}
