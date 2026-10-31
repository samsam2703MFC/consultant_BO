<?php
declare(strict_types=1);

namespace App\Controllers\Web\Production;

use App\Core\Web\BaseController;
use App\Core\Web\JsonErrors;
use App\Exceptions\CustomException;
use App\Services\Organization\PageAccessService;
use App\Services\Workshop\Planning\CapacityService;
use Symfony\Component\HttpFoundation\Request;

/**
 * Moce produkcyjne [234] (makiety 10 i 11) : wykorzystanie maszyn wobec planu tygodnia, ludzie (zespoły), mroźnia zapasu,
 * ilość na zmianę produktu (wąskie gardło), « co jeszcze zmieszczę », ustawienia maszyn.
 */
final class CapacityController extends BaseController
{
    use JsonErrors;

    private const PATH = '/production/capacity';

    public function __construct(private CapacityService $capacity, private PageAccessService $access)
    {
    }

    /** Dane i ustawienia : ta sama zgoda co strona (Produkcja). */
    private function guard(): void
    {
        if (!$this->access->canSee($this->userId(), self::PATH)) throw new CustomException('Brak dostępu do mocy produkcyjnych.', 'errors', 403);
    }

    public function index()
    {
        try {
            $boot = $this->capacity->data($this->date(), $this->whatIf(), $this->group());
        } catch (\Throwable $e) {
            error_log((string) $e);
            $boot = ['error' => 'Nie udało się policzyć mocy produkcyjnych : ' . $e->getMessage()];
        }
        $this->view('production/capacity', ['data' => $boot]);
    }

    public function data()
    {
        try {
            $this->guard();
            return $this->json(['data' => $this->capacity->data($this->date(), $this->whatIf(), $this->group())]);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    public function simulate()
    {
        try {
            $this->guard();
            $qty = (float) str_replace(',', '.', (string) ($_GET['qty'] ?? '0'));
            return $this->json(['data' => $this->capacity->simulate($this->date(), (int) ($_GET['item'] ?? 0), $qty, $this->whatIf())]);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    public function saveMachine($id)
    {
        try {
            $this->guard();
            $this->capacity->saveMachine((int) $id, $this->getJson(Request::createFromGlobals()), $this->userId());
            return $this->json(['message' => 'Zapisano ustawienia maszyny.']);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    public function saveFreezer()
    {
        try {
            $this->guard();
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

    /** @return array{afternoon:bool, night:bool} */
    private function whatIf(): array
    {
        $w = is_string($_GET['shifts'] ?? null) ? explode(',', $_GET['shifts']) : [];
        return ['afternoon' => in_array('afternoon', $w, true), 'night' => in_array('night', $w, true)];
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
