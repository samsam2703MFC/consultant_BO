<?php

namespace App\Repositories\Dashboard;

use App\Domain\Client\Order\OpenOrders;
use App\Domain\Stock\ToProduce;

use App\Core\Helpers\BaseRepository;

/**
 * Odczyty dla strony „Dzisiaj” — tylko SELECT-y, nic nie zapisuje.
 */
class TodayRepository extends BaseRepository
{
    protected string $table = 'customer_order';

    /** Najbliższe dni wysyłki z otwartymi zamówieniami (status 1). */
    public function getShippingDays(int $limit = 6): array
    {
        $limit = max(1, min(20, $limit));
        return $this->query(
            "SELECT co.expected_shipping_date AS day,
                    COUNT(DISTINCT co.id) AS orders,
                    COUNT(DISTINCT co.id_customer) AS clients,
                    GROUP_CONCAT(DISTINCT c.company ORDER BY c.company SEPARATOR ', ') AS client_names,
                    COALESCE(SUM(cop.quantity_carton), 0) AS cartons
             FROM customer_order co
             LEFT JOIN customer c ON c.id = co.id_customer
             LEFT JOIN customer_order_product cop ON cop.id_order = co.id
             WHERE " . OpenOrders::sql('co') . "
             GROUP BY co.expected_shipping_date
             ORDER BY co.expected_shipping_date
             LIMIT {$limit}"
        ) ?: [];
    }

    /** Liczba klientów na dany dzień wg stanu palet: 1 = skanowanie, 2 = gotowe do dokumentów, 3 = dokumenty wystawione. */
    public function getPalletStates(array $days): array
    {
        if (!$days) return [];
        $params = [];
        foreach (array_values($days) as $i => $day) $params["d{$i}"] = $day;
        $in = implode(', ', array_map(fn($k) => ':' . $k, array_keys($params)));
        $rows = $this->query(
            "SELECT delivery_date AS day,
                    COUNT(DISTINCT CASE WHEN status = 1 THEN id_customer END) AS scanning,
                    COUNT(DISTINCT CASE WHEN status = 2 THEN id_customer END) AS ready,
                    COUNT(DISTINCT CASE WHEN status = 3 THEN id_customer END) AS shipped
             FROM palets_delivery
             WHERE delivery_date IN ({$in})
             GROUP BY delivery_date",
            $params
        ) ?: [];
        return array_column($rows, null, 'day');
    }

    /** Produkty, których brakuje na dany dzień: zamówione − (magazyn + spaletyzowane). */
    public function getMissingForDay(string $day): array
    {
        $rows = $this->query(
            "SELECT COUNT(*) AS products, COALESCE(SUM(missing), 0) AS cartons
             FROM (
                SELECT cop.id_product,
                       GREATEST(SUM(cop.quantity_carton) - COALESCE(MAX(st.qty), 0), 0) AS missing
                FROM customer_order co
                JOIN customer_order_product cop ON cop.id_order = co.id
                LEFT JOIN (SELECT id_product, COUNT(*) AS qty FROM carton_stock
                           WHERE status IN (" . OpenOrders::STOCK_STATUSES . ") GROUP BY id_product) st ON st.id_product = cop.id_product
                WHERE " . OpenOrders::quantitySql('co') . " AND co.expected_shipping_date = :day
                GROUP BY cop.id_product
                HAVING missing > 0
             ) x",
            ['day' => $day]
        );
        return $rows[0] ?? ['products' => 0, 'cartons' => 0];
    }

    public function getOpenComplaints(): array
    {
        $rows = $this->query(
            "SELECT SUM(acceptance_status = 'NEW') AS new_count,
                    SUM(acceptance_status = 'IN_REVIEW') AS review_count,
                    MIN(CASE WHEN acceptance_status = 'NEW' THEN acceptance_timestamp END) AS oldest_new
             FROM complaint
             WHERE acceptance_status IN ('NEW', 'IN_REVIEW')"
        );
        return $rows[0] ?? [];
    }

    /** Proformy otwartych zamówień: brak, nieopłacone, niewysłane klientowi ani franczyzie. */
    public function getOpenOrderProformas(): array
    {
        $rows = $this->query(
            "SELECT COUNT(*) AS orders,
                    SUM(co.id_proforma IS NULL) AS missing,
                    SUM(dp.status = 'issued') AS unpaid,
                    SUM(dp.status = 'issued' AND COALESCE(dp.is_sent_to_client, 0) = 0
                        AND COALESCE(dp.is_sent_to_franchise, 0) = 0) AS not_sent
             FROM customer_order co
             LEFT JOIN document_proforma dp ON dp.id = co.id_proforma
             WHERE " . OpenOrders::sql('co')
        );
        return $rows[0] ?? [];
    }

    /** Palety gotowe do wystawienia dokumentów w ostatnich 14 dniach (klient + dzień). */
    public function countPalletsReadyForDocuments(): int
    {
        $rows = $this->query(
            "SELECT COUNT(*) AS total FROM palets_delivery
             WHERE status = 2 AND delivery_date >= CURDATE() - INTERVAL 14 DAY"
        );
        return (int) ($rows[0]['total'] ?? 0);
    }

    /**
     * Produkty poniżej minimum — ta sama reguła co /stock i Stan magazynu (App\Domain\Stock\ToProduce) :
     * dostępne = stan (wolne + na paletach) − zamówienia otwarte + zlecenia MTS w toku < stan min. (karta MTS).
     */
    public function countProductsBelowMinimum(): int
    {
        $rows = $this->query(
            "SELECT COUNT(*) AS total FROM (
                SELECT " . ToProduce::minCartonsSql('ts', 'fp') . " AS min_cartons,
                       COALESCE(st.qty, 0) - COALESCE(oo.cartons, 0) + IF(fp.ppc > 0, COALESCE(mo.pieces, 0) / fp.ppc, 0) AS available
                FROM final_product fp
                LEFT JOIN n_prod_item ti ON ti.id_final_product = fp.id AND ti.item_type = 'product'
                LEFT JOIN n_prod_product_settings ts ON ts.id_item = ti.id
                LEFT JOIN (SELECT id_product, COUNT(*) AS qty FROM carton_stock
                           WHERE status IN (" . OpenOrders::STOCK_STATUSES . ") GROUP BY id_product) st ON st.id_product = fp.id
                LEFT JOIN (SELECT cop.id_product, SUM(cop.quantity_carton) AS cartons FROM customer_order co
                           JOIN customer_order_product cop ON cop.id_order = co.id
                           WHERE " . OpenOrders::quantitySql('co') . " GROUP BY cop.id_product) oo ON oo.id_product = fp.id
                LEFT JOIN (SELECT id_item, SUM(quantity) AS pieces FROM n_prod_order WHERE " . ToProduce::IN_PRODUCTION_WHERE . " GROUP BY id_item) mo ON mo.id_item = ti.id
                WHERE fp.active = 1
             ) x WHERE x.min_cartons IS NOT NULL AND ROUND(x.available, 1) < x.min_cartons"
        );
        return (int) ($rows[0]['total'] ?? 0);
    }

    public function countActiveProductsWithoutRecipe(): int
    {
        $rows = $this->query(
            "SELECT COUNT(*) AS total FROM final_product WHERE active = 1 AND id_recipe IS NULL"
        );
        return (int) ($rows[0]['total'] ?? 0);
    }

    public function getLatestOrders(int $limit = 6): array
    {
        $limit = max(1, min(20, $limit));
        return $this->query(
            "SELECT co.id, co.reference, co.expected_shipping_date, co.create_order_date, co.status,
                    c.company,
                    (SELECT COALESCE(SUM(quantity_carton), 0) FROM customer_order_product WHERE id_order = co.id) AS cartons,
                    dp.status AS proforma_status
             FROM customer_order co
             LEFT JOIN customer c ON c.id = co.id_customer
             LEFT JOIN document_proforma dp ON dp.id = co.id_proforma
             ORDER BY co.create_order_date DESC, co.id DESC
             LIMIT {$limit}"
        ) ?: [];
    }
}
