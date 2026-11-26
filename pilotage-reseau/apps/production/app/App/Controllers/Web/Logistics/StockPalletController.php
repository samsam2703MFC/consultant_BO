<?php
declare(strict_types=1);

namespace App\Controllers\Web\Logistics;

use App\Core\Web\BaseController;
use App\Core\Web\JsonErrors;
use Symfony\Component\HttpFoundation\Request;
use App\Services\Logistics\StockPalletService;
use App\Core\Api\Workshop\WorkshopConfig;
use App\Exceptions\CustomException;
use App\Services\Client\Order\OrderFulfilmentService;
use App\Services\Organization\PageAccessService;

/** [296] Stany i palety (makieta 53) : magazyn → palety → wysyłka, skład palety, śledzenie partii ; usunięcie palety [333]. */
final class StockPalletController extends BaseController
{
    use JsonErrors;

    public function __construct(private StockPalletService $stock, private WorkshopConfig $config,
        private OrderFulfilmentService $fulfilment, private PageAccessService $access) {}

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

    /** [430] GET /ajax/logistics/stock/orders/{id} : commandes ouvertes du produit (en retard / cette semaine / plus tard). */
    public function productOrders($id)
    {
        try {
            return $this->json($this->stock->productOrders((int) $id));
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    /**
     * [442] POST /ajax/logistics/stock/orders/close {ids, action: shipped|archived} : fermeture en lot des commandes en
     * retard de la fenêtre « Zaległe ». Droit « stock_pallets » (préfixe) + accès aux commandes clients ; seules les
     * commandes encore ouvertes et datées avant la semaine de production sont changées.
     */
    public function closeLateOrders()
    {
        try {
            if (!$this->access->canSee($this->userId(), '/client-orders')) throw new CustomException('Brak dostępu do zamówień klientów.', 'errors', 403);
            $in = $this->getJson(Request::createFromGlobals());
            $ids = is_array($in['ids'] ?? null) ? array_slice($in['ids'], 0, 500) : [];
            if ($ids === []) throw new CustomException('Wybierz zamówienia.', 'errors', 422);
            $action = (string) ($in['action'] ?? '');
            $closed = $this->fulfilment->closeLate($ids, $action, $this->stock->weekStart(), null,
                'Stan ogólny : zaległe zamówienie ' . ($action === 'archived' ? 'zarchiwizowane' : 'oznaczone jako wysłane') . ' ręcznie');
            $skipped = count(array_unique(array_map('intval', $ids))) - count($closed);
            return $this->json(['closed' => $closed, 'message' => count($closed) . ' zam. ' . ($action === 'archived' ? 'zarchiwizowanych' : 'oznaczonych jako wysłane')
                . ($skipped > 0 ? ' ; ' . $skipped . ' pominiętych (już zamknięte albo nie zaległe)' : '') . '.']);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
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
