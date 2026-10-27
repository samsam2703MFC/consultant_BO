<?php
declare(strict_types=1);

namespace App\Services\Workshop\Execution;

use App\Core\Api\Workshop\ApiContext;
use App\Core\Api\Workshop\ApiException;
use App\Services\Workshop\Product\ItemService;

/**
 * Tablet grupy, zakładka « Magazyn » : produkty grupy ze stanem w kartonach — ta sama formuła co Stan magazynu
 * w ERP (ItemService::stockOverview, App\Domain\Stock\ToProduce) — i ile wolno jeszcze wyprodukować :
 * zamówienia ponad stan + do stanu maks. (MTS ; bez maks. : do stanu min.), minus dobre (zrobione − straty)
 * wpisane dziś na tablecie grupy, które nie są jeszcze na stanie. Lista produkcji tygodnia : /kiosk/week.
 */
final class KioskStockService
{
    public function __construct(private ItemService $items, private OutputService $outputs)
    {
    }

    public function group(ApiContext $context): array
    {
        $groupId = $context->deviceGroupId ?? throw new ApiException('DEVICE_WITHOUT_GROUP', 409);
        $today = [];
        $day = $this->outputs->dayOf($groupId, date('Y-m-d'), $context->translator->lang());
        foreach ($day['items'] as $i) {
            if ($i['ppc']) $today[$i['item_id']] = round(max(0.0, $i['good']) / $i['ppc'], 1);
        }
        $overview = $this->items->stockOverview($groupId);
        $rows = [];
        foreach ($overview['categories'] as $cat) {
            foreach ($cat['items'] as $p) {
                $stock = round($p['free_cartons'] + $p['palletised_cartons'], 1);
                $mts = $p['mode'] === 'MTS';
                // MTO bez zamówień i bez stanu : nic do pokazania zespołowi.
                if (!$mts && $p['ordered_cartons'] <= 0 && $stock <= 0) continue;
                $doneToday = $p['id'] !== null ? ($today[$p['id']] ?? 0.0) : 0.0;
                $target = $mts ? ($p['stock_max_cartons'] !== null ? (int) $p['to_max_cartons'] : (int) ($p['to_min_cartons'] ?? 0)) : 0;
                $allowed = max(0, (int) ceil($p['orders_over_stock_cartons'] + $target - $doneToday - 1e-6));
                $rows[] = [
                    'item_id' => $p['id'], 'name' => $p['name'], 'category' => $cat['name'], 'mode' => $p['mode'], 'ppc' => $p['ppc'],
                    'stock_cartons' => $stock, 'ordered_cartons' => round($p['ordered_cartons'], 1),
                    'min_cartons' => $p['stock_min_cartons'], 'max_cartons' => $p['stock_max_cartons'],
                    'in_production_cartons' => $p['in_production_cartons'], 'today_cartons' => $doneToday,
                    'orders_over_stock_cartons' => (int) $p['orders_over_stock_cartons'], 'allowed_cartons' => $allowed,
                    'below_min' => (bool) $p['below_min'], 'no_max' => $mts && $p['stock_max_cartons'] === null,
                    'next_shipping' => $p['orders'] ? min(array_column($p['orders'], 'shipping_date')) : null,
                ];
            }
        }
        usort($rows, fn($a, $b) => [!$a['below_min'], $a['mode'] !== 'MTS', -$a['allowed_cartons'], $a['name']] <=> [!$b['below_min'], $b['mode'] !== 'MTS', -$b['allowed_cartons'], $b['name']]);
        return [
            'date' => date('Y-m-d'),
            'group' => $day['group'],
            'items' => $rows,
            'totals' => [
                'products' => count($rows),
                'mts' => count(array_filter($rows, fn($r) => $r['mode'] === 'MTS')),
                'below_min' => count(array_filter($rows, fn($r) => $r['below_min'])),
                'allowed_cartons' => array_sum(array_column($rows, 'allowed_cartons')),
                'ordered_cartons' => round(array_sum(array_column($rows, 'ordered_cartons')), 1),
                'today_cartons' => round(array_sum(array_column($rows, 'today_cartons')), 1),
            ],
        ];
    }
}
