<?php
namespace App\Controllers\Web\Organization;

use App\Core\Web\BaseController;
use App\Core\Web\JsonErrors;
use App\Exceptions\CustomException;
use App\Services\Organization\PositionSheetService;
use App\Services\Organization\PositionTaskService;
use App\Services\Organization\TaskFormService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/** Stanowiska i zadania użytkowników ERP, karty stanowisk (fiches de poste), karta użytkownika, „Moje zadania”. */
class PositionController extends BaseController
{
    use JsonErrors;

    public function __construct(private PositionTaskService $service, private PositionSheetService $sheets)
    {
    }

    public function positions()
    {
        $this->view('organization/positions', [
            'positions' => $this->service->positions(),
            'users' => $this->service->users(),
            'frequencies' => PositionTaskService::FREQUENCY_LABELS,
            'weekdays' => PositionTaskService::WEEKDAYS,
            'categories' => $this->service->categories(),
            'forms' => TaskFormService::choices(),
        ]);
    }

    public function userSheet($id)
    {
        $this->page(fn() => $this->view('organization/user', $this->asMember($this->service->userSheet((int) $id)) + [
            'positions' => $this->service->positions(),
            'module' => $this->service->moduleRoles((int) $id) + ['can_set' => $this->service->canSetModuleRoles($this->userId())],
            'frequencies' => PositionTaskService::FREQUENCY_LABELS,
        ]));
    }

    public function myTasks()
    {
        $userId = $this->userId();
        $this->view('organization/my_tasks', $this->asMember($userId ? $this->service->myTasks($userId) : ['user' => null, 'groups' => [], 'overdue' => [], 'counts' => []]));
    }

    public function setStatus($id)
    {
        try {
            $occurrence = $this->service->setStatus((int) $id, $this->getJson(Request::createFromGlobals()), $this->userId());
            return $this->json(['message' => $occurrence['status'] === 'done' ? 'Zrobione.' : ($occurrence['status'] === 'skipped' ? 'Pominięte.' : 'Przywrócone.'), 'data' => $occurrence]);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    /** Plan tygodnia stanowiska (tylko administrator ; strona chroniona też przez katalog uprawnień). */
    public function weekPlan($id)
    {
        $this->page(fn() => $this->view('organization/week_plan', ['plan' => $this->service->weekPlan((int) $id, $this->userId())]));
    }

    public function weekPlanData($id)
    {
        try {
            return $this->json(['data' => $this->service->weekPlan((int) $id, $this->userId())]);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    public function saveWeekPlan($id)
    {
        try {
            $n = $this->service->saveWeekPlan((int) $id, $this->getJson(Request::createFromGlobals()), $this->userId());
            return $this->json(['message' => "Zapisano plan tygodnia ({$n} zadań).", 'data' => $this->service->weekPlan((int) $id, $this->userId())]);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    public function copyWeekPlan($id)
    {
        try {
            $input = $this->getJson(Request::createFromGlobals());
            $n = $this->service->copyPlan((int) $id, (int) ($input['from'] ?? 0), $this->userId());
            return $this->json(['message' => $n ? "Skopiowano zadania : {$n}." : 'Nic do skopiowania — te zadania już są.', 'data' => $this->service->weekPlan((int) $id, $this->userId())]);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    public function savePosition($id = null)
    {
        try {
            $positionId = $this->service->savePosition($this->getJson(Request::createFromGlobals()), $id === null ? null : (int) $id);
            return $this->json(['message' => 'Zapisano stanowisko.', 'id' => $positionId]);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    public function addTask($id)
    {
        try {
            $taskId = $this->service->saveTask($this->getJson(Request::createFromGlobals()), null, (int) $id);
            return $this->json(['message' => 'Dodano zadanie.', 'id' => $taskId]);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    public function updateTask($id)
    {
        try {
            $this->service->saveTask($this->getJson(Request::createFromGlobals()), (int) $id, null);
            return $this->json(['message' => 'Zapisano zadanie.']);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    /** Kategoria zadań (nowa albo zmiana nazwy, ikony, kolejności, aktywności). */
    public function saveCategory($id = null)
    {
        try {
            $categoryId = $this->service->saveCategory($this->getJson(Request::createFromGlobals()), $id === null ? null : (int) $id);
            return $this->json(['message' => 'Zapisano kategorię.', 'id' => $categoryId]);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    public function assign($id)
    {
        try {
            $this->service->assign((int) $id, $this->getJson(Request::createFromGlobals()));
            return $this->json(['message' => 'Zapisano stanowisko użytkownika.']);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    /** Rola użytkownika w module produkcji (dostęp do planowania, tabletów, modułu kierownika produkcji). */
    public function moduleRole($id)
    {
        try {
            $this->service->setModuleRole((int) $id, $this->getJson(Request::createFromGlobals()), $this->userId());
            return $this->json(['message' => 'Zapisano rolę w module produkcji.']);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    /** Karta stanowiska : treść PL / FR, przełożony, osoby, zadania, strony ERP ; edycja dla tych, którzy widzą stronę. */
    public function sheet($id)
    {
        $this->page(fn() => $this->view('organization/position_sheet', ['sheet' => $this->sheets->sheet((int) $id)] + $this->sheets->choices()));
    }

    public function sheetPdf($id)
    {
        $lang = (string) ($_GET['lang'] ?? 'both');
        $file = $this->sheets->pdf((int) $id, in_array($lang, ['pl', 'fr', 'both'], true) ? $lang : 'both');
        return new Response($file['content'], 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . $file['filename'] . '"',
            'Content-Length' => strlen($file['content']),
        ]);
    }

    public function saveSheet($id)
    {
        try {
            $this->sheets->save((int) $id, $this->getJson(Request::createFromGlobals()), $this->userId());
            return $this->json(['message' => 'Zapisano kartę stanowiska.']);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    public function validateSheet($id)
    {
        try {
            $this->sheets->validate((int) $id, $this->userId());
            return $this->json(['message' => 'Karta stanowiska zatwierdzona.']);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    public function sheetEmployees($id)
    {
        try {
            $n = $this->sheets->setEmployees((int) $id, $this->getJson(Request::createFromGlobals()));
            return $this->json(['message' => "Zapisano pracowników na stanowisku ({$n})."]);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    /** Strona : nieznana pozycja (usunięty użytkownik, stanowisko) → strona « Nie znaleziono » (404), nie błąd PHP. */
    private function page(callable $render): void
    {
        try {
            $render();
        } catch (CustomException $e) {
            if ((int) $e->getCode() !== 404) throw $e;
            http_response_code(404);
            $this->view('errors/not_found', ['message' => $e->getMessage(), 'back' => ['url' => '/organization/positions', 'label' => 'Funkcje i zadania']]);
        }
    }

    /** BaseController::view() réserve « user » (barre du haut) : la personne affichée devient « member ». */
    private function asMember(array $data): array
    {
        $data['member'] = $data['user'];
        unset($data['user']);
        return $data;
    }

    private function userId(): ?int
    {
        $id = user('id');
        return $id ? (int) $id : null;
    }
}
