<?php
declare(strict_types=1);

namespace App\Repositories\Logistics;

use App\Repositories\Workshop\WorkshopRepository;

/** Instrukcje dostawy klienta (jeden zaszyfrowany blok na klienta). */
final class CustomerDeliveryInfoRepository extends WorkshopRepository
{
    protected string $table = 'n_customer_delivery_info';

    public function row(int $customerId): ?array
    {
        return $this->all('SELECT id_customer, payload, updated_by, updated_at FROM n_customer_delivery_info WHERE id_customer = ?', [$customerId])[0] ?? null;
    }

    /** @return array<int, array> wiersze klientów */
    public function rows(array $customerIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $customerIds))));
        if (!$ids) return [];
        $in = implode(',', array_fill(0, count($ids), '?'));
        return array_column($this->all("SELECT id_customer, payload, updated_by, updated_at FROM n_customer_delivery_info WHERE id_customer IN ({$in})", $ids), null, 'id_customer');
    }

    public function save(int $customerId, string $payload, ?string $author): void
    {
        $this->db->prepare('INSERT INTO n_customer_delivery_info (id_customer, payload, updated_by) VALUES (?, ?, ?)
                            ON DUPLICATE KEY UPDATE payload = VALUES(payload), updated_by = VALUES(updated_by)')
            ->execute([$customerId, $payload, $author]);
    }

    public function remove(int $customerId): void
    {
        $this->db->prepare('DELETE FROM n_customer_delivery_info WHERE id_customer = ?')->execute([$customerId]);
    }
}
