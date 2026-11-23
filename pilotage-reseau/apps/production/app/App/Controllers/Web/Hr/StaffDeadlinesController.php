<?php
declare(strict_types=1);

namespace App\Controllers\Web\Hr;

use App\Core\Web\BaseController;
use App\Core\Web\JsonErrors;
use App\Exceptions\CustomException;
use App\Repositories\Hr\HrRepository;
use App\Services\Organization\PageAccessService;
use App\Services\Organization\Wizards\StaffDeadlinesService;
use Symfony\Component\HttpFoundation\Request;

/**
 * Asystent « Badania i szkolenia (przegląd terminów) » [404] (Kierownik produkcji, co miesiąc) : przegląd (badania lekarskie
 * z HR, rejestr szkoleń), decyzja dla terminu, zatwierdzenie. Droit propre « staff_deadlines » (page et appels ajax) ET droit
 * « hr » : les dates de badania sont des données du tableau HR, montrées seulement à qui le voit déjà (sans « hr » : page
 * sans données, appels 403). Chaque lecture est notée dans le journal HR (n_hr_log, action « deadlines »), comme le tableau
 * HR ; rien de personnel dans les journaux du serveur. Écrit seulement n_staff_training, n_staff_deadline_review et
 * n_wizard_done ; aucun e-mail ni SMS.
 */
final class StaffDeadlinesController extends BaseController
{
    use JsonErrors;

    public const PATH = StaffDeadlinesService::PATH;

    public function __construct(private StaffDeadlinesService $deadlines, private PageAccessService $access, private HrRepository $hr)
    {
    }

    public function index()
    {
        $this->view('hr/staff_deadlines', ['data' => $this->canHr() ? $this->read() : null]);
    }

    public function data()
    {
        try {
            $this->guard();
            return $this->json(['data' => $this->read()]);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    /** POST {key, decision: booked|current|not_applicable, booked_on?, note, seen?} — krok 2 : decyzja dla terminu. */
    public function decision()
    {
        try {
            $this->guard();
            $data = $this->deadlines->saveDecision($this->getJson(Request::createFromGlobals()), $this->userId());
            return $this->json(['message' => 'Zapisano.', 'data' => $this->withLinks($data)]);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    /** POST {id_employee, kind, title?, done_on, valid_until?, note?} — wpis do rejestru szkoleń. */
    public function training()
    {
        try {
            $this->guard();
            $data = $this->deadlines->saveTraining($this->getJson(Request::createFromGlobals()), $this->userId());
            return $this->json(['message' => 'Zapisano szkolenie.', 'data' => $this->withLinks($data)]);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    /** POST {id} — błędny wpis usunięty z rejestru (wyłączony). */
    public function removeTraining()
    {
        try {
            $this->guard();
            $data = $this->deadlines->removeTraining($this->getJson(Request::createFromGlobals()), $this->userId());
            return $this->json(['message' => 'Usunięto wpis.', 'data' => $this->withLinks($data)]);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    /** POST — krok 3 « Zakończ » : n_wizard_done, zadanie miesiąca zaliczone. */
    public function finish()
    {
        try {
            $this->guard();
            $r = $this->deadlines->finish($this->userId());
            return $this->json(['message' => 'Badania i szkolenia sprawdzone — ' . $r['summary'] . '.', 'result' => ['summary' => $r['summary']], 'data' => $this->withLinks($r['data'])]);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    /** Lecture des données (notée dans le journal HR, sans détail personnel). */
    private function read(): array
    {
        $data = $this->withLinks($this->deadlines->data());
        $this->hr->log($this->userId(), null, 'deadlines');
        return $data;
    }

    private function guard(): void
    {
        if (!$this->access->canSee($this->userId(), self::PATH)) throw new CustomException('Brak dostępu do asystenta « Badania i szkolenia ».', 'errors', 403);
        if (!$this->canHr()) throw new CustomException('Brak dostępu do HR : daty badań widzi tylko ten, kto ma prawo « HR ».', 'errors', 403);
    }

    private function canHr(): bool
    {
        return $this->access->canSee($this->userId(), '/hr');
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
