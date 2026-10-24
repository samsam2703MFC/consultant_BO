<?php
declare(strict_types=1);

namespace App\Services\Workshop\Manager;

/**
 * Kierownik Produkcji i Rozwoju Produktu : sekcje stanowiska, ich wskaźniki i zasady premii (bez bazy danych).
 *
 * Premia sekcji = pula roczna × waga × proporcja okresu × współczynnik realizacji. Realizacja wskaźnika : « im
 * wyżej, tym lepiej » = wynik ÷ cel ; « im niżej, tym lepiej » = cel ÷ wynik ; odchylenie cen (może być ujemne)
 * liczone na indeksie 100 + odchylenie. Każda realizacja ograniczona do 120 % przed średnią sekcji (wynik 0 przy
 * celu « niżej » > 0 = 120 %, przy celu 0 = 100 %). Współczynnik : realizacja sekcji < 90 % → 0, potem
 * proporcjonalnie, maks. 120 %. Wyniki nigdy nie są zaokrąglane przed progiem.
 */
final class ManagerIndicators
{
    public const CAP = 1.2;
    public const FLOOR = 0.9;

    /** Sekcje : waga (% puli), okres premii (miesiące), wskaźniki. */
    public const SECTIONS = [
        'production' => ['weight' => 20, 'months' => 3, 'indicators' => ['material_cost_pct', 'loss_pct']],
        'development' => ['weight' => 30, 'months' => 6, 'indicators' => ['range_margin_pct', 'new_profitable_pct', 'below_threshold_pct']],
        'purchasing' => ['weight' => 15, 'months' => 3, 'indicators' => ['price_gap_pct', 'stockouts', 'stock_days']],
        'staffing' => ['weight' => 15, 'months' => 3, 'indicators' => ['staff_cost_per_carton', 'overtime_hours']],
        'logistics' => ['weight' => 20, 'months' => 3, 'indicators' => ['otif_pct', 'logistics_cost_pct']],
    ];

    /** Misje stanowiska (sekcje karty) ; HACCP = warunek premii, bez wskaźnika. */
    public const MISSIONS = ['production', 'development', 'purchasing', 'staffing', 'logistics', 'haccp'];

    /** Propozycja godzin miesięcznie na misję (pełny etat 168 h) — do zmiany w ustawieniach planu. */
    public const DEFAULT_MONTHLY_HOURS = 168.0;
    public const DEFAULT_MISSION_HOURS = ['production' => 50, 'development' => 30, 'purchasing' => 22, 'staffing' => 16, 'logistics' => 25, 'haccp' => 25];

    /**
     * Wskaźniki : kierunek (higher / lower / index), jednostka, sposób łączenia miesięcy w okresie (ratio = liczone
     * na całym okresie z ERP ; avg = średnia miesięcy), domyślny cel szkicu (z opisu stanowiska), źródło
     * (erp = ERP liczy ; manual = tylko wpis ręczny ; erp+manual = ERP, a bez danych ERP wpis ręczny).
     */
    public const INDICATORS = [
        'material_cost_pct' => ['section' => 'production', 'direction' => 'lower', 'unit' => '%', 'source' => 'erp'],
        'loss_pct' => ['section' => 'production', 'direction' => 'lower', 'unit' => '%', 'source' => 'erp+manual'],
        'range_margin_pct' => ['section' => 'development', 'direction' => 'higher', 'unit' => '%', 'source' => 'erp'],
        'new_profitable_pct' => ['section' => 'development', 'direction' => 'higher', 'unit' => '%', 'source' => 'erp'],
        'below_threshold_pct' => ['section' => 'development', 'direction' => 'lower', 'unit' => '%', 'source' => 'erp'],
        'price_gap_pct' => ['section' => 'purchasing', 'direction' => 'index', 'unit' => '%', 'source' => 'erp'],
        'stockouts' => ['section' => 'purchasing', 'direction' => 'lower', 'unit' => 'count_month', 'source' => 'manual', 'default' => 0],
        'stock_days' => ['section' => 'purchasing', 'direction' => 'lower', 'unit' => 'days', 'source' => 'erp'],
        'staff_cost_per_carton' => ['section' => 'staffing', 'direction' => 'lower', 'unit' => 'eur_carton', 'source' => 'erp'],
        'overtime_hours' => ['section' => 'staffing', 'direction' => 'lower', 'unit' => 'hours_month', 'source' => 'erp+manual'],
        'otif_pct' => ['section' => 'logistics', 'direction' => 'higher', 'unit' => '%', 'source' => 'erp', 'default' => 95],
        'logistics_cost_pct' => ['section' => 'logistics', 'direction' => 'lower', 'unit' => '%', 'source' => 'erp'],
    ];

    /** Realizacja wskaźnika (0…1,2) ; null = nie da się policzyć (brak celu, brak wyniku, cel bez sensu). */
    public static function attainment(string $indicator, ?float $target, ?float $realized): ?float
    {
        if ($target === null || $realized === null) return null;
        $direction = self::INDICATORS[$indicator]['direction'];
        if ($direction === 'index') {
            // Odchylenie ceny : indeks 100 + odchylenie (cena referencyjna = 100), « im niżej, tym lepiej ».
            [$target, $realized, $direction] = [100 + $target, 100 + $realized, 'lower'];
            if ($target <= 0 || $realized <= 0) return null;
        }
        if ($direction === 'higher') {
            if ($target <= 0) return null;
            return min(self::CAP, max(0.0, $realized / $target));
        }
        if ($target < 0) return null;
        if ($realized <= 0) return $target > 0 ? self::CAP : 1.0;
        return min(self::CAP, $target / $realized);
    }

    /** Współczynnik z realizacji sekcji : < 90 % → 0 ; potem proporcjonalnie, maks. 120 %. */
    public static function coefficient(float $attainment): float
    {
        return $attainment < self::FLOOR ? 0.0 : min(self::CAP, $attainment);
    }

    /** Status wskaźnika : green ≥ 100 %, orange 90–100 %, red < 90 %. */
    public static function status(?float $attainment): ?string
    {
        if ($attainment === null) return null;
        return $attainment >= 1.0 ? 'green' : ($attainment >= self::FLOOR ? 'orange' : 'red');
    }

    /**
     * Premia sekcji : pula roczna × waga × (miesiące okresu ÷ 12) × obecność × współczynnik.
     * @return array{share:float, amount:float}
     */
    public static function sectionBonus(float $envelope, string $section, float $presence, float $coefficient): array
    {
        $s = self::SECTIONS[$section];
        $share = $envelope * $s['weight'] / 100 * $s['months'] / 12 * $presence;
        return ['share' => $share, 'amount' => $share * $coefficient];
    }

    /** Okres premii sekcji kończący się kwartałem 'YYYY-Qn' : kwartał albo półrocze (rozwój produktów, tylko w Q2 i Q4). */
    public static function sectionPeriod(string $section, string $quarter): ?array
    {
        if (!preg_match('/^(\d{4})-Q([1-4])$/', $quarter, $m)) return null;
        [$year, $q] = [(int) $m[1], (int) $m[2]];
        $months = self::SECTIONS[$section]['months'];
        if ($months === 6 && $q % 2 === 1) return null;
        $endMonth = $q * 3;
        $startMonth = $endMonth - $months + 1;
        $from = sprintf('%04d-%02d-01', $year, $startMonth);
        $to = date('Y-m-t', strtotime(sprintf('%04d-%02d-01', $year, $endMonth)));
        return ['key' => $months === 6 ? $year . '-H' . ($q / 2) : $quarter, 'from' => $from, 'to' => $to];
    }
}
