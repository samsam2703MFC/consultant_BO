<?php
declare(strict_types=1);

namespace App\Controllers\Web\Product;

use App\Core\Web\BaseController;
use App\Core\Web\JsonErrors;
use App\Services\Logistics\MixedPalletService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Kartony i palety › Paleta mieszana [215] (makieta 214) : propozycje dnia wysyłki, edytor warstw, zatwierdzenie,
 * etykieta palety PDF. Dane : MixedPalletService.
 */
final class MixedPalletController extends BaseController
{
    use JsonErrors;

    public function __construct(private MixedPalletService $mixed) {}

    public function index()
    {
        try {
            $boot = $this->mixed->page($_GET);
        } catch (\Throwable $e) {
            $boot = ['error' => $e->getMessage()];
        }
        $this->view('product/cartons_mixed', ['boot' => json_encode($boot, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP)]);
    }

    public function data()
    {
        return $this->respond(fn() => ['data' => $this->mixed->page($_GET)]);
    }

    public function preview()
    {
        return $this->respond(fn() => ['data' => $this->mixed->preview($_GET)]);
    }

    public function confirm()
    {
        return $this->respond(fn() => ['message' => 'Zatwierdzono paletę mieszaną.', 'data' => $this->mixed->confirm($this->getJson(Request::createFromGlobals()), $this->userId())]);
    }

    public function delete($id)
    {
        return $this->respond(fn() => ['message' => 'Usunięto paletę mieszaną.', 'data' => $this->mixed->delete((int) $id)]);
    }

    public function label()
    {
        try {
            $file = $this->mixed->label($_GET);
            return new Response($file['file_string'], 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename=' . $file['filename']]);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    private function respond(callable $fn, int $status = 200)
    {
        try {
            return $this->json($fn(), $status);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    private function userId(): ?int
    {
        $id = user('id');
        return $id ? (int) $id : null;
    }
}
