<?php
declare(strict_types=1);

namespace App\Core\Web;

/**
 * [239] Sesja administratora (menu pod awatarem) : administrator ogląda ERP jak dane stanowisko — rail i wyszukiwarka
 * ze stronami stanowiska, strona startowa stanowiska, « Mój tydzień » i « Moje zadania » ze zadaniami stanowiska.
 * Uprawnienia się nie zmieniają (administrator dalej otworzy każdą stronę). Zapis w sesji PHP ; null = pełny widok.
 */
final class ViewSession
{
    public const KEY = 'view_position';

    /** @var (callable(): array)|null menu sesji dla paska u góry (liczone dopiero przy rysowaniu strony) */
    public static $menu = null;

    public static function position(): ?int
    {
        $v = $_SESSION[self::KEY] ?? null;
        return is_int($v) && $v > 0 ? $v : null;
    }

    public static function set(?int $positionId): void
    {
        if ($positionId !== null && $positionId > 0) $_SESSION[self::KEY] = $positionId;
        else unset($_SESSION[self::KEY]);
    }

    /** @return array{can_switch:bool, label:string, current:?int, sessions:list<array>} */
    public static function menu(): array
    {
        $empty = ['can_switch' => false, 'label' => '', 'current' => null, 'sessions' => []];
        if (self::$menu === null) return $empty;
        try {
            return (self::$menu)();
        } catch (\Throwable $e) {
            error_log('[view-session] ' . $e->getMessage());
            return $empty;
        }
    }
}
