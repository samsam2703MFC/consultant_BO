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
use App\Services\Client\Order\OrderFulfilmentService;
use App\Services\Notification\AppNotificationService;
use App\Services\Shipping\ShippingDocumentService;
use App\Services\Utils\DocumentLabels;
use App\Services\Utils\TCPDFService;

/**
 * Tablet kierowcy : przejazd pojazdu w dniu wysyłki (klienci w kolejności rozładunku z załadunku dnia, palety i ich
 * numery ze skanera, kartony, waga, dokumenty, adres i telefon, instrukcje dostawy klienta : procedura rozładunku,
 * alarm, klucz, zamrażarka), start i koniec trasy, przyjazd do klienta (zdjęcia przed rozładunkiem z godziną),
 * potwierdzenie dostawy (zdjęcia po rozładunku, odbiorca, podpis, palety oddane i zwrócone) albo problem (przyczyna,
 * notatka, zdjęcie → dzwonek w ERP). Raport PDF dostawy i trasy (zdjęcia przed / po z godziną, podpis) na tablecie i w ERP.
 * W ERP : potwierdzenia dnia (Logistyka › Dostawy), status u klienta w załadunku dnia.
 */
final class DriverService
{
    public const REASONS = ['closed', 'refused', 'damaged', 'missing', 'late', 'other'];
    public const REASON_LABELS = [
        'closed' => ['Zamknięte', 'Fermé'], 'refused' => ['Odmowa przyjęcia', 'Refus'], 'damaged' => ['Uszkodzenie', 'Casse'],
        'missing' => ['Brak towaru', 'Manquant'], 'late' => ['Opóźnienie', 'Retard'], 'other' => ['Inny', 'Autre'],
    ];
    private const MAX_SIGNATURE_BYTES = 300_000;
    /** rodzaj zdjęcia z tabletu => rodzaj w bazie */
    private const PHOTO_KINDS = ['before' => 'photo_before', 'after' => 'photo_after'];
    /** Zdjęcia postojów wczytane dla listy ERP (presentErpStop). */
    private array $media = [];

    public function __construct(
        private LogisticsService $logistics,
        private DeliveryRepository $deliveries,
        private ExecutionRepository $devices,
        private ParamRepository $params,
        private TransactionManager $tx,
        private AppNotificationService $notifications,
        private ShippingDocumentService $documents,
        private CustomerDeliveryInfoService $deliveryInfo,
        private OrderFulfilmentService $fulfilment,
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
        $lang = $context->translator->lang() === 'fr' ? 1 : 0;
        if ($vehicleId !== null) {
            $ids = $truck['customers'] ?? [];
            $info = $this->deliveries->customers($ids);
            $docs = $this->deliveries->documents($date);
            $instructions = $this->deliveryInfo->forCustomers($ids);
            $numbers = $this->deliveries->palletNumbers($date);
            $recorded = [];
            foreach ($this->deliveries->stops($date) as $s) $recorded[(int) $s['id_customer']] = $s;
            $media = $this->deliveries->mediaIndex(array_column($recorded, 'id'));
            $n = 0;
            foreach ($ids as $cid) {
                $c = $plan['by_id'][$cid];
                $r = $recorded[$cid] ?? null;
                $stops[] = $this->presentStop(++$n, $c, $info[$cid] ?? [], $docs[$cid] ?? [], $r, $r ? ($media[(int) $r['id']] ?? []) : [],
                    $numbers[$cid] ?? [], $instructions[$cid] ?? [], $lang);
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
                'arrived' => count(array_filter($stops, fn($s) => $s['status'] === 'arrived')),
            ],
            'max_photos' => $this->maxPhotos(),
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
        $driver = $this->driverName($context, $v->value('driver_name'));
        if ($driver !== null) $data['driver_name'] = $driver;
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
            'driver_name' => $this->driverName($context, $v->value('driver_name')), 'id_device' => (int) $device['id'],
        ];
        $stopId = $this->tx->execute(function () use ($data, $signature, $photo) {
            $id = $this->deliveries->saveStop($data);
            if ($signature) $this->deliveries->replaceMedia($id, 'signature', $signature[0], $signature[1]);
            if ($photo) $this->deliveries->replaceMedia($id, 'photo', $photo[0], $photo[1]);
            return $id;
        });
        // Livraison confirmée : commandes de ce client pour ce jour « Zrealizowane » (P1) ; n'empêche jamais l'enregistrement.
        if ($status === 'delivered') {
            try {
                $this->fulfilment->markDelivered($date, $customerId, 'Tablet kierowcy : dostarczono (' . ($data['receiver_name'] ?? '') . ')');
            } catch (\Throwable $e) {
                error_log('driver stop: order status not updated: ' . $e->getMessage());
            }
        }
        if ($status === 'problem') {
            $reason = self::REASON_LABELS[$data['reason']][0];
            $this->notifications->publish('delivery_problem', 'warning', 'Problem w dostawie',
                $customer['name'] . ' : ' . $reason . ($data['note'] ? ' — ' . $data['note'] : '') . '.', '/logistics/deliveries?date=' . $date,
                ['id_stop' => $stopId, 'id_customer' => $customerId, 'date' => $date], 'delivery_problem:' . $date . ':' . $customerId . ':' . $recordedAt);
        }
        return ['id' => $stopId, 'customer_id' => $customerId, 'status' => $status, 'recorded_at' => $recordedAt];
    }

    /**
     * Zdjęcie przed rozładunkiem (przyjazd : postój « na miejscu » z godziną przyjazdu) albo po rozładunku. Każde
     * zdjęcie osobno (mały plik przy słabym zasięgu) ; godzina zrobienia zdjęcia z tabletu ; to samo client_uuid
     * wysłane drugi raz (kolejka offline) nie tworzy kopii.
     */
    public function photo(ApiContext $context, array $body): array
    {
        $device = $this->driverDevice($context);
        $v = (new Validator($body))->only(['date', 'customer_id', 'vehicle_id', 'kind', 'photo', 'taken_at', 'client_uuid', 'lat', 'lng', 'driver_name'])
            ->date('date', true, false)->integer('customer_id', true, 1)->integer('vehicle_id', false, 1, null, true)
            ->choice('kind', array_keys(self::PHOTO_KINDS))->datetime('taken_at')->string('client_uuid', 64, false, true)
            ->decimal('lat', false, -90, 90, true)->decimal('lng', false, -180, 180, true)->string('driver_name', 100, false, true);
        $image = null;
        if ($v->hasRaw('photo') && $v->raw('photo') !== null) {
            $image = $this->image($v, 'photo', ['image/jpeg', 'image/png'], max(100, (int) $this->params->get('driver.photo_max_kb', '1500')) * 1024);
        } else {
            $v->add('photo', 'REQUIRED');
        }
        $v->throwIfInvalid();

        $date = $v->value('date');
        $this->checkDate($date);
        $customerId = (int) $v->value('customer_id');
        $plan = $this->logistics->day($date);
        $customer = $plan['by_id'][$customerId] ?? throw ApiException::validation([['field' => 'customer_id', 'code' => 'DRIVER_CUSTOMER_NOT_PLANNED', 'params' => ['id' => $customerId]]]);
        $takenAt = $v->value('taken_at');
        if ($takenAt === null || abs(strtotime($takenAt) - time()) > 2 * 86400) $takenAt = date('Y-m-d H:i:s');
        $kind = self::PHOTO_KINDS[$v->value('kind')];
        $uuid = $v->value('client_uuid');
        $max = $this->maxPhotos();
        $driver = $this->driverName($context, $v->value('driver_name'));

        return $this->tx->execute(function () use ($device, $v, $date, $customerId, $customer, $takenAt, $kind, $uuid, $image, $max, $driver) {
            $stop = $this->deliveries->stopFor($date, $customerId);
            if ($stop === null) {
                $stopId = $this->deliveries->saveStop([
                    'ship_date' => $date, 'id_customer' => $customerId, 'id_carrier_vehicle' => $v->value('vehicle_id') ?? $customer['vehicle_id'],
                    'status' => 'arrived', 'recorded_at' => $takenAt, 'arrived_at' => $kind === 'photo_before' ? $takenAt : null,
                    'lat' => $v->value('lat'), 'lng' => $v->value('lng'), 'driver_name' => $driver, 'id_device' => (int) $device['id'],
                ]);
            } else {
                $stopId = (int) $stop['id'];
                if ($kind === 'photo_before') $this->deliveries->markArrived($stopId, $takenAt);
            }
            $replay = $uuid !== null && $this->deliveries->mediaExists($stopId, $uuid);
            if (!$replay && $this->deliveries->countMedia($stopId, $kind) >= $max) {
                throw ApiException::validation([['field' => 'photo', 'code' => 'DRIVER_PHOTO_LIMIT', 'params' => ['max' => $max]]]);
            }
            $id = $this->deliveries->addMedia($stopId, $kind, $image[0], $image[1], $takenAt, $uuid);
            return ['id' => $id, 'stop_id' => $stopId, 'kind' => $v->value('kind'), 'taken_at' => $takenAt, 'count' => $this->deliveries->countMedia($stopId, $kind)];
        });
    }

    /** Raport PDF trasy (albo jednego klienta) dla kierowcy, w języku tabletu. */
    public function report(ApiContext $context, string $date, ?int $vehicleId, ?int $customerId): array
    {
        $device = $this->driverDevice($context);
        $this->checkDate($date);
        $vehicleId ??= $device['id_carrier_vehicle'] !== null ? (int) $device['id_carrier_vehicle'] : null;
        if ($vehicleId === null) throw ApiException::validation([['field' => 'vehicle_id', 'code' => 'REQUIRED', 'params' => []]]);
        return $this->tourPdf($date, $vehicleId, $customerId, $context->translator->lang());
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
        $this->media = $this->deliveries->mediaIndex(array_column($stops, 'id'));
        $numbers = $this->deliveries->palletNumbers($date);
        $trucks = [];
        foreach ($plan['fleet'] as $truck) {
            if (!$truck['customers'] && !isset($runs[$truck['id']])) continue;
            $rows = [];
            foreach ($truck['customers'] as $cid) {
                $c = $plan['by_id'][$cid];
                $rows[] = ['customer_id' => $cid, 'name' => $c['name'], 'town' => $c['town'], 'pallets' => !empty($numbers[$cid]) ? count($numbers[$cid]) : $c['pallets'],
                    'pallet_numbers' => $numbers[$cid] ?? [], 'stop' => $this->presentErpStop($stops[$cid] ?? null)];
                unset($stops[$cid]);
            }
            $run = $runs[$truck['id']] ?? null;
            $tourUrl = self::tourUrl($date, (int) $truck['id']);
            $trucks[] = ['id' => $truck['id'], 'label' => $truck['label'], 'carrier' => $truck['carrier'], 'departure' => $truck['departure'],
                'tour_url' => $tourUrl, 'tour_qr' => self::qrSvg($tourUrl),
                'driver' => $run['driver_name'] ?? ($vehicles[$truck['id']]['driver'] ?? null), 'started_at' => $run['started_at'] ?? null, 'finished_at' => $run['finished_at'] ?? null,
                'rows' => $rows, 'delivered' => count(array_filter($rows, fn($r) => ($r['stop']['status'] ?? null) === 'delivered')),
                'arrived' => count(array_filter($rows, fn($r) => ($r['stop']['status'] ?? null) === 'arrived')),
                'problems' => count(array_filter($rows, fn($r) => ($r['stop']['status'] ?? null) === 'problem'))];
        }
        // Postoje u klientów spoza planu pojazdów (np. przeniesionych) : osobno, żeby nic nie zginęło.
        $other = array_values(array_map(fn($s) => ['customer_id' => (int) $s['id_customer'], 'name' => $s['customer'], 'town' => null, 'pallets' => null,
            'pallet_numbers' => $numbers[(int) $s['id_customer']] ?? [], 'stop' => $this->presentErpStop($s)], $stops));
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
        $stops = $this->deliveries->stops($date);
        $this->media = $this->deliveries->mediaIndex(array_column($stops, 'id'));
        foreach ($stops as $s) $out[(int) $s['id_customer']] = $this->presentErpStop($s);
        return $out;
    }

    public function media(int $id): array
    {
        return $this->deliveries->media($id) ?? throw new CustomException('Nie znaleziono pliku.', 'errors', 404);
    }

    /** PDF « Potwierdzenie dostawy » jednego postoju (ERP) : ten sam układ co raport trasy. */
    public function proofPdf(int $stopId, string $lang = 'pl'): array
    {
        $s = $this->deliveries->stopDetail($stopId) ?? throw new CustomException('Nie znaleziono dostawy.', 'errors', 404);
        $lang = DocumentLabels::lang($lang);
        $i = $lang === 'fr' ? 1 : 0;
        $pdf = new TCPDFService('P', 'mm', 'A4', true, 'UTF-8', false);
        $numbers = $this->deliveries->palletNumbers($s['ship_date'])[(int) $s['id_customer']] ?? [];
        $docs = $this->deliveries->documents($s['ship_date'])[(int) $s['id_customer']] ?? [];
        $title = $i ? 'Confirmation de livraison' : 'Potwierdzenie dostawy';
        $pdf->brandPage($title, ['ref' => $s['customer'] . ' · ' . $s['ship_date'], 'lang' => $lang]);
        $this->renderStop($pdf, $s, $numbers, $docs, $i, $title);
        $name = trim((string) preg_replace('/[^A-Za-z0-9\-]+/', '_', (string) $s['customer']), '_');
        return ['filename' => 'POTWIERDZENIE_DOSTAWY_' . $name . '_' . $s['ship_date'] . '.pdf', 'content' => $pdf->Output('', 'S')];
    }

    /**
     * Raport trasy (pojazd i dzień) : podsumowanie postojów (przyjazd, dostawa, odbiorca, palety i ich numery, zdjęcia,
     * podpis), potem każdy postój na osobnej stronie ze zdjęciami przed i po rozładunku (z godziną) i podpisem.
     * $customerId : raport jednego klienta.
     */
    public function tourPdf(string $date, int $vehicleId, ?int $customerId = null, string $lang = 'pl'): array
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !strtotime($date)) throw new CustomException('Nieprawidłowa data.', 'errors', 422);
        $lang = DocumentLabels::lang($lang);
        $i = $lang === 'fr' ? 1 : 0;
        $t = fn(string $pl, string $fr) => $i ? $fr : $pl;
        $plan = $this->logistics->day($date);
        $vehicles = $this->deliveries->vehicles();
        $vehicle = $vehicles[$vehicleId] ?? throw new CustomException('Nie znaleziono pojazdu.', 'errors', 404);
        $truck = null;
        foreach ($plan['fleet'] as $v) if ((int) $v['id'] === $vehicleId) $truck = $v;
        $recorded = [];
        foreach ($this->deliveries->stops($date) as $s) $recorded[(int) $s['id_customer']] = $s;
        // Klienci pojazdu w kolejności rozładunku + postoje zapisane przez ten pojazd poza planem.
        $ids = $truck['customers'] ?? [];
        foreach ($recorded as $cid => $s) if ((int) $s['id_carrier_vehicle'] === $vehicleId && !in_array($cid, $ids, true)) $ids[] = $cid;
        if ($customerId !== null) $ids = array_values(array_filter($ids, fn($cid) => $cid === $customerId));
        if (!$ids) throw new CustomException($t('Brak klientów w tej trasie.', 'Aucun client dans cette tournée.'), 'errors', 404);
        $numbers = $this->deliveries->palletNumbers($date);
        $docs = $this->deliveries->documents($date);
        $media = $this->deliveries->mediaIndex(array_map(fn($cid) => $recorded[$cid]['id'] ?? 0, $ids));
        $run = $this->deliveries->run($date, $vehicleId);

        $pdf = new TCPDFService('P', 'mm', 'A4', true, 'UTF-8', false);
        $title = $customerId !== null ? $t('Potwierdzenie dostawy', 'Confirmation de livraison') : $t('Raport trasy', 'Rapport de tournée');
        $pdf->brandPage($title, ['ref' => $vehicle['label'] . ' · ' . $date, 'lang' => $lang]);
        $hm = fn(?string $dt) => $dt ? date('H:i', strtotime($dt)) : '—';
        $pdf->brandHeader($title, [
            [$t('Dzień wysyłki', 'Date d’expédition'), date('d.m.Y', strtotime($date))],
            [$t('Pojazd', 'Véhicule'), implode(' · ', array_unique(array_filter([$vehicle['label'], (string) $vehicle['carrier']])))],
            [$t('Kierowca', 'Chauffeur'), ($run['driver_name'] ?? null) ?: ($vehicle['driver'] ?? '—')],
            [$t('Trasa', 'Tournée'), $hm($run['started_at'] ?? null) . ' – ' . $hm($run['finished_at'] ?? null)],
        ], $customerId !== null ? (string) ($plan['by_id'][$customerId]['name'] ?? $recorded[$customerId]['customer'] ?? '') : $vehicle['label']);

        if ($customerId === null) {
            // Kod QR trasy : tablet kierowcy otwiera ten pojazd i ten dzień (skan w aplikacji).
            $y = $pdf->GetY() + 1;
            $x = $pdf->getPageWidth() - 12 - 24;
            $pdf->write2DBarcode(self::tourUrl($date, $vehicleId), 'QRCODE,M', $x, $y, 24, 24, ['border' => false, 'padding' => 0, 'fgcolor' => [0, 0, 0], 'bgcolor' => false], 'N');
            $pdf->SetFont('dejavusans', '', 7.5);
            $pdf->SetXY(12, $y + 8);
            $pdf->MultiCell($x - 16, 8, $t('Tablet kierowcy : po zalogowaniu (lista + PIN) « Skanuj QR trasy » otwiera ten pojazd i ten dzień.',
                'Tablette chauffeur : après connexion (liste + PIN), « Scanner le QR de la tournée » ouvre ce véhicule et ce jour.'), 0, 'L');
            $pdf->SetY($y + 27);
            $status = fn(?array $s) => match ($s['status'] ?? null) {
                'delivered' => $t('dostarczono', 'livré') . ' ' . $hm($s['recorded_at']),
                'problem' => $t('problem', 'problème') . ' : ' . (self::REASON_LABELS[$s['reason']][$i] ?? $s['reason']),
                'arrived' => $t('na miejscu', 'sur place'),
                default => $t('bez potwierdzenia', 'non confirmé'),
            };
            $rows = [];
            $n = 0;
            foreach ($ids as $cid) {
                $s = $recorded[$cid] ?? null;
                $m = $s ? ($media[(int) $s['id']] ?? []) : [];
                $nums = $numbers[$cid] ?? [];
                $rows[] = [++$n, (string) ($plan['by_id'][$cid]['name'] ?? $s['customer'] ?? $cid), $hm($s['arrived_at'] ?? null), $status($s), (string) ($s['receiver_name'] ?? '—'),
                    ($nums ? count($nums) : ($plan['by_id'][$cid]['pallets'] ?? '—')) . ($nums ? "\n" . implode(', ', $nums) : ''),
                    $s && $s['pallets_returned'] !== null ? (string) $s['pallets_returned'] : '—',
                    count($m['photo_before'] ?? []) . ' / ' . count($m['photo_after'] ?? []), !empty($m['signature']) ? '✓' : '—'];
            }
            $pdf->brandSection($t('Postoje', 'Arrêts'), $t('W kolejności rozładunku', 'Dans l’ordre de déchargement'));
            $pdf->brandTable([
                ['label' => '#', 'w' => 0.4, 'align' => 'R'], ['label' => $t('Klient', 'Client'), 'w' => 2.6], ['label' => $t('Przyjazd', 'Arrivée'), 'w' => 0.9],
                ['label' => $t('Status', 'Statut'), 'w' => 1.8], ['label' => $t('Odbiorca', 'Réceptionnaire'), 'w' => 1.5],
                ['label' => $t('Palety', 'Palettes'), 'sub' => $t('numery', 'numéros'), 'w' => 1.4], ['label' => $t('Zwrot', 'Rendues'), 'w' => 0.8, 'align' => 'R'],
                ['label' => $t('Zdjęcia', 'Photos'), 'sub' => $t('przed / po', 'avant / après'), 'w' => 1, 'align' => 'R'], ['label' => $t('Podpis', 'Signature'), 'w' => 0.8, 'align' => 'R'],
            ], $rows, ['font' => 7.5]);
        }

        foreach ($ids as $k => $cid) {
            $s = $recorded[$cid] ?? null;
            if ($s === null) continue;
            if ($customerId === null || $k > 0) $pdf->AddPage();
            $detail = $this->deliveries->stopDetail((int) $s['id']);
            $this->renderStop($pdf, $detail, $numbers[$cid] ?? [], $docs[$cid] ?? [], $i, null);
        }
        $name = trim((string) preg_replace('/[^A-Za-z0-9\-]+/', '_', $customerId !== null ? (string) ($plan['by_id'][$customerId]['name'] ?? $customerId) : $vehicle['label']), '_');
        return ['filename' => ($customerId !== null ? 'POTWIERDZENIE_DOSTAWY_' : 'RAPORT_TRASY_') . $name . '_' . $date . '.pdf', 'content' => $pdf->Output('', 'S')];
    }

    /** Jeden postój : klient, przebieg dostawy (przyjazd, dostawa, czas rozładunku, palety i numery), zdjęcia przed i po z godziną, podpis. */
    private function renderStop(TCPDFService $pdf, array $s, array $numbers, array $docs, int $i, ?string $title): void
    {
        $t = fn(string $pl, string $fr) => $i ? $fr : $pl;
        $dt = fn(?string $v) => $v ? date('d.m.Y H:i', strtotime($v)) : '—';
        if ($title !== null) {
            $pdf->brandHeader($title, [
                [$t('Dzień wysyłki', 'Date d’expédition'), date('d.m.Y', strtotime($s['ship_date']))],
                [$t('Pojazd', 'Véhicule'), implode(' · ', array_unique(array_filter([trim((string) ($s['regis_num'] ?? '')), trim((string) ($s['carrier'] ?? ''))]))) ?: '—'],
                [$t('Kierowca', 'Chauffeur'), $s['driver_name'] ?: '—'],
            ], (string) $s['customer']);
        }
        $address = trim(implode(' ', array_filter([$s['fac_street'], $s['fac_street_num']])) . ', ' . implode(' ', array_filter([$s['fac_zip'], $s['fac_town']])) . ' ' . ($s['fac_country'] ?? ''), ' ,');
        $pdf->brandSection((string) $s['customer'], $address);
        $rows = [];
        if ($s['arrived_at']) $rows[] = [$t('Przyjazd', 'Arrivée'), $dt($s['arrived_at'])];
        $rows[] = match ($s['status']) {
            'delivered' => [$t('Dostarczono', 'Livré'), $dt($s['recorded_at']) . ' — ' . $t('odebrał(a) : ', 'réceptionné par : ') . $s['receiver_name']],
            'problem' => [$t('Problem', 'Problème'), $dt($s['recorded_at']) . ' — ' . (self::REASON_LABELS[$s['reason']][$i] ?? $s['reason'])],
            default => [$t('Status', 'Statut'), $t('na miejscu, dostawa niepotwierdzona', 'sur place, livraison non confirmée')],
        };
        if ($s['arrived_at'] && $s['status'] !== 'arrived') {
            $minutes = (int) round((strtotime($s['recorded_at']) - strtotime($s['arrived_at'])) / 60);
            if ($minutes >= 0) $rows[] = [$t('Czas rozładunku', 'Durée du déchargement'), $minutes . ' min'];
        }
        $rows[] = [$t('Palety', 'Palettes'), ($numbers ? count($numbers) . ' · ' . $t('nr ', 'n° ') . implode(', ', $numbers) : '—')
            . ($s['pallets_delivered'] !== null ? ' · ' . $t('dostarczone : ', 'livrées : ') . $s['pallets_delivered'] : '')];
        if ($s['pallets_returned'] !== null) $rows[] = [$t('Palety zwrócone (EUR)', 'Palettes rendues (EUR)'), (string) $s['pallets_returned']];
        if ($docs) $rows[] = [$t('Dokumenty', 'Documents'), implode(', ', array_map(fn($d) => $d['type'] . ' ' . $d['num'], $docs))];
        if ($s['note']) $rows[] = [$t('Uwagi', 'Remarques'), (string) $s['note']];
        if ($s['lat'] !== null && $s['lng'] !== null) $rows[] = [$t('Położenie', 'Position'), $s['lat'] . ', ' . $s['lng']];
        $pdf->brandTable([['label' => $t('Dostawa', 'Livraison'), 'w' => 1.3], ['label' => '', 'w' => 4]], $rows);

        $groups = [];
        foreach ($s['media'] as $m) $groups[$m['kind']][] = $m;
        foreach ([['photo_before', $t('Zdjęcia przed rozładunkiem', 'Photos avant déchargement')], ['photo_after', $t('Zdjęcia po rozładunku', 'Photos après déchargement')],
                     ['photo', $t('Zdjęcie', 'Photo')]] as [$kind, $label]) {
            if (!empty($groups[$kind])) $this->photoGrid($pdf, $groups[$kind], $label);
        }
        if (!empty($groups['signature'])) {
            $sig = end($groups['signature']);
            $file = $this->deliveries->media((int) $sig['id']);
            if ($file) {
                if ($pdf->GetY() + 60 > $pdf->getPageHeight() - $pdf->getBreakMargin()) $pdf->AddPage();
                $pdf->brandSection($t('Podpis odbiorcy', 'Signature du réceptionnaire'), $dt($s['recorded_at']) . ($s['receiver_name'] ? ' · ' . $s['receiver_name'] : ''));
                $y = $pdf->GetY();
                $pdf->Image('@' . $file['content'], $pdf->getMargins()['left'], $y, 90, 0, 'PNG', '', 'N', true, 150, '', false, false, 0, true);
                $pdf->SetY(max($pdf->GetY(), $y) + 4);
            }
        }
    }

    /** Zdjęcia po dwa w rzędzie, pod każdym data i godzina zrobienia ; tytuł zawsze na tej samej stronie co pierwszy rząd. */
    private function photoGrid(TCPDFService $pdf, array $photos, string $label): void
    {
        $w = ($pdf->contentWidth() - 6) / 2;
        $rows = [];
        foreach (array_chunk($photos, 2) as $pair) {
            $items = [];
            foreach ($pair as $m) {
                $file = $this->deliveries->media((int) $m['id']);
                if (!$file) continue;
                $size = @getimagesizefromstring($file['content']);
                $ratio = $size && $size[0] > 0 ? $size[1] / $size[0] : 0.75;
                $iw = $w;
                $ih = $iw * $ratio;
                if ($ih > 100) { $ih = 100; $iw = $ih / $ratio; }
                $items[] = [$file, $iw, $ih, (string) ($m['taken_at'] ?? $m['created_at'])];
            }
            if ($items) $rows[] = [$items, max(array_map(fn($it) => $it[2], $items)) + 6];
        }
        if (!$rows) return;
        $limit = fn() => $pdf->getPageHeight() - $pdf->getBreakMargin();
        if ($pdf->GetY() + 18 + $rows[0][1] > $limit()) $pdf->AddPage();
        $pdf->brandSection($label, count($photos) . ' × ' . implode(', ', array_map(fn($m) => date('H:i', strtotime((string) ($m['taken_at'] ?? $m['created_at']))), $photos)));
        $left = $pdf->getMargins()['left'];
        foreach ($rows as [$items, $rowH]) {
            if ($pdf->GetY() + $rowH > $limit()) $pdf->AddPage();
            $y = $pdf->GetY();
            foreach ($items as $k => [$file, $iw, $ih, $taken]) {
                $x = $left + $k * ($w + 6);
                $pdf->Image('@' . $file['content'], $x, $y, $iw, $ih, $file['mime'] === 'image/png' ? 'PNG' : 'JPG', '', '', true, 150, '', false, false, 1, false);
                $pdf->SetFont('dejavusans', '', 7.5);
                $pdf->SetTextColor(90, 90, 90);
                $pdf->MultiCell($w, 4, date('d.m.Y H:i:s', strtotime($taken)), 0, 'L', false, 1, $x, $y + $ih + 1);
            }
            $pdf->SetTextColor(0, 0, 0);
            $pdf->SetY($y + $rowH + 2);
        }
    }

    private function maxPhotos(): int
    {
        return max(1, min(20, (int) $this->params->get('driver.max_photos', '6')));
    }

    /** Adres trasy dla tabletu kierowcy (kod QR w ERP i na raporcie trasy) : otwiera pojazd i dzień po zalogowaniu. */
    public static function tourUrl(string $date, int $vehicleId): string
    {
        return (defined('ROOT') ? ROOT : '') . '/driver?date=' . rawurlencode($date) . '&vehicle=' . $vehicleId;
    }

    /** Kod QR jako SVG (jedna ścieżka, skalowalny) do wstawienia w stronę ERP. */
    public static function qrSvg(string $text): string
    {
        $matrix = (new \TCPDF2DBarcode($text, 'QRCODE,M'))->getBarcodeArray();
        $n = (int) $matrix['num_cols'];
        $path = '';
        foreach ($matrix['bcode'] as $y => $row) {
            foreach ($row as $x => $on) if ($on) $path .= 'M' . ($x + 2) . ' ' . ($y + 2) . 'h1v1h-1z';
        }
        $size = $n + 4;
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $size . ' ' . $size . '" shape-rendering="crispEdges" role="img" aria-label="QR">'
            . '<rect width="' . $size . '" height="' . $size . '" fill="#fff"/><path d="' . $path . '" fill="#000"/></svg>';
    }

    /** Kierowca zalogowany na tablecie (lista + PIN) ; nazwisko z tabletu tylko bez sesji osoby (stara wersja aplikacji). */
    private function driverName(ApiContext $context, ?string $typed): ?string
    {
        if ($context->employeeId !== null) {
            $employee = $this->devices->employee((int) $context->employeeId);
            if ($employee) return mb_substr((string) $employee['name'], 0, 100);
        }
        return $typed;
    }

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

    private function presentStop(int $n, array $c, array $info, array $docs, ?array $recorded, array $media, array $palletNumbers, array $instructions, int $lang): array
    {
        $street = trim(implode(' ', array_filter([$info['fac_street'] ?? '', $info['fac_street_num'] ?? ''])));
        $town = trim(implode(' ', array_filter([$info['fac_zip'] ?? '', $info['fac_town'] ?? $c['town']])));
        $address = trim((string) ($info['delivery_place'] ?? '')) !== '' ? trim((string) $info['delivery_place'])
            : trim(implode(', ', array_filter([$street, $town, $info['fac_country'] ?? $c['country']])));
        return [
            'n' => $n, 'customer_id' => (int) $c['id'], 'name' => $c['name'], 'address' => $address,
            'phone' => ($info['phone_head'] ?? '') ?: (($info['phone_alt'] ?? '') ?: null),
            // Palety : liczba i numery ze skanera magazynu, gdy zeskanowane ; inaczej liczba obliczona z kartonów (szacunek).
            'pallets' => $palletNumbers ? count($palletNumbers) : $c['pallets'], 'pallets_estimated' => !$palletNumbers, 'pallet_numbers' => $palletNumbers,
            'cartons' => round((float) $c['cartons'], 1), 'weight_kg' => round((float) $c['weight_kg'], 1), 'scanned' => (int) $c['scanned'],
            'instructions' => array_values(array_map(fn($key) => ['key' => $key, 'label' => CustomerDeliveryInfoService::FIELDS[$key][$lang],
                'value' => $instructions[$key], 'secret' => CustomerDeliveryInfoService::FIELDS[$key][3]], array_keys(array_intersect_key(CustomerDeliveryInfoService::FIELDS, $instructions)))),
            'products' => array_map(fn($p) => ['name' => $p['name'], 'cartons' => $p['cartons']], $c['products']),
            'documents' => $docs,
            'status' => $recorded['status'] ?? null,
            'recorded_at' => $recorded['recorded_at'] ?? null,
            'arrived_at' => $recorded['arrived_at'] ?? null,
            'photos' => ['before' => $media['photo_before'] ?? [], 'after' => $media['photo_after'] ?? []],
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
        $media = $this->media[(int) $s['id']] ?? [];
        return [
            'id' => (int) $s['id'], 'status' => $s['status'], 'reason' => $s['reason'], 'reason_label' => $s['reason'] ? self::REASON_LABELS[$s['reason']][0] : null,
            'recorded_at' => $s['recorded_at'], 'arrived_at' => $s['arrived_at'] ?? null, 'receiver_name' => $s['receiver_name'], 'driver_name' => $s['driver_name'],
            'before_ids' => array_column($media['photo_before'] ?? [], 'id'), 'after_ids' => array_column($media['photo_after'] ?? [], 'id'),
            'pallets_delivered' => $s['pallets_delivered'] !== null ? (int) $s['pallets_delivered'] : null,
            'pallets_returned' => $s['pallets_returned'] !== null ? (int) $s['pallets_returned'] : null,
            'note' => $s['note'], 'signature_id' => $s['signature_id'] ? (int) $s['signature_id'] : null,
            'photo_ids' => $s['photo_ids'] ? array_map('intval', explode(',', (string) $s['photo_ids'])) : [],
        ];
    }
}
