<?php
declare(strict_types=1);

namespace App\Controllers\Web\Organization;

use App\Core\Web\BaseController;
use App\Core\Web\JsonErrors;
use App\Exceptions\CustomException;
use App\Services\Organization\TaskLockService;
use Symfony\Component\HttpFoundation\Request;

/**
 * [280] Zadania obowiązkowe (makieta 46) : écran « Najpierw to zadanie » (ERP bloqué), assistant à étapes d'une tâche sans
 * assistant propre (« Zakończ zadanie »), demande de déblocage, décision du supérieur / de l'administrateur.
 */
final class TaskLockController extends BaseController
{
    use JsonErrors;

    public function __construct(private TaskLockService $lock)
    {
    }

    /** GET /tasks/required : tâches qui bloquent ; rien ne bloque → page d'accueil. */
    public function required()
    {
        $blocking = $this->lock->blocking($this->userId());
        if (!$blocking) {
            header('Location: ' . ROOT . '/home', true, 302);
            exit;
        }
        $this->view('organization/task_required', ['blocking' => $blocking, 'now' => date('Y-m-d H:i')]);
    }

    /** GET /tasks/wizard/{id} : assistant de la tâche (assistant propre → redirection). */
    public function wizard($id)
    {
        try {
            $w = $this->lock->wizard((int) $id, $this->userId());
        } catch (CustomException $e) {
            http_response_code((int) $e->getCode() ?: 400);
            $this->view('organization/task_wizard', ['error' => $e->getMessage()]);
            return;
        }
        if ($w['own_wizard']) {
            header('Location: ' . ROOT . $w['own_wizard'], true, 302);
            exit;
        }
        $this->view('organization/task_wizard', ['w' => $w]);
    }

    public function finish($id)
    {
        return $this->guarded(fn() => $this->lock->finish((int) $id, $this->input(), $this->userId()));
    }

    public function requestUnblock($id)
    {
        return $this->guarded(fn() => $this->lock->requestUnblock((int) $id, $this->input(), $this->userId()));
    }

    /** GET /tasks/unblock/{user}/{task} : décision du supérieur ou de l'administrateur. */
    public function unblockPage($user, $task)
    {
        try {
            $v = $this->lock->unblockView((int) $user, (int) $task, $this->userId());
        } catch (CustomException $e) {
            http_response_code((int) $e->getCode() ?: 400);
            $this->view('organization/task_unblock', ['error' => $e->getMessage()]);
            return;
        }
        $this->view('organization/task_unblock', ['v' => $v]);
    }

    public function unblock($user, $task)
    {
        return $this->guarded(fn() => $this->lock->unblock((int) $user, (int) $task, $this->input(), $this->userId()));
    }

    private function guarded(callable $fn)
    {
        try {
            $out = $fn();
            return $this->json(['message' => $out['message'] ?? 'OK', 'data' => $out]);
        } catch (CustomException $e) {
            return $this->json(['message' => $e->getMessage()], in_array((int) $e->getCode(), [403, 404, 422], true) ? (int) $e->getCode() : 400);
        } catch (\Throwable $e) {
            error_log('[task-lock] ' . $e->getMessage());
            return $this->json(['message' => 'Błąd serwera.'], 500);
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
