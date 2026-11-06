<?php
namespace App\Controllers\Web\Organization;

use App\Core\Web\BaseController;
use App\Core\Web\JsonErrors;
use App\Exceptions\CustomException;
use App\Services\Organization\TeamTasksService;
use Symfony\Component\HttpFoundation\Request;

/**
 * Zadania zespołu [232] (makiety 7 i 8) : tablica Kierownika produkcji — zadania wszystkich grup i osób, zrobione /
 * niezrobione, filtry, « Przypomnij » (osoba : dzwonek + e-mail ; grupa : komunikat na tablecie potwierdzany PIN-em).
 */
class TeamTasksController extends BaseController
{
    use JsonErrors;

    private const QUERY = ['view', 'offset', 'group', 'person', 'state', 'freq', 'category', 'q'];

    public function __construct(private TeamTasksService $team)
    {
    }

    public function index()
    {
        try {
            $this->view('organization/team_tasks', ['data' => $this->team->board($this->query(), $this->userId())]);
        } catch (CustomException $e) {
            if ((int) $e->getCode() !== 403) throw $e;
            http_response_code(403);
            $this->view('errors/not_found', ['message' => $e->getMessage(), 'back' => ['url' => '/today', 'label' => 'Dzisiaj']]);
        }
    }

    public function data()
    {
        try {
            return $this->json(['data' => $this->team->board($this->query(), $this->userId())]);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    public function remind()
    {
        try {
            $r = $this->team->remind($this->getJson(Request::createFromGlobals()), $this->userId());
            $parts = [];
            if ($r['users']) $parts[] = $r['users'] . ' ' . ($r['users'] === 1 ? 'osoba' : 'osoby') . ' (dzwonek' . ($r['emails'] ? ' + e-mail' : '') . ')';
            if ($r['tablets']) $parts[] = $r['tablets'] . ' ' . ($r['tablets'] === 1 ? 'tablet' : 'tablety') . ' (komunikat)';
            return $this->json(['message' => 'Przypomnienie wysłane : ' . implode(', ', $parts) . '.' . ($r['skipped'] ? ' Pominięte : ' . implode(' ; ', $r['skipped']) . '.' : ''), 'data' => $r]);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    private function query(): array
    {
        $q = [];
        foreach (self::QUERY as $k) if (isset($_GET[$k]) && is_string($_GET[$k])) $q[$k] = mb_substr($_GET[$k], 0, 80);
        return $q;
    }

    private function userId(): ?int
    {
        $id = user('id');
        return $id ? (int) $id : null;
    }
}
