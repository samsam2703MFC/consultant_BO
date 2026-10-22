<?php
namespace App\Controllers\Web\Organization;

use App\Core\Web\BaseController;
use App\Core\Web\JsonErrors;
use App\Services\Organization\PositionTaskService;
use Symfony\Component\HttpFoundation\Request;

/** Stanowiska i zadania użytkowników ERP, karta użytkownika, „Moje zadania”. */
class PositionController extends BaseController
{
    use JsonErrors;

    public function __construct(private PositionTaskService $service)
    {
    }

    public function positions()
    {
        $this->view('organization/positions', [
            'positions' => $this->service->positions(),
            'users' => $this->service->users(),
            'frequencies' => PositionTaskService::FREQUENCY_LABELS,
        ]);
    }

    public function userSheet($id)
    {
        $this->view('organization/user', $this->asMember($this->service->userSheet((int) $id)) + [
            'positions' => $this->service->positions(),
            'module' => $this->service->moduleRoles((int) $id) + ['can_set' => $this->service->canSetModuleRoles($this->userId())],
            'frequencies' => PositionTaskService::FREQUENCY_LABELS,
        ]);
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
