<?php
namespace App\Repositories\Analysis;

use App\Core\Helpers\BaseRepository;
use PDO;

/**
 * Analiza sprzedaży : zakresy RRRR-MM, wszystkie liczby z jednej definicji sprzedaży WDT (WdtSalesRepository)
 * — te same sumy co Rentowność, eksport i rentowność klienta.
 */
class SalesAnalyticsRepository extends BaseRepository
{
    protected string $table = 'document_wdt';

    public function __construct(PDO $pdo, private WdtSalesRepository $wdt)
    {
        parent::__construct($pdo);
    }

    /**
     * Zwraca dostępny zakres dat dokumentów WDT (min/max).
     */
    public function getAvailableDateRange(?int $customerId = null): array
    {
        return $this->wdt->monthRange($customerId);
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
    public function getSummary(string $dateFrom, string $dateTo, ?int $customerId = null, ?int $sectionId = null): array
    {
        $row = $this->sales($dateFrom, $dateTo, $this->customerWhere($customerId, $sectionId))[0] ?? [];
        return [
            'total_value' => (float) ($row['value_eur'] ?? 0),
            'total_documents' => (int) ($row['documents'] ?? 0),
            'total_customers' => (int) ($row['customers'] ?? 0),
            'total_cartons' => (int) ($row['cartons'] ?? 0),
        ];
    }

    /**
     * Agregacja miesięczna: rok-miesiąc, wartość, liczba dokumentów, kartony.
     */
    public function getMonthlyRevenue(string $dateFrom, string $dateTo, ?int $customerId = null, ?int $sectionId = null): array
    {
        $rows = $this->sales($dateFrom, $dateTo, $this->customerWhere($customerId, $sectionId), ['month']);
        usort($rows, fn($a, $b) => strcmp($a['ym'], $b['ym']));
        return array_map(fn($r) => ['period' => $r['ym'], 'total_value' => (float) $r['value_eur'], 'total_documents' => (int) $r['documents'], 'total_cartons' => (int) $r['cartons']], $rows);
    }

    /**
     * Średnia miesięczna sprzedaż każdego produktu w podanym zakresie dat : suma ÷ miesiące, w których produkt
     * się sprzedał (ta sama reguła w eksporcie dla klienta).
     * Zwraca product_id, name, avg_quantity, avg_value, total_quantity, total_value, months_count.
     */
    public function getAverageProductSales(string $dateFrom, string $dateTo, ?int $customerId = null, ?int $sectionId = null): array
    {
        $rows = $this->sales($dateFrom, $dateTo, $this->customerWhere($customerId, $sectionId), ['product']);
        $categories = $this->categoryNames();
        $sections = array_column($this->getSections(), 'name', 'id');
        $out = array_map(function ($r) use ($categories, $sections) {
            $months = max(1, (int) $r['months_with_sales']);
            $sectionId = $r['product_section_id'] !== null ? (int) $r['product_section_id'] : null;
            return [
                'product_id' => (int) $r['product_id'],
                'product_name' => $r['product_name'],
                'category_name' => $r['product_category_id'] !== null ? ($categories[(int) $r['product_category_id']] ?? null) : null,
                'section_id' => $sectionId,
                'section_name' => $sectionId !== null ? ($sections[$sectionId] ?? 'Bez działu') : 'Bez działu',
                'total_quantity' => (int) $r['cartons'],
                'total_value' => (float) $r['value_eur'],
                'months_count' => (int) $r['months_with_sales'],
                'avg_quantity' => (int) $r['cartons'] / $months,
                'avg_value' => (float) $r['value_eur'] / $months,
            ];
        }, $rows);
        usort($out, fn($a, $b) => $b['avg_value'] <=> $a['avg_value']);
        return $out;
    }

    /**
     * Trend sprzedażowy wybranego produktu: miesiąc po miesiącu w zakresie dat.
     */
    public function getProductSalesTrend(int $productId, string $dateFrom, string $dateTo, ?int $customerId = null): array
    {
        $rows = $this->sales($dateFrom, $dateTo, $this->customerWhere($customerId) + ['product_id' => $productId], ['month']);
        usort($rows, fn($a, $b) => strcmp($a['ym'], $b['ym']));
        return array_map(fn($r) => ['period' => $r['ym'], 'quantity' => (int) $r['cartons'], 'value' => (float) $r['value_eur']], $rows);
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

    /**
     * Sprzedaż wg działów produkcji (dawna strona « Sprzedaż wg działów ») : kartony, wartość i produkty
     * w zakresie, z tymi samymi wykluczeniami co reszta analizy (zastąpione WDT, filtr klienta).
     */
    public function getSectionTotals(string $dateFrom, string $dateTo, ?int $customerId = null): array
    {
        $rows = $this->sales($dateFrom, $dateTo, $this->customerWhere($customerId), ['section']);
        $products = [];
        foreach ($this->sales($dateFrom, $dateTo, $this->customerWhere($customerId), ['section', 'product']) as $p) {
            $products[$p['section_id'] ?? ''] = ($products[$p['section_id'] ?? ''] ?? 0) + 1;
        }
        $out = array_map(fn($r) => [
            'section_id' => $r['section_id'] !== null ? (int) $r['section_id'] : null,
            'section_name' => $r['section_name'],
            'total_quantity' => (int) $r['cartons'],
            'total_value' => (float) $r['value_eur'],
            'products' => $products[$r['section_id'] ?? ''] ?? 0,
        ], $rows);
        usort($out, fn($a, $b) => $b['total_value'] <=> $a['total_value']);
        return $out;
    }

    public function getSections(): array
    {
        return $this->query('SELECT id, name FROM production_sections ORDER BY name') ?: [];
    }

    /** Zakres RRRR-MM → dni sprzedaży w WdtSalesRepository. */
    private function sales(string $dateFrom, string $dateTo, array $filters, array $groupBy = []): array
    {
        [$from, $to] = WdtSalesRepository::monthBounds($dateFrom, $dateTo);
        return $this->wdt->sales($from, $to, $filters, $groupBy);
    }

    /** Filtr klienta (i działu) każdej agregacji. */
    private function customerWhere(?int $customerId, ?int $sectionId = null): array
    {
        $filters = [];
        if ($customerId !== null) $filters['customer_id'] = $customerId;
        if ($sectionId !== null) $filters['section_id'] = $sectionId;
        return $filters;
    }

    private function categoryNames(): array
    {
        return array_column($this->query('SELECT id, name FROM category_product') ?: [], 'name', 'id');
    }
}
