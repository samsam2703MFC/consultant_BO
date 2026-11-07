<?php
declare(strict_types=1);

namespace App\Services\Client;

use App\Services\Communication\DocumentDistributionService;
use App\Services\Logistics\CustomerDeliveryInfoService;
use App\Services\Logistics\LogisticsService;

/**
 * Kreator klienta (audyt 2, lot C) : po utworzeniu klienta (ClientService::insert — firma, kontakty, cennik)
 * zapisuje kolejne kroki : stała trasa, instrukcje dostawy (zaszyfrowane), dokumenty jak u wybranego klienta,
 * opcje klienta (strona, cel obrotu). Każdy krok osobno : błąd jednego nie cofa klienta, tylko trafia na listę
 * « do uzupełnienia » pokazaną na karcie klienta.
 */
final class ClientCreationService
{
    public function __construct(
        private LogisticsService $logistics,
        private CustomerDeliveryInfoService $deliveryInfo,
        private DocumentDistributionService $distribution,
        private ClientSpaceService $space,
    ) {
    }

    /** @return list<string> problemy (krok : komunikat) */
    public function complete(int $customerId, array $input, ?string $author): array
    {
        $problems = [];
        $step = function (string $label, callable $fn) use (&$problems) {
            try {
                $fn();
            } catch (\Throwable $e) {
                $problems[] = $label . ' : ' . $e->getMessage();
            }
        };
        $tourId = (int) ($input['tour_id'] ?? 0);
        if ($tourId > 0) {
            $stop = trim((string) ($input['tour_stop'] ?? ''));
            $step('Trasa', fn() => $this->logistics->placeOnTour($customerId, $tourId, $stop === '' ? null : (int) $stop));
        }
        $step('Instrukcje dostawy', fn() => $this->deliveryInfo->save($customerId, $input, $author));
        $copyFrom = (int) ($input['documents_from'] ?? 0);
        if ($copyFrom > 0) $step('Dokumenty', fn() => $this->distribution->copyClient($copyFrom, [$customerId], $author));
        $step('Opcje klienta', fn() => $this->space->saveSettings($customerId, $input, $author));
        return $problems;
    }
}
