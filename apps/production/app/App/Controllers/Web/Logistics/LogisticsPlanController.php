<?php
declare(strict_types=1);

namespace App\Controllers\Web\Logistics;

use App\Core\Web\BaseController;
use App\Core\Web\JsonErrors;
use App\Services\Logistics\LogisticsPlanService;
use App\Services\Logistics\LogisticsTripService;
use Symfony\Component\HttpFoundation\Request;

/**
 * Plan logistyki na 12 tygodni [200] : tablica ciężarówki × tygodnie (klienci przeciągani), prognoza palet
 * (N-1 × współczynnik, bez N-1 średnia 3 miesięcy, dodatkowe palety), święta PL / BE i urlopy kierowców.
 */
final class LogisticsPlanController extends BaseController
{
    use JsonErrors;

    public function __construct(private LogisticsPlanService $plans, private LogisticsTripService $trips) {}

    public function index()
    {
        try {
            $plan = $this->plans->plan($this->from());
        } catch (\Throwable) {
            $plan = $this->plans->plan();
        }
        $this->view('logistics/plan', ['boot' => json_encode($plan, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP), 'plan' => $plan]);
    }

    public function data()
    {
        return $this->respond(fn() => ['data' => $this->plans->plan($this->from())]);
    }

    public function saveLoads()
    {
        return $this->respond(fn() => ['message' => 'Zapisano.', 'data' => $this->plans->saveLoads((array) ($this->input()['items'] ?? []), $this->userId(), $this->from())]);
    }

    public function saveWeek($week)
    {
        return $this->respond(fn() => ['message' => 'Zapisano dzień wyjazdu.', 'data' => $this->plans->saveWeek((string) $week, $this->input(), $this->userId(), $this->from())]);
    }

    public function saveVehicleWeek($week, $id)
    {
        return $this->respond(fn() => ['message' => 'Zapisano ciężarówkę w tygodniu.', 'data' => $this->plans->saveVehicleWeek((string) $week, (int) $id, $this->input(), $this->userId(), $this->from())]);
    }

    public function saveCoef()
    {
        $in = $this->input();
        $customer = ($in['customer_id'] ?? null) === null || $in['customer_id'] === '' ? null : (int) $in['customer_id'];
        return $this->respond(fn() => ['message' => $customer === null ? 'Zapisano współczynnik wszystkich klientów.' : 'Zapisano współczynnik.',
            'data' => $this->plans->saveCoef($customer, $in['coef'] ?? null, $this->userId(), $this->from())]);
    }

    public function saveEvent()
    {
        return $this->respond(fn() => ['message' => 'Zapisano dodatkowe palety.', 'data' => $this->plans->saveEvent($this->input(), $this->userId(), $this->from())]);
    }

    public function deleteEvent($id)
    {
        return $this->respond(fn() => ['message' => 'Usunięto.', 'data' => $this->plans->deleteEvent((int) $id, $this->from())]);
    }

    public function addAbsence()
    {
        return $this->respond(fn() => ['message' => 'Dodano urlop.', 'data' => $this->plans->addAbsence($this->input(), $this->userId(), $this->from())], 201);
    }

    public function deleteAbsence($id)
    {
        return $this->respond(fn() => ['message' => 'Usunięto urlop.', 'data' => $this->plans->deleteAbsence((int) $id, $this->from())]);
    }

    public function saveHoliday()
    {
        return $this->respond(fn() => ['message' => 'Zapisano dzień wolny.', 'data' => $this->plans->saveHoliday($this->input(), $this->userId(), $this->from())]);
    }

    public function autoDispatch()
    {
        return $this->respond(function () {
            $d = $this->plans->autoDispatch($this->userId(), $this->from());
            $r = $d['result'];
            return ['message' => $r['placed'] ? "Rozdzielono : {$r['placed']} × klient na ciężarówce" . ($r['left'] ? ", {$r['left']} bez miejsca." : '.') : ($r['left'] ? "Brak miejsca dla {$r['left']} × klient — dodaj ciężarówkę." : 'Wszyscy klienci są już na ciężarówkach.'), 'data' => $d];
        });
    }

    public function resetLoads()
    {
        return $this->respond(function () {
            $d = $this->plans->resetLoads($this->userId(), $this->from());
            $r = $d['result'];
            return ['message' => $r['cleared'] ? "Do rozplanowania : {$r['cleared']} × klient zdjęty z ciężarówki ({$r['weeks']} tyg.)"
                . ($r['unapproved'] ? ", {$r['unapproved']} tyg. do ponownego zatwierdzenia." : '.') : 'Nikt nie był na ciężarówce — wszystko już do rozplanowania.', 'data' => $d];
        });
    }

    /** Kalendarz tras [207] : trasa ciężarówki zmieniona w kalendarzu (początek, fazy). */
    public function saveTrip($week, $id, $no = 1)
    {
        return $this->respond(function () use ($week, $id, $no) {
            $this->trips->save((string) $week, (int) $id, $this->input(), $this->userId(), (int) $no);
            return ['message' => 'Zapisano trasę.', 'data' => $this->plans->plan($this->from())];
        });
    }

    public function resetTrip($week, $id, $no = 1)
    {
        return $this->respond(function () use ($week, $id, $no) {
            $this->trips->reset((string) $week, (int) $id, (int) $no);
            return ['message' => 'Trasa z szablonu.', 'data' => $this->plans->plan($this->from())];
        });
    }

    public function tripRest($week)
    {
        return $this->respond(function () use ($week) {
            $n = $this->trips->insertRest((string) $week, $this->userId());
            return ['message' => $n ? "Przerwy i odpoczynki wstawione : {$n} × trasa." : 'Trasy z szablonu mają już przerwy i odpoczynki.', 'data' => $this->plans->plan($this->from())];
        });
    }

    /** Tras (zmian) ciężarówki z kierowcą w tygodniu — domyślnie [208] (standard 1). */
    public function setVehicleTrips($id)
    {
        return $this->respond(fn() => ['message' => 'Zapisano liczbę tras w tygodniu.', 'data' => $this->plans->setVehicleTrips((int) $id, $this->input()['trips_per_week'] ?? null, $this->userId(), $this->from())]);
    }

    public function saveTripTemplate()
    {
        return $this->respond(function () {
            $this->trips->saveTemplate($this->input(), $this->userId());
            return ['message' => 'Zapisano szablon trasy.', 'data' => $this->plans->plan($this->from())];
        });
    }

    public function copyWeek($week)
    {
        return $this->respond(function () use ($week) {
            $d = $this->plans->copyWeek((string) $week, $this->userId(), $this->from());
            return ['message' => "Skopiowano układ : {$d['result']['placed']} × klient w następnych tygodniach.", 'data' => $d];
        });
    }

    public function approve()
    {
        return $this->respond(function () {
            $week = $this->input()['week_start'] ?? null;
            $d = $this->plans->approve($week !== null && $week !== '' ? (string) $week : null, $this->userId(), $this->from());
            $r = $d['result'];
            return ['message' => ($r['weeks'] > 1 ? "Zatwierdzono {$r['weeks']} tygodni" : 'Zatwierdzono tydzień') . " : {$r['saved']} × pojazd klienta na dzień wyjazdu"
                . ($r['kept'] ? ", {$r['kept']} wybranych wcześniej ręcznie bez zmian." : '.'), 'data' => $d];
        });
    }

    private function from(): ?string
    {
        $from = (string) ($_GET['from'] ?? '');
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) ? $from : null;
    }

    private function respond(callable $fn, int $status = 200)
    {
        try {
            return $this->json($fn(), $status);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    private function input(): array
    {
        return $this->getJson(Request::createFromGlobals());
    }

    private function userId(): ?int
    {
        $id = user('id');
        return $id ? (int) $id : null;
    }
}
