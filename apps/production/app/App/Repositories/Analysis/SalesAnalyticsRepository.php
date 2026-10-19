<?php
namespace App\Repositories\Analysis;

use App\Core\Helpers\BaseRepository;

class SalesAnalyticsRepository extends BaseRepository
{
    protected string $table = 'document_wdt';

    /**
     * Zwraca dostępny zakres dat dokumentów WDT (min/max).
     */
    public function getAvailableDateRange(?int $customerId = null): array
    {
        $params = [];
        $customerWhere = $this->customerWhere('', $customerId, $params);
        $sql = "SELECT
                    MIN(DATE_FORMAT(established_date, '%Y-%m')) AS date_min,
                    MAX(DATE_FORMAT(established_date, '%Y-%m')) AS date_max
                FROM document_wdt
                WHERE 1 = 1{$customerWhere}";
        $rows = $this->query($sql, $params);
        return $rows[0] ?? ['date_min' => null, 'date_max' => null];
    }

    /** Clients that actually have WDT documents, including inactive historical clients. */
    public function getFilterableCustomers(): array
    {
        return $this->query(
            'SELECT DISTINCT c.id, c.company
             FROM document_wdt wdt
             INNER JOIN customer c ON c.id = wdt.id_customer
             ORDER BY c.company ASC, c.id ASC'
        ) ?: [];
    }

    /**
     * Podsumowanie KPI dla zakresu dat (year-month od/do).
     */
    public function getSummary(string $dateFrom, string $dateTo, ?int $customerId = null): array
    {
        $params = ['date_from' => $dateFrom, 'date_to' => $dateTo];
        $customerWhere = $this->customerWhere('wdt.', $customerId, $params);
        $sql = "SELECT
                    IFNULL(SUM(wdtp.quantity * wdtp.price_carton), 0)  AS total_value,
                    COUNT(DISTINCT wdt.id)                              AS total_documents,
                    COUNT(DISTINCT wdt.id_customer)                    AS total_customers,
                    IFNULL(SUM(wdtp.quantity), 0)                      AS total_cartons
                FROM document_wdt wdt
                INNER JOIN document_wdt_product wdtp ON wdt.id = wdtp.id_document_wdt
                WHERE DATE_FORMAT(wdt.established_date, '%Y-%m') >= :date_from
                  AND DATE_FORMAT(wdt.established_date, '%Y-%m') <= :date_to{$customerWhere}";
        $rows = $this->query($sql, $params);
        return $rows[0] ?? [];
    }

    /**
     * Agregacja miesięczna: rok-miesiąc, wartość, liczba dokumentów, kartony.
     */
    public function getMonthlyRevenue(string $dateFrom, string $dateTo, ?int $customerId = null): array
    {
        $params = ['date_from' => $dateFrom, 'date_to' => $dateTo];
        $customerWhere = $this->customerWhere('wdt.', $customerId, $params);
        $sql = "SELECT
                    DATE_FORMAT(wdt.established_date, '%Y-%m')          AS period,
                    IFNULL(SUM(wdtp.quantity * wdtp.price_carton), 0)   AS total_value,
                    COUNT(DISTINCT wdt.id)                              AS total_documents,
                    IFNULL(SUM(wdtp.quantity), 0)                       AS total_cartons
                FROM document_wdt wdt
                INNER JOIN document_wdt_product wdtp ON wdt.id = wdtp.id_document_wdt
                WHERE DATE_FORMAT(wdt.established_date, '%Y-%m') >= :date_from
                  AND DATE_FORMAT(wdt.established_date, '%Y-%m') <= :date_to{$customerWhere}
                GROUP BY DATE_FORMAT(wdt.established_date, '%Y-%m')
                ORDER BY period ASC";
        return $this->query($sql, $params) ?: [];
    }

    /**
     * Średnia miesięczna sprzedaż każdego produktu w podanym zakresie dat.
     * Zwraca product_id, name, avg_quantity, avg_value, total_quantity, total_value, months_count.
     */
    public function getAverageProductSales(string $dateFrom, string $dateTo, ?int $customerId = null): array
    {
        $params = ['date_from' => $dateFrom, 'date_to' => $dateTo];
        $customerWhere = $this->customerWhere('wdt.', $customerId, $params);
        $sql = "SELECT
                    fp.id                                               AS product_id,
                    fp.name                                             AS product_name,
                    cp.name                                             AS category_name,
                    SUM(wdtp.quantity)                                  AS total_quantity,
                    SUM(wdtp.quantity * wdtp.price_carton)              AS total_value,
                    COUNT(DISTINCT DATE_FORMAT(wdt.established_date, '%Y-%m')) AS months_count,
                    SUM(wdtp.quantity)
                        / COUNT(DISTINCT DATE_FORMAT(wdt.established_date, '%Y-%m')) AS avg_quantity,
                    SUM(wdtp.quantity * wdtp.price_carton)
                        / COUNT(DISTINCT DATE_FORMAT(wdt.established_date, '%Y-%m')) AS avg_value
                FROM document_wdt wdt
                INNER JOIN document_wdt_product wdtp ON wdt.id = wdtp.id_document_wdt
                INNER JOIN final_product fp ON wdtp.id_product = fp.id
                LEFT JOIN category_product cp ON fp.id_category = cp.id
                WHERE DATE_FORMAT(wdt.established_date, '%Y-%m') >= :date_from
                  AND DATE_FORMAT(wdt.established_date, '%Y-%m') <= :date_to{$customerWhere}
                GROUP BY fp.id, fp.name, cp.name
                ORDER BY avg_value DESC";
        return $this->query($sql, $params) ?: [];
    }

    /**
     * Trend sprzedażowy wybranego produktu: miesiąc po miesiącu w zakresie dat.
     */
    public function getProductSalesTrend(int $productId, string $dateFrom, string $dateTo, ?int $customerId = null): array
    {
        $params = ['product_id' => $productId, 'date_from' => $dateFrom, 'date_to' => $dateTo];
        $customerWhere = $this->customerWhere('wdt.', $customerId, $params);
        $sql = "SELECT
                    DATE_FORMAT(wdt.established_date, '%Y-%m')         AS period,
                    IFNULL(SUM(wdtp.quantity), 0)                      AS quantity,
                    IFNULL(SUM(wdtp.quantity * wdtp.price_carton), 0)  AS value
                FROM document_wdt wdt
                INNER JOIN document_wdt_product wdtp ON wdt.id = wdtp.id_document_wdt
                WHERE wdtp.id_product = :product_id
                  AND DATE_FORMAT(wdt.established_date, '%Y-%m') >= :date_from
                  AND DATE_FORMAT(wdt.established_date, '%Y-%m') <= :date_to{$customerWhere}
                GROUP BY DATE_FORMAT(wdt.established_date, '%Y-%m')
                ORDER BY period ASC";
        return $this->query($sql, $params) ?: [];
    }

    /**
     * Dane jednego produktu (name, category) po ID.
     */
    public function getProductById(int $productId): array
    {
        $sql = "SELECT fp.id, fp.name, cp.name AS category_name
                FROM final_product fp
                LEFT JOIN category_product cp ON fp.id_category = cp.id
                WHERE fp.id = :id LIMIT 1";
        $rows = $this->query($sql, ['id' => $productId]);
        return $rows[0] ?? [];
    }

    private function customerWhere(string $tablePrefix, ?int $customerId, array &$params): string
    {
        if ($customerId === null) return '';
        $params['customer_id'] = $customerId;
        return " AND {$tablePrefix}id_customer = :customer_id";
    }
}

