<?php
declare(strict_types=1);

namespace App\Controllers\Web\Organization;

use App\Core\Web\BaseController;
use App\Core\Web\JsonErrors;
use App\Exceptions\CustomException;
use App\Services\Organization\UserLeaveService;
use Symfony\Component\HttpFoundation\Request;

/** [289] « Mój urlop » (makieta 50) : mój urlop z zastępcą, decyzje CEO, kto kogo zastępuje. */
final class UserLeaveController extends BaseController
{
    use JsonErrors;

    public function __construct(private UserLeaveService $leave)
    {
    }

    /** GET /my-leave */
    public function page()
    {
        $userId = $this->userId();
        if (!$userId) { header('Location: ' . ROOT . '/', true, 302); exit; }
        $this->view('organization/my_leave', ['l' => $this->leave->page($userId)]);
    }

    public function data()
    {
        return $this->guarded(fn(int $u) => $this->leave->page($u));
    }

    public function create()
    {
        return $this->guarded(fn(int $u) => $this->leave->create($u, $this->input()));
    }

    public function substitute($id)
    {
        return $this->guarded(fn(int $u) => ['message' => 'Zapisano zastępcę.', 'data' => $this->leave->changeSubstitute((int) $id, $this->input()['substitute_id'] ?? null, $u)]);
    }

    public function approve($id)
    {
        return $this->guarded(fn(int $u) => ['message' => 'Urlop zatwierdzony — zastępca dostał powiadomienie.', 'data' => $this->leave->approve((int) $id, $u)]);
    }

    public function reject($id)
    {
        return $this->guarded(fn(int $u) => ['message' => 'Urlop odrzucony.', 'data' => $this->leave->reject((int) $id, (string) ($this->input()['reason'] ?? ''), $u)]);
    }

    public function cancel($id)
    {
        return $this->guarded(function (int $u) use ($id) {
            $this->leave->cancel((int) $id, $u);
            return ['message' => 'Urlop anulowany.'];
        });
    }

    private function guarded(callable $fn)
    {
        try {
            $u = $this->userId() ?? throw new CustomException('Zaloguj się.', 'errors', 401);
            $out = $fn($u);
            return $this->json(isset($out['message']) ? $out : ['data' => $out]);
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
