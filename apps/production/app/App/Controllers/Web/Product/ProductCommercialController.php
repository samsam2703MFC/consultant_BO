<?php
namespace App\Controllers\Web\Product;

use App\Core\Web\BaseController;
use App\Core\Web\JsonErrors;
use App\Services\Product\ProductCommercialService;
use Symfony\Component\HttpFoundation\Request;

/** Karta produktu, krok « Ceny i zgodność » : podsumowanie i kopia ceny standardowej do klientów bez ceny. */
class ProductCommercialController extends BaseController
{
    use JsonErrors;

    public function __construct(private ProductCommercialService $commercial) {}

    public function summary($id)
    {
        try {
            return $this->json(['data' => $this->commercial->summary((int) $id)]);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    public function copyPrice($id)
    {
        try {
            $result = $this->commercial->copyStandardPrice((int) $id, $this->getJson(Request::createFromGlobals()));
            return $this->json(['message' => 'Dodano cenę u ' . $result['copied'] . ' klientów.', 'data' => $result]);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }
}
