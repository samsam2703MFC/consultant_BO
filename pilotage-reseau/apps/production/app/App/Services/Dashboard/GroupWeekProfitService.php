<?php
namespace App\Services\Dashboard;

use App\Repositories\Dashboard\ProfitabilityRepository;
use App\Repositories\Workshop\ExecutionRepository;
use App\Repositories\Workshop\ParamRepository;
use App\Repositories\Workshop\PlanningRepository;
use App\Services\Workshop\Planning\WeekStart;
use App\Services\Workshop\Product\CobotRouting;
use App\Services\Workshop\Product\StandardTime;
use DateTimeImmutable;

/**
 * Rentowność grup : tydzień i miesiąc (Analiza).
 *
 * Par groupe de produits (dział), pour la semaine de production en cours et le mois en cours (jusqu'à aujourd'hui),
 * et pour chacune des 12 dernières semaines (graphique) :
 *  - heures : pointages des personnes du groupe (n_prod_clock, groupe principal), coupés aux bornes de la période ;
 *  - production : cartons étiquetés (entrée en stock) × prix moyen de vente du carton sur 26 semaines (EUR) ;
 *  - CA : ventes WDT (EUR) ;
 *  - marge : CA − matières − personnel − coûts du groupe, avec la même règle que la page « Rentowność »
 *    (ProfitabilityService::compute) : coûts mensuels au prorata des jours de la période, matières au taux
 *    matières / ventes du mois du groupe (recettes aplaties, prix de fin de mois).
 * Cobot : groupe dont un poste actif est un robot collaboratif ; part = temps des étapes faites sur cobot ÷ temps de
 * toute la gamme, sur la production des 12 semaines (gammes des produits du groupe), et heures de travail économisées.
 */
final class GroupWeekProfitService
{
    public const WEEKS = 12;
    private const PRICE_DAYS = 182;

    public function __construct(
        private ProfitabilityService $profit,
        private ProfitabilityRepository $repo,
        private ExecutionRepository $execution,
        private PlanningRepository $planning,
        private ParamRepository $params,
    ) {
    }

    public function overview(?DateTimeImmutable $now = null): array
    {
        $now ??= new DateTimeImmutable();
        $today = $now->format('Y-m-d');
        $rate = $this->repo->eurRate();
        $current = WeekStart::of($today, WeekStart::day($this->params));

        // Périodes : 12 semaines (la dernière en cours, jusqu'à aujourd'hui) et le mois en cours.
        $weeks = [];
        for ($i = self::WEEKS - 1; $i >= 0; $i--) {
            $from = date('Y-m-d', strtotime($current . ' -' . (7 * $i) . ' days'));
            $to = min($today, date('Y-m-d', strtotime($from . ' +6 days')));
            $weeks[] = ['key' => 'w' . $from, 'from' => $from, 'to' => $to, 'week_end' => date('Y-m-d', strtotime($from . ' +6 days')),
                'iso_week' => (int) date('W', strtotime($from)), 'current' => $i === 0];
        }
        $month = ['key' => 'month', 'from' => $now->format('Y-m-01'), 'to' => $today, 'label' => ProfitabilityService::monthLabel($now->format('Y-m'))];
        $periods = [...$weeks, $month];
        $from = min($weeks[0]['from'], $month['from']);

        // Données lues une fois pour toute la fenêtre.
        $daily = $this->repo->dailySales($from, $today);
        $ratios = $this->materialRatios($from, $today, $rate);
        $produced = $this->repo->producedDaily($from, $today);
        $prices = $this->repo->cartonPrices(date('Y-m-d', strtotime($today . ' -' . self::PRICE_DAYS . ' days')), $today);
        $groups = $this->repo->productionGroups();
        $sectionOf = [];
        foreach ($groups as $g) if ($g['id_section'] !== null) $sectionOf[(int) $g['id']] = (int) $g['id_section'];
        $hours = $this->hours($periods, $sectionOf, $now);

        $rows = [];
        $notes = [];
        $estimated = [];
        foreach ($periods as $p) {
            $computed = $this->profit->compute($this->spec($p), $this->salesRows($daily, $ratios, $p, $rate), [], $rate);
            // Mois sans coûts saisis : un seul avertissement pour toutes les périodes (pas un par semaine).
            foreach ($computed['estimated_months'] as $ym => $source) $estimated[$ym] = $source;
            foreach ($computed['warnings'] as $w) if (!str_starts_with($w, 'Brak wprowadzonych kosztów miesięcznych')) $notes[$w] = true;
            foreach ($computed['sections'] as $id => $s) {
                if ($s['is_shared']) continue;
                $rows[$id] ??= ['id' => $id, 'name' => $s['name'], 'periods' => []];
                $contribution = $s['revenue'] - $s['materials'] - $s['staff'] - $s['overhead'];
                $rows[$id]['periods'][$p['key']] = [
                    'revenue' => round($s['revenue'], 2), 'materials' => round($s['materials'], 2), 'staff' => round($s['staff'], 2), 'overhead' => round($s['overhead'], 2),
                    'margin' => round($contribution, 2), 'margin_pct' => $s['revenue'] > 0 ? round($contribution / $s['revenue'] * 100, 1) : null,
                    'hours' => round($hours[$p['key']]['s' . $id] ?? 0.0, 1),
                ] + $this->production($produced, $prices, $p, $id);
            }
        }
        // Groupes de production sans groupe de produits (ex. formage) : heures seulement.
        foreach ($groups as $g) {
            if ($g['id_section'] !== null || !(int) $g['active']) continue;
            $key = 'g' . (int) $g['id'];
            $row = ['id' => null, 'group_id' => (int) $g['id'], 'name' => $g['name_pl'], 'periods' => [], 'hours_only' => true];
            foreach ($periods as $p) $row['periods'][$p['key']] = ['revenue' => null, 'materials' => null, 'staff' => null, 'overhead' => null, 'margin' => null,
                'margin_pct' => null, 'hours' => round($hours[$p['key']][$key] ?? 0.0, 1), 'cartons' => null, 'production' => null];
            $rows[$key] = $row;
        }

        if ($estimated) {
            ksort($estimated);
            $bySource = [];
            foreach ($estimated as $ym => $source) $bySource[$source][] = ProfitabilityService::monthLabel($ym);
            $parts = [];
            foreach ($bySource as $source => $months) $parts[] = implode(', ', $months) . ' → przyjęto ' . ProfitabilityService::monthLabel($source);
            $notes['Brak wprowadzonych kosztów miesięcznych: ' . implode('; ', $parts) . '.'] = true;
        }

        $cobots = $this->cobots($groups, $sectionOf, $produced, $weeks[0]['from'], $today);
        $out = [];
        foreach ($rows as $key => $r) {
            $has = array_filter($r['periods'], fn($x) => ($x['revenue'] ?? 0) != 0 || $x['hours'] > 0 || ($x['cartons'] ?? 0) > 0 || ($x['staff'] ?? 0) > 0);
            if (!$has) continue;
            $r['colors'] = array_values(array_unique(array_filter(array_map(fn($g) => ($r['id'] !== null ? (int) $g['id_section'] === $r['id'] : (int) $g['id'] === ($r['group_id'] ?? 0)) ? $g['color'] : null, $groups))));
            $r['cobot'] = $r['id'] !== null ? ($cobots['s' . $r['id']] ?? null) : ($cobots['g' . $r['group_id']] ?? null);
            $r['week'] = $r['periods'][$weeks[self::WEEKS - 1]['key']];
            $r['month'] = $r['periods']['month'];
            $r['series'] = array_map(fn($w) => $r['periods'][$w['key']], $weeks);
            unset($r['periods']);
            $out[] = $r;
        }
        // Parts de la semaine dans le total (heures, CA) : base du graphique « % heures ».
        foreach ($weeks as $i => $w) {
            $totalHours = array_sum(array_map(fn($r) => $r['series'][$i]['hours'], $out));
            $totalRevenue = array_sum(array_map(fn($r) => (float) ($r['series'][$i]['revenue'] ?? 0), $out));
            foreach ($out as &$r) {
                $r['series'][$i]['hours_pct'] = $totalHours > 0 ? round($r['series'][$i]['hours'] / $totalHours * 100, 1) : null;
                $r['series'][$i]['revenue_pct'] = $totalRevenue > 0 && $r['series'][$i]['revenue'] !== null ? round($r['series'][$i]['revenue'] / $totalRevenue * 100, 1) : null;
            }
            unset($r);
        }
        usort($out, fn($a, $b) => [!empty($a['hours_only']), -($a['month']['revenue'] ?? 0)] <=> [!empty($b['hours_only']), -($b['month']['revenue'] ?? 0)]);

        $sum = fn(string $period, string $k) => array_sum(array_map(fn($r) => (float) ($r[$period][$k] ?? 0), $out));
        $totals = [];
        foreach (['week', 'month'] as $period) {
            $revenue = $sum($period, 'revenue');
            $margin = $sum($period, 'margin');
            $totals[$period] = ['revenue' => round($revenue, 2), 'margin' => round($margin, 2), 'margin_pct' => $revenue > 0 ? round($margin / $revenue * 100, 1) : null,
                'hours' => round($sum($period, 'hours'), 1), 'production' => round($sum($period, 'production'), 2), 'cartons' => (int) $sum($period, 'cartons')];
        }
        return [
            'week' => $weeks[self::WEEKS - 1] + ['label' => $this->weekLabel($weeks[self::WEEKS - 1])],
            'month' => $month,
            'weeks' => array_map(fn($w) => $w + ['label' => $this->weekLabel($w)], $weeks),
            'groups' => $out,
            'totals' => $totals,
            'rate' => $rate,
            'notes' => array_keys($notes),
            'no_clock' => $sum('month', 'hours') == 0.0,
        ];
    }

    private function weekLabel(array $w): string
    {
        return 'T' . $w['iso_week'] . ' · ' . date('d.m', strtotime($w['from'])) . '–' . date('d.m', strtotime($w['week_end']));
    }

    /** Période → mois touchés et part de chaque mois (jours de la période ÷ jours du mois). */
    private function spec(array $p): array
    {
        $months = [];
        for ($d = strtotime($p['from']); $d <= strtotime($p['to']); $d = strtotime('+1 day', $d)) {
            $ym = date('Y-m', $d);
            $months[$ym] = ($months[$ym] ?? 0) + 1 / (int) date('t', $d);
        }
        return ['key' => $p['key'], 'months' => $months, 'from' => $p['from'], 'to' => $p['to'], 'end' => $p['to'], 'current' => false, 'label' => $p['key'], 'elapsed' => 1.0];
    }

    /** Taux matières / ventes par mois et groupe (PLN par EUR vendu). */
    private function materialRatios(string $from, string $to, float $rate): array
    {
        $out = [];
        foreach ($this->profit->sales(substr($from, 0, 7) . '-01', $to, $rate) as $r) {
            if ((float) $r['revenue_eur'] > 0) $out[$r['ym'] . '|' . (int) $r['id_section']] = (float) $r['materials_pln'] / (float) $r['revenue_eur'];
        }
        return $out;
    }

    /** Lignes de ventes de la période au format de ProfitabilityService::compute (mois, groupe). */
    private function salesRows(array $daily, array $ratios, array $p, float $rate): array
    {
        $rows = [];
        foreach ($daily as $d) {
            if ($d['day'] < $p['from'] || $d['day'] > $p['to']) continue;
            $key = $d['ym'] . '|' . (int) $d['id_section'];
            $rows[$key] ??= ['ym' => $d['ym'], 'id_section' => $d['id_section'], 'revenue_eur' => 0.0, 'materials_pln' => 0.0, 'cartons' => 0];
            $rows[$key]['revenue_eur'] += (float) $d['revenue_eur'];
            $rows[$key]['materials_pln'] += (float) $d['revenue_eur'] * ($ratios[$key] ?? 0.0);
            $rows[$key]['cartons'] += (int) $d['cartons'];
        }
        return array_values($rows);
    }

    /** Cartons produits et leur valeur (prix moyen de vente du carton). */
    private function production(array $produced, array $prices, array $p, int $sectionId): array
    {
        $cartons = 0;
        $value = 0.0;
        foreach ($produced as $r) {
            if ((int) $r['id_section'] !== $sectionId || $r['day'] < $p['from'] || $r['day'] > $p['to']) continue;
            $cartons += (int) $r['cartons'];
            $value += (int) $r['cartons'] * ($prices[(int) $r['id_product']] ?? 0.0);
        }
        return ['cartons' => $cartons, 'production' => round($value, 2)];
    }

    /** Heures pointées par période et groupe : clé 's' + groupe de produits, sinon 'g' + groupe de production. */
    private function hours(array $periods, array $sectionOf, DateTimeImmutable $now): array
    {
        $first = min(array_column($periods, 'from'));
        $nowTs = $now->getTimestamp();
        $out = [];
        foreach ($this->execution->clocks($first . ' 00:00:00', $now->format('Y-m-d H:i:s')) as $k) {
            $group = $k['id_primary_group'] !== null ? (int) $k['id_primary_group'] : null;
            if ($group === null) continue;
            $key = isset($sectionOf[$group]) ? 's' . $sectionOf[$group] : 'g' . $group;
            $in = strtotime($k['clock_in']);
            $outTs = $k['clock_out'] !== null ? strtotime($k['clock_out']) : $nowTs;
            foreach ($periods as $p) {
                $start = max($in, strtotime($p['from'] . ' 00:00:00'));
                $end = min($outTs, strtotime($p['to'] . ' +1 day'), $nowTs);
                if ($end > $start) $out[$p['key']][$key] = ($out[$p['key']][$key] ?? 0.0) + ($end - $start) / 3600;
            }
        }
        return $out;
    }

    /**
     * Cobots par groupe : noms, part du processus sur cobot (temps des étapes sur cobot ÷ temps de la gamme) et heures
     * de travail économisées, sur la production de la fenêtre ; sans production ni gamme : part inconnue (null).
     */
    private function cobots(array $groups, array $sectionOf, array $produced, string $from, string $to): array
    {
        $byGroup = [];
        foreach ($this->repo->cobots() as $c) $byGroup[(int) $c['id_group']][] = $c['name_pl'];
        if (!$byGroup) return [];
        $routings = $this->planning->routings();
        $items = $this->repo->productItems();
        $pieces = [];
        foreach ($produced as $r) {
            if ($r['day'] < $from || $r['day'] > $to || $r['id_section'] === null) continue;
            $pieces[(int) $r['id_section']][(int) $r['id_product']] = ($pieces[(int) $r['id_section']][(int) $r['id_product']] ?? 0) + (int) $r['cartons'] * max(1, (int) $r['ppc']);
        }
        $out = [];
        foreach ($byGroup as $groupId => $names) {
            $sectionId = $sectionOf[$groupId] ?? null;
            $key = $sectionId !== null ? 's' . $sectionId : 'g' . $groupId;
            $total = 0.0;
            $onCobot = 0.0;
            $saved = 0.0;
            foreach ($sectionId !== null ? ($pieces[$sectionId] ?? []) : [] as $productId => $qty) {
                $steps = $routings[$items[$productId] ?? 0] ?? [];
                if (!$steps) continue;
                $calc = StandardTime::routing($steps, $qty);
                foreach ($calc['steps'] as $i => $row) {
                    $total += $row['elapsed_s'];
                    if (isset($steps[$i]['cobot'])) $onCobot += $row['elapsed_s'];
                }
                $saved += CobotRouting::savedHours($steps, $qty);
            }
            $out[$key] ??= ['names' => [], 'share_pct' => null, 'saved_h' => 0.0];
            $out[$key]['names'] = array_values(array_unique([...$out[$key]['names'], ...$names]));
            $out[$key]['saved_h'] = round($out[$key]['saved_h'] + $saved, 1);
            if ($total > 0) $out[$key]['share_pct'] = round($onCobot / $total * 100, 1);
        }
        return $out;
    }
}
