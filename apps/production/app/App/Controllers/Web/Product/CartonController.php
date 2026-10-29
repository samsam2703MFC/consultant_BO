<?php
declare(strict_types=1);

namespace App\Controllers\Web\Product;

use App\Core\Web\BaseController;
use App\Core\Web\JsonErrors;
use App\Services\Product\CartonService;
use Symfony\Component\HttpFoundation\Request;

/**
 * Kartony i palety [205] (rail Produkt) : rodzaje kartonów (wymiary, waga, maks. ładunek), układ na palecie, produkty
 * w kartonie ; obliczenia kroku « Paleta » karty produktu.
 */
final class CartonController extends BaseController
{
    use JsonErrors;

    public function __construct(private CartonService $cartons) {}

    public function index()
    {
        $this->view('product/cartons', ['boot' => json_encode($this->cartons->catalog(), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP)]);
    }

    public function data()
    {
        return $this->respond(fn() => ['data' => $this->cartons->catalog()]);
    }

    public function show($id)
    {
        return $this->respond(fn() => ['data' => $this->cartons->detail((int) $id)]);
    }

    public function save($id)
    {
        return $this->respond(fn() => ['message' => 'Zapisano karton.', 'data' => $this->cartons->save((int) $id, $this->input(), $this->userId())]);
    }

    public function candidates()
    {
        return $this->respond(fn() => ['data' => $this->cartons->candidates()]);
    }

    public function add()
    {
        return $this->respond(fn() => ['message' => 'Dodano karton do katalogu.', 'data' => $this->cartons->add($this->input(), $this->userId())], 201);
    }

    /** Krok « Paleta » : układ dla wymiarów i ograniczeń (GET, parametry w adresie). */
    public function plan()
    {
        return $this->respond(fn() => ['data' => $this->cartons->plan($_GET)]);
    }

    /** Krok « Paleta » : kartony do wyboru, karton produktu, sprzedaż na tydzień. */
    public function product($id)
    {
        return $this->respond(fn() => ['data' => $this->cartons->productInfo((int) $id)]);
    }

    private function respond(callable $fn, int $status = 200)
    {
        try {
            return $this->json($fn(), $status);
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
