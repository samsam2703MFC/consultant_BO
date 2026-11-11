<?php
namespace App\Controllers\Web\Shipping;

use App\Core\Web\BaseController;
use App\Services\Shipping\ShippingWizardService;

/**
 * Kreator „Wyślij dzień”: data → klienci i kompletność palet → przewoźnik
 * i pojazd → podsumowanie → utworzenie dokumentów (istniejące POST /ajax/shipments).
 */
class ShippingWizardController extends BaseController
{
    public function __construct(private ShippingWizardService $wizard, private \App\Repositories\Client\Complaint\ReturnsRepository $returns)
    {
    }

    public function index()
    {
        $dates = $this->wizard->readyDates();
        $requested = (string) ($_GET['date'] ?? '');
        $selected = in_array($requested, array_column($dates, 'date'), true) ? $requested : '';

        $this->view('shipping/wizard', [
            'dates'         => $dates,
            'carriers'      => $this->wizard->activeCarriers(),
            'complaint_dates' => $this->wizard->complaintDates(),
            // P8 : zamienniki z reklamacji czekające na wysyłkę, pokazane przy kliencie (spakowane wychodzą z jego WZ).
            'replacements'  => $this->replacementsByCustomer(),
            'selected_date' => $selected,
            'requested_date' => $requested,
        ]);
    }

    private function replacementsByCustomer(): array
    {
        $out = [];
        foreach ($this->returns->toShip() as $r) {
            $out[(int) $r['id_customer']][] = ['product' => (string) ($r['product'] ?? '—'), 'cartons' => (int) $r['carton_qty'], 'packed' => (int) $r['status'] === 1];
        }
        return $out;
    }

    /** GET /ajax/shipments/last-carrier?client_id= — podpowiedź przewoźnika (tylko odczyt). */
    public function ajaxLastCarrier()
    {
        $clientId = (int) ($_GET['client_id'] ?? 0);
        if ($clientId <= 0) {
            return $this->json(['message' => 'Brak wymaganego parametru: client_id'], 400);
        }

        return $this->json(['data' => $this->wizard->lastCarrierForCustomer($clientId)]);
    }

    /** GET /ajax/shipments/planned-vehicles?date=RRRR-MM-DD — pojazdy z « Załadunek dnia » (tylko odczyt). */
    public function ajaxPlannedVehicles()
    {
        $date = (string) ($_GET['date'] ?? '');
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (!$d || $d->format('Y-m-d') !== $date) {
            return $this->json(['message' => 'Nieprawidłowa data.'], 400);
        }
        return $this->json(['data' => (object) $this->wizard->plannedVehicles($date)]);
    }
}
