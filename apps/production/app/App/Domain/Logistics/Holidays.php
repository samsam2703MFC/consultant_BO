<?php
declare(strict_types=1);

namespace App\Domain\Logistics;

/**
 * Święta ustawowe (dni wolne od pracy) w Polsce (wyjazd, produkcja) i w Belgii (dostawa u klientów) [200].
 * PL : także Wigilia (od 2025). Święta ruchome od Wielkanocy (algorytm gregoriański, bez rozszerzenia calendar).
 */
final class Holidays
{
    public const COUNTRIES = ['PL', 'BE'];

    /** @return array<string, string> data Y-m-d => nazwa (po polsku) */
    public static function forYear(int $year, string $country): array
    {
        $easter = self::easter($year);
        $e = fn(int $days): string => date('Y-m-d', strtotime("{$easter} {$days} days"));
        $out = match (strtoupper($country)) {
            'PL' => [
                "{$year}-01-01" => 'Nowy Rok',
                "{$year}-01-06" => 'Trzech Króli',
                $easter => 'Wielkanoc',
                $e(1) => 'Poniedziałek Wielkanocny',
                "{$year}-05-01" => 'Święto Pracy',
                "{$year}-05-03" => 'Święto Konstytucji 3 Maja',
                $e(49) => 'Zielone Świątki',
                $e(60) => 'Boże Ciało',
                "{$year}-08-15" => 'Wniebowzięcie NMP',
                "{$year}-11-01" => 'Wszystkich Świętych',
                "{$year}-11-11" => 'Święto Niepodległości',
                "{$year}-12-24" => 'Wigilia',
                "{$year}-12-25" => 'Boże Narodzenie',
                "{$year}-12-26" => 'Drugi dzień Bożego Narodzenia',
            ],
            'BE' => [
                "{$year}-01-01" => 'Nowy Rok',
                $e(1) => 'Poniedziałek Wielkanocny',
                "{$year}-05-01" => 'Święto Pracy',
                $e(39) => 'Wniebowstąpienie',
                $e(50) => 'Poniedziałek Zielonych Świątek',
                "{$year}-07-21" => 'Święto Narodowe Belgii',
                "{$year}-08-15" => 'Wniebowzięcie NMP',
                "{$year}-11-01" => 'Wszystkich Świętych',
                "{$year}-11-11" => 'Rocznica rozejmu (Armistice)',
                "{$year}-12-25" => 'Boże Narodzenie',
            ],
            default => [],
        };
        if ($year < 2025 && strtoupper($country) === 'PL') unset($out["{$year}-12-24"]);
        ksort($out);
        return $out;
    }

    /**
     * Święta w okresie : [data => [kraj => nazwa]].
     * @param array<string, array<string, array{name:string, off:bool}>> $manual dni dodane (off) / pominięte (!off) : data => kraj => …
     */
    public static function between(string $from, string $to, array $countries, array $manual = []): array
    {
        $out = [];
        for ($y = (int) substr($from, 0, 4); $y <= (int) substr($to, 0, 4); $y++) {
            foreach ($countries as $c) {
                foreach (self::forYear($y, $c) as $d => $name) if ($d >= $from && $d <= $to) $out[$d][$c] = $name;
            }
        }
        foreach ($manual as $d => $byCountry) {
            if ($d < $from || $d > $to) continue;
            foreach ($byCountry as $c => $m) {
                if ($m['off']) $out[$d][$c] = $m['name'];
                else unset($out[$d][$c]);
            }
            if (isset($out[$d]) && !$out[$d]) unset($out[$d]);
        }
        ksort($out);
        return $out;
    }

    /** Niedziela Wielkanocna (Y-m-d), algorytm Meeusa / Jonesa / Butchera. */
    public static function easter(int $year): string
    {
        $a = $year % 19;
        $b = intdiv($year, 100);
        $c = $year % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $month = intdiv($h + $l - 7 * $m + 114, 31);
        $day = (($h + $l - 7 * $m + 114) % 31) + 1;
        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }
}
