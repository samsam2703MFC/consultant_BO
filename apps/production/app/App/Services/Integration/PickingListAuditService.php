<?php
declare(strict_types=1);

namespace App\Services\Integration;

use App\Repositories\Integration\PickingListGenerationRepository;

final class PickingListAuditService
{
    public function __construct(private PickingListGenerationRepository $generations)
    {
    }

    /** @param list<array<string, mixed>> $orders @param array<string, mixed> $context */
    public function record(array $orders, string $filename, string $pdfBytes, array $context): void
    {
        $source = $context['source'] ?? '';
        if (!in_array($source, ['api', 'web'], true)) {
            throw new \InvalidArgumentException('Nieprawidłowe źródło audytu listy kompletacyjnej.');
        }

        $orderIds = [];
        $products = [];
        foreach ($orders as $order) {
            $orderId = (int) ($order['order_data']['id'] ?? 0);
            if ($orderId <= 0) {
                throw new \RuntimeException('Brak identyfikatora zamówienia w migawce listy kompletacyjnej.');
            }
            $orderIds[] = $orderId;
            foreach (($order['product_data'] ?? []) as $product) {
                if ((float) ($product['sum'] ?? 0) <= 0) {
                    continue;
                }
                $products[] = [
                    'order_id' => $orderId,
                    'shipping_date' => $order['shipping_date'] ?? null,
                    'customer' => $order['customer_data'] ?? null,
                    'category_name' => $product['category_name'] ?? null,
                    'product_name' => $product['product_name'] ?? null,
                    'quantity_carton' => $product['sum'],
                ];
            }
        }

        $this->generations->insert([
            'source' => $source,
            'id_device' => $source === 'api' ? (int) ($context['device_id'] ?? 0) ?: null : null,
            'id_user' => $source === 'web' ? (int) ($context['user_id'] ?? 0) ?: null : null,
            'filename' => $filename,
            'pdf_sha256' => hash('sha256', $pdfBytes),
            'order_count' => count($orderIds),
            'product_count' => count($products),
            'selected_order_ids_json' => json_encode(array_values($orderIds), JSON_THROW_ON_ERROR),
            'products_snapshot_json' => json_encode($products, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        ]);
    }

    /** @return list<array<string, mixed>> */
    public function getRecent(): array
    {
        return $this->generations->getRecent();
    }

    /** @return array<string, mixed> */
    public function getById(int $id): array
    {
        return $this->generations->getById($id);
    }
}
