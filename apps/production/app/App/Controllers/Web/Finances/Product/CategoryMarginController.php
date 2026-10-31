<?php
namespace App\Controllers\Web\Finances\Product;

use App\Core\Web\BaseController;
use App\Exceptions\CustomException;
use App\Repositories\Analysis\MarginRecalcRepository;
use App\Repositories\Product\FinalProductRepository;
use App\Services\Finances\ClientPriceService;
use App\Services\Finances\ProductMarginService;
use App\Services\Finances\StandardPriceService;
use App\Services\Finances\StorePrice\StoreSellabilityService;
use App\Services\Recipe\RecipeCostRecalculationService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

class CategoryMarginController extends BaseController
{
    public function __construct(
        private ProductMarginService                 $productMarginService,
        private FinalProductRepository               $finalProductRepository,
        private StandardPriceService                 $standardPriceService,
        private RecipeCostRecalculationService       $recipeCosts,
        private MarginRecalcRepository               $recalc,
        private StoreSellabilityService              $sellability,
        private ClientPriceService                   $clientPrices,
    ) {
    }

    public function overview(): void
    {
        $request  = Request::createFromGlobals();
        $months   = (int)$request->query->get('months', 12);
        $months   = max(1, min(24, $months));

        $available = $this->productMarginService->getAvailableMonthsCount();
        $result    = $this->productMarginService->calculateCategoryOverview($months);
        // Widok « Sprzedawalność w Belgii » : cena fabryczna za sztukę × mnożnik franczyzy vs cena w sklepie (API panelu) ;
        // « Ceny klientów » [212] : cennik klienta po kliencie — obecna, nowa wg marży, różnica (nigdy niżej)
        $view      = in_array($request->query->get('view'), ['be', 'clients'], true) ? $request->query->get('view') : 'margin';
        $userId    = user('id') ? (int) user('id') : null;
        $client    = (int) $request->query->get('client', 0);

        $this->view('finances/product-margin/categories', [
            'view'                   => $view,
            'be'                     => $view === 'be' ? $this->sellability->forCategories($result ?: [], $userId) : null,
            'cp'                     => $view === 'clients' ? $this->clientPrices->overview($result ?: [], $client > 0 ? $client : null) : null,
            'cp_admin'               => $view === 'clients' && $this->clientPrices->isAdmin($userId),
            'selected_months'        => $months,
            'available_months_count' => $available,
            'result'                 => $result,
            'recalc'                 => $this->recalcEffects($result),
            'targets'                => $this->targetEffects($result),
        ]);
    }

    /**
     * POST /ajax/category-margin/recalculate : « Przelicz wszystkie marże » — koszt surowców wszystkich
     * receptur po bieżących cenach surowców ; ceny sprzedaży bez zmian. Zapisuje koszt przed i po,
     * aby strona pokazała efekt na sztukę.
     */
    public function recalculate(): JsonResponse
    {
        try {
            $before = $this->recalc->currentCosts();
            $run = $this->recipeCosts->recalcForAll();
            $after = $this->recalc->currentCosts();
            $id = user('id');
            $recipes = (int) ($run['recipes'] ?? 0);
            $saved = $this->recalc->save($before, $after, $id ? (int) $id : null, $recipes);
            $message = $saved['changed']
                ? "Przeliczono koszty {$recipes} receptur — zmiana kosztu u {$saved['changed']} z {$saved['products']} produktów."
                : "Przeliczono koszty {$recipes} receptur — ceny surowców bez zmian od ostatniego przeliczenia ; kolumna « Efekt na sztukę » pokazuje ostatnią zmianę każdego produktu.";
            return $this->json(['ok' => true, 'recipes' => $recipes, 'products' => $saved['products'], 'changed' => $saved['changed'], 'message' => $message]);
        } catch (\Throwable $e) {
            error_log('[category-margin] recalculate failed: ' . $e->getMessage());
            return $this->json(['ok' => false, 'error' => 'Nie udało się przeliczyć kosztów receptur.'], 500);
        }
    }

    /**
     * Efekt ostatniego przeliczenia na sztukę : zmiana kosztu surowców sztuki (€ i %) i zmiana marży
     * (pp) przy obecnej cenie sprzedaży. @return array{at: ?string, by: ?string, products: array<int, array>}
     */
    private function recalcEffects(array $result): array
    {
        $rows = $this->recalc->last();
        if (!$rows) return ['at' => null, 'by' => null, 'products' => []];
        $rate = (float) ($result['exchange_rate'] ?? 0);
        $products = [];
        foreach ($result['categories'] ?? [] as $cat) {
            foreach ($cat['products'] as $p) {
                $r = $rows[$p['product_id']] ?? null;
                if ($r === null) continue;
                $ppc = max(1, (int) ($r['ppc'] ?? 1));
                $delta = (float) $r['new_cost_carton'] - (float) $r['old_cost_carton'];
                $sale = (float) ($p['sale_price_pln'] ?? 0);
                // Miesiąc : zmiana kosztu kartonu × średnia sprzedaż kartonów na miesiąc (WDT) — efekt na marży.
                $monthPln = -$delta * (float) ($p['avg_sold_qty_per_month'] ?? 0);
                $products[$p['product_id']] = [
                    'month_pln' => $monthPln, 'month_eur' => $rate > 0 ? $monthPln / $rate : null,
                    'delta_pln_unit' => $delta / $ppc,
                    'delta_eur_unit' => $rate > 0 ? $delta / $ppc / $rate : null,
                    'delta_pct' => (float) $r['old_cost_carton'] > 0 ? $delta / (float) $r['old_cost_carton'] * 100 : null,
                    'margin_pp' => $sale > 0 ? -$delta / $sale * 100 : null,
                    'changed_at' => abs($delta) >= 0.00005 ? $r['recalculated_at'] : null,
                    'old_unit_eur' => $rate > 0 ? (float) $r['old_cost_carton'] / $ppc / $rate : null,
                    'new_unit_eur' => $rate > 0 ? (float) $r['new_cost_carton'] / $ppc / $rate : null,
                ];
            }
        }
        $lastRun = $this->recalc->lastRun();
        $first = reset($rows);
        $categories = [];
        foreach ($result['categories'] ?? [] as $cat) {
            foreach ($cat['products'] as $p) {
                if (isset($products[$p['product_id']]['month_eur'])) $categories[$cat['category_id']] = ($categories[$cat['category_id']] ?? 0) + $products[$p['product_id']]['month_eur'];
            }
        }
        return ['at' => $lastRun['run_at'] ?? max(array_column($rows, 'recalculated_at')), 'by' => $lastRun['by_name'] ?? ($first['by_name'] ?? null),
            'run_changed' => $lastRun !== null ? (int) $lastRun['changed'] : null, 'products' => $products,
            'categories' => $categories, 'month_eur' => array_sum($categories)];
    }

    /**
     * Ceny wg marży docelowej kategorii (category_product.target_margin_percentage) : cena kartonu dająca
     * marżę celu = koszt wytworzenia ÷ (1 − cel) i zmiana obrotu (CA) na miesiąc przy tej cenie =
     * (cena celu − obecna cena) × średnia sprzedaż kartonów na miesiąc. Kategoria bez celu : brak.
     * @return array{products: array<int, array>, categories: array<int, float>, month_eur: float}
     */
    private function targetEffects(array $result): array
    {
        $rate = (float) ($result['exchange_rate'] ?? 0);
        $products = [];
        $categories = [];
        foreach ($result['categories'] ?? [] as $cat) {
            if ((float) $cat['target_margin'] <= 0 || (float) $cat['target_margin'] >= 100 || $rate <= 0) continue;
            foreach ($cat['products'] as $p) {
                if (empty($p['has_data']) || $p['suggested_price_pln'] === null || (float) $p['sale_price_pln'] <= 0) continue;
                $deltaPln = (float) $p['suggested_price_pln'] - (float) $p['sale_price_pln'];
                $monthEur = $deltaPln * (float) ($p['avg_sold_qty_per_month'] ?? 0) / $rate;
                $products[$p['product_id']] = [
                    'price_eur' => (float) $p['suggested_price_pln'] / $rate,
                    'delta_pct' => $deltaPln / (float) $p['sale_price_pln'] * 100,
                    'ca_month_eur' => $monthEur,
                    'target' => (float) $cat['target_margin'],
                ];
                $categories[$cat['category_id']] = ($categories[$cat['category_id']] ?? 0) + $monthEur;
            }
        }
        return ['products' => $products, 'categories' => $categories, 'month_eur' => array_sum($categories)];
    }

    /**
     * POST /ajax/category-margin/client-prices {customer_id, product_ids[], months} : nowe ceny klienta wg marży [212]
     * (serwer liczy od nowa ; tylko podwyżki, historia cen n_product_price_change).
     */
    public function applyClientPrices(): JsonResponse
    {
        $body = $this->getJson(Request::createFromGlobals());
        $months = max(1, min(24, (int) ($body['months'] ?? 12)));
        $userId = user('id') ? (int) user('id') : null;
        try {
            $result = $this->clientPrices->apply($this->productMarginService->calculateCategoryOverview($months) ?: [],
                (int) ($body['customer_id'] ?? 0), (array) ($body['product_ids'] ?? []), $userId);
        } catch (CustomException $e) {
            return $this->json(['ok' => false, 'error' => $e->getMessage()], (int) $e->getCode() ?: 400);
        }
        return $this->json(['ok' => true] + $result);
    }

    /** PUT /ajax/category-margin/client-prices/params {cap_pct, round_eur, keep_discount} — administrator. */
    public function saveClientPriceParams(): JsonResponse
    {
        $userId = user('id') ? (int) user('id') : null;
        try {
            $params = $this->clientPrices->saveParams($this->getJson(Request::createFromGlobals()), $userId);
        } catch (CustomException $e) {
            return $this->json(['ok' => false, 'error' => $e->getMessage()], (int) $e->getCode() ?: 400);
        }
        return $this->json(['ok' => true, 'params' => $params]);
    }

    /**
     * POST /ajax/category-margin/product-action
     * Obsługuje:
     *   action = 'set_price'      → zmiana ceny standard_price + ceny klientów równe standardowej (StandardPriceService)
     *   action = 'deactivate'     → ustawienie active = 0
     */
    public function productAction(): JsonResponse
    {
        $request = Request::createFromGlobals();
        $body    = $this->getJson($request);

        $action    = $body['action']     ?? '';
        $productId = (int)($body['product_id'] ?? 0);

        if ($productId <= 0) {
            return $this->json(['ok' => false, 'error' => 'Brak product_id.'], 400);
        }

        switch ($action) {
            case 'set_price':
                // Ta sama reguła co cennik ogólny : klienci z ceną standardową idą za nią, ceny wynegocjowane zostają
                try {
                    $userId = user('id');
                    $result = $this->standardPriceService->change($productId, $body['price'] ?? null, 'category_margin', $userId ? (int) $userId : null);
                } catch (CustomException $e) {
                    return $this->json(['ok' => false, 'error' => $e->getMessage()], (int) $e->getCode() ?: 400);
                }

                return $this->json([
                    'ok'      => true,
                    'message' => $result['message'],
                    'price'   => $result['price'],
                ]);

            case 'deactivate':
                $this->finalProductRepository->deactivate($productId);

                return $this->json([
                    'ok'      => true,
                    'message' => 'Produkt został wyłączony ze sprzedaży (active = 0).',
                ]);

            default:
                return $this->json(['ok' => false, 'error' => 'Nieznana akcja: ' . $action], 400);
        }
    }
}

