<?php
declare(strict_types=1);

namespace App\Services\Product;

use App\Exceptions\CustomException;
use App\Repositories\Product\ProductCommercialRepository;
use App\Services\Finances\StorePrice\StoreSellabilityService;

/**
 * Karta produktu, krok « Ceny i zgodność » (audyt 2, lot C — kreator produktu, krok 7) : cena standardowa (€ / karton,
 * zapis jak w cenniku ogólnym), kopia tej ceny do klientów bez ceny produktu (bez franczyzy — jej cennik jest
 * w systemie franczyzy), opakowanie sprzedażowe PPWR i sprzedawalność w Belgii (cena sklepu ÷ cena fabryczna).
 */
final class ProductCommercialService
{
    public function __construct(private ProductCommercialRepository $repo, private StoreSellabilityService $sellability)
    {
    }

    public function summary(int $productId): array
    {
        $p = $this->repo->product($productId) ?? throw new CustomException('Nie znaleziono produktu.', 'errors', 404);
        $clients = $this->repo->clientPrices($productId);
        $direct = array_values(array_filter($clients, fn($c) => !(int) $c['franchise']));
        $missing = array_values(array_filter($direct, fn($c) => $c['price'] === null));
        $ppwr = $this->repo->ppwr($productId);
        try {
            $be = $this->sellability->evaluate([$productId])[$productId] ?? null;
        } catch (\Throwable) {
            $be = null;
        }
        return [
            'product_id' => $productId,
            'standard_price' => (float) $p['standard_price'],
            'ppc' => (int) $p['ppc'],
            'clients' => ['direct' => count($direct), 'with_price' => count($direct) - count($missing), 'franchise' => count($clients) - count($direct),
                'missing' => array_map(fn($c) => ['id' => (int) $c['id'], 'name' => $c['name']], $missing)],
            'ppwr' => $ppwr ? ['status' => $ppwr['verification_status'], 'unit' => $ppwr['sales_unit_name'], 'complete' => $ppwr['verification_status'] === 'COMPLETE'] : null,
            'belgium' => $be ? ['verdict' => $be['verdict'], 'coef' => $be['coef'], 'piece' => $be['piece'], 'store' => $be['store']['name'] ?? null,
                'max_carton' => $be['max_carton']] : null,
        ];
    }

    /** @return array{copied:int, summary:array} */
    public function copyStandardPrice(int $productId, array $input): array
    {
        $summary = $this->summary($productId);
        if ($summary['standard_price'] <= 0) throw new CustomException('Najpierw podaj cenę standardową.', 'errors', 422);
        $allowed = array_column($summary['clients']['missing'], 'id');
        $ids = array_values(array_intersect(array_map('intval', (array) ($input['client_ids'] ?? [])), $allowed));
        if (!$ids) throw new CustomException('Wybierz klientów bez ceny tego produktu.', 'errors', 422);
        $copied = $this->repo->addClientPrices($productId, $ids, $summary['standard_price']);
        return ['copied' => $copied, 'summary' => $this->summary($productId)];
    }
}
