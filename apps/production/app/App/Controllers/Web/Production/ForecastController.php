<?php
declare(strict_types=1);

namespace App\Controllers\Web\Production;

use App\Core\Web\BaseController;
use App\Core\Web\JsonErrors;
use App\Exceptions\CustomException;
use App\Services\Forecast\ForecastService;
use App\Services\Organization\PageAccessService;
use Symfony\Component\HttpFoundation\Request;

/**
 * Produkcja › Prognoza 13 tygodni [220] (makiety 1–3) : prognoza kroczących 13 tygodni (zamówienia, N-1 × trend, gamy
 * sezonowe panelu TF Buddy — tylko nasze produkty), sezony z panelu (połączenie produktu gamy z naszym produktem), zakupy
 * 13 tygodni, zatwierdzenie w poniedziałek. Dane : ForecastService ; panel tylko do odczytu (GET).
 */
final class ForecastController extends BaseController
{
    use JsonErrors;

    private const PATH = '/production/forecast';

    public function __construct(private ForecastService $forecast, private PageAccessService $access)
    {
    }

    public function index()
    {
        try {
            $boot = $this->forecast->page() + ['product_options' => $this->forecast->productOptions()];
        } catch (\Throwable $e) {
            $boot = ['error' => $e->getMessage()];
        }
        $this->view('production/forecast', ['boot' => json_encode($boot, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP)]);
    }

    public function data()
    {
        return $this->respond(fn() => ['data' => $this->forecast->page()]);
    }

    public function approve()
    {
        return $this->respond(fn() => ['message' => 'Prognoza zatwierdzona — zadanie tygodnia zaliczone.', 'data' => $this->forecast->approve($this->userId(), $this->input()['note'] ?? null)]);
    }

    public function reopen()
    {
        return $this->respond(fn() => ['message' => 'Cofnięto zatwierdzenie tego tygodnia.', 'data' => $this->forecast->reopen()]);
    }

    public function refresh()
    {
        return $this->respond(fn() => ['message' => 'Odczytano gamy z panelu.', 'data' => $this->forecast->refreshPanel($this->userId())]);
    }

    public function link($id)
    {
        return $this->respond(fn() => ['message' => 'Zapisano.', 'data' => $this->forecast->link((int) $id, $this->input(), $this->userId())]);
    }

    private function respond(callable $fn)
    {
        try {
            if (!$this->access->canSee($this->userId(), self::PATH)) throw new CustomException('Brak dostępu do prognozy (Produkcja).', 'errors', 403);
            return $this->json($fn());
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
