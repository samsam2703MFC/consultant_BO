<?php
declare(strict_types=1);

namespace App\Controllers\Web\Production;

use App\Core\Web\BaseController;
use App\Core\Web\JsonErrors;
use App\Exceptions\CustomException;
use App\Services\Organization\PageAccessService;
use App\Services\Workshop\Planning\CapacityService;
use App\Services\Workshop\Planning\MachineDataService;
use Symfony\Component\HttpFoundation\Request;

/**
 * Moce produkcyjne [234] (makiety 10 i 11) : wykorzystanie maszyn wobec planu tygodnia, ludzie (zespoły), mroźnia zapasu,
 * ilość na zmianę produktu (wąskie gardło), « co jeszcze zmieszczę », ustawienia maszyn.
 * Pełna wersja : administrator i stanowiska z prawem « capacity_full » (dyrektor zakładu) ; produkcja (prawo « production »)
 * widzi tylko stan obecny — tydzień bieżący, do odczytu.
 * Dane maszyn [240] (makiety 12–13) : przegląd i kreator maszyny (produkty, wsad, wsadów na godzinę, osób) — pełna wersja
 * albo prawo do kart produktów (kierownik produkcji) ; zapis w etapach kart produkcyjnych.
 */
final class CapacityController extends BaseController
{
    use JsonErrors;

    private const PATH = '/production/capacity';
    private const FULL = '/production/capacity/full';

    public function __construct(private CapacityService $capacity, private PageAccessService $access, private MachineDataService $machines)
    {
    }

    /** [240] Dane maszyn : pełna wersja albo karty produktów (etapy produktów). */
    private function canEditMachines(): bool
    {
        return $this->full() || $this->access->canSee($this->userId(), '/workshop/products');
    }

    private function guardMachines(): void
    {
        $this->guard();
        if (!$this->canEditMachines()) throw new CustomException('Dane maszyn : prawo do kart produktów albo pełna wersja mocy produkcyjnych.', 'errors', 403);
    }

    /** GET : przegląd danych maszyn (makieta 12). */
    public function machineData()
    {
        try {
            $this->guardMachines();
            return $this->json(['data' => $this->machines->overview()]);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    /** GET : kreator maszyny — maszyna i produkty. */
    public function machineProducts($id)
    {
        try {
            $this->guardMachines();
            return $this->json(['data' => $this->machines->machine((int) $id)]);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    /** PUT {rows: [{item_id, per_batch, per_hour, people}]} : zapis kreatora maszyny. */
    public function saveMachineProducts($id)
    {
        try {
            $this->guardMachines();
            $rows = $this->getJson(Request::createFromGlobals())['rows'] ?? null;
            if (!is_array($rows)) throw new CustomException('Brak wierszy.', 'errors', 422);
            $r = $this->machines->save((int) $id, $rows, $this->userId());
            $n = $r['created'] + $r['updated'];
            return $this->json(['data' => $r, 'message' => $n ? "Zapisano : {$n} " . ($n === 1 ? 'produkt' : ($n % 10 >= 2 && $n % 10 <= 4 && ($n % 100 < 12 || $n % 100 > 14) ? 'produkty' : 'produktów')) . '.' : 'Bez zmian.']);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    /** Dane : ta sama zgoda co strona (Produkcja). */
    private function guard(): void
    {
        if (!$this->access->canSee($this->userId(), self::PATH)) throw new CustomException('Brak dostępu do mocy produkcyjnych.', 'errors', 403);
    }

    /** Symulacja i ustawienia : tylko pełna wersja. */
    private function guardFull(): void
    {
        $this->guard();
        if (!$this->full()) throw new CustomException('Symulacje i ustawienia mocy : administrator albo dyrektor zakładu (prawo « Moce produkcyjne — pełna wersja »).', 'errors', 403);
    }

    private function full(): bool
    {
        return $this->access->canSee($this->userId(), self::FULL);
    }

    private function payload(): array
    {
        $d = $this->full() ? $this->capacity->data($this->date(), $this->whatIf(), $this->group()) : $this->capacity->state($this->group());
        return $d + ['machines_edit' => $this->canEditMachines()];
    }

    public function index()
    {
        try {
            $boot = $this->payload();
        } catch (\Throwable $e) {
            error_log((string) $e);
            $boot = ['error' => 'Nie udało się policzyć mocy produkcyjnych : ' . $e->getMessage()];
        }
        $this->view('production/capacity', ['data' => $boot, 'full' => $this->full()]);
    }

    public function data()
    {
        try {
            $this->guard();
            return $this->json(['data' => $this->payload()]);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    public function simulate()
    {
        try {
            $this->guardFull();
            $qty = (float) str_replace(',', '.', (string) ($_GET['qty'] ?? '0'));
            return $this->json(['data' => $this->capacity->simulate($this->date(), (int) ($_GET['item'] ?? 0), $qty, $this->whatIf())]);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    public function saveMachine($id)
    {
        try {
            $this->guardFull();
            $this->capacity->saveMachine((int) $id, $this->getJson(Request::createFromGlobals()), $this->userId());
            return $this->json(['message' => 'Zapisano ustawienia maszyny.']);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    public function saveFreezer()
    {
        try {
            $this->guardFull();
            $this->capacity->saveFreezer($this->getJson(Request::createFromGlobals()), $this->userId());
            return $this->json(['message' => 'Zapisano mroźnię zapasu.']);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    private function date(): ?string
    {
        $d = $_GET['date'] ?? null;
        return is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) ? $d : null;
    }

    /** Co jeśli : + zmiana popołudniowa (zmiany nocnej nie ma). @return array{afternoon:bool} */
    private function whatIf(): array
    {
        $w = is_string($_GET['shifts'] ?? null) ? explode(',', $_GET['shifts']) : [];
        return ['afternoon' => in_array('afternoon', $w, true)];
    }

    private function group(): ?int
    {
        return isset($_GET['group']) && ctype_digit((string) $_GET['group']) ? (int) $_GET['group'] : null;
    }

    private function userId(): ?int
    {
        $id = user('id');
        return $id ? (int) $id : null;
    }
}
