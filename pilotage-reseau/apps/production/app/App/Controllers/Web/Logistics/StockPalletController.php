<?php
declare(strict_types=1);

namespace App\Controllers\Web\Logistics;

use App\Core\Web\BaseController;
use App\Services\Logistics\StockPalletService;

/** [296] Stany i palety (makieta 53) : magazyn → palety → wysyłka, skład palety, śledzenie partii. Do odczytu. */
final class StockPalletController extends BaseController
{
    public function __construct(private StockPalletService $stock) {}

    /** GET /logistics/stock?q=&pallet=&product=&made= (partia = produkt + dzień etykiety) */
    public function index()
    {
        $id = static fn(string $k): ?int => isset($_GET[$k]) && preg_match('/^\d{1,10}$/', (string) $_GET[$k]) ? (int) $_GET[$k] : null;
        $made = isset($_GET['made']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $_GET['made']) ? (string) $_GET['made'] : null;
        $this->view('logistics/stock_pallets', $this->stock->overview(mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 80), $id('pallet'), $id('product'), $made));
    }
}
