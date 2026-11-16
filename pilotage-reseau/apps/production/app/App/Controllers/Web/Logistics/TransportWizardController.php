<?php
declare(strict_types=1);

namespace App\Controllers\Web\Logistics;

use App\Core\Web\BaseController;
use App\Core\Web\JsonErrors;
use App\Services\Logistics\TransportWizardService;
use Symfony\Component\HttpFoundation\Request;

/** [303] Asystent « Transport dla zamówień » (makieta 56) : dni bez transportu → przypisz do pojazdu → sprawdź. */
final class TransportWizardController extends BaseController
{
    use JsonErrors;

    public function __construct(private TransportWizardService $wizard)
    {
    }

    public function index()
    {
        $this->view('logistics/transport_wizard', ['data' => $this->wizard->data()]);
    }

    public function data()
    {
        try {
            return $this->json(['data' => $this->wizard->data()]);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    /** POST {date, vehicle_id, customers[]} */
    public function assign()
    {
        try {
            $n = $this->wizard->assign($this->getJson(Request::createFromGlobals()), (int) user('id') ?: null);
            return $this->json(['message' => $n . ' ' . ($n === 1 ? 'klient przypisany' : 'klientów przypisanych') . ' do transportu.', 'data' => $this->wizard->data()]);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    /** [306] POST {vehicle_id} (włącz) albo {carrier_id, regis_num} (nowy) + {pallet_places, departure_time} */
    public function addVehicle()
    {
        try {
            $id = $this->wizard->addVehicle($this->getJson(Request::createFromGlobals()), (int) user('id') ?: null);
            return $this->json(['message' => 'Pojazd jest we flocie.', 'vehicle_id' => $id, 'data' => $this->wizard->data()]);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    /** [306] POST {vehicle_id, driver_id?, driver_phone?, tour_id?} */
    public function linkDriver()
    {
        try {
            $this->wizard->linkDriver($this->getJson(Request::createFromGlobals()), (int) user('id') ?: null);
            return $this->json(['message' => 'Kierowca i trasa zapisane.', 'data' => $this->wizard->data()]);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    /** [306] POST {name, surname, section_id, pin?, driver_phone?, vehicle_id?} */
    public function addDriver()
    {
        try {
            $r = $this->wizard->addDriver($this->getJson(Request::createFromGlobals()), (int) user('id') ?: null);
            return $this->json(['message' => 'Kierowca dodany.', 'driver_id' => $r['id'], 'problems' => $r['problems'], 'data' => $this->wizard->data()]);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }
}
