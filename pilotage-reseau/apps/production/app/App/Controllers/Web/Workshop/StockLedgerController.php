<?php
namespace App\Controllers\Web\Workshop;

use App\Core\Web\BaseController;
use App\Exceptions\CustomException;
use App\Services\Stock\StockLedgerService;
use Symfony\Component\HttpFoundation\Response;

/** Ruchy magazynowe produktów gotowych : okres (przyjęcia, wydania, saldo), karta produktu, CSV. */
class StockLedgerController extends BaseController
{
    public function __construct(private StockLedgerService $ledger)
    {
    }

    /** GET /workshop/stock-movements?from=&to=&category=&q= */
    public function index()
    {
        try {
            $data = $this->ledger->overview($_GET['from'] ?? null, $_GET['to'] ?? null, self::category(), (string) ($_GET['q'] ?? ''));
        } catch (CustomException $e) {
            $data = $this->ledger->overview(null, null, null, '') + ['error' => $e->getMessage()];
        }
        $this->view('stock/movements', $data);
    }

    /** GET /workshop/stock-movements/{id}?from=&to= */
    public function product($id)
    {
        try {
            $data = $this->ledger->product((int) $id, $_GET['from'] ?? null, $_GET['to'] ?? null);
        } catch (CustomException $e) {
            http_response_code($e->getCode() === 404 ? 404 : 422);
            echo htmlspecialchars($e->getMessage());
            return;
        }
        $this->view('stock/movement_product', $data);
    }

    /** GET /workshop/stock-movements.csv?from=&to=&category=&q= */
    public function csv()
    {
        $data = $this->ledger->overview($_GET['from'] ?? null, $_GET['to'] ?? null, self::category(), (string) ($_GET['q'] ?? ''));
        return new Response($this->ledger->csv($data), 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="RUCHY_MAGAZYNOWE_' . $data['from'] . '_' . $data['to'] . '.csv"',
        ]);
    }

    private static function category(): ?int
    {
        $c = filter_var($_GET['category'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        return $c === false ? null : $c;
    }
}
