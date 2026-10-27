<?php
declare(strict_types=1);

namespace App\Domain\Params;

/**
 * Une seule règle par paramètre (n_prod_param), quelle que soit la porte d'entrée : page Ustawienia › Pozostałe,
 * API des référentiels, import du tableau Excel (Tabela do wypełnienia). Bornes par code et règles entre deux
 * paramètres (le second ne peut pas être plus petit que le premier).
 */
final class ParamRules
{
    /** code => [min, max, entier ?] */
    public const RANGES = [
        'stock.suggest_weeks' => [1, 52, true],
        'stock.suggest_min_weeks' => [0, 26, false],
        'stock.suggest_max_weeks' => [0.5, 52, false],
        'alerts.complaint_days' => [1, 60, true],
        'alerts.complaint_escalate_days' => [1, 90, true],
        'alerts.cost_due_day' => [1, 28, true],
        'shipping_issue.decision_days' => [1, 30, true],
        'shipping_issue.min_amount' => [0, 1000, true],
        'shipping_issue.lookback_days' => [7, 365, true],
        'send_status.stuck_minutes' => [5, 1440, true],
        'send_status.lookback_days' => [1, 365, true],
        'reminders.auto_levels' => [0, 2, true],
        'kiosk.waste_photo_required' => [0, 1, true],
        'kiosk.waste_photo_max' => [1, 8, true],
        'trace.label_days' => [0, 7, true],
        'kiosk.office_qr_ttl_s' => [30, 600, true],
        'supply.invoice_price_alert_pct' => [1, 100, false],
    ];

    /** [code bas, code haut, code d'erreur API, message ERP] : la valeur haute ne peut pas être inférieure à la basse. */
    public const ORDER = [
        ['alerts.complaint_days', 'alerts.complaint_escalate_days', 'PARAM_ESCALATE_BEFORE_ALERT', 'Alert pilny nie może być wcześniej niż zwykły.'],
        ['stock.suggest_min_weeks', 'stock.suggest_max_weeks', 'PARAM_MAX_BELOW_MIN', 'Zapas maksymalny nie może być mniejszy niż minimalny.'],
    ];

    /** Message d'erreur de borne pour un code connu (null = valeur correcte ou code sans borne). */
    public static function rangeError(string $code, string $raw): ?string
    {
        if (!isset(self::RANGES[$code])) return null;
        [$min, $max, $int] = self::RANGES[$code];
        $raw = str_replace(',', '.', trim($raw));
        if (!is_numeric($raw) || ($int && (string) (int) $raw !== $raw) || (float) $raw < $min || (float) $raw > $max) {
            return "Wartość od {$min} do {$max}" . ($int ? ' (liczba całkowita)' : '') . '.';
        }
        return null;
    }

    /**
     * Règles entre paramètres sur l'état final : $values (nouvelles valeurs) complétées par $current (code => valeur).
     * @return array<string, array{0:string, 1:string}> code fautif => [code d'erreur API, message ERP]
     */
    public static function orderErrors(array $values, callable $current): array
    {
        $errors = [];
        foreach (self::ORDER as [$low, $high, $code, $message]) {
            if (!array_key_exists($low, $values) && !array_key_exists($high, $values)) continue;
            $a = $values[$low] ?? $current($low);
            $b = $values[$high] ?? $current($high);
            if ($a === null || $b === null || $a === '' || $b === '') continue;
            if ((float) str_replace(',', '.', (string) $b) < (float) str_replace(',', '.', (string) $a)) {
                $errors[array_key_exists($high, $values) ? $high : $low] = [$code, $message];
            }
        }
        return $errors;
    }
}
