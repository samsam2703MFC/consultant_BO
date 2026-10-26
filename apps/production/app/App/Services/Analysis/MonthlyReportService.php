<?php
declare(strict_types=1);

namespace App\Services\Analysis;

use App\Core\Helpers\ComplaintReasonTranslator;
use App\Exceptions\CustomException;
use App\Repositories\Analysis\MonthlyReportRepository;
use App\Repositories\Analysis\WdtSalesRepository;
use App\Repositories\Communication\CommunicationTemplateRepository;
use App\Repositories\Dashboard\ProfitabilityRepository;
use App\Repositories\Logistics\DeliveryRepository;
use App\Repositories\Purchasing\MaterialCostRepository;
use App\Repositories\Workshop\ParamRepository;
use App\Services\Communication\OutgoingMessageService;
use App\Services\Communication\TemplateRenderer;
use App\Services\Dashboard\ProfitabilityService;
use App\Services\Logistics\DriverService;
use App\Services\Utils\TCPDFService;
use DateTimeImmutable;

/**
 * Raport miesięczny zarządu : rentowność (sprzedaż, marża brutto po surowcach, personel / sprzedaż, wynik), marża
 * produktów i kategorii, klienci, reklamacje (liczba, wartość przyjęta, przyczyny, produkty, klienci, czas decyzji),
 * niezgodności i dostawy z tabletu kierowcy — miesiąc wobec poprzedniego (M-1) i tego samego miesiąca rok wcześniej
 * (N-1) ; nowe produkty (w rozwoju i wprowadzone w ostatnich 12 miesiącach : sprzedaż, klienci, marża) i tabela roku
 * miesiąc po miesiącu z narastającym wynikiem wobec N-1. Te same liczby co Rentowność (ProfitabilityService) i Analiza
 * sprzedaży (WDT).
 *
 * Strona, PDF (PL / FR) i e-mail z PDF (szablon « management_report »). Wysyłka automatyczna : parametr
 * report.monthly_recipients, od dnia report.monthly_send_day miesiąca za miesiąc poprzedni, raz na miesiąc.
 */
final class MonthlyReportService
{
    public const MESSAGE_TYPE = 'management_report';
    private const MAX_RECIPIENTS = 10;
    /** Przyczyny reklamacji po francusku (po polsku : ComplaintReasonTranslator). */
    private const REASONS_FR = [
        'size_or_weight_issue' => 'Taille ou poids', 'broken_packaging' => 'Emballage abîmé', 'incorrect_information' => 'Information erronée sur le produit',
        'lack_of_conformity' => 'Non conforme à la commande', 'incorrect_quantity_of_products_in_package' => 'Quantité incorrecte dans l’emballage',
        'product_quality' => 'Qualité du produit', 'delivery_issue' => 'Problème de livraison', 'transport_damage' => 'Transport mal protégé',
        'driver_issue' => 'Problème avec le chauffeur', 'delivery_delay' => 'Retard de livraison', 'other' => 'Autre',
    ];
    /** status produktu testowego => [PL, FR] */
    private const DEV_STATUS = [1 => ['Propozycja', 'Proposition'], 2 => ['W produkcji', 'En production']];
    /** Nowe produkty : ile pokazać w tabeli (najwięcej sprzedaży). */
    private const NEW_PRODUCTS_SHOWN = 15;
    private const MONTHS_FR = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];

    /** klucz => [etykieta PL, etykieta FR, jednostka, lepiej gdy rośnie] */
    private const KPIS = [
        'revenue' => ['Sprzedaż', 'Chiffre d’affaires', 'eur', true],
        'cartons' => ['Kartony sprzedane', 'Cartons vendus', 'int', true],
        'customers' => ['Klienci kupujący', 'Clients actifs', 'int', true],
        'gross_pct' => ['Marża brutto (po surowcach)', 'Marge brute (après matières)', 'pct', true],
        'staff_pct' => ['Personel / sprzedaż', 'Personnel / CA', 'pct', false],
        'result' => ['Wynik produkcji', 'Résultat de production', 'eur', true],
        'margin_pct' => ['Marża wyniku', 'Marge nette de production', 'pct', true],
        'complaints' => ['Reklamacje', 'Réclamations', 'int', false],
        'complaint_value' => ['Reklamacje przyjęte (wartość)', 'Réclamations acceptées (valeur)', 'eur', false],
        'complaint_rate' => ['Reklamacje / sprzedaż', 'Réclamations / CA', 'pct2', false],
        'complaint_days' => ['Czas do decyzji (dni)', 'Délai de décision (jours)', 'days', false],
        'nc' => ['Niezgodności', 'Non-conformités', 'int', false],
    ];

    public function __construct(
        private ProfitabilityService $profit,
        private ProfitabilityRepository $profitRepo,
        private MonthlyReportRepository $repo,
        private WdtSalesRepository $wdt,
        private DeliveryRepository $deliveries,
        private ParamRepository $params,
        private CommunicationTemplateRepository $templates,
        private TemplateRenderer $renderer,
        private OutgoingMessageService $outgoing,
        private MaterialCostRepository $company,
    ) {
    }

    /** Miesiące do wyboru : bieżący (w toku) i 12 poprzednich. */
    public function months(?DateTimeImmutable $today = null): array
    {
        $today ??= new DateTimeImmutable('today');
        $first = $today->modify('first day of this month');
        $out = [];
        for ($i = 0; $i <= 12; $i++) {
            $ym = $first->modify("-{$i} months")->format('Y-m');
            $out[$ym] = ProfitabilityService::monthLabel($ym) . ($i === 0 ? ' (w toku)' : '');
        }
        return $out;
    }

    /** Miesiąc z adresu (RRRR-MM, nie późniejszy niż bieżący) ; domyślnie ostatni zamknięty miesiąc. */
    public function normalize(?string $month, ?DateTimeImmutable $today = null): string
    {
        $today ??= new DateTimeImmutable('today');
        $current = $today->format('Y-m');
        if ($month !== null && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) && $month <= $current && $month >= '2000-01') return $month;
        return $today->modify('first day of this month')->modify('-1 month')->format('Y-m');
    }

    public function build(?string $month, ?DateTimeImmutable $today = null): array
    {
        $today ??= new DateTimeImmutable('today');
        $month = $this->normalize($month, $today);
        $first = new DateTimeImmutable($month . '-01');
        $months = ['cur' => $month, 'prev' => $first->modify('-1 month')->format('Y-m'), 'ly' => $first->modify('-1 year')->format('Y-m')];
        $rate = $this->profitRepo->eurRate();

        $p = [];
        foreach ($months as $key => $ym) $p[$key] = $this->profitability($ym, $today, $rate);
        $spec = $p['cur']['period'];
        [$from, $to] = [$spec['from'], $spec['to']];

        $complaints = [];
        $nc = [];
        $customers = [];
        foreach ($months as $key => $ym) {
            [$f, $t] = [$p[$key]['period']['from'], $p[$key]['period']['to']];
            $complaints[$key] = $this->repo->complaintTotals($f, $t);
            $nc[$key] = $this->repo->nonConformities($f, $t);
            $customers[$key] = $this->wdt->sales($f, $t, [], ['customer']);
        }
        $customerCount = fn(array $rows) => count(array_filter($rows, fn($r) => (float) $r['value_eur'] > 0));

        $kpi = [];
        foreach ($months as $key => $ym) {
            $t = $p[$key]['totals'];
            $c = $complaints[$key];
            $kpi[$key] = [
                'revenue' => $t['revenue'], 'cartons' => $t['cartons'],
                'customers' => $customerCount($customers[$key]),
                'gross_pct' => $t['gross_pct'], 'staff_pct' => $t['staff_pct'], 'result' => $t['result'], 'margin_pct' => $t['margin_pct'],
                'complaints' => $c['count'], 'complaint_value' => $c['value'],
                'complaint_rate' => $t['revenue'] > 0 ? $c['value'] / $t['revenue'] * 100 : null,
                'complaint_days' => $c['days_to_decision'],
                'nc' => array_sum(array_map(fn($r) => (int) $r['n'], $nc[$key])),
            ];
        }
        $kpis = [];
        foreach (self::KPIS as $code => [$pl, $fr, $unit, $up]) {
            $values = ['cur' => $kpi['cur'][$code], 'prev' => $kpi['prev'][$code], 'ly' => $kpi['ly'][$code]];
            $kpis[] = ['code' => $code, 'label' => [$pl, $fr], 'unit' => $unit, 'up' => $up, 'values' => $values,
                'trend_prev' => $this->trend($values['cur'], $values['prev'], $unit, $up),
                'trend_ly' => $this->trend($values['cur'], $values['ly'], $unit, $up)];
        }

        $data = [
            'month' => $month,
            'label' => [ProfitabilityService::monthLabel($month), self::monthFr($month)],
            'labels' => array_map(fn($ym) => [ProfitabilityService::monthLabel($ym, true), self::monthFr($ym, true)], $months),
            'months' => $months,
            'current' => $spec['current'],
            'elapsed' => $spec['elapsed'],
            'from' => $from,
            'to' => $to,
            'kpis' => $kpis,
            'groups' => $this->groups($p['cur'], $p['ly']),
            'margin' => $this->margins($from, $to, $rate),
            'customers' => $this->customers($customers['cur'], $customers['ly']),
            'complaints' => [
                'totals' => $complaints,
                'reasons' => array_map(fn($r) => ['reason' => $r['reason'], 'label' => [ComplaintReasonTranslator::translate($r['reason']), self::REASONS_FR[$r['reason']] ?? (string) $r['reason']],
                    'n' => (int) $r['n'], 'value' => (float) $r['value']], $this->repo->complaintsByReason($from, $to)),
                'products' => array_map(fn($r) => ['name' => $r['name'], 'n' => (int) $r['n'], 'value' => (float) $r['value']], $this->repo->complaintsByProduct($from, $to)),
                'customers' => array_map(fn($r) => ['name' => $r['name'], 'n' => (int) $r['n'], 'value' => (float) $r['value']], $this->repo->complaintsByCustomer($from, $to)),
                'per_1000_cartons' => $p['cur']['totals']['cartons'] > 0 ? $complaints['cur']['count'] / $p['cur']['totals']['cartons'] * 1000 : null,
            ],
            'nc' => $nc['cur'],
            'deliveries' => $this->deliveries($from, $to),
            'notes' => $this->notes($p['cur']),
            'products' => $this->newProducts($month, $from, $to, $rate, (float) $kpi['cur']['revenue']),
            'year' => $this->year($month, $today, $rate, $p),
        ];
        $data['highlights'] = $this->highlights($data, $p);
        return $data;
    }

    /**
     * Nowe produkty : w rozwoju (produkty testowe) i wprowadzone w ostatnich 12 miesiącach (do końca miesiąca raportu)
     * — sprzedaż od wprowadzenia, kartony, klienci, sprzedaż w miesiącu, marża po surowcach, średnio na miesiąc.
     */
    private function newProducts(string $month, string $from, string $to, float $rate, float $monthRevenue): array
    {
        $since = (new DateTimeImmutable($month . '-01'))->modify('-11 months')->format('Y-m-d');
        $launched = $this->repo->launchedProducts($since, $to);
        $ids = array_map(fn($r) => (int) $r['id'], $launched);
        $sold = $ids ? array_column($this->wdt->sales($since, $to, ['product_id' => $ids], ['product']), null, 'product_id') : [];
        $inMonth = $ids ? array_column($this->wdt->sales($from, $to, ['product_id' => $ids], ['product']), null, 'product_id') : [];
        $materials = [];
        if ($sold) {
            foreach ($this->profit->production($since, $to, $rate)['groups'] as $g) {
                foreach ($g['categories'] as $c) foreach ($c['products'] as $pr) $materials[(int) $pr['id']] = [$pr['revenue'], $pr['materials']];
            }
        }
        $end = new DateTimeImmutable($to);
        $rows = [];
        foreach ($launched as $r) {
            $id = (int) $r['id'];
            $revenue = (float) ($sold[$id]['value_eur'] ?? 0);
            $start = new DateTimeImmutable($r['launched']);
            $months = max(1, ((int) $end->format('Y') - (int) $start->format('Y')) * 12 + (int) $end->format('n') - (int) $start->format('n') + 1);
            [$mRevenue, $mMaterials] = $materials[$id] ?? [0.0, 0.0];
            $rows[] = [
                'id' => $id, 'name' => $r['name'], 'active' => (bool) (int) $r['active'], 'launched' => $r['launched'], 'months' => $months,
                'revenue' => $revenue, 'cartons' => (float) ($sold[$id]['cartons'] ?? 0), 'customers' => (int) ($sold[$id]['customers'] ?? 0),
                'month_revenue' => (float) ($inMonth[$id]['value_eur'] ?? 0), 'per_month' => $revenue / $months,
                'margin_pct' => $mRevenue > 0 ? ($mRevenue - $mMaterials) / $mRevenue * 100 : null,
                'decision' => $r['decision'],
            ];
        }
        usort($rows, fn($a, $b) => [$b['revenue'], $b['launched']] <=> [$a['revenue'], $a['launched']]);
        $monthNew = array_sum(array_column($rows, 'month_revenue'));
        $dev = array_map(fn($d) => [
            'name' => $d['name'], 'status' => self::DEV_STATUS[(int) $d['id_status']] ?? ['—', '—'], 'stage' => $d['stage'],
            'stage_at' => $d['stage_at'], 'created_at' => $d['created_at'], 'recipe' => $d['id_recipe'] !== null, 'on_tablet' => (bool) (int) $d['on_tablet'],
        ], $this->repo->developmentProducts());
        return [
            'since' => $since,
            'count' => count($rows),
            'with_sales' => count(array_filter($rows, fn($r) => $r['revenue'] > 0)),
            'revenue' => array_sum(array_column($rows, 'revenue')),
            'month_revenue' => $monthNew,
            'month_share' => $monthRevenue > 0 ? $monthNew / $monthRevenue * 100 : null,
            'rows' => array_slice($rows, 0, self::NEW_PRODUCTS_SHOWN),
            'development' => $dev,
            'development_counts' => ['proposal' => count(array_filter($dev, fn($d) => $d['status'][0] === 'Propozycja')), 'production' => count(array_filter($dev, fn($d) => $d['status'][0] === 'W produkcji'))],
        ];
    }

    /**
     * Rok miesiąca raportu, miesiąc po miesiącu (od stycznia do miesiąca raportu) : sprzedaż i ta sama sprzedaż rok
     * wcześniej (miesiąc w toku : do tego samego dnia), kartony, marża brutto, personel / sprzedaż, wynik, reklamacje ;
     * narastająco od początku roku wobec N-1.
     */
    private function year(string $month, DateTimeImmutable $today, float $rate, array $p): array
    {
        $year = (int) substr($month, 0, 4);
        $last = (int) substr($month, 5, 2);
        $end = $p['cur']['period']['to'];
        $endLy = ($year - 1) . substr($end, 4);
        if (!checkdate((int) substr($endLy, 5, 2), (int) substr($endLy, 8, 2), $year - 1)) $endLy = date('Y-m-t', strtotime(($year - 1) . substr($end, 4, 3) . '-01'));
        $complaints = $this->repo->complaintsByMonth("{$year}-01-01", $end);
        $complaintsLy = $this->repo->complaintsByMonth(($year - 1) . '-01-01', $endLy);
        $salesLy = array_column($this->wdt->sales(($year - 1) . '-01-01', $endLy, [], ['month']), null, 'ym');
        // Miesiąc raportu i poprzedni są już policzone.
        $known = [$month => $p['cur'], (new DateTimeImmutable($month . '-01'))->modify('-1 month')->format('Y-m') => $p['prev']];
        $rows = [];
        for ($m = 1; $m <= $last; $m++) {
            $ym = sprintf('%d-%02d', $year, $m);
            $prof = $known[$ym] ?? $this->profitability($ym, $today, $rate);
            $t = $prof['totals'];
            $ly = sprintf('%d-%02d', $year - 1, $m);
            $revenueLy = isset($salesLy[$ly]) ? (float) $salesLy[$ly]['value_eur'] : 0.0;
            $rows[] = [
                'ym' => $ym, 'label' => [ProfitabilityService::monthLabel($ym, true), self::monthFr($ym, true)], 'current' => $prof['period']['current'],
                'revenue' => $t['revenue'], 'revenue_ly' => $revenueLy, 'delta_ly' => $revenueLy > 0 ? ($t['revenue'] - $revenueLy) / $revenueLy * 100 : null,
                'cartons' => $t['cartons'], 'gross' => $t['gross'], 'gross_pct' => $t['gross_pct'], 'staff' => $t['staff'], 'staff_pct' => $t['staff_pct'],
                'result' => $t['result'], 'margin_pct' => $t['margin_pct'],
                'complaints' => $complaints[$ym]['n'] ?? 0, 'complaint_value' => $complaints[$ym]['value'] ?? 0.0,
                'complaints_ly' => $complaintsLy[$ly]['n'] ?? 0,
            ];
        }
        $sum = fn(string $k) => array_sum(array_column($rows, $k));
        $revenue = $sum('revenue');
        $revenueLy = $sum('revenue_ly');
        return [
            'year' => $year,
            'rows' => $rows,
            'total' => [
                'revenue' => $revenue, 'revenue_ly' => $revenueLy, 'delta_ly' => $revenueLy > 0 ? ($revenue - $revenueLy) / $revenueLy * 100 : null,
                'cartons' => $sum('cartons'), 'gross_pct' => $revenue > 0 ? $sum('gross') / $revenue * 100 : null,
                'staff_pct' => $revenue > 0 ? $sum('staff') / $revenue * 100 : null, 'result' => $sum('result'),
                'margin_pct' => $revenue > 0 ? $sum('result') / $revenue * 100 : null,
                'complaints' => $sum('complaints'), 'complaint_value' => $sum('complaint_value'), 'complaints_ly' => $sum('complaints_ly'),
            ],
        ];
    }

    /** Uwagi o danych (PL, FR) : koszty miesięczne przyjęte z innego miesiąca, brak kursu EUR lub kosztów. */
    private function notes(array $profit): array
    {
        $notes = [];
        foreach ($profit['estimated_months'] as $ym => $source) {
            $notes[] = ['Brak kosztów miesięcznych za ' . ProfitabilityService::monthLabel($ym) . ' — przyjęto koszty z ' . ProfitabilityService::monthLabel($source) . ' (kalkulator kosztów)',
                'Coûts mensuels de ' . self::monthFr($ym) . ' non saisis — coûts de ' . self::monthFr($source) . ' repris (calculateur de coûts)'];
        }
        foreach ($profit['warnings'] as $w) {
            if (str_starts_with($w, 'Brak wprowadzonych kosztów miesięcznych')) continue;
            $fr = match (true) {
                str_starts_with($w, 'Brak kursu EUR') => 'Taux EUR absent des paramètres de la société — coûts en PLN non convertis.',
                str_starts_with($w, 'Brak miesięcznych kosztów') => 'Aucun coût mensuel saisi (personnel, énergie, coûts fixes) — à compléter dans le calculateur de coûts.',
                default => $w,
            };
            $notes[] = [$w, $fr];
        }
        return $notes;
    }

    /** Rentowność jednego miesiąca, tak jak na stronie Rentowność (bieżący miesiąc : stan na dziś + zamówienia otwarte). */
    private function profitability(string $ym, DateTimeImmutable $today, float $rate): array
    {
        $spec = $this->profit->period($ym === $today->format('Y-m') ? 'month' : $ym, $today);
        $sales = array_values(array_filter($this->profit->sales($spec['from'], $spec['to'], $rate), fn($r) => isset($spec['months'][$r['ym']])));
        $open = $spec['current'] ? $this->profitRepo->openOrders($spec['from'], $spec['end']) : [];
        return $this->profit->compute($spec, $sales, $open, $rate);
    }

    private function groups(array $cur, array $ly): array
    {
        $before = array_column($ly['groups'], null, 'id');
        return array_map(function ($g) use ($before) {
            $last = $before[$g['id']]['revenue'] ?? null;
            return [
                'name' => $g['name'], 'revenue' => $g['revenue'], 'revenue_ly' => $last,
                'delta_ly' => $last > 0 ? ($g['revenue'] - $last) / $last * 100 : null,
                'gross_pct' => $g['gross_pct'], 'staff_pct' => $g['staff_pct'], 'staff_target' => $g['staff_target'],
                'contribution' => $g['contribution'], 'margin_pct' => $g['margin_pct'], 'margin_target' => $g['margin_target'],
                'below' => $g['margin_pct'] !== null && $g['margin_pct'] < $g['margin_target'],
            ];
        }, $cur['groups']);
    }

    /** Marża po surowcach : kategorie i produkty (najwięksi), produkty o najniższej marży (z istotną sprzedażą). */
    private function margins(string $from, string $to, float $rate): array
    {
        $production = $this->profit->production($from, $to, $rate);
        $categories = [];
        $products = [];
        foreach ($production['groups'] as $g) {
            foreach ($g['categories'] as $c) {
                $categories[] = ['name' => $c['name'], 'group' => $g['name'], 'revenue' => $c['revenue'], 'materials' => $c['materials'],
                    'margin_pct' => $c['margin_pct'], 'cartons' => $c['cartons']];
                foreach ($c['products'] as $pr) {
                    $products[] = ['id' => $pr['id'], 'name' => $pr['name'], 'category' => $c['name'], 'revenue' => $pr['revenue'],
                        'materials' => $pr['materials'], 'margin' => $pr['revenue'] - $pr['materials'], 'margin_pct' => $pr['margin_pct'], 'cartons' => $pr['cartons']];
                }
            }
        }
        usort($categories, fn($a, $b) => $b['revenue'] <=> $a['revenue']);
        usort($products, fn($a, $b) => $b['revenue'] <=> $a['revenue']);
        $threshold = max(100.0, $production['total'] * 0.005);
        $weak = array_values(array_filter($products, fn($pr) => $pr['revenue'] >= $threshold && $pr['margin_pct'] !== null));
        usort($weak, fn($a, $b) => $a['margin_pct'] <=> $b['margin_pct']);
        return [
            'total' => $production['total'],
            'categories' => array_slice($categories, 0, 10),
            'top' => array_slice($products, 0, 10),
            'weak' => array_slice($weak, 0, 8),
            'weak_threshold' => $threshold,
        ];
    }

    private function customers(array $cur, array $ly): array
    {
        $before = array_column(array_map(fn($r) => ['id' => (int) $r['id_customer'], 'v' => (float) $r['value_eur']], $ly), 'v', 'id');
        $rows = array_map(fn($r) => ['id' => (int) $r['id_customer'], 'revenue' => (float) $r['value_eur'], 'cartons' => (float) $r['cartons']], $cur);
        usort($rows, fn($a, $b) => $b['revenue'] <=> $a['revenue']);
        $total = array_sum(array_column($rows, 'revenue'));
        $top = array_slice($rows, 0, 10);
        $names = $this->repo->customerNames(array_column($top, 'id'));
        $lost = array_diff_key(array_filter($before, fn($v) => $v > 0), array_column($rows, 'revenue', 'id'));
        return [
            'top' => array_map(fn($r) => $r + [
                'name' => $names[$r['id']] ?? ('#' . $r['id']),
                'share' => $total > 0 ? $r['revenue'] / $total * 100 : null,
                'revenue_ly' => $before[$r['id']] ?? null,
                'delta_ly' => ($before[$r['id']] ?? 0) > 0 ? ($r['revenue'] - $before[$r['id']]) / $before[$r['id']] * 100 : null,
            ], $top),
            'top_share' => $total > 0 ? array_sum(array_column($top, 'revenue')) / $total * 100 : null,
            'count' => count(array_filter($rows, fn($r) => $r['revenue'] > 0)),
            'new' => count(array_diff_key(array_filter(array_column($rows, 'revenue', 'id'), fn($v) => $v > 0), $before)),
            'lost' => count($lost),
        ];
    }

    private function deliveries(string $from, string $to): array
    {
        $out = ['delivered' => 0, 'problem' => 0, 'pallets_returned' => 0, 'reasons' => []];
        foreach ($this->deliveries->summary($from, $to) as $r) {
            $n = (int) $r['n'];
            $out[$r['status'] === 'problem' ? 'problem' : 'delivered'] += $n;
            $out['pallets_returned'] += (int) $r['pallets_returned'];
            if ($r['status'] === 'problem') {
                $label = DriverService::REASON_LABELS[$r['reason'] ?? 'other'] ?? DriverService::REASON_LABELS['other'];
                $out['reasons'][] = ['label' => $label, 'n' => $n];
            }
        }
        return $out;
    }

    /** Najważniejsze w kilku zdaniach (PL, FR), z liczb raportu. */
    private function highlights(array $d, array $p): array
    {
        $k = array_column($d['kpis'], null, 'code');
        [$pm, $lm] = [$d['labels']['prev'], $d['labels']['ly']];
        $out = [];
        $rev = $k['revenue']['values'];
        $line = [sprintf('Sprzedaż %s', self::eur($rev['cur'])), sprintf('Chiffre d’affaires %s', self::eur($rev['cur']))];
        foreach (['ly' => $lm, 'prev' => $pm] as $key => $label) {
            if ($rev[$key] > 0) {
                $delta = self::signedPct(($rev['cur'] - $rev[$key]) / $rev[$key] * 100);
                $line[0] .= ' · ' . $delta . ' wobec ' . $label[0];
                $line[1] .= ' · ' . $delta . ' vs ' . $label[1];
            }
        }
        if ($d['current']) {
            $line[0] .= ' (miesiąc w toku : ' . round($d['elapsed'] * 100) . ' %)';
            $line[1] .= ' (mois en cours : ' . round($d['elapsed'] * 100) . ' %)';
        }
        $out[] = $line;

        $gross = $k['gross_pct']['values'];
        if ($gross['cur'] !== null) {
            $pp = $gross['prev'] !== null ? ' (' . self::signedPp($gross['cur'] - $gross['prev']) . ' wobec ' . $pm[0] . ')' : '';
            $ppFr = $gross['prev'] !== null ? ' (' . self::signedPp($gross['cur'] - $gross['prev']) . ' vs ' . $pm[1] . ')' : '';
            $res = $k['result']['values']['cur'];
            $out[] = ['Marża brutto po surowcach ' . self::pct($gross['cur']) . $pp . ' · wynik produkcji ' . self::eur($res) . ' (' . self::pct($k['margin_pct']['values']['cur']) . ')',
                'Marge brute après matières ' . self::pct($gross['cur']) . $ppFr . ' · résultat de production ' . self::eur($res) . ' (' . self::pct($k['margin_pct']['values']['cur']) . ')'];
        }

        $below = array_values(array_filter($d['groups'], fn($g) => $g['below'] && $g['revenue'] > 0));
        if ($below) {
            $names = implode(', ', array_map(fn($g) => $g['name'] . ' ' . self::pct($g['margin_pct']), $below));
            $out[] = ['Poniżej celu marży : ' . $names, 'Sous l’objectif de marge : ' . $names];
        } elseif ($d['groups']) {
            $out[] = ['Wszystkie grupy na poziomie celu marży lub powyżej', 'Tous les groupes atteignent l’objectif de marge'];
        }

        if ($d['margin']['weak']) {
            $w = $d['margin']['weak'][0];
            $out[] = ['Najniższa marża : ' . $w['name'] . ' ' . self::pct($w['margin_pct']) . ' przy sprzedaży ' . self::eur($w['revenue']),
                'Marge la plus faible : ' . $w['name'] . ' ' . self::pct($w['margin_pct']) . ' pour ' . self::eur($w['revenue']) . ' de ventes'];
        }

        $c = $d['complaints']['totals']['cur'];
        if ($c['count'] > 0) {
            $reason = $d['complaints']['reasons'][0]['label'] ?? [null, null];
            $out[] = [sprintf('Reklamacje : %d (przyjęte %d, odrzucone %d, otwarte %d) · wartość przyjęta %s (%s sprzedaży)%s', $c['count'], $c['accepted'], $c['rejected'], $c['open'],
                    self::eur($c['value']), self::pct($k['complaint_rate']['values']['cur'], 2), $reason[0] ? ' · główna przyczyna : ' . $reason[0] : ''),
                sprintf('Réclamations : %d (acceptées %d, refusées %d, ouvertes %d) · valeur acceptée %s (%s du CA)%s', $c['count'], $c['accepted'], $c['rejected'], $c['open'],
                    self::eur($c['value']), self::pct($k['complaint_rate']['values']['cur'], 2), $reason[1] ? ' · motif principal : ' . $reason[1] : '')];
        } else {
            $out[] = ['Brak reklamacji w miesiącu', 'Aucune réclamation dans le mois'];
        }

        $np = $d['products'];
        if ($np['count'] || $np['development']) {
            $since = date('m.Y', strtotime($np['since']));
            $out[] = [sprintf('Nowe produkty (od %s) : %d wprowadzonych, %d ze sprzedażą — %s w miesiącu (%s sprzedaży) · w rozwoju : %d', $since, $np['count'], $np['with_sales'],
                    self::eur($np['month_revenue']), self::pct($np['month_share']), count($np['development'])),
                sprintf('Nouveaux produits (depuis %s) : %d lancés, %d vendus — %s dans le mois (%s du CA) · en développement : %d', $since, $np['count'], $np['with_sales'],
                    self::eur($np['month_revenue']), self::pct($np['month_share']), count($np['development']))];
        }
        $yt = $d['year']['total'];
        if (count($d['year']['rows']) > 1) {
            $out[] = [sprintf('Od początku roku %d : sprzedaż %s (%s wobec N-1) · wynik %s · reklamacje : %d', $d['year']['year'], self::eur($yt['revenue']), self::signedPct($yt['delta_ly']), self::eur($yt['result']), $yt['complaints']),
                sprintf('Depuis le début de %d : CA %s (%s vs N-1) · résultat %s · réclamations : %d', $d['year']['year'], self::eur($yt['revenue']), self::signedPct($yt['delta_ly']), self::eur($yt['result']), $yt['complaints'])];
        }

        $dl = $d['deliveries'];
        if ($dl['delivered'] + $dl['problem'] > 0) {
            $out[] = [sprintf('Dostawy potwierdzone na tablecie : %d, z problemem : %d, palety zwrotne : %d', $dl['delivered'], $dl['problem'], $dl['pallets_returned']),
                sprintf('Livraisons confirmées sur la tablette : %d, avec problème : %d, palettes retournées : %d', $dl['delivered'], $dl['problem'], $dl['pallets_returned'])];
        }
        $cu = $d['customers'];
        if ($cu['top_share'] !== null) {
            $n = count($cu['top']);
            $share = $cu['count'] > $n ? sprintf(' · %d największych : %s sprzedaży', $n, self::pct($cu['top_share'])) : '';
            $shareFr = $cu['count'] > $n ? sprintf(' · %d premiers : %s du CA', $n, self::pct($cu['top_share'])) : '';
            $out[] = [sprintf('Klienci kupujący : %d%s · nowi wobec N-1 : %d · bez zakupów wobec N-1 : %d', $cu['count'], $share, $cu['new'], $cu['lost']),
                sprintf('Clients actifs : %d%s · nouveaux vs N-1 : %d · sans achat vs N-1 : %d', $cu['count'], $shareFr, $cu['new'], $cu['lost'])];
        }
        return $out;
    }

    /** Kierunek zmiany : delta (w jednostce wskaźnika lub %), dobra / zła. */
    private function trend(int|float|null $cur, int|float|null $ref, string $unit, bool $up): ?array
    {
        if ($cur === null || $ref === null) return null;
        $relative = in_array($unit, ['eur', 'int'], true);
        if ($relative && (float) $ref == 0.0) return null;
        $delta = $relative ? ($cur - $ref) / abs((float) $ref) * 100 : $cur - $ref;
        if (abs($delta) < 0.05) return ['delta' => 0.0, 'kind' => $relative ? 'pct' : 'pp', 'good' => null];
        return ['delta' => $delta, 'kind' => $relative ? 'pct' : ($unit === 'days' ? 'days' : 'pp'), 'good' => ($delta > 0) === $up];
    }

    // ---- PDF -------------------------------------------------------------------------------------------

    /** @return array{filename:string, content:string} */
    public function pdf(?string $month, string $lang = 'pl', ?DateTimeImmutable $today = null): array
    {
        return $this->render($this->build($month, $today), $lang === 'fr' ? 'fr' : 'pl');
    }

    private function render(array $d, string $lang): array
    {
        $i = $lang === 'fr' ? 1 : 0;
        $t = fn(string $pl, string $fr) => $i ? $fr : $pl;
        $title = $t('Raport miesięczny', 'Rapport mensuel');
        $pdf = new TCPDFService('P', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->brandPage($title, ['ref' => $d['month'], 'lang' => $lang]);
        $pdf->brandHeader($title, [
            [$t('Miesiąc', 'Mois'), $d['label'][$i] . ($d['current'] ? ' · ' . $t('w toku', 'en cours') : '')],
            [$t('Okres', 'Période'), date('d.m.Y', strtotime($d['from'])) . ' – ' . date('d.m.Y', strtotime($d['to']))],
            [$t('Porównanie', 'Comparaison'), $d['labels']['prev'][$i] . ' · ' . $d['labels']['ly'][$i]],
            [$t('Wygenerowano', 'Généré le'), date('d.m.Y H:i')],
        ], $t('Rentowność, marża, reklamacje', 'Rentabilité, marge, réclamations'));

        foreach ($d['notes'] as $n) $pdf->brandNote($n[$i]);
        $pdf->brandSection($t('Najważniejsze', 'L’essentiel'));
        $pdf->brandText(implode("\n", array_map(fn($h) => '•  ' . $h[$i], $d['highlights'])));

        $l = $d['labels'];
        $pdf->brandSection($t('Wskaźniki', 'Indicateurs'), $t('Miesiąc wobec poprzedniego (M-1) i roku wcześniej (N-1)', 'Mois comparé au mois précédent (M-1) et à l’année précédente (N-1)'));
        $pdf->brandTable([
            ['label' => $t('Wskaźnik', 'Indicateur'), 'w' => 3.2],
            ['label' => $l['cur'][$i], 'w' => 1.4, 'align' => 'R'],
            ['label' => $l['prev'][$i], 'sub' => 'M-1', 'w' => 1.4, 'align' => 'R'],
            ['label' => $l['ly'][$i], 'sub' => 'N-1', 'w' => 1.4, 'align' => 'R'],
            ['label' => 'Δ M-1', 'w' => 1.1, 'align' => 'R'],
            ['label' => 'Δ N-1', 'w' => 1.1, 'align' => 'R'],
        ], array_map(fn($k) => [$k['label'][$i], self::value($k['values']['cur'], $k['unit']), self::value($k['values']['prev'], $k['unit']),
            self::value($k['values']['ly'], $k['unit']), self::trendText($k['trend_prev']), self::trendText($k['trend_ly'])], $d['kpis']));

        $y = $d['year'];
        if ($y['rows']) {
            $pdf->brandSection($t('Rok ', 'Année ') . $y['year'], $t('Miesiąc po miesiącu, narastająco wobec N-1 (miesiąc w toku : N-1 do tego samego dnia)', 'Mois par mois, cumul comparé à N-1 (mois en cours : N-1 au même jour)'));
            $rows = array_map(fn($r) => [$r['label'][$i] . ($r['current'] ? ' *' : ''), self::eur($r['revenue']), self::eur($r['revenue_ly']), self::signedPct($r['delta_ly']),
                self::pct($r['gross_pct']), self::pct($r['staff_pct']), self::eur($r['result']), self::pct($r['margin_pct']), $r['complaints']], $y['rows']);
            $yt = $y['total'];
            $rows[] = ['_total' => [$t('Razem', 'Total'), self::eur($yt['revenue']), self::eur($yt['revenue_ly']), self::signedPct($yt['delta_ly']), self::pct($yt['gross_pct']),
                self::pct($yt['staff_pct']), self::eur($yt['result']), self::pct($yt['margin_pct']), $yt['complaints']]];
            $pdf->brandTable([['label' => $t('Miesiąc', 'Mois'), 'w' => 1.1], ['label' => $t('Sprzedaż', 'CA'), 'w' => 1.3, 'align' => 'R'], ['label' => 'N-1', 'w' => 1.3, 'align' => 'R'],
                ['label' => 'Δ N-1', 'w' => 0.9, 'align' => 'R'], ['label' => $t('Marża brutto', 'Marge brute'), 'w' => 1, 'align' => 'R'], ['label' => $t('Personel', 'Personnel'), 'w' => 1, 'align' => 'R'],
                ['label' => $t('Wynik', 'Résultat'), 'w' => 1.2, 'align' => 'R'], ['label' => $t('Marża wyniku', 'Marge nette'), 'w' => 1, 'align' => 'R'], ['label' => $t('Rekl.', 'Récl.'), 'w' => 0.6, 'align' => 'R']],
                $rows, ['font' => 7.5]);
        }

        if ($d['groups']) {
            $pdf->brandSection($t('Grupy produktów', 'Groupes de produits'), $t('Marża brutto = po surowcach ; marża wyniku = po surowcach, personelu i kosztach grupy', 'Marge brute = après matières ; marge nette = après matières, personnel et coûts du groupe'));
            $pdf->brandTable([
                ['label' => $t('Grupa', 'Groupe'), 'w' => 2.4],
                ['label' => $t('Sprzedaż', 'CA'), 'w' => 1.4, 'align' => 'R'],
                ['label' => 'Δ N-1', 'w' => 1, 'align' => 'R'],
                ['label' => $t('Marża brutto', 'Marge brute'), 'w' => 1.2, 'align' => 'R'],
                ['label' => $t('Personel', 'Personnel'), 'sub' => $t('cel', 'objectif'), 'w' => 1.2, 'align' => 'R'],
                ['label' => $t('Wynik', 'Résultat'), 'w' => 1.3, 'align' => 'R'],
                ['label' => $t('Marża wyniku', 'Marge nette'), 'sub' => $t('cel', 'objectif'), 'w' => 1.3, 'align' => 'R'],
            ], array_map(fn($g) => [$g['name'], self::eur($g['revenue']), self::signedPct($g['delta_ly']), self::pct($g['gross_pct']),
                self::pct($g['staff_pct']) . ' / ' . self::pct($g['staff_target'], 0), self::eur($g['contribution']),
                self::pct($g['margin_pct']) . ' / ' . self::pct($g['margin_target'], 0) . ($g['below'] ? ' ▼' : '')], $d['groups']));
        }

        $m = $d['margin'];
        if ($m['top']) {
            $pdf->brandSection($t('Marża produktów', 'Marge des produits'), $t('Po surowcach i opakowaniach z receptur, ceny surowców z miesiąca', 'Après matières et emballages des recettes, prix des matières du mois'));
            $cols = [['label' => $t('Produkt', 'Produit'), 'w' => 3.2], ['label' => $t('Kategoria', 'Catégorie'), 'w' => 1.8],
                ['label' => $t('Kartony', 'Cartons'), 'w' => 0.9, 'align' => 'R'], ['label' => $t('Sprzedaż', 'CA'), 'w' => 1.2, 'align' => 'R'],
                ['label' => $t('Marża', 'Marge'), 'w' => 1.2, 'align' => 'R'], ['label' => '%', 'w' => 0.8, 'align' => 'R']];
            $row = fn($pr) => [$pr['name'], $pr['category'], self::num($pr['cartons']), self::eur($pr['revenue']), self::eur($pr['margin']), self::pct($pr['margin_pct'])];
            $pdf->brandTable($cols, array_merge([['_group' => $t('10 największych sprzedaży', '10 meilleures ventes')]], array_map($row, $m['top']),
                $m['weak'] ? [['_group' => $t('Najniższa marża (sprzedaż ≥ ', 'Marge la plus faible (CA ≥ ') . self::eur($m['weak_threshold']) . ')']] : [], array_map($row, $m['weak'])));
            $pdf->brandTable([['label' => $t('Kategoria', 'Catégorie'), 'w' => 2.6], ['label' => $t('Grupa', 'Groupe'), 'w' => 1.8],
                ['label' => $t('Sprzedaż', 'CA'), 'w' => 1.2, 'align' => 'R'], ['label' => $t('Surowce', 'Matières'), 'w' => 1.2, 'align' => 'R'], ['label' => $t('Marża', 'Marge'), 'w' => 0.9, 'align' => 'R']],
                array_map(fn($c) => [$c['name'], $c['group'], self::eur($c['revenue']), self::eur($c['materials']), self::pct($c['margin_pct'])], $m['categories']));
        }

        if ($d['customers']['top']) {
            $pdf->brandSection($t('Klienci', 'Clients'), $t('10 największych klientów miesiąca wobec N-1', '10 premiers clients du mois comparés à N-1'));
            $pdf->brandTable([['label' => $t('Klient', 'Client'), 'w' => 3.4], ['label' => $t('Sprzedaż', 'CA'), 'w' => 1.3, 'align' => 'R'],
                ['label' => $t('Udział', 'Part'), 'w' => 0.9, 'align' => 'R'], ['label' => 'N-1', 'w' => 1.3, 'align' => 'R'], ['label' => 'Δ N-1', 'w' => 1, 'align' => 'R']],
                array_map(fn($c) => [$c['name'], self::eur($c['revenue']), self::pct($c['share']), self::eur($c['revenue_ly']), self::signedPct($c['delta_ly'])], $d['customers']['top']));
        }

        $np = $d['products'];
        if ($np['rows'] || $np['development']) {
            $pdf->brandSection($t('Nowe produkty', 'Nouveaux produits'), sprintf($t('Wprowadzone od %s : %d, ze sprzedażą %d, sprzedaż %s ; w miesiącu %s (%s sprzedaży)', 'Lancés depuis %s : %d, vendus %d, CA %s ; dans le mois %s (%s du CA)'),
                date('m.Y', strtotime($np['since'])), $np['count'], $np['with_sales'], self::eur($np['revenue']), self::eur($np['month_revenue']), self::pct($np['month_share'])));
            if ($np['rows']) {
                $pdf->brandTable([['label' => $t('Produkt', 'Produit'), 'w' => 3], ['label' => $t('Od', 'Depuis'), 'w' => 0.9], ['label' => $t('Sprzedaż', 'CA'), 'w' => 1.1, 'align' => 'R'],
                    ['label' => $t('Na miesiąc', 'Par mois'), 'w' => 1, 'align' => 'R'], ['label' => $t('W miesiącu', 'Ce mois'), 'w' => 1, 'align' => 'R'], ['label' => $t('Kartony', 'Cartons'), 'w' => 0.8, 'align' => 'R'],
                    ['label' => $t('Klienci', 'Clients'), 'w' => 0.7, 'align' => 'R'], ['label' => $t('Marża', 'Marge'), 'w' => 0.8, 'align' => 'R']],
                    array_map(fn($r) => [$r['name'] . ($r['active'] ? '' : ' ' . $t('(nieaktywny)', '(inactif)')), date('m.Y', strtotime($r['launched'])), self::eur($r['revenue']), self::eur($r['per_month']),
                        self::eur($r['month_revenue']), self::num($r['cartons']), $r['customers'], self::pct($r['margin_pct'])], $np['rows']), ['font' => 7.5]);
            }
            if ($np['development']) {
                $pdf->brandTable([['label' => $t('W rozwoju', 'En développement'), 'w' => 3], ['label' => $t('Status', 'Statut'), 'w' => 1.1], ['label' => $t('Ostatni etap', 'Dernière étape'), 'w' => 2.2],
                    ['label' => $t('Receptura', 'Recette'), 'w' => 0.8, 'align' => 'R'], ['label' => $t('Od', 'Depuis'), 'w' => 0.9]],
                    array_map(fn($r) => [$r['name'], $r['status'][$i], ($r['stage'] ?? '—') . ($r['stage_at'] ? ' · ' . date('d.m.Y', strtotime($r['stage_at'])) : ''),
                        $r['recipe'] ? '✓' : '—', date('m.Y', strtotime($r['created_at']))], array_slice($np['development'], 0, 20)), ['font' => 7.5]);
            }
        }

        $c = $d['complaints'];
        $pdf->brandSection($t('Reklamacje', 'Réclamations'), $t('Wartość = przyjęta ilość × cena zakupu klienta', 'Valeur = quantité acceptée × prix d’achat du client'));
        $tot = $c['totals'];
        $pdf->brandTable([['label' => '', 'w' => 2.6], ['label' => $l['cur'][$i], 'w' => 1.2, 'align' => 'R'], ['label' => $l['prev'][$i], 'sub' => 'M-1', 'w' => 1.2, 'align' => 'R'], ['label' => $l['ly'][$i], 'sub' => 'N-1', 'w' => 1.2, 'align' => 'R']], [
            [$t('Zgłoszone', 'Déclarées'), $tot['cur']['count'], $tot['prev']['count'], $tot['ly']['count']],
            [$t('Przyjęte', 'Acceptées'), $tot['cur']['accepted'], $tot['prev']['accepted'], $tot['ly']['accepted']],
            [$t('Odrzucone', 'Refusées'), $tot['cur']['rejected'], $tot['prev']['rejected'], $tot['ly']['rejected']],
            [$t('Otwarte', 'Ouvertes'), $tot['cur']['open'], $tot['prev']['open'], $tot['ly']['open']],
            [$t('Wartość przyjęta', 'Valeur acceptée'), self::eur($tot['cur']['value']), self::eur($tot['prev']['value']), self::eur($tot['ly']['value'])],
            [$t('Czas do decyzji', 'Délai de décision'), self::value($tot['cur']['days_to_decision'], 'days'), self::value($tot['prev']['days_to_decision'], 'days'), self::value($tot['ly']['days_to_decision'], 'days')],
        ]);
        if ($c['reasons']) {
            $pdf->brandTable([['label' => $t('Przyczyna', 'Motif'), 'w' => 3.4], ['label' => $t('Liczba', 'Nombre'), 'w' => 1, 'align' => 'R'], ['label' => $t('Wartość', 'Valeur'), 'w' => 1.2, 'align' => 'R']],
                array_map(fn($r) => [$r['label'][$i], $r['n'], self::eur($r['value'])], $c['reasons']));
        }
        if ($c['products'] || $c['customers']) {
            $rows = [];
            if ($c['products']) $rows = array_merge($rows, [['_group' => $t('Produkty', 'Produits')]], array_map(fn($r) => [$r['name'], $r['n'], self::eur($r['value'])], $c['products']));
            if ($c['customers']) $rows = array_merge($rows, [['_group' => $t('Klienci', 'Clients')]], array_map(fn($r) => [$r['name'], $r['n'], self::eur($r['value'])], $c['customers']));
            $pdf->brandTable([['label' => $t('Najczęściej reklamowane', 'Les plus réclamés'), 'w' => 3.4], ['label' => $t('Liczba', 'Nombre'), 'w' => 1, 'align' => 'R'], ['label' => $t('Wartość', 'Valeur'), 'w' => 1.2, 'align' => 'R']], $rows);
        }

        if ($d['nc']) {
            $sources = ['supplier_delivery' => ['Dostawa surowców', 'Livraison fournisseur'], 'production' => ['Produkcja', 'Production'],
                'customer' => ['Klient', 'Client'], 'internal' => ['Wewnętrzna', 'Interne']];
            $pdf->brandSection($t('Niezgodności', 'Non-conformités'));
            $pdf->brandTable([['label' => $t('Źródło', 'Origine'), 'w' => 2.6], ['label' => $t('Liczba', 'Nombre'), 'w' => 1, 'align' => 'R'],
                ['label' => $t('Krytyczne', 'Critiques'), 'w' => 1, 'align' => 'R'], ['label' => $t('Otwarte', 'Ouvertes'), 'w' => 1, 'align' => 'R'], ['label' => $t('Koszt', 'Coût'), 'w' => 1.2, 'align' => 'R']],
                array_map(fn($r) => [($sources[$r['source']] ?? [$r['source'], $r['source']])[$i], (int) $r['n'], (int) $r['critical'], (int) $r['open'], self::eur((float) $r['cost'])], $d['nc']));
        }

        $dl = $d['deliveries'];
        if ($dl['delivered'] + $dl['problem'] > 0) {
            $pdf->brandSection($t('Dostawy (tablet kierowcy)', 'Livraisons (tablette chauffeur)'));
            $rows = [[$t('Potwierdzone', 'Confirmées'), $dl['delivered']], [$t('Z problemem', 'Avec problème'), $dl['problem']], [$t('Palety zwrotne', 'Palettes retournées'), $dl['pallets_returned']]];
            foreach ($dl['reasons'] as $r) $rows[] = ['   ' . $r['label'][$i], $r['n']];
            $pdf->brandTable([['label' => '', 'w' => 3], ['label' => $t('Liczba', 'Nombre'), 'w' => 1, 'align' => 'R']], $rows);
        }
        $pdf->brandNote($t('Źródła : dokumenty WDT (sprzedaż netto, EUR), receptury i ceny surowców, koszty miesięczne z kalkulatora kosztów, reklamacje, niezgodności, tablet kierowcy.',
            'Sources : documents WDT (ventes nettes, EUR), recettes et prix des matières, coûts mensuels du calculateur de coûts, réclamations, non-conformités, tablette chauffeur.'));

        return ['filename' => 'RAPORT_MIESIECZNY_' . $d['month'] . '_' . strtoupper($lang) . '.pdf', 'content' => $pdf->Output('', 'S')];
    }

    // ---- E-mail ----------------------------------------------------------------------------------------

    /**
     * Raport PDF e-mailem (jedna wiadomość na adres, przez kolejkę i tryb MAIL_DELIVERY_MODE).
     * @return array{queued:int, recipients:list<string>}
     */
    public function send(array $input, string $author, ?DateTimeImmutable $today = null): array
    {
        $month = $this->normalize(isset($input['month']) ? (string) $input['month'] : null, $today);
        if (isset($input['month']) && $month !== (string) $input['month']) throw new CustomException('Nieprawidłowy miesiąc raportu.', 'errors', 422);
        $lang = (string) ($input['lang'] ?? 'pl');
        if (!in_array($lang, ['pl', 'fr'], true)) throw new CustomException('Język raportu : pl albo fr.', 'errors', 422);
        $recipients = self::recipients((string) ($input['to'] ?? ''));
        if (!$recipients) throw new CustomException('Podaj co najmniej jeden adres e-mail.', 'errors', 422);
        return $this->queue($month, $lang, $recipients, $author, $today);
    }

    /**
     * Wysyłka automatyczna (cron mail_dispatch) : od dnia report.monthly_send_day raport za poprzedni miesiąc do
     * adresów report.monthly_recipients, raz (wiadomość « monthly-report:RRRR-MM » już w kolejce = nic).
     */
    public function autoSend(?DateTimeImmutable $today = null): array
    {
        $today ??= new DateTimeImmutable('today');
        $recipients = self::recipients($this->params->get('report.monthly_recipients', ''));
        if (!$recipients) return ['skipped' => 'no recipients'];
        $day = max(1, min(28, (int) $this->params->get('report.monthly_send_day', '3')));
        if ((int) $today->format('j') < $day) return ['skipped' => 'before day ' . $day];
        $month = $today->modify('first day of this month')->modify('-1 month')->format('Y-m');
        if ($this->repo->alreadySent(self::MESSAGE_TYPE, 'monthly-report:' . $month)) return ['skipped' => 'already sent ' . $month];
        $lang = $this->params->get('report.monthly_lang', 'pl') === 'fr' ? 'fr' : 'pl';
        return ['month' => $month] + $this->queue($month, $lang, $recipients, 'monthly-report:' . $month, $today);
    }

    /** Ustawienia wysyłki automatycznej : adresy (puste = wyłączona), dzień miesiąca 1–28, język PDF. */
    public function saveSettings(array $input, ?int $userId): array
    {
        $recipients = self::recipients((string) ($input['recipients'] ?? ''));
        $day = filter_var($input['send_day'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 28]]);
        if ($day === false) throw new CustomException('Dzień wysyłki : od 1 do 28.', 'errors', 422);
        $lang = (string) ($input['lang'] ?? 'pl');
        if (!in_array($lang, ['pl', 'fr'], true)) throw new CustomException('Język raportu : pl albo fr.', 'errors', 422);
        $this->params->setValue('report.monthly_recipients', implode(', ', $recipients), $userId);
        $this->params->setValue('report.monthly_send_day', (string) $day, $userId);
        $this->params->setValue('report.monthly_lang', $lang, $userId);
        return $this->settings();
    }

    public function settings(): array
    {
        return [
            'recipients' => $this->params->get('report.monthly_recipients', ''),
            'send_day' => (int) $this->params->get('report.monthly_send_day', '3'),
            'lang' => $this->params->get('report.monthly_lang', 'pl') === 'fr' ? 'fr' : 'pl',
        ];
    }

    private function queue(string $month, string $lang, array $recipients, string $author, ?DateTimeImmutable $today): array
    {
        $template = $this->templates->getDefault(self::MESSAGE_TYPE, $lang) ?: $this->templates->getDefault(self::MESSAGE_TYPE, 'pl');
        if (!$template) throw new CustomException('Brak szablonu e-mail « ' . self::MESSAGE_TYPE . ' ».', 'errors', 422);
        $d = $this->build($month, $today);
        $file = $this->render($d, $lang);
        $i = $lang === 'fr' ? 1 : 0;
        $k = array_column($d['kpis'], null, 'code');
        $variables = [
            'report' => [
                'period' => $d['label'][$i],
                'highlights' => implode("\n", array_map(fn($h) => '• ' . $h[$i], $d['highlights'])),
                'revenue' => self::eur($k['revenue']['values']['cur']),
                'margin' => self::pct($k['gross_pct']['values']['cur']),
                'result' => self::eur($k['result']['values']['cur']),
                'complaints' => (string) $k['complaints']['values']['cur'],
            ],
            'company' => ['name' => $this->company->companyName() ?: 'SL Food'],
        ];
        $subject = $this->renderer->render($template['subject_template'], $variables);
        $body = $this->renderer->render($template['body_template'], $variables);
        $queued = 0;
        foreach ($recipients as $to) {
            $this->outgoing->queue([
                'message_type' => self::MESSAGE_TYPE, 'template' => $template, 'recipient' => $to,
                'subject' => $subject, 'body' => $body, 'variables' => $variables, 'created_by' => $author,
            ], [['filename' => $file['filename'], 'mime_type' => 'application/pdf', 'content' => $file['content']]]);
            $queued++;
        }
        return ['queued' => $queued, 'recipients' => $recipients];
    }

    /** Adresy rozdzielone przecinkiem, średnikiem lub spacją ; nieprawidłowy adres = błąd. */
    public static function recipients(string $raw): array
    {
        $list = array_values(array_unique(array_filter(array_map('trim', preg_split('/[\s,;]+/', $raw) ?: []))));
        foreach ($list as $a) {
            if (!filter_var($a, FILTER_VALIDATE_EMAIL)) throw new CustomException('Nieprawidłowy adres e-mail : ' . $a, 'errors', 422);
        }
        if (count($list) > self::MAX_RECIPIENTS) throw new CustomException('Najwyżej ' . self::MAX_RECIPIENTS . ' adresów.', 'errors', 422);
        return $list;
    }

    // ---- Formatowanie ----------------------------------------------------------------------------------

    public static function monthFr(string $ym, bool $short = false): string
    {
        [$y, $m] = array_map('intval', explode('-', $ym));
        $name = self::MONTHS_FR[$m - 1];
        return ($short ? mb_substr($name, 0, 4) . (mb_strlen($name) > 4 ? '.' : '') : $name) . ' ' . $y;
    }

    public static function value(int|float|null $v, string $unit): string
    {
        return match ($unit) {
            'eur' => self::eur($v),
            'pct' => self::pct($v),
            'pct2' => self::pct($v, 2),
            'days' => $v === null ? '—' : number_format((float) $v, 1, ',', ' '),
            default => $v === null ? '—' : self::num($v),
        };
    }

    public static function trendText(?array $t): string
    {
        if ($t === null) return '—';
        $arrow = $t['good'] === null ? '' : ($t['good'] ? ' ▲' : ' ▼');
        return match ($t['kind']) {
            'pct' => self::signedPct($t['delta']),
            'days' => ($t['delta'] > 0 ? '+' : '') . number_format($t['delta'], 1, ',', ' ') . ' d',
            default => self::signedPp($t['delta']),
        } . $arrow;
    }

    public static function eur(int|float|null $v): string
    {
        return $v === null ? '—' : number_format((float) $v, 0, ',', ' ') . ' €';
    }

    public static function pct(int|float|null $v, int $decimals = 1): string
    {
        return $v === null ? '—' : number_format((float) $v, $decimals, ',', ' ') . ' %';
    }

    public static function num(int|float|null $v): string
    {
        return $v === null ? '—' : number_format((float) $v, 0, ',', ' ');
    }

    public static function signedPct(int|float|null $v): string
    {
        return $v === null ? '—' : ($v > 0 ? '+' : '') . number_format((float) $v, 1, ',', ' ') . ' %';
    }

    public static function signedPp(int|float|null $v): string
    {
        return $v === null ? '—' : ($v > 0 ? '+' : '') . number_format((float) $v, 1, ',', ' ') . ' pp';
    }
}
