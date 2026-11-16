<?php
declare(strict_types=1);

namespace App\Services\Logistics;

use App\Exceptions\CustomException;

/**
 * [303] Asystent « Transport dla zamówień » (makieta 56). Zamówienia na dziś i na jutro, których klient nie ma pojazdu
 * (LogisticsService::day()['unassigned'] — ta sama reguła co w Dniu wysyłki : przydział dnia, pojazd trasy, ostatni CMR).
 * Logistyka zaznacza klientów i przypisuje ich do pojazdu z floty (przydział dnia, jak w Dniu wysyłki). Dopóki zostaje
 * klient bez transportu, zadanie obowiązkowe Logistyki (orders_transport) blokuje ERP.
 */
final class TransportWizardService
{
    public const CODE = 'orders_transport';
    private const DAYS = ['niedz.', 'pon.', 'wt.', 'śr.', 'czw.', 'pt.', 'sob.'];

    public function __construct(private LogisticsService $logistics)
    {
    }

    /** Dni sprawdzane : dziś i jutro. @return list<string> */
    public function dates(?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable('today');
        return [$now->format('Y-m-d'), $now->modify('+1 day')->format('Y-m-d')];
    }

    /** Klienci bez transportu w sprawdzanych dniach (liczba). */
    public function uncovered(?\DateTimeImmutable $now = null): int
    {
        $n = 0;
        foreach ($this->dates($now) as $d) $n += count($this->logistics->day($d)['unassigned']);
        return $n;
    }

    /** Dane asystenta : dni z klientami bez transportu i flotą (zapełnienie). */
    public function data(?\DateTimeImmutable $now = null): array
    {
        $days = [];
        $total = 0;
        foreach ($this->dates($now) as $d) {
            $day = $this->logistics->day($d);
            $un = array_map(fn($c) => ['id' => (int) $c['id'], 'name' => (string) $c['name'], 'town' => $c['town'] ?? null, 'orders' => (int) $c['orders'],
                'pallets' => (int) $c['pallets'], 'cartons' => round((float) ($c['cartons'] ?? 0), 1)], $day['unassigned']);
            $fleet = array_map(fn($v) => ['id' => (int) $v['id'], 'label' => (string) $v['label'], 'carrier' => $v['carrier'] ?? null, 'places' => $v['places'],
                'pallets' => (int) $v['pallets'], 'customers' => count($v['customers']), 'departure' => $v['departure'] ?? null,
                'names' => array_values(array_map(fn($cid) => (string) ($day['by_id'][$cid]['name'] ?? ''), $v['customers']))],
                array_values(array_filter($day['fleet'], fn($v) => $v['active'] || $v['customers'])));
            $total += count($un);
            $days[] = ['date' => $d, 'label' => self::DAYS[(int) date('w', strtotime($d))] . ' ' . date('d.m', strtotime($d)), 'unassigned' => $un, 'fleet' => $fleet,
                'customers' => count($day['customers'])];
        }
        return ['days' => $days, 'uncovered' => $total];
    }

    /** Klienci dnia → pojazd z floty (przydział dnia). @return int liczba przypisanych */
    public function assign(array $in, ?int $userId, ?\DateTimeImmutable $now = null): int
    {
        $date = (string) ($in['date'] ?? '');
        if (!in_array($date, $this->dates($now), true)) throw new CustomException('Ten dzień nie jest w asystencie (dziś i jutro).', 'errors', 422);
        $vehicle = (int) ($in['vehicle_id'] ?? 0);
        if ($vehicle <= 0) throw new CustomException('Wybierz transport.', 'errors', 422);
        $ids = array_values(array_unique(array_filter(array_map('intval', (array) ($in['customers'] ?? [])), fn($i) => $i > 0)));
        if (!$ids) throw new CustomException('Zaznacz klientów.', 'errors', 422);
        $day = $this->logistics->day($date);
        $fleetIds = array_map(fn($v) => (int) $v['id'], $day['fleet']);
        if (!in_array($vehicle, $fleetIds, true)) throw new CustomException('Ten pojazd nie jest we flocie.', 'errors', 422);
        $known = array_map(fn($c) => (int) $c['id'], $day['customers']);
        foreach ($ids as $cid) {
            if (!in_array($cid, $known, true)) throw new CustomException('Ten klient nie ma zamówień w tym dniu.', 'errors', 422);
            $this->logistics->assign(['date' => $date, 'customer_id' => $cid, 'vehicle_id' => $vehicle], $userId);
        }
        return count($ids);
    }
}
