<?php
declare(strict_types=1);

namespace App\Core\Web;

/**
 * [275] Pasek u góry « W pracy od … · Zakończ dzień » (wejście do ERP przez QR) : stan dnia liczony dopiero przy rysowaniu
 * strony ; null = pasek ukryty (administrator, stanowisko bez opcji, konto niepowiązane, wejście nieodbite).
 */
final class ErpDay
{
    /** @var (callable(): array)|null */
    public static $state = null;

    public static function state(): ?array
    {
        if (self::$state === null) return null;
        try {
            $s = (self::$state)();
            return !empty($s['enabled']) && !empty($s['clocked_in']) ? $s : null;
        } catch (\Throwable $e) {
            error_log('[erp-day] ' . $e->getMessage());
            return null;
        }
    }
}
