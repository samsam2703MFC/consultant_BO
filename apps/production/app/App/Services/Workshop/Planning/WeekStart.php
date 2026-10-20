<?php
declare(strict_types=1);

namespace App\Services\Workshop\Planning;

use App\Repositories\Workshop\ParamRepository;

/**
 * Początek tygodnia produkcji (week.start_day : 1 = poniedziałek … 7 = niedziela ; w pracowni środa).
 * Tydzień = 7 dni od tego dnia ; klucz przydziałów Gantta (tfb_prod_week_slot.week_start).
 */
final class WeekStart
{
    public static function day(ParamRepository $params): int
    {
        $d = (int) $params->get('week.start_day', '1');
        return $d >= 1 && $d <= 7 ? $d : 1;
    }

    /** Pierwszy dzień tygodnia produkcji, do którego należy data (RRRR-MM-DD albo timestamp). */
    public static function of(string|int $date, int $startDay): string
    {
        $t = is_int($date) ? strtotime(date('Y-m-d', $date)) : strtotime(substr($date, 0, 10));
        $back = ((int) date('N', $t) - $startDay + 7) % 7;
        return date('Y-m-d', strtotime("-{$back} days", $t));
    }
}
