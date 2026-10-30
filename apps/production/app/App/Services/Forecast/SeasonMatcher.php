<?php
declare(strict_types=1);

namespace App\Services\Forecast;

/**
 * Gamy panelu [220] : daty gamy w danym roku (from_md / to_md, przez Nowy Rok) i podpowiedź naszego produktu dla produktu
 * sklepu — podobieństwo słów nazwy (bez akcentów, liczb i słów pomocniczych ; słowa prawie równe : « frangipane » =
 * « frangipanne », « épice » = « épices »), przy remisie produkt aktywny.
 */
final class SeasonMatcher
{
    /** Pewna podpowiedź (wchodzi do prognozy bez potwierdzenia, oznaczona « auto »). */
    public const SURE = 0.6;
    /** Najsłabsza podpowiedź pokazywana na liście. */
    public const HINT = 0.34;

    private const STOP = ['de', 'du', 'des', 'la', 'le', 'les', 'et', 'au', 'aux', 'en', 'a', 'pour', 'avec', 'sans', 'the', 'z', 'i', 'w', 'na',
        'piece', 'pieces', 'pc', 'pcs', 'personnes', 'pers', 'personne', 'ind', 'individuel', 'individuelle', 'cru', 'crue', 'emb', 'base', 'g', 'gr', 'kg', 'ml', 'cl', 'l', 'cm', 'mm', 'b'];

    /**
     * Wystąpienia gamy, które dotykają okresu [from, to] : daty w tym roku i te same daty rok wcześniej.
     * Gama nie cykliczna : jej daty (bez roku wcześniej).
     * @return list<array{from:string, to:string, n1_from:?string, n1_to:?string}>
     */
    public static function occurrences(array $period, string $from, string $to): array
    {
        $out = [];
        if (!(int) ($period['is_recurring'] ?? 1)) {
            $s = (string) ($period['start_date'] ?? '');
            $e = (string) ($period['end_date'] ?? '');
            if (self::isDate($s) && self::isDate($e) && $s <= $to && $e >= $from) $out[] = ['from' => $s, 'to' => $e, 'n1_from' => null, 'n1_to' => null];
            return $out;
        }
        $fm = (int) ($period['from_md'] ?? 0);
        $tm = (int) ($period['to_md'] ?? 0);
        if (!self::md($fm) || !self::md($tm)) return [];
        for ($y = (int) substr($from, 0, 4) - 1; $y <= (int) substr($to, 0, 4); $y++) {
            $s = self::dateOf($y, $fm);
            $e = self::dateOf($tm < $fm ? $y + 1 : $y, $tm);
            if ($s > $to || $e < $from) continue;
            $out[] = ['from' => $s, 'to' => $e, 'n1_from' => self::dateOf($y - 1, $fm), 'n1_to' => self::dateOf($tm < $fm ? $y : $y - 1, $tm)];
        }
        return $out;
    }

    /** Długość gamy w dniach (cykliczna : od from_md do to_md, przez Nowy Rok). */
    public static function spanDays(array $period): int
    {
        if (!(int) ($period['is_recurring'] ?? 1)) {
            $s = strtotime((string) ($period['start_date'] ?? ''));
            $e = strtotime((string) ($period['end_date'] ?? ''));
            return $s && $e && $e >= $s ? (int) round(($e - $s) / 86400) + 1 : 0;
        }
        $fm = (int) ($period['from_md'] ?? 0);
        $tm = (int) ($period['to_md'] ?? 0);
        if (!self::md($fm) || !self::md($tm)) return 0;
        $s = strtotime(self::dateOf(2001, $fm));
        $e = strtotime(self::dateOf($tm < $fm ? 2002 : 2001, $tm));
        return (int) round(($e - $s) / 86400) + 1;
    }

    /** @return list<string> słowa nazwy do porównania */
    public static function tokens(string $name): array
    {
        $s = mb_strtolower($name);
        $s = strtr($s, ['à' => 'a', 'â' => 'a', 'ä' => 'a', 'á' => 'a', 'ą' => 'a', 'ç' => 'c', 'ć' => 'c', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'ę' => 'e',
            'î' => 'i', 'ï' => 'i', 'í' => 'i', 'ô' => 'o', 'ö' => 'o', 'ó' => 'o', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ú' => 'u', 'ł' => 'l', 'ń' => 'n',
            'ś' => 's', 'ź' => 'z', 'ż' => 'z', 'œ' => 'oe', 'æ' => 'ae', 'ÿ' => 'y']);
        $s = preg_replace("/[^a-z0-9]+/", ' ', $s) ?? '';
        $out = [];
        foreach (preg_split('/\s+/', trim($s)) ?: [] as $w) {
            if ($w === '' || preg_match('/\d/', $w) || in_array($w, self::STOP, true)) continue;
            $out[$w] = true;
        }
        return array_keys($out);
    }

    /** Podobieństwo zbiorów słów (0–1) ze słowami prawie równymi. */
    public static function score(array $a, array $b): float
    {
        if (!$a || !$b) return 0.0;
        $used = [];
        $common = 0;
        foreach ($a as $x) {
            foreach ($b as $j => $y) {
                if (isset($used[$j]) || !self::same($x, $y)) continue;
                $used[$j] = true;
                $common++;
                break;
            }
        }
        return $common / (count($a) + count($b) - $common);
    }

    /**
     * Najlepsze produkty ERP dla nazwy produktu sklepu.
     * @param list<array{id:int, name:string, active:bool, tokens:list<string>}> $candidates
     * @return list<array{id:int, name:string, active:bool, score:float}> do 3, od najlepszego
     */
    public static function best(string $name, array $candidates, int $limit = 3): array
    {
        $t = self::tokens($name);
        $rows = [];
        foreach ($candidates as $c) {
            $s = self::score($t, $c['tokens']);
            if ($s >= self::HINT) $rows[] = ['id' => $c['id'], 'name' => $c['name'], 'active' => $c['active'], 'score' => round($s, 2)];
        }
        usort($rows, fn($x, $y) => [-$x['score'], $x['active'] ? 0 : 1, $x['name']] <=> [-$y['score'], $y['active'] ? 0 : 1, $y['name']]);
        return array_slice($rows, 0, $limit);
    }

    private static function same(string $x, string $y): bool
    {
        if ($x === $y) return true;
        $lx = strlen($x);
        $ly = strlen($y);
        if (min($lx, $ly) >= 4 && abs($lx - $ly) <= 2 && (str_starts_with($x, $y) || str_starts_with($y, $x))) return true;
        return min($lx, $ly) >= 6 && levenshtein($x, $y) <= 1;
    }

    /** Poniedziałek tygodnia daty. */
    public static function monday(string $date): string
    {
        return date('Y-m-d', strtotime($date . ' -' . ((int) date('N', strtotime($date)) - 1) . ' days'));
    }

    /** Ten sam tydzień ISO rok wcześniej (poniedziałek) — jak plan logistyki. */
    public static function lastYearWeek(string $monday): string
    {
        return \App\Services\Logistics\LogisticsPlanService::lastYear($monday);
    }

    private static function md(int $md): bool
    {
        $m = intdiv($md, 100);
        $d = $md % 100;
        return $m >= 1 && $m <= 12 && $d >= 1 && $d <= 31;
    }

    private static function dateOf(int $year, int $md): string
    {
        $m = intdiv($md, 100);
        $d = min($md % 100, (int) date('t', mktime(12, 0, 0, $m, 1, $year)));
        return sprintf('%04d-%02d-%02d', $year, $m, $d);
    }

    private static function isDate(string $v): bool
    {
        return (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $v);
    }
}
