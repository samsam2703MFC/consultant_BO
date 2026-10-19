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
    public function __construct(private ShippingWizardService $wizard)
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
            'selected_date' => $selected,
            'requested_date' => $requested,
        ]);
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
}
