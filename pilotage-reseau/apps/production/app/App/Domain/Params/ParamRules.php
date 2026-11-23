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
        // Plan logistyki [200]
        'logistics.plan_weeks' => [4, 26, true],
        'logistics.plan_ship_weekday' => [1, 5, true],
        'logistics.plan_delivery_days' => [0, 3, true],
        'logistics.plan_avg_weeks' => [4, 26, true],
        'logistics.plan_coef_default' => [0.3, 5, false],
        // Kartony i palety [205], Kalendarz tras [207]
        'logistics.pallet_max_height_mm' => [300, 3000, true],
        'logistics.pallet_max_kg' => [50, 3000, true],
        'logistics.pallet_overhang_mm' => [0, 100, true],
        'logistics.truck_inner_height_mm' => [1000, 3500, true],
        'logistics.trip_load_min' => [0, 600, true],
        'logistics.trip_drive_out_min' => [0, 3000, true],
        'logistics.trip_between_min' => [0, 600, true],
        'logistics.trip_unload_min' => [0, 600, true],
        'logistics.trip_unload_pallet_min' => [0, 60, true],
        'logistics.trip_drive_back_min' => [0, 3000, true],
        'logistics.trip_weekly_rest_min' => [0, 6000, true],
        // Ceny klientów wg marży [212]
        'prices.client_cap_pct' => [0.5, 50, false],
        'prices.client_round_eur' => [0.01, 1, false],
        'prices.client_interval_days' => [0, 365, true],
        // Kreator cen [218]
        'prices.material_coef_default' => [1, 10, false],
        'prices.notice_days' => [0, 60, true],
        // Ścieżka cen [351] : progi zmiany kosztu surowców (podwyżka / obniżka) i największa obniżka naraz
        'prices.cost_up_pct' => [0.5, 50, false],
        'prices.cost_down_pct' => [0.5, 50, false],
        'prices.client_cut_pct' => [0.5, 50, false],
        // [369] Zamówienia dnia : délai minimal commande → expédition (jours ; 0 = pas de contrôle du délai)
        'orders.min_lead_days' => [0, 30, true],
        // [370] Klienci bez zamówienia : jours de commande sur 8 semaines pour être régulier, part des semaines pour un
        // jour habituel (%), jours ouvrés de tolérance après le jour attendu
        'orders.regular_min_orders' => [2, 40, true],
        'orders.weekday_share_pct' => [10, 100, true],
        'orders.missing_grace_days' => [0, 10, true],
        // [373] Płynność i gotówka : créances échues d'un client (zł), paiements à un fournisseur sous 14 jours (zł),
        // coussin minimal (zł, peut être négatif : ligne de crédit), délais de paiement fournisseur et client par défaut (jours)
        'liquidity.overdue_min' => [0, 10000000, false],
        'liquidity.big_purchase_min' => [0, 10000000, false],
        'liquidity.min_balance' => [-10000000, 10000000, false],
        'liquidity.supplier_terms_days' => [0, 120, true],
        'liquidity.client_terms_days' => [0, 120, true],
        // [374] Opóźnienia dostaw : jours en arrière (date d'expédition), jours « W transporcie » sans livraison confirmée,
        // nombre de changements de la date d'expédition, minutes après l'heure de départ
        'deliveries.delay_lookback_days' => [1, 90, true],
        'deliveries.transit_max_days' => [1, 30, true],
        'deliveries.date_changes_max' => [1, 10, true],
        'deliveries.departure_grace_min' => [0, 600, true],
        // [375] Terminowość tygodnia (OTIF) : objectif en %, jours admis entre la date d'expédition et la livraison confirmée
        'otif.target_pct' => [50, 100, false],
        'otif.delivery_days' => [0, 7, true],
        // [377] Partie i kontrola dnia : jours rattrapés au plus quand l'assistant n'a pas été validé
        'lots_qc.catchup_days' => [1, 31, true],
        // [378] Faktury i KSeF dnia : jours rattrapés, jours avant qu'une facture non envoyée / sans réponse KSeF soit à expliquer,
        // jours pendant lesquels les problèmes ouverts des expéditions antérieures reviennent
        'ksef_day.catchup_days' => [1, 31, true],
        'ksef_day.pending_days' => [1, 14, true],
        'ksef_day.lookback_days' => [7, 92, true],
        // [379] Reklamacje klientów : jours avant qu'une réclamation sans décision ou acceptée non vue par le groupe soit à expliquer,
        // jours en arrière des réclamations acceptées contrôlées
        'complaints_review.open_days' => [1, 60, true],
        'complaints_review.lookback_days' => [7, 365, true],
        // [380] HACCP miesiąca : mois en arrière des ruptures de la chaîne du froid non traitées, mois précédents de la tendance
        'haccp_month.backlog_months' => [1, 36, true],
        'haccp_month.trend_months' => [1, 12, true],
        // [381] Palety zwrotne : semaines du solde des palettes EUR par client, solde au-dessus duquel le client est à expliquer
        'pallets_returns.balance_weeks' => [1, 52, true],
        'pallets_returns.threshold' => [1, 200, true],
        // [383] Poczta od klientów : heures (jours ouvrés) sans réponse avant signalement, jours en arrière contrôlés
        'customer_mail.reply_hours' => [1, 240, true],
        'customer_mail.lookback_days' => [1, 90, true],
        // [384] Nowe produkty : mois de lancements examinés, seuils d'écart aux objectifs (coût %, prix %, marge en points,
        // ventes %), semaines avant de juger les ventes
        'launches_review.months' => [1, 12, true],
        'launches_review.cost_over_pct' => [0, 100, true],
        'launches_review.price_under_pct' => [0, 100, true],
        'launches_review.margin_gap_pts' => [0, 50, true],
        'launches_review.volume_under_pct' => [0, 100, true],
        'launches_review.min_weeks' => [1, 26, true],
        // [385] Dane klientów : mois sans commande au-delà desquels un client n'est plus « actif » (fenêtre des e-mails en erreur et
        // des produits commandés sans prix)
        'customers_data.active_months' => [1, 24, true],
        // [398] Intrastat miesiąca : seuils annuels (milliers de zł) saisis d'après la réglementation de l'année ; 0 = non défini
        'intrastat.threshold_dispatch_kpln' => [0, 1000000, false],
        'intrastat.threshold_arrival_kpln' => [0, 1000000, false],
        // [387] Rutyny poranne AI : interrupteur général et par assistant (0/1), budget mensuel (zł), taux USD → PLN du coût affiché
        'ai.routines_enabled' => [0, 1, true],
        'ai.routines_budget_pln' => [0, 10000, false],
        'ai.routines_usd_pln' => [1, 10, false],
        'ai.routines_on.orders_day_checked' => [0, 1, true],
        'ai.routines_on.deliveries_delays_checked' => [0, 1, true],
        'ai.routines_on.invoices_ksef_checked' => [0, 1, true],
        // [404] Badania i szkolenia : 1 = « brak szkolenia BHP » signalé pour chaque salarié actif (délai d'alerte : hr.alert_days)
        'staff_deadlines.bhp_required' => [0, 1, true],
        // [405] Serwisy i przeglądy maszyn : jours avant le terme d'un przegląd, minutes d'un arrêt « awaria » qui demandent un zgłoszenie
        'maintenance.alert_days' => [1, 180, true],
        'maintenance.breakdown_min_minutes' => [0, 600, true],
        // [406] Teczka do audytu : jours de grâce après le rythme d'un assistant de contrôle, jours avant le contrôle d'un document
        'audit_folder.grace_days' => [0, 60, true],
        'audit_folder.alert_days' => [0, 90, true],
        'stock.suggest_min_weeks' => [0, 26, false],
        'stock.plan_safety_pct' => [0, 200, false],
        'stock.freezer_pallet_places' => [0, 100000, true],
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
        'kiosk.ordered_days' => [1, 28, true],
        'new_product.factory_coef' => [1, 10, false],
        'new_product.store_mult' => [1, 10, false],
        'new_product.store_round_eur' => [0, 1, false],
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
