<?php
declare(strict_types=1);

namespace App\Services\Workshop\Supply;

use App\Core\Api\Workshop\ApiContext;
use App\Core\Api\Workshop\Validator;
use App\Repositories\Workshop\ParamRepository;
use App\Repositories\Workshop\SupplyRepository;
use App\Services\Purchasing\CostOptimizationService;
use App\Services\Workshop\Planning\WeekStart;

/**
 * Dostawy surowców (Produkcja) : kto dostarcza co i kiedy.
 *
 * Tydzień produkcji (week.start_day) : zamówienia u dostawców według dnia dostawy — oczekiwane, częściowo przyjęte,
 * przyjęte (w dniu przyjęcia), po terminie ; zaległe z wcześniejszych tygodni i bez terminu osobno. Dostawcy : co
 * dostarczają (bieżący dostawca surowca, surowce z zamówień i faktur z 6 miesięcy), w jakie dni zwykle dostarczają
 * (przyjęcia zamówień i daty faktur z 6 miesięcy), ostatnia i najbliższa dostawa, otwarte zamówienia.
 */
final class DeliveryScheduleService
{
    private const HISTORY_DAYS = 182;

    public function __construct(private SupplyRepository $repo, private ParamRepository $params)
    {
    }

    public function week(?string $date, ApiContext $context): array
    {
        (new Validator(['date' => $date]))->date('date')->throwIfInvalid();
        $today = date('Y-m-d');
        $from = WeekStart::of($date ?: $today, WeekStart::day($this->params));
        $to = date('Y-m-d', strtotime($from . ' +6 days'));
        $lang = $context->translator->lang();

        $days = [];
        for ($i = 0; $i < 7; $i++) {
            $d = date('Y-m-d', strtotime($from . " +{$i} days"));
            $days[$d] = ['date' => $d, 'weekday' => (int) date('N', strtotime($d)), 'today' => $d === $today, 'deliveries' => []];
        }
        $late = [];
        $undated = [];
        foreach ($this->orders($this->repo->schedule($from, $to), $lang) as $o) {
            // Przyjęte : w dniu (ostatniego) przyjęcia, gdy wypada w tygodniu ; inaczej w dniu planowanym.
            $day = $o['status'] === 'received' && $o['received_on'] && isset($days[$o['received_on']]) ? $o['received_on'] : $o['planned_date'];
            $o['state'] = $o['status'] === 'received' ? 'received'
                : ($o['planned_date'] !== null && $o['planned_date'] < $today ? 'late' : ($o['status'] === 'partial' ? 'partial' : 'expected'));
            if ($day !== null && isset($days[$day])) {
                $o['date'] = $day;
                $days[$day]['deliveries'][] = $o;
            } elseif ($o['planned_date'] === null) {
                $o['date'] = null;
                $undated[] = $o;
            } elseif ($o['planned_date'] < $from && $o['status'] !== 'received') {
                $o['date'] = $o['planned_date'];
                $late[] = $o;
            }
        }

        $inWeek = array_merge(...array_map(fn($d) => $d['deliveries'], array_values($days)));
        $suppliers = $this->suppliers($today, $lang, $inWeek);
        return [
            'week_start' => $from, 'week_end' => $to, 'iso_week' => (int) date('W', strtotime($from)), 'today' => $today,
            'history_days' => self::HISTORY_DAYS,
            'days' => array_values($days), 'late' => $late, 'undated' => $undated,
            'totals' => [
                'deliveries' => count($inWeek), 'suppliers' => count(array_unique(array_map(fn($o) => $o['supplier']['id'], $inWeek))),
                'received' => count(array_filter($inWeek, fn($o) => $o['state'] === 'received')),
                'late' => count($late) + count(array_filter($inWeek, fn($o) => $o['state'] === 'late')), 'undated' => count($undated),
            ],
            'suppliers' => $suppliers,
            'can_order' => $context->can('prod.supply.write'),
        ];
    }

    /** Linie zamówień → zamówienia z liniami w jednostce wyświetlanej (kg, l…). */
    private function orders(array $rows, string $lang): array
    {
        $out = [];
        foreach ($rows as $r) {
            $id = (int) $r['id'];
            $out[$id] ??= ['order_id' => $id, 'code' => $r['code'], 'status' => $r['status'], 'planned_date' => $r['delivery_date'], 'received_on' => null,
                'supplier' => ['id' => (int) $r['id_supplier'], 'name' => trim((string) $r['supplier_name']), 'phone' => $r['supplier_phone'] ?: null], 'lines' => []];
            if ($r['last_on'] !== null && ($out[$id]['received_on'] === null || $r['last_on'] > $out[$id]['received_on'])) $out[$id]['received_on'] = $r['last_on'];
            $unit = CostOptimizationService::displayUnit($r['unit']);
            $out[$id]['lines'][] = ['material_id' => (int) $r['id_raw_material'], 'name' => $lang === 'fr' && $r['name_fr'] ? $r['name_fr'] : $r['name'],
                'packaging' => (bool) $r['packaging'], 'quantity' => (float) $r['quantity'], 'received' => round((float) $r['received_qty'], 3),
                'unit' => $unit['label'], 'factor' => $unit['factor']];
        }
        return array_values($out);
    }

    /** Dostawcy : co, kiedy zwykle, ostatnia / najbliższa dostawa ; najpierw ci z dostawą w tygodniu. */
    /**
     * Zwykłe dni dostaw dostawcy (1 = poniedziałek … 7) : dni z karty dostawcy (maska), a bez nich z historii —
     * co najmniej 2 dostawy i ćwierć wszystkich dostaw w tym dniu tygodnia.
     */
    public static function usualDays(?int $mask, array $historyDays): array
    {
        if ($mask) return array_values(array_filter(range(1, 7), fn($d) => $mask & (1 << ($d - 1))));
        $counts = array_fill(1, 7, 0);
        foreach ($historyDays as $d) $counts[(int) date('N', strtotime($d))]++;
        $total = count($historyDays);
        return array_keys(array_filter($counts, fn($n) => $total > 0 && $n >= 2 && $n >= $total * 0.25));
    }

    /** Pierwszy zwykły dzień dostawy od daty (włącznie) ; bez zwykłych dni : ta data. */
    public static function nextUsualDay(string $from, array $usual): string
    {
        if (!$usual) return $from;
        for ($k = 0; $k < 7; $k++) {
            $d = date('Y-m-d', strtotime($from . " +{$k} days"));
            if (in_array((int) date('N', strtotime($d)), $usual, true)) return $d;
        }
        return $from;
    }

    private function suppliers(string $today, string $lang, array $inWeek): array
    {
        $since = date('Y-m-d', strtotime($today . ' -' . self::HISTORY_DAYS . ' days'));
        $materials = [];
        foreach ($this->repo->suppliedMaterials($since) as $m) {
            $materials[(int) $m['id_supplier']][] = ['id' => (int) $m['id'], 'name' => $lang === 'fr' && $m['name_fr'] ? $m['name_fr'] : $m['name'],
                'packaging' => (bool) $m['packaging'], 'current' => (bool) $m['current_supplier']];
        }
        $history = [];
        foreach ($this->repo->deliveryDays($since) as $d) $history[(int) $d['id_supplier']][$d['day']] = true;
        $open = array_column($this->repo->openBySupplier($today), null, 'id_supplier');
        $weekFirst = [];
        foreach ($inWeek as $o) $weekFirst[$o['supplier']['id']] = min($weekFirst[$o['supplier']['id']] ?? '9999-12-31', $o['date']);

        $out = [];
        foreach ($this->repo->suppliersWithSupply() as $s) {
            $id = (int) $s['id'];
            $days = array_keys($history[$id] ?? []);
            $counts = array_fill(1, 7, 0);
            foreach ($days as $d) $counts[(int) date('N', strtotime($d))]++;
            $total = count($days);
            $usual = self::usualDays($s['delivery_weekdays'] !== null ? (int) $s['delivery_weekdays'] : null, $days);
            $o = $open[$id] ?? null;
            $out[] = [
                'id' => $id, 'name' => trim((string) $s['name']), 'phone' => $s['phone'] ?: null, 'email' => $s['email'] ?: null, 'active' => (bool) $s['is_active'],
                'materials' => $materials[$id] ?? [],
                'weekdays' => array_map(fn($day, $n) => ['day' => $day, 'deliveries' => $n], array_keys($counts), array_values($counts)),
                'usual_days' => $usual, 'deliveries' => $total, 'last_delivery' => $days ? max($days) : null,
                'next_delivery' => $o['next_delivery'] ?? null, 'open_orders' => (int) ($o['open_orders'] ?? 0), 'in_week' => $weekFirst[$id] ?? null,
            ];
        }
        usort($out, fn($a, $b) => [$a['in_week'] === null, $a['in_week'] ?? '', $a['next_delivery'] === null, $a['next_delivery'] ?? '', !$a['materials'], mb_strtolower($a['name'])]
            <=> [$b['in_week'] === null, $b['in_week'] ?? '', $b['next_delivery'] === null, $b['next_delivery'] ?? '', !$b['materials'], mb_strtolower($b['name'])]);
        return $out;
    }
}
