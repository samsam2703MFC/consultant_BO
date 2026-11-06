<?php
declare(strict_types=1);

namespace App\Services\Finances;

use App\Repositories\Analysis\WdtSalesRepository;
use App\Repositories\Document\Proforma\ProformaRepository;
use App\Repositories\Finances\ClientVolumeRepository;
use App\Services\Communication\PaymentReminderContextService;
use App\Services\Production\Cost\SectionUnitCostService;

/**
 * Sprzedaż › Klienci : wolumen i marża. Sprzedaż WDT (ta sama definicja co Analiza sprzedaży i Rentowność) klient po
 * kliencie i miesiąc po miesiącu, porównanie z tym samym okresem roku poprzedniego (N-1), marża po surowcach (koszt
 * receptury z karty produktu) i po koszcie pełnym (dział, koszty ogólne, logistyka — kalkulator kosztów), średnia
 * cena kartonu (efekt ceny), nieopłacone proformy i dopłaty różnic, alerty.
 * Kwoty w € (koszty w zł przeliczone kursem z ustawień).
 */
class ClientVolumeService
{
    /** Spadek sprzedaży (r/r, okres porównywalny) od którego klient jest na liście alertów. */
    public const DECLINE_ALERT_PCT = -15.0;
    /** Marża po surowcach niższa od średniej firmy o tyle punktów = alert. */
    public const MARGIN_GAP_PP = 10.0;
    /** Proforma nieopłacona dłużej niż tyle dni = alert. */
    public const UNPAID_DAYS = 7;

    public function __construct(
        private WdtSalesRepository $sales,
        private SectionUnitCostService $unitCosts,
        private ClientVolumeRepository $repo,
        private ProformaRepository $proformas,
        private PaymentReminderContextService $differences,
    ) {
    }

    /** Lata z danymi sprzedaży (najnowszy pierwszy). */
    public function years(): array
    {
        $range = $this->sales->monthRange();
        $from = (int) substr((string) ($range['date_min'] ?? date('Y')), 0, 4) ?: (int) date('Y');
        $to = max((int) date('Y'), $from);
        return range($to, min($from, $to));
    }

    public function report(int $year, ?string $today = null): array
    {
        $today ??= date('Y-m-d');
        $current = (int) substr($today, 0, 4) === $year;
        // Okres porównywalny : pełne miesiące do bieżącego (bieżący rok) albo cały rok.
        $lastMonth = $current ? (int) substr($today, 5, 2) : 12;
        $rate = $this->sales->eurRate() ?: 1.0;

        $rows = array_merge(
            $this->sales->sales("{$year}-01-01", "{$year}-12-31", [], ['month', 'customer', 'product']),
            $this->sales->sales(($year - 1) . '-01-01', ($year - 1) . '-12-31', [], ['month', 'customer', 'product'])
        );
        $cards = $this->sales->productCards(array_map(fn($r) => (int) $r['product_id'], $rows));
        $basis = $this->costBasis($year);

        $clients = [];
        $company = ['months' => [], 'n' => self::zero(), 'prev' => self::zero(), 'prev_full' => self::zero()];
        for ($m = 1; $m <= 12; $m++) $company['months'][$m] = ['n' => self::zero(), 'prev' => self::zero()];
        $names = $this->clientNames();
        foreach ($rows as $r) {
            $ym = (string) $r['ym'];
            $y = (int) substr($ym, 0, 4);
            $m = (int) substr($ym, 5, 2);
            $id = (int) $r['id_customer'];
            $cartons = (float) $r['cartons'];
            $value = (float) $r['value_eur'];
            $raw = (float) ($cards[(int) $r['product_id']]['raw_cost'] ?? 0) * $cartons / $rate;
            $full = $basis ? SectionUnitCostService::unitCost($basis, $r['product_section_id'] !== null ? (int) $r['product_section_id'] : null, 0.0)['total'] * $cartons / $rate + $raw : null;
            $line = ['cartons' => $cartons, 'value' => $value, 'raw' => $raw, 'full' => $full];
            $clients[$id] ??= ['id' => $id, 'name' => $names[$id] ?? ('#' . $id), 'months' => array_fill(1, 12, ['n' => self::zero(), 'prev' => self::zero()]),
                'n' => self::zero(), 'prev' => self::zero(), 'prev_full' => self::zero()];
            $key = $y === $year ? 'n' : 'prev';
            self::add($clients[$id]['months'][$m][$key], $line);
            self::add($company['months'][$m][$key], $line);
            if ($y === $year) { self::add($clients[$id]['n'], $line); self::add($company['n'], $line); }
            else {
                self::add($clients[$id]['prev_full'], $line);
                self::add($company['prev_full'], $line);
                if ($m <= $lastMonth) { self::add($clients[$id]['prev'], $line); self::add($company['prev'], $line); }
            }
        }

        $unpaid = $this->unpaidByClient($today);
        $diffs = $this->differencesByClient($today);
        $avgMargin = self::marginPct($company['n']);
        $alerts = [];
        foreach ($clients as $id => &$c) {
            $c['kpi'] = self::kpi($c['n'], $c['prev']);
            $c['unpaid'] = $unpaid[$id] ?? ['count' => 0, 'amount' => 0.0, 'overdue' => 0, 'overdue_amount' => 0.0, 'older' => 0];
            $c['differences'] = $diffs[$id] ?? ['count' => 0, 'amount' => 0.0];
            if ($c['kpi']['delta_pct'] !== null && $c['kpi']['delta_pct'] <= self::DECLINE_ALERT_PCT && $c['prev']['value'] > 0) {
                $alerts[] = ['level' => 'warning', 'client' => $c['name'], 'id' => $id, 'text' => sprintf('sprzedaż %s %% r/r (%s → %s €)',
                    self::fmt($c['kpi']['delta_pct'], 1), self::fmt($c['prev']['value']), self::fmt($c['n']['value']))];
            }
            if ($c['prev']['value'] > 0 && $c['n']['value'] == 0.0) {
                $alerts[] = ['level' => 'danger', 'client' => $c['name'], 'id' => $id, 'text' => 'brak zakupów w tym roku (klient z zakupami w N-1)'];
            }
            if ($avgMargin !== null && $c['kpi']['margin_pct'] !== null && $c['n']['value'] > 0 && $c['kpi']['margin_pct'] < $avgMargin - self::MARGIN_GAP_PP) {
                $alerts[] = ['level' => 'warning', 'client' => $c['name'], 'id' => $id, 'text' => sprintf('marża po surowcach %s %% (średnia firmy %s %%)', self::fmt($c['kpi']['margin_pct'], 1), self::fmt($avgMargin, 1))];
            }
            if ($c['kpi']['price_delta_pct'] !== null && $c['kpi']['price_delta_pct'] < -5) {
                $alerts[] = ['level' => 'info', 'client' => $c['name'], 'id' => $id, 'text' => sprintf('średnia cena kartonu %s %% r/r', self::fmt($c['kpi']['price_delta_pct'], 1))];
            }
            if ($c['unpaid']['overdue'] > 0) {
                $alerts[] = ['level' => 'danger', 'client' => $c['name'], 'id' => $id, 'text' => sprintf('%d proform(y) bez płatności ponad %d dni (%s €)', $c['unpaid']['overdue'], self::UNPAID_DAYS, self::fmt($c['unpaid']['overdue_amount']))];
            }
            if ($c['differences']['count'] > 0) {
                $alerts[] = ['level' => 'warning', 'client' => $c['name'], 'id' => $id, 'text' => sprintf('dopłaty różnic do odzyskania : %s €', self::fmt($c['differences']['amount']))];
            }
        }
        unset($c);
        uasort($clients, fn($a, $b) => $b['n']['value'] <=> $a['n']['value'] ?: $b['prev']['value'] <=> $a['prev']['value']);
        $order = ['danger' => 0, 'warning' => 1, 'info' => 2];
        usort($alerts, fn($a, $b) => $order[$a['level']] <=> $order[$b['level']]);

        $company['kpi'] = self::kpi($company['n'], $company['prev']);
        $company['active'] = count(array_filter($clients, fn($c) => $c['n']['value'] > 0));
        $company['active_prev'] = count(array_filter($clients, fn($c) => $c['prev']['value'] > 0));
        $unpaidAll = array_column($clients, 'unpaid');
        $company['unpaid'] = ['count' => array_sum(array_column($unpaidAll, 'count')), 'amount' => array_sum(array_column($unpaidAll, 'amount')),
            'older' => array_sum(array_column($unpaid, 'older'))];
        $company['differences'] = ['amount' => array_sum(array_column(array_column($clients, 'differences'), 'amount'))];
        // Udział największego klienta w sprzedaży (ryzyko koncentracji)
        $top = $clients ? reset($clients) : null;
        $company['top_share'] = $top && $company['n']['value'] > 0 ? $top['n']['value'] / $company['n']['value'] * 100 : null;
        $company['top_name'] = $top['name'] ?? null;

        return [
            'year' => $year, 'years' => $this->years(), 'last_month' => $lastMonth, 'current' => $current,
            'clients' => array_values($clients), 'company' => $company, 'alerts' => $alerts,
            'full_cost' => $basis !== null, 'cost_months' => $basis['month_ids'] ?? [], 'rate' => $rate,
            'thresholds' => ['decline' => self::DECLINE_ALERT_PCT, 'margin_gap' => self::MARGIN_GAP_PP, 'unpaid_days' => self::UNPAID_DAYS, 'recent_days' => self::RECENT_DAYS],
        ];
    }

    private static function zero(): array
    {
        return ['cartons' => 0.0, 'value' => 0.0, 'raw' => 0.0, 'full' => 0.0, 'full_known' => true];
    }

    private static function add(array &$acc, array $line): void
    {
        $acc['cartons'] += $line['cartons'];
        $acc['value'] += $line['value'];
        $acc['raw'] += $line['raw'];
        if ($line['full'] === null) $acc['full_known'] = false; else $acc['full'] += $line['full'];
    }

    private static function marginPct(array $t): ?float
    {
        return $t['value'] > 0 ? ($t['value'] - $t['raw']) / $t['value'] * 100 : null;
    }

    private static function kpi(array $n, array $prev): array
    {
        $price = $n['cartons'] > 0 ? $n['value'] / $n['cartons'] : null;
        $pricePrev = $prev['cartons'] > 0 ? $prev['value'] / $prev['cartons'] : null;
        return [
            'delta' => $n['value'] - $prev['value'],
            'delta_pct' => $prev['value'] > 0 ? ($n['value'] - $prev['value']) / $prev['value'] * 100 : null,
            'cartons_delta_pct' => $prev['cartons'] > 0 ? ($n['cartons'] - $prev['cartons']) / $prev['cartons'] * 100 : null,
            'margin' => $n['value'] - $n['raw'],
            'margin_pct' => self::marginPct($n),
            'margin_prev_pct' => self::marginPct($prev),
            'full_margin' => $n['full_known'] && $n['value'] > 0 ? $n['value'] - $n['full'] : null,
            'full_margin_pct' => $n['full_known'] && $n['value'] > 0 ? ($n['value'] - $n['full']) / $n['value'] * 100 : null,
            'price' => $price,
            'price_prev' => $pricePrev,
            'price_delta_pct' => $price !== null && $pricePrev ? ($price - $pricePrev) / $pricePrev * 100 : null,
        ];
    }

    /** Koszt pełny : miesiące kosztowe roku N (kalkulator kosztów) ; brak = tylko marża po surowcach. */
    private function costBasis(int $year): ?array
    {
        $ids = $this->repo->costMonthIds($year);
        if (!$ids) return null;
        $basis = $this->unitCosts->months($ids);
        return $basis['cartons'] > 0 ? $basis : null;
    }

    private function clientNames(): array
    {
        return $this->repo->clientNames();
    }

    /** Należności bieżące = ostatnie 90 dni ; starsze proformy bez statusu to zwykle zaległości w ewidencji płatności. */
    public const RECENT_DAYS = 90;

    private function unpaidByClient(string $today): array
    {
        $out = [];
        foreach ($this->proformas->getUnpaidForReminder() as $p) {
            $id = (int) $p['id_customer'];
            $out[$id] ??= ['count' => 0, 'amount' => 0.0, 'overdue' => 0, 'overdue_amount' => 0.0, 'older' => 0];
            $days = (int) floor((strtotime($today) - strtotime((string) $p['established_date'])) / 86400);
            if ($days > self::RECENT_DAYS) { $out[$id]['older']++; continue; }
            $out[$id]['count']++;
            $out[$id]['amount'] += (float) $p['total_price_net'];
            if ($days > self::UNPAID_DAYS) { $out[$id]['overdue']++; $out[$id]['overdue_amount'] += (float) $p['total_price_net']; }
        }
        return $out;
    }

    private function differencesByClient(string $today): array
    {
        $out = [];
        foreach ($this->differences->selectableDifferences() as $d) {
            $amount = (float) ($d['difference_amount'] ?? 0);
            if ($amount <= 0) continue;
            if ((strtotime($today) - strtotime((string) ($d['difference_date'] ?? $today))) / 86400 > self::RECENT_DAYS) continue;
            $id = (int) ($d['id_customer'] ?? 0);
            $out[$id] ??= ['count' => 0, 'amount' => 0.0];
            $out[$id]['count']++;
            $out[$id]['amount'] += $amount;
        }
        return $out;
    }

    private static function fmt(float $v, int $dec = 0): string
    {
        return number_format($v, $dec, ',', ' ');
    }
}
