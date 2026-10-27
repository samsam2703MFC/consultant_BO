<?php
declare(strict_types=1);

namespace App\Services\Logistics;

use App\Repositories\Logistics\ShippingDayRepository;

/**
 * Dzień wysyłki (audyt 2, P3) : jeden dzień logistyki jako 7 etapów, pokazanych nad zakładkami Dzień wysyłki —
 * 1 Plan (trasy, pojazdy) → 2 Załadunek (palety) → 3 Powiadomienia klientów → 4 Dokumenty WZ / CMR / WDT →
 * 5 KSeF i sklepy → 6 Kierowcy (wydruki, tablet) → 7 Po dostawie (potwierdzenia, problemy).
 * Każdy etap : ile klientów dnia jest gotowych, stan i link do strony, na której się go kończy. Nic tu nie zapisuje.
 */
final class ShippingDayService
{
    public function __construct(private LogisticsService $logistics, private ShippingDayRepository $repo)
    {
    }

    /** @return array{date:string, customers:int, steps:list<array{n:int, label:string, detail:string, state:string, url:string}>} */
    public function steps(string $date): array
    {
        $day = $this->logistics->day($date);
        $customers = $day['customers'];
        $total = count($customers);
        $ids = array_column($customers, 'id');
        $shipments = $this->repo->shipments($date);
        // Dokumenty utworzone = klient zaplanowany i załadowany (pojazd na CMR, palety zeskanowane do WZ).
        $planned = count(array_filter($customers, fn($c) => $c['vehicle_id'] !== null || isset($shipments[$c['id']])));
        $loaded = count(array_filter($customers, fn($c) => isset($shipments[$c['id']]) || ((int) $c['pallets'] > 0 && (int) $c['scanned'] >= (int) $c['pallets'])));
        $notified = count(array_intersect($ids, $this->repo->notifiedCustomers($date)));
        $documents = count(array_intersect_key($shipments, array_flip($ids)));
        $accepted = count(array_filter($shipments, fn($s) => $s['ksef'] === 'accepted'));
        $ksefFailed = count(array_filter($shipments, fn($s) => in_array($s['ksef'], ['failed', 'rejected'], true)));
        $shops = $this->repo->shopNotices($date);
        $shopFailed = (int) ($shops['failed'] ?? 0);
        $shopWaiting = (int) ($shops['pending'] ?? 0) + (int) ($shops['processing'] ?? 0);
        $vehicles = count(array_unique(array_filter(array_column($customers, 'vehicle_id'), fn($v) => $v !== null)));
        $runs = $this->repo->runs($date);
        $stops = array_intersect_key($this->repo->stops($date), array_flip($ids));
        $delivered = count(array_filter($stops, fn($s) => $s === 'delivered'));
        $problems = count(array_filter($stops, fn($s) => $s === 'problem'));

        $q = '?date=' . rawurlencode($date);
        $count = fn(int $done, int $of) => $of > 0 ? "{$done} / {$of}" : '—';
        $state = fn(int $done, int $of, bool $warn = false) => $warn ? 'warn' : ($of > 0 && $done >= $of ? 'done' : ($done > 0 ? 'current' : 'todo'));
        $steps = [
            ['label' => 'Plan : trasy i pojazdy', 'detail' => $count($planned, $total) . ' klientów z pojazdem', 'state' => $state($planned, $total), 'url' => '/logistics' . $q],
            ['label' => 'Załadunek palet', 'detail' => $count($loaded, $total) . ' klientów zeskanowanych', 'state' => $state($loaded, $total), 'url' => '/logistics/loading-list' . $q],
            ['label' => 'Powiadomienia klientów', 'detail' => $count($notified, $total) . ' powiadomionych', 'state' => $state($notified, $total), 'url' => '/logistics/notices' . $q],
            ['label' => 'Dokumenty WZ / CMR / WDT', 'detail' => $count($documents, $total) . ' klientów (Wyślij dzień)', 'state' => $state($documents, $total), 'url' => '/shipping/wizard' . $q],
            ['label' => 'KSeF i sklepy', 'detail' => ($shipments ? "KSeF {$accepted} / " . count($shipments) : 'KSeF —')
                . ($shopFailed ? " · sklepy : {$shopFailed} błąd" : ($shopWaiting ? " · sklepy : {$shopWaiting} w kolejce" : '')),
                'state' => $state($accepted, count($shipments), $ksefFailed > 0 || $shopFailed > 0),
                'url' => $ksefFailed || ($shipments && $accepted < count($shipments)) ? '/client-orders/queue?rule=ksef' : ($shopFailed ? '/communication/outbox?source=shipment' : '/shipments')],
            ['label' => 'Kierowcy : wydruki i tablet', 'detail' => $count($runs, $vehicles) . ' tras otwartych na tablecie', 'state' => $state($runs, $vehicles), 'url' => '/logistics/documents' . $q],
            ['label' => 'Po dostawie', 'detail' => $count($delivered, $total) . ' dostarczonych' . ($problems ? " · {$problems} problem" : ''),
                'state' => $state($delivered, $total, $problems > 0), 'url' => '/logistics/deliveries' . $q],
        ];
        foreach ($steps as $i => &$s) $s = ['n' => $i + 1] + $s;
        return ['date' => $date, 'customers' => $total, 'steps' => $steps];
    }
}
