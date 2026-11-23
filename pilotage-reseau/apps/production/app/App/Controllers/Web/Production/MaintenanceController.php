<?php
declare(strict_types=1);

namespace App\Controllers\Web\Production;

use App\Core\Web\BaseController;
use App\Core\Web\JsonErrors;
use App\Exceptions\CustomException;
use App\Services\Organization\PageAccessService;
use App\Services\Organization\Wizards\MaintenanceService;
use Symfony\Component\HttpFoundation\Request;

/**
 * Asystent « Serwisy i przeglądy maszyn » [405] (Kierownik produkcji, co miesiąc i w każdej chwili) : przegląd (rejestry
 * urządzeń, przeglądów i zgłoszeń, awarie z tabletów), decyzja dla elementu, zatwierdzenie. Droit propre « maintenance » (page
 * et appels ajax : App ne contrôle que les pages, les appels vérifient le même droit). Les liens vers les écrans d'origine
 * (moce produkcyjne, koszty usług) ne sont donnés que si l'utilisateur les voit. Écrit seulement les tables de l'assistant et
 * n_wizard_done ; rien n'est commandé ni envoyé.
 */
final class MaintenanceController extends BaseController
{
    use JsonErrors;

    public const PATH = MaintenanceService::PATH;

    public function __construct(private MaintenanceService $maintenance, private PageAccessService $access)
    {
    }

    public function index()
    {
        $this->view('production/maintenance', ['data' => $this->withLinks($this->maintenance->data())]);
    }

    public function data()
    {
        return $this->run(fn() => ['data' => $this->withLinks($this->maintenance->data())]);
    }

    /** POST {key, decision: booked|done|not_applicable, booked_on?, note, seen?} — krok 2 : decyzja. */
    public function decision()
    {
        return $this->run(fn() => ['message' => 'Zapisano.', 'data' => $this->withLinks($this->maintenance->saveDecision($this->input(), $this->userId()))]);
    }

    /** POST {name, kind, id_workstation?, location?, note?} — urządzenie do rejestru. */
    public function equipment()
    {
        return $this->run(fn() => ['message' => 'Dodano urządzenie.', 'data' => $this->withLinks($this->maintenance->saveEquipment($this->input(), $this->userId()))]);
    }

    /** POST {id_equipment, kind, title?, interval_months?, last_done?, next_due?, provider?} — przegląd / serwis / pomiar. */
    public function check()
    {
        return $this->run(fn() => ['message' => 'Dodano przegląd.', 'data' => $this->withLinks($this->maintenance->saveCheck($this->input(), $this->userId()))]);
    }

    /** POST {id, done_on, next_due?} — wykonanie przeglądu. */
    public function checkDone()
    {
        return $this->run(fn() => ['message' => 'Zapisano wykonanie.', 'data' => $this->withLinks($this->maintenance->checkDone($this->input(), $this->userId()))]);
    }

    /** POST {kind, title, id_equipment? | id_workstation? | id_ws_state?, provider?, reported_on?, due_date?, status?, note?} — zgłoszenie. */
    public function ticket()
    {
        return $this->run(fn() => ['message' => 'Zapisano zgłoszenie.', 'data' => $this->withLinks($this->maintenance->saveTicket($this->input(), $this->userId()))]);
    }

    /** POST {id, status, due_date?, closed_on?} — stan zgłoszenia. */
    public function ticketStatus()
    {
        return $this->run(fn() => ['message' => 'Zapisano stan zgłoszenia.', 'data' => $this->withLinks($this->maintenance->ticketStatus($this->input(), $this->userId()))]);
    }

    /** POST — krok 3 « Zakończ » : n_wizard_done, zadanie miesiąca zaliczone. */
    public function finish()
    {
        return $this->run(function () {
            $r = $this->maintenance->finish($this->userId());
            return ['message' => 'Serwisy i przeglądy sprawdzone — ' . $r['summary'] . '.', 'result' => ['summary' => $r['summary']], 'data' => $this->withLinks($r['data'])];
        });
    }

    /** Droit vérifié, puis réponse JSON (erreurs : JsonErrors). */
    private function run(callable $f)
    {
        try {
            if (!$this->access->canSee($this->userId(), self::PATH)) throw new CustomException('Brak dostępu do asystenta « Serwisy i przeglądy maszyn ».', 'errors', 403);
            return $this->json($f());
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    private function input(): array
    {
        return $this->getJson(Request::createFromGlobals());
    }

    /** Liens vers les écrans d'origine : seulement ceux que l'utilisateur peut ouvrir (droits des stanowisk). */
    private function withLinks(array $d): array
    {
        $uid = $this->userId();
        $seen = [];
        $can = function (string $href) use ($uid, &$seen): bool {
            $path = (string) strtok($href, '?#');
            return $seen[$path] ??= $this->access->canSee($uid, $path);
        };
        foreach ($d['review'] as &$it) $it['links'] = array_values(array_filter($it['links'], fn($l) => $can($l['href'])));
        unset($it);
        $d['links'] = array_values(array_filter($d['links'], fn($l) => $can($l['href'])));
        return $d;
    }

    private function userId(): ?int
    {
        $id = user('id');
        return $id ? (int) $id : null;
    }
}
