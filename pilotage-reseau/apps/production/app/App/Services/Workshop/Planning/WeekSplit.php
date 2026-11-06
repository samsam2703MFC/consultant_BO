<?php
declare(strict_types=1);

namespace App\Services\Workshop\Planning;

/**
 * Podział produktu tygodnia na dni (« Ułóż tydzień ») : zadania na wysyłkę i na magazyn, potem dni po kolei.
 * Produkt z partią produkcyjną (karta produktu, krok « Etapy i czasy », np. pistolet 1300 szt.) dzieli się
 * tylko na pełne partie ; bez partii — na pełne kartony.
 */
final class WeekSplit
{
    private const EPS = 0.0005;

    /**
     * Części « na wysyłkę » na terminy (to, co już przydzielone, pokrywa najpierw najbliższe terminy), reszta
     * « na magazyn ». Z partią część na termin jest zaokrąglona w górę do pełnych partii, a nadwyżka partii
     * pokrywa kolejne terminy.
     * @param array<string,float> $directParts termin => ilość
     * @return array{direct: array<string,float>, stock: float}
     */
    public static function tasks(float $unassigned, float $assigned, array $directParts, ?float $batch): array
    {
        $direct = [];
        $cover = $assigned;
        $left = $unassigned;
        foreach ($directParts as $due => $qty) {
            $take = min($qty, $cover);
            $cover -= $take;
            $need = $qty - $take;
            if ($need <= self::EPS || $left <= self::EPS) continue;
            $part = min($left, $batch ? ceil(round($need / $batch, 6)) * $batch : $need);
            $direct[$due] = round($part, 3);
            $left -= $part;
            $cover += $part - $need;
        }
        return ['direct' => $direct, 'stock' => max(0.0, round($left, 3))];
    }

    /**
     * Wypełnia dni okna po kolei (najwcześniej) ; dzieli na pełne jednostki ($unit : partia albo karton), gdy
     * dzień jest pełny. To, co się nie mieści, idzie na ostatni dzień okna (przeciążenie).
     * @param list<string> $window @param array<string,float> $free wolne godziny w dniach
     * @param callable(float):float $hours godziny dla ilości @return array<string,float> dzień => ilość
     */
    public static function place(float $qty, array $window, float $unit, array $free, callable $hours): array
    {
        $out = [];
        foreach ($window as $i => $day) {
            if ($qty <= self::EPS) break;
            $room = max(0.0, $free[$day] ?? 0.0);
            if ($i === count($window) - 1 || $hours($qty) <= $room + 0.01) {
                $out[$day] = $qty;
                break;
            }
            if ($room < 0.25) continue;
            $part = floor($qty * $room / max(0.001, $hours($qty)) / $unit) * $unit;
            for ($n = 0; $part > 0 && $hours($part) > $room + 0.01 && $n < 50; $n++) $part -= $unit;
            if ($part < $unit - self::EPS || $part >= $qty - self::EPS) continue;
            $out[$day] = round($part, 3);
            $qty = round($qty - $part, 3);
        }
        return $out;
    }
}
