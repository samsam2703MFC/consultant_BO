<?php
declare(strict_types=1);

namespace App\Services\Logistics;

use App\Core\Api\Workshop\ApiContext;
use App\Core\Api\Workshop\ApiException;
use App\Core\Api\Workshop\Validator;
use App\Core\TransactionManager;
use App\Exceptions\CustomException;
use App\Repositories\Logistics\DeliveryRepository;
use App\Repositories\Workshop\ExecutionRepository;
use App\Repositories\Workshop\ParamRepository;
use App\Services\Notification\AppNotificationService;
use App\Services\Shipping\ShippingDocumentService;
use App\Services\Utils\DocumentLabels;
use App\Services\Utils\TCPDFService;

/**
 * Tablet kierowcy : przejazd pojazdu w dniu wysyłki (klienci w kolejności rozładunku z załadunku dnia, palety,
 * kartony, waga, dokumenty, adres i telefon), start i koniec trasy, potwierdzenie dostawy (odbiorca, podpis,
 * zdjęcie, palety oddane i zwrócone) albo problem (przyczyna, notatka, zdjęcie → dzwonek w ERP).
 * W ERP : potwierdzenia dnia (Logistyka › Dostawy), PDF potwierdzenia dostawy, status u klienta w załadunku dnia.
 */
final class DriverService
{
    public const REASONS = ['closed', 'refused', 'damaged', 'missing', 'late', 'other'];
    public const REASON_LABELS = [
        'closed' => ['Zamknięte', 'Fermé'], 'refused' => ['Odmowa przyjęcia', 'Refus'], 'damaged' => ['Uszkodzenie', 'Casse'],
        'missing' => ['Brak towaru', 'Manquant'], 'late' => ['Opóźnienie', 'Retard'], 'other' => ['Inny', 'Autre'],
    ];
    private const MAX_SIGNATURE_BYTES = 300_000;

    public function __construct(
        private LogisticsService $logistics,
        private DeliveryRepository $deliveries,
        private ExecutionRepository $devices,
        private ParamRepository $params,
        private TransactionManager $tx,
        private AppNotificationService $notifications,
        private ShippingDocumentService $documents,
    ) {
    }

    /** Dzień kierowcy : pojazdy dnia, wybrany pojazd (z tabletu albo z listy), przejazd i postoje w kolejności. */
    public function day(ApiContext $context, ?string $date, ?int $vehicleId): array
    {
        $device = $this->driverDevice($context);
        $vehicleId ??= $device['id_carrier_vehicle'] !== null ? (int) $device['id_carrier_vehicle'] : null;
        if ($date === null) {
            // Po północy kierowca kończy trasę wczorajszej wysyłki : dzień otwartego przejazdu.
            $open = $vehicleId !== null ? $this->deliveries->openRun($vehicleId, date('Y-m-d', strtotime('-1 day'))) : null;
            $date = $open['ship_date'] ?? date('Y-m-d');
        }
        $this->checkDate($date);
        $plan = $this->logistics->day($date);
        $vehicles = $this->deliveries->vehicles();
        $fleet = array_values(array_filter($plan['fleet'], fn($v) => $v['customers'] || $v['id'] === $vehicleId));
        if ($vehicleId === null && $fleet) $vehicleId = (int) $fleet[0]['id'];
        $truck = null;
        foreach ($fleet as $v) if ((int) $v['id'] === $vehicleId) $truck = $v;

        $stops = [];
        if ($vehicleId !== null) {
            $ids = $truck['customers'] ?? [];
            $info = $this->deliveries->customers($ids);
            $docs = $this->deliveries->documents($date);
            $recorded = [];
            foreach ($this->deliveries->stops($date) as $s) $recorded[(int) $s['id_customer']] = $s;
            $n = 0;
            foreach ($ids as $cid) {
                $c = $plan['by_id'][$cid];
                $stops[] = $this->presentStop(++$n, $c, $info[$cid] ?? [], $docs[$cid] ?? [], $recorded[$cid] ?? null);
            }
        }
        $run = $vehicleId !== null ? $this->deliveries->run($date, $vehicleId) : null;
        $vehicle = $vehicleId !== null ? ($vehicles[$vehicleId] ?? null) : null;
        return [
            'date' => $date,
            'dates' => array_map(fn($d) => date('Y-m-d', strtotime($date . " {$d} day")), [-1, 0, 1]),
            'device' => ['id' => (int) $device['id'], 'name' => $device['name'], 'vehicle_id' => $device['id_carrier_vehicle'] !== null ? (int) $device['id_carrier_vehicle'] : null],
            'vehicles' => array_map(fn($v) => ['id' => (int) $v['id'], 'label' => $v['label'], 'carrier' => $v['carrier'], 'customers' => count($v['customers']),
                'pallets' => $v['pallets'], 'departure' => $v['departure']], $fleet),
            'vehicle' => $vehicle ? $vehicle + ['departure' => $truck['departure'] ?? null, 'loading_from' => $truck['loading_from'] ?? null,
                'pallets' => $truck['pallets'] ?? 0, 'weight_kg' => $truck['weight_kg'] ?? 0, 'places' => $truck['places'] ?? null] : null,
            'run' => $run ? ['driver_name' => $run['driver_name'], 'started_at' => $run['started_at'], 'finished_at' => $run['finished_at']] : null,
            'stops' => $stops,
            'counts' => [
                'stops' => count($stops),
                'delivered' => count(array_filter($stops, fn($s) => $s['status'] === 'delivered')),
                'problem' => count(array_filter($stops, fn($s) => $s['status'] === 'problem')),
            ],
            'reasons' => array_map(fn($k) => ['code' => $k, 'label' => self::REASON_LABELS[$k][$context->translator->lang() === 'fr' ? 1 : 0]], self::REASONS),
        ];
    }

    /** Start albo koniec trasy (kierowca z nazwiska). */
    public function run(ApiContext $context, array $body): array
    {
        $device = $this->driverDevice($context);
        $v = (new Validator($body))->only(['date', 'vehicle_id', 'action', 'driver_name'])
            ->date('date', true, false)->integer('vehicle_id', true, 1)->choice('action', ['start', 'finish'])->string('driver_name', 100, false, true);
        $v->throwIfInvalid();
        $date = $v->value('date');
        $this->checkDate($date);
        $vehicleId = (int) $v->value('vehicle_id');
        if (!isset($this->deliveries->vehicles()[$vehicleId])) throw ApiException::validation([['field' => 'vehicle_id', 'code' => 'REFERENCE_NOT_FOUND', 'params' => ['id' => $vehicleId]]]);
        $data = ['id_device' => (int) $device['id']];
        if ($v->provided('driver_name')) $data['driver_name'] = $v->value('driver_name');
        $current = $this->deliveries->run($date, $vehicleId);
        if ($v->value('action') === 'start') {
            $data['started_at'] = $current['started_at'] ?? date('Y-m-d H:i:s');
            $data['finished_at'] = null;
        } else {
            $data['started_at'] = $current['started_at'] ?? date('Y-m-d H:i:s');
            $data['finished_at'] = date('Y-m-d H:i:s');
        }
        $this->deliveries->saveRun($date, $vehicleId, $data);
        $run = $this->deliveries->run($date, $vehicleId);
        return ['driver_name' => $run['driver_name'], 'started_at' => $run['started_at'], 'finished_at' => $run['finished_at']];
    }

    /**
     * Postój : dostarczono (odbiorca i podpis obowiązkowe) albo problem (przyczyna obowiązkowa). Zapis ponowiony
     * (np. po braku sieci) nadpisuje ten sam postój — kolejka tabletu może wysłać go drugi raz bez szkody.
     */
    public function stop(ApiContext $context, array $body): array
    {
        $device = $this->driverDevice($context);
        $v = (new Validator($body))->only(['date', 'customer_id', 'vehicle_id', 'status', 'reason', 'receiver_name', 'pallets_delivered', 'pallets_returned',
                'note', 'signature', 'photo', 'lat', 'lng', 'recorded_at', 'driver_name', 'client_uuid'])
            ->date('date', true, false)->integer('customer_id', true, 1)->integer('vehicle_id', false, 1, null, true)
            ->choice('status', ['delivered', 'problem'])->choice('reason', self::REASONS, false, true)
            ->string('receiver_name', 100, false, true)->integer('pallets_delivered', false, 0, 99, true)->integer('pallets_returned', false, 0, 99, true)
            ->string('note', 500, false, true)->decimal('lat', false, -90, 90, true)->decimal('lng', false, -180, 180, true)
            ->datetime('recorded_at')->string('driver_name', 100, false, true)->string('client_uuid', 64, false, true);
        $status = $v->value('status');
        if ($status === 'delivered' && $v->value('receiver_name') === null) $v->add('receiver_name', 'REQUIRED');
        if ($status === 'problem' && $v->value('reason') === null) $v->add('reason', 'REQUIRED');
        $signature = $photo = null;
        if ($v->hasRaw('signature') && $v->raw('signature') !== null) {
            $signature = $this->image($v, 'signature', ['image/png'], self::MAX_SIGNATURE_BYTES);
        } elseif ($status === 'delivered') {
            $v->add('signature', 'REQUIRED');
        }
        if ($v->hasRaw('photo') && $v->raw('photo') !== null) {
            $photo = $this->image($v, 'photo', ['image/jpeg', 'image/png'], max(100, (int) $this->params->get('driver.photo_max_kb', '1500')) * 1024);
        }
        $v->throwIfInvalid();

        $date = $v->value('date');
        $this->checkDate($date);
        $customerId = (int) $v->value('customer_id');
        $plan = $this->logistics->day($date);
        $customer = $plan['by_id'][$customerId] ?? throw ApiException::validation([['field' => 'customer_id', 'code' => 'DRIVER_CUSTOMER_NOT_PLANNED', 'params' => ['id' => $customerId]]]);
        $recordedAt = $v->value('recorded_at');
        if ($recordedAt === null || abs(strtotime($recordedAt) - time()) > 2 * 86400) $recordedAt = date('Y-m-d H:i:s');

        $data = [
            'ship_date' => $date, 'id_customer' => $customerId,
            'id_carrier_vehicle' => $v->value('vehicle_id') ?? $customer['vehicle_id'],
            'status' => $status, 'reason' => $status === 'problem' ? $v->value('reason') : null,
            'receiver_name' => $v->value('receiver_name'), 'pallets_delivered' => $v->value('pallets_delivered'), 'pallets_returned' => $v->value('pallets_returned'),
            'note' => $v->value('note'), 'lat' => $v->value('lat'), 'lng' => $v->value('lng'), 'recorded_at' => $recordedAt,
            'driver_name' => $v->value('driver_name'), 'id_device' => (int) $device['id'],
        ];
        $stopId = $this->tx->execute(function () use ($data, $signature, $photo) {
            $id = $this->deliveries->saveStop($data);
            if ($signature) $this->deliveries->replaceMedia($id, 'signature', $signature[0], $signature[1]);
            if ($photo) $this->deliveries->replaceMedia($id, 'photo', $photo[0], $photo[1]);
            return $id;
        });
        if ($status === 'problem') {
            $reason = self::REASON_LABELS[$data['reason']][0];
            $this->notifications->publish('delivery_problem', 'warning', 'Problem w dostawie',
                $customer['name'] . ' : ' . $reason . ($data['note'] ? ' — ' . $data['note'] : '') . '.', '/logistics/deliveries?date=' . $date,
                ['id_stop' => $stopId, 'id_customer' => $customerId, 'date' => $date], 'delivery_problem:' . $date . ':' . $customerId . ':' . $recordedAt);
        }
        return ['id' => $stopId, 'customer_id' => $customerId, 'status' => $status, 'recorded_at' => $recordedAt];
    }

    /** Dokumenty dnia dla kierowców (WZ, CMR, WDT na papier) — ten sam plik co w wysyłce. */
    public function documents(ApiContext $context, string $date): array
    {
        $this->driverDevice($context);
        $this->checkDate($date);
        $file = $this->documents->generateDayFile($date);
        return ['filename' => $file['filename'], 'content' => $file['file_string']];
    }

    // --- ERP -----------------------------------------------------------------------------------------

    /** Logistyka › Dostawy : przejazdy i postoje dnia według pojazdu, z klientami bez potwierdzenia. */
    public function erpDay(string $date): array
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !strtotime($date)) throw new CustomException('Nieprawidłowa data.', 'errors', 422);
        $plan = $this->logistics->day($date);
        $vehicles = $this->deliveries->vehicles();
        $runs = array_column($this->deliveries->runs($date), null, 'id_carrier_vehicle');
        $stops = [];
        foreach ($this->deliveries->stops($date) as $s) $stops[(int) $s['id_customer']] = $s;
        $trucks = [];
        foreach ($plan['fleet'] as $truck) {
            if (!$truck['customers'] && !isset($runs[$truck['id']])) continue;
            $rows = [];
            foreach ($truck['customers'] as $cid) {
                $c = $plan['by_id'][$cid];
                $rows[] = ['customer_id' => $cid, 'name' => $c['name'], 'town' => $c['town'], 'pallets' => $c['pallets'], 'stop' => $this->presentErpStop($stops[$cid] ?? null)];
                unset($stops[$cid]);
            }
            $run = $runs[$truck['id']] ?? null;
            $trucks[] = ['id' => $truck['id'], 'label' => $truck['label'], 'carrier' => $truck['carrier'], 'departure' => $truck['departure'],
                'driver' => $run['driver_name'] ?? ($vehicles[$truck['id']]['driver'] ?? null), 'started_at' => $run['started_at'] ?? null, 'finished_at' => $run['finished_at'] ?? null,
                'rows' => $rows, 'delivered' => count(array_filter($rows, fn($r) => ($r['stop']['status'] ?? null) === 'delivered')),
                'problems' => count(array_filter($rows, fn($r) => ($r['stop']['status'] ?? null) === 'problem'))];
        }
        // Postoje u klientów spoza planu pojazdów (np. przeniesionych) : osobno, żeby nic nie zginęło.
        $other = array_values(array_map(fn($s) => ['customer_id' => (int) $s['id_customer'], 'name' => $s['customer'], 'town' => null, 'pallets' => null,
            'stop' => $this->presentErpStop($s)], $stops));
        $all = array_merge(...array_map(fn($t) => $t['rows'], $trucks), ...[$other]);
        return [
            'date' => $date,
            'trucks' => $trucks,
            'other' => $other,
            'totals' => [
                'customers' => count($all),
                'delivered' => count(array_filter($all, fn($r) => ($r['stop']['status'] ?? null) === 'delivered')),
                'problems' => count(array_filter($all, fn($r) => ($r['stop']['status'] ?? null) === 'problem')),
            ],
        ];
    }

    /** Status dostawy na klienta (załadunek dnia). @return array<int, array> */
    public function statuses(string $date): array
    {
        $out = [];
        foreach ($this->deliveries->stops($date) as $s) $out[(int) $s['id_customer']] = $this->presentErpStop($s);
        return $out;
    }

    public function media(int $id): array
    {
        return $this->deliveries->media($id) ?? throw new CustomException('Nie znaleziono pliku.', 'errors', 404);
    }

    /** PDF « Potwierdzenie dostawy » : klient, pojazd, kierowca, palety, odbiorca, podpis, zdjęcie. */
    public function proofPdf(int $stopId, string $lang = 'pl'): array
    {
        $s = $this->deliveries->stopDetail($stopId) ?? throw new CustomException('Nie znaleziono dostawy.', 'errors', 404);
        $lang = DocumentLabels::lang($lang);
        $i = $lang === 'pl' ? 0 : 1;
        $t = fn(string $pl, string $fr) => $i ? $fr : $pl;
        $pdf = new TCPDFService('P', 'mm', 'A4', true, 'UTF-8', false);
        $title = $t('Potwierdzenie dostawy', 'Confirmation de livraison');
        $pdf->brandPage($title, ['ref' => $s['customer'] . ' · ' . $s['ship_date'], 'lang' => $lang]);
        $pdf->brandHeader($title, [
            [$t('Dzień wysyłki', 'Date d’expédition'), date('d.m.Y', strtotime($s['ship_date']))],
            [$t('Zapis', 'Enregistré'), date('d.m.Y H:i', strtotime($s['recorded_at']))],
            [$t('Pojazd', 'Véhicule'), implode(' · ', array_unique(array_filter([trim((string) ($s['regis_num'] ?? '')), trim((string) ($s['carrier'] ?? ''))]))) ?: '—'],
            [$t('Kierowca', 'Chauffeur'), $s['driver_name'] ?: '—'],
        ], (string) $s['customer']);
        $address = trim(implode(' ', array_filter([$s['fac_street'], $s['fac_street_num']])) . ', ' . implode(' ', array_filter([$s['fac_zip'], $s['fac_town']])) . ' ' . ($s['fac_country'] ?? ''), ' ,');
        $pdf->brandSection($t('Klient', 'Client'));
        $pdf->brandText($s['customer'] . ($address !== '' ? "\n" . $address : ''));
        $pdf->brandSection($t('Dostawa', 'Livraison'));
        $lines = [];
        if ($s['status'] === 'delivered') {
            $lines[] = $t('Dostarczono — odebrał(a) : ', 'Livré — réceptionné par : ') . $s['receiver_name'];
        } else {
            $lines[] = $t('Problem : ', 'Problème : ') . (self::REASON_LABELS[$s['reason']][$i] ?? $s['reason']);
        }
        if ($s['pallets_delivered'] !== null) $lines[] = $t('Palety dostarczone : ', 'Palettes livrées : ') . $s['pallets_delivered'];
        if ($s['pallets_returned'] !== null) $lines[] = $t('Palety zwrócone (EUR) : ', 'Palettes rendues (EUR) : ') . $s['pallets_returned'];
        if ($s['note']) $lines[] = $t('Uwagi : ', 'Remarques : ') . $s['note'];
        if ($s['lat'] !== null && $s['lng'] !== null) $lines[] = $t('Położenie : ', 'Position : ') . $s['lat'] . ', ' . $s['lng'];
        $pdf->brandText(implode("\n", $lines));
        foreach ($s['media'] as $m) {
            $file = $this->deliveries->media((int) $m['id']);
            if (!$file) continue;
            $pdf->brandSection($m['kind'] === 'signature' ? $t('Podpis odbiorcy', 'Signature du réceptionnaire') : $t('Zdjęcie', 'Photo'));
            $w = $m['kind'] === 'signature' ? 90 : 120;
            $y = $pdf->GetY();
            $pdf->Image('@' . $file['content'], $pdf->getMargins()['left'], $y, $w, 0, $m['kind'] === 'signature' ? 'PNG' : 'JPG', '', 'N', true, 150, '', false, false, 0, true);
            $pdf->SetY(max($pdf->GetY(), $y) + 4);
        }
        $name = trim((string) preg_replace('/[^A-Za-z0-9\-]+/', '_', (string) $s['customer']), '_');
        return ['filename' => 'POTWIERDZENIE_DOSTAWY_' . $name . '_' . $s['ship_date'] . '.pdf', 'content' => $pdf->Output('', 'S')];
    }

    // --- Pomocnicze ------------------------------------------------------------------------------------

    private function driverDevice(ApiContext $context): array
    {
        $device = $this->devices->find((int) $context->deviceId) ?? throw new ApiException('DEVICE_UNAUTHENTICATED', 401);
        if (($device['mode'] ?? 'atelier') !== 'driver') throw new ApiException('DEVICE_NOT_DRIVER', 403);
        return $device;
    }

    /** Dzień wysyłki : od tygodnia wstecz do jutra. */
    private function checkDate(string $date): void
    {
        $ts = strtotime($date);
        if ($ts === false || $ts < strtotime('-7 days midnight') || $ts > strtotime('+1 day midnight')) {
            throw ApiException::validation([['field' => 'date', 'code' => 'DRIVER_DATE_OUT_OF_RANGE', 'params' => []]]);
        }
    }

    /** @return array{0:string,1:string} [mime, zawartość] obrazu z data URL */
    private function image(Validator $v, string $field, array $mimes, int $max): ?array
    {
        $raw = $v->raw($field);
        if (!is_string($raw) || !preg_match('#^data:(image/(?:png|jpeg));base64,([A-Za-z0-9+/=]+)$#', $raw, $m) || !in_array($m[1], $mimes, true)) {
            $v->add($field, 'INVALID_IMAGE');
            return null;
        }
        $content = base64_decode($m[2], true);
        $magic = $m[1] === 'image/png' ? "\x89PNG\r\n\x1a\n" : "\xFF\xD8\xFF";
        if ($content === false || !str_starts_with($content, $magic) || strlen($content) < 100) {
            $v->add($field, 'INVALID_IMAGE');
            return null;
        }
        if (strlen($content) > $max) {
            $v->add($field, 'IMAGE_TOO_LARGE', ['max_kb' => intdiv($max, 1024)]);
            return null;
        }
        return [$m[1], $content];
    }

    private function presentStop(int $n, array $c, array $info, array $docs, ?array $recorded): array
    {
        $street = trim(implode(' ', array_filter([$info['fac_street'] ?? '', $info['fac_street_num'] ?? ''])));
        $town = trim(implode(' ', array_filter([$info['fac_zip'] ?? '', $info['fac_town'] ?? $c['town']])));
        $address = trim((string) ($info['delivery_place'] ?? '')) !== '' ? trim((string) $info['delivery_place'])
            : trim(implode(', ', array_filter([$street, $town, $info['fac_country'] ?? $c['country']])));
        return [
            'n' => $n, 'customer_id' => (int) $c['id'], 'name' => $c['name'], 'address' => $address,
            'phone' => ($info['phone_head'] ?? '') ?: (($info['phone_alt'] ?? '') ?: null),
            'pallets' => $c['pallets'], 'cartons' => round((float) $c['cartons'], 1), 'weight_kg' => round((float) $c['weight_kg'], 1), 'scanned' => (int) $c['scanned'],
            'products' => array_map(fn($p) => ['name' => $p['name'], 'cartons' => $p['cartons']], $c['products']),
            'documents' => $docs,
            'status' => $recorded['status'] ?? null,
            'recorded_at' => $recorded['recorded_at'] ?? null,
            'receiver_name' => $recorded['receiver_name'] ?? null,
            'reason' => $recorded['reason'] ?? null,
            'note' => $recorded['note'] ?? null,
            'pallets_delivered' => isset($recorded['pallets_delivered']) ? (int) $recorded['pallets_delivered'] : null,
            'pallets_returned' => isset($recorded['pallets_returned']) ? (int) $recorded['pallets_returned'] : null,
            'has_signature' => !empty($recorded['signature_id']),
            'has_photo' => !empty($recorded['photo_ids']),
        ];
    }

    private function presentErpStop(?array $s): ?array
    {
        if ($s === null) return null;
        return [
            'id' => (int) $s['id'], 'status' => $s['status'], 'reason' => $s['reason'], 'reason_label' => $s['reason'] ? self::REASON_LABELS[$s['reason']][0] : null,
            'recorded_at' => $s['recorded_at'], 'receiver_name' => $s['receiver_name'], 'driver_name' => $s['driver_name'],
            'pallets_delivered' => $s['pallets_delivered'] !== null ? (int) $s['pallets_delivered'] : null,
            'pallets_returned' => $s['pallets_returned'] !== null ? (int) $s['pallets_returned'] : null,
            'note' => $s['note'], 'signature_id' => $s['signature_id'] ? (int) $s['signature_id'] : null,
            'photo_ids' => $s['photo_ids'] ? array_map('intval', explode(',', (string) $s['photo_ids'])) : [],
        ];
    }
}
