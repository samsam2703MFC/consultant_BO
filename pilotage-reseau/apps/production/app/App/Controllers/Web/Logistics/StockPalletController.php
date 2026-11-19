<?php
declare(strict_types=1);

namespace App\Controllers\Web\Logistics;

use App\Core\Web\BaseController;
use App\Core\Web\JsonErrors;
use Symfony\Component\HttpFoundation\Request;
use App\Services\Logistics\StockPalletService;
use App\Core\Api\Workshop\WorkshopConfig;

/** [296] Stany i palety (makieta 53) : magazyn → palety → wysyłka, skład palety, śledzenie partii ; usunięcie palety [333]. */
final class StockPalletController extends BaseController
{
    use JsonErrors;

    public function __construct(private StockPalletService $stock, private WorkshopConfig $config) {}

    /** GET /logistics/stock?q=&pallet=&product=&made= (partia = produkt + dzień etykiety) */
    public function index()
    {
        // [350] Śledzenie partii przeniesione do Produkcja › Partie i identyfikowalność : stare linki (?product=&made=) tam.
        if (!empty($_GET['product']) && empty($_GET['pallet'])) {
            $q = ['product' => (int) $_GET['product']];
            if (isset($_GET['made']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $_GET['made'])) $q['made'] = (string) $_GET['made'];
            header('Location: ' . ROOT . '/workshop/traceability?' . http_build_query($q) . '#lot', true, 302);
            exit;
        }
        $id = static fn(string $k): ?int => isset($_GET[$k]) && preg_match('/^\d{1,10}$/', (string) $_GET[$k]) ? (int) $_GET[$k] : null;
        $made = isset($_GET['made']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $_GET['made']) ? (string) $_GET['made'] : null;
        $data = $this->stock->overview(mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 80), $id('pallet'), $id('product'), $made);
        // [327] Wysłane w osobnej zakładce : główny widok = magazyn → w budowie → gotowe.
        $data['tab'] = in_array($_GET['tab'] ?? '', ['shipped', 'expiring', 'general'], true) ? $_GET['tab'] : 'flow';
        // [334] Stan ogólny : stan − zamówione → zostaje po wysyłce, względem stanu maks.
        // Tryb MTS / MTO i stan maks. zapisywane przez API kart produktów (te same reguły i uprawnienia co strona Produkty).
        if ($data['tab'] === 'general') $data += ['general' => $this->stock->generalStock(), 'api_base' => $this->config->webApiBase(ROOT), 'session_user' => (int) user('id')];
        $this->view('logistics/stock_pallets', $data);
    }

    /** [333] DELETE /ajax/logistics/stock/pallets/{id} {mode: stock|delete, reason?} */
    public function deletePallet($id)
    {
        try {
            $in = $this->getJson(Request::createFromGlobals());
            $r = $this->stock->deletePallet((int) $id, (string) ($in['mode'] ?? ''), isset($in['reason']) ? (string) $in['reason'] : null, $this->userId());
            return $this->json(['message' => 'Paleta usunięta — ' . $r['cartons'] . ' kart. ' . ($r['mode'] === 'stock' ? 'wróciło do magazynu.' : 'usuniętych.')]);
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
