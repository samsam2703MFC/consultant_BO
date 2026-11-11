<?php
declare(strict_types=1);

namespace App\Domain\Logistics;

/**
 * Trasa ciężarówki [207] (plan logistyki › Kalendarz tras) : fazy z czasem trwania (min) — załadunek, jazda,
 * przerwa, odpoczynek dobowy, rozładunek u klienta, powrót, odpoczynek tygodniowy — oraz czas pracy kierowcy
 * (rozporządzenie UE 561/2006) : jazda bez przerwy ≤ 4 h 30, przerwa 45 min (albo 15 + 30), jazda dzienna ≤ 9 h
 * (2 × w tygodniu 10 h), odpoczynek dobowy 11 h (skrócony 9 h).
 */
final class TripPlan
{
    public const KINDS = ['load', 'drive', 'brk', 'rest', 'unload', 'back', 'weekly'];
    public const DRIVE_MAX = 270;
    public const BREAK = 45;
    public const DAY_DRIVE = 540;
    public const DAY_DRIVE_EXT = 600;
    public const DAILY_REST = 660;
    public const DAILY_REST_REDUCED = 540;
    /** Krótszy kawałek jazdy niż ten nie jest dzielony : przerwa / odpoczynek najpierw. */
    private const MIN_SPLIT = 60;

    /**
     * Fazy pracy (bez przerw) z szablonu : załadunek, jazda tam, rozładunki (jazda między klientami), powrót.
     * @param array{load_min:int, drive_out_min:int, between_min:int, unload_min:int, unload_pallet_min:int, drive_back_min:int} $tpl
     * @param list<array{client_id:int, pallets:int}> $stops kolejność trasy
     * @return list<array{k:string, m:int, client_id?:int}>
     */
    public static function work(array $tpl, array $stops): array
    {
        $out = [];
        if ($tpl['load_min'] > 0) $out[] = ['k' => 'load', 'm' => $tpl['load_min']];
        if ($tpl['drive_out_min'] > 0) $out[] = ['k' => 'drive', 'm' => $tpl['drive_out_min']];
        foreach (array_values($stops) as $i => $s) {
            if ($i > 0 && $tpl['between_min'] > 0) $out[] = ['k' => 'drive', 'm' => $tpl['between_min']];
            $out[] = ['k' => 'unload', 'm' => max(5, $tpl['unload_min'] + $tpl['unload_pallet_min'] * max(0, (int) $s['pallets'])), 'client_id' => (int) $s['client_id']];
        }
        if ($tpl['drive_back_min'] > 0) $out[] = ['k' => 'back', 'm' => $tpl['drive_back_min']];
        return $out;
    }

    /**
     * Przerwy i odpoczynki wstawione według reguł : jazda dzielona po 4 h 30 (przerwa 45 min), po 9 h jazdy w ciągu
     * dnia odpoczynek dobowy 11 h ; na końcu odpoczynek tygodniowy. Fazy przerw / odpoczynków z wejścia są pomijane.
     */
    public static function withRest(array $phases, int $weeklyRest): array
    {
        $out = [];
        $cont = 0;
        $day = 0;
        foreach (self::merge($phases) as $p) {
            if (!in_array($p['k'], ['drive', 'back'], true)) { $out[] = $p; continue; }
            $m = $p['m'];
            while ($m > 0) {
                $avail = min(self::DRIVE_MAX - $cont, self::DAY_DRIVE - $day);
                if ($avail <= 0 || ($avail < $m && $avail < self::MIN_SPLIT)) {
                    if (self::DAY_DRIVE - $day < self::MIN_SPLIT || self::DAY_DRIVE - $day <= 0) { $out[] = ['k' => 'rest', 'm' => self::DAILY_REST]; $day = 0; }
                    else $out[] = ['k' => 'brk', 'm' => self::BREAK];
                    $cont = 0;
                    continue;
                }
                $take = min($m, $avail);
                $out[] = ['k' => $p['k'], 'm' => $take];
                $cont += $take;
                $day += $take;
                $m -= $take;
            }
        }
        if ($weeklyRest > 0) $out[] = ['k' => 'weekly', 'm' => $weeklyRest];
        return $out;
    }

    /** Bez przerw, odpoczynków i odpoczynku tygodniowego ; sąsiednie jazdy tego samego rodzaju złączone. */
    public static function merge(array $phases): array
    {
        $out = [];
        foreach ($phases as $p) {
            if (in_array($p['k'], ['brk', 'rest', 'weekly'], true)) continue;
            $last = count($out) - 1;
            if ($last >= 0 && in_array($p['k'], ['drive', 'back'], true) && $out[$last]['k'] === $p['k']) { $out[$last]['m'] += $p['m']; continue; }
            $out[] = $p;
        }
        return $out;
    }

    /**
     * Godziny faz od wyjazdu (czas rzeczywisty, zmiana czasu uwzględniona).
     * @return list<array> fazy z s, e (Y-m-d H:i)
     */
    public static function timeline(string $startAt, array $phases): array
    {
        $t = strtotime($startAt);
        $out = [];
        foreach ($phases as $p) {
            $s = $t;
            $t += $p['m'] * 60;
            $out[] = $p + ['s' => date('Y-m-d H:i', $s), 'e' => date('Y-m-d H:i', $t)];
        }
        return $out;
    }

    /**
     * Kontrola czasu pracy kierowcy : najdłuższa jazda bez przerwy, najdłuższa jazda dzienna, najkrótszy odpoczynek dobowy.
     * @return list<array{code:string, level:string, value:int, limit:int}>
     */
    public static function checks(array $phases): array
    {
        $cont = 0; $maxCont = 0; $day = 0; $maxDay = 0; $minRest = null; $part = 0;
        foreach ($phases as $p) {
            $m = (int) $p['m'];
            if (in_array($p['k'], ['drive', 'back'], true)) {
                $cont += $m; $day += $m;
                $maxCont = max($maxCont, $cont); $maxDay = max($maxDay, $day);
            } elseif ($p['k'] === 'brk') {
                // 45 min albo 15 + 30 (najpierw co najmniej 15, potem co najmniej 30).
                if ($m >= self::BREAK || ($part >= 15 && $m >= 30)) { $cont = 0; $part = 0; }
                elseif ($m >= 15) $part = max($part, $m);
            } elseif ($p['k'] === 'rest' || $p['k'] === 'weekly') {
                $cont = 0; $part = 0; $day = 0;
                if ($p['k'] === 'rest') $minRest = $minRest === null ? $m : min($minRest, $m);
            }
        }
        $out = [
            ['code' => 'CONT_DRIVE', 'level' => $maxCont <= self::DRIVE_MAX ? 'ok' : 'bad', 'value' => $maxCont, 'limit' => self::DRIVE_MAX],
            ['code' => 'DAY_DRIVE', 'level' => $maxDay <= self::DAY_DRIVE ? 'ok' : ($maxDay <= self::DAY_DRIVE_EXT ? 'warn' : 'bad'), 'value' => $maxDay, 'limit' => self::DAY_DRIVE],
        ];
        if ($minRest !== null) $out[] = ['code' => 'DAILY_REST', 'level' => $minRest >= self::DAILY_REST ? 'ok' : ($minRest >= self::DAILY_REST_REDUCED ? 'warn' : 'bad'), 'value' => $minRest, 'limit' => self::DAILY_REST];
        return $out;
    }
}
