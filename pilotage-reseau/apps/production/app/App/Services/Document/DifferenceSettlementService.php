<?php
declare(strict_types=1);

namespace App\Services\Document;

use App\Repositories\Shipping\Difference\OrderShippingDifferenceProductRepository;

/** Builds a difference document from immutable paid-proforma snapshots and WDT output. */
final class DifferenceSettlementService
{
    public function __construct(private OrderShippingDifferenceProductRepository $products)
    {
    }

    public function storeForWdt(int $differenceId, int $wdtId): bool
    {
        $paid = $this->products->getPaidProformaProductsByWdt($wdtId);
        $shipped = $this->products->getShippedProductsByWdt($wdtId);
        $byProduct = [];

        foreach ($paid as $row) {
            $id = (int) $row['id_product'];
            $byProduct[$id] = [
                'id_product' => $id,
                'product_name' => (string) $row['product_name'],
                'quantity_ordered' => $row['quantity_ordered'],
                'price_during_ordering' => $row['price_during_ordering'],
                'ordered_total_amount' => $row['ordered_total_amount'],
                'invoiced_quantity' => '0',
                'price_during_invoicing' => '0.00',
                'invoiced_total_amount' => '0.00',
            ];
        }

        foreach ($shipped as $row) {
            $id = (int) $row['id_product'];
            if (!isset($byProduct[$id])) {
                $byProduct[$id] = [
                    'id_product' => $id,
                    'product_name' => (string) $row['product_name'],
                    'quantity_ordered' => '0',
                    'price_during_ordering' => '0.00',
                    'ordered_total_amount' => '0.00',
                    'invoiced_quantity' => '0',
                    'price_during_invoicing' => '0.00',
                    'invoiced_total_amount' => '0.00',
                ];
            }
            $byProduct[$id]['invoiced_quantity'] = $row['invoiced_quantity'];
            $byProduct[$id]['price_during_invoicing'] = $row['price_during_invoicing'];
            $byProduct[$id]['invoiced_total_amount'] = $row['invoiced_total_amount'];
            if ($byProduct[$id]['product_name'] === '') {
                $byProduct[$id]['product_name'] = (string) $row['product_name'];
            }
        }

        return $this->products->insertSettlementProducts($differenceId, array_values($byProduct));
    }
}
