<?php
namespace App\Repositories\Analysis;
use App\Core\Helpers\BaseRepository;
class SalesByDepartmentRepository extends BaseRepository
{
    protected string $table = 'document_wdt';
    /**
     * Zwraca dostępny zakres dat dokumentów WDT (min/max).
     */
    public function getAvailableDateRange(): array
    {
        $sql = "SELECT
                    MIN(DATE_FORMAT(established_date, '%Y-%m')) AS date_min,
                    MAX(DATE_FORMAT(established_date, '%Y-%m')) AS date_max
                FROM document_wdt";
        $rows = $this->query($sql);
        return $rows[0] ?? ['date_min' => null, 'date_max' => null];
    }
    /**
     * Zwraca wszystkie działy produkcyjne.
     */
    public function getDepartments(): array
    {
        $sql = "SELECT id, name FROM production_sections ORDER BY name";
        return $this->query($sql) ?: [];
    }
    /**
     * Agregacja sprzedaży produktów pogrupowana po dziale produkcyjnym.
     */
    public function getSalesByDepartment(string $dateFrom, string $dateTo, ?int $sectionId = null): array
    {
        $params = ['date_from' => $dateFrom, 'date_to' => $dateTo];
        $sectionFilter = '';
        if ($sectionId !== null) {
            $sectionFilter = 'AND fp.id_section = :section_id';
            $params['section_id'] = $sectionId;
        }
        $sql = "SELECT
                    ps.id                                                           AS section_id,
                    ps.name                                                         AS section_name,
                    fp.id                                                           AS product_id,
                    fp.name                                                         AS product_name,
                    COALESCE(cp.name, '—')                                          AS category_name,
                    SUM(wdtp.quantity)                                              AS total_quantity,
                    SUM(wdtp.quantity * wdtp.price_carton)                          AS total_value,
                    COUNT(DISTINCT DATE_FORMAT(wdt.established_date, '%Y-%m'))      AS months_count,
                    SUM(wdtp.quantity)
                        / COUNT(DISTINCT DATE_FORMAT(wdt.established_date, '%Y-%m')) AS avg_quantity,
                    SUM(wdtp.quantity * wdtp.price_carton)
                        / COUNT(DISTINCT DATE_FORMAT(wdt.established_date, '%Y-%m')) AS avg_value
                FROM document_wdt wdt
                INNER JOIN document_wdt_product wdtp ON wdt.id = wdtp.id_document_wdt
                INNER JOIN final_product fp           ON wdtp.id_product = fp.id
                INNER JOIN production_sections ps     ON fp.id_section = ps.id
                LEFT  JOIN category_product cp        ON fp.id_category = cp.id
                WHERE DATE_FORMAT(wdt.established_date, '%Y-%m') >= :date_from
                  AND DATE_FORMAT(wdt.established_date, '%Y-%m') <= :date_to
                  $sectionFilter
                GROUP BY ps.id, ps.name, fp.id, fp.name, cp.name
                ORDER BY ps.name ASC, total_value DESC";
        return $this->query($sql, $params) ?: [];
    }
    /**
     * Podsumowanie KPI per dział (łączna sprzedaż, kartony) w zakresie dat.
     */
    public function getSectionSummaries(string $dateFrom, string $dateTo): array
    {
        $sql = "SELECT
                    ps.id                                                       AS section_id,
                    ps.name                                                     AS section_name,
                    COALESCE(SUM(wdtp.quantity), 0)                            AS total_quantity,
                    COALESCE(SUM(wdtp.quantity * wdtp.price_carton), 0)        AS total_value,
                    COUNT(DISTINCT fp.id)                                       AS product_count
                FROM production_sections ps
                LEFT JOIN final_product fp          ON fp.id_section = ps.id
                LEFT JOIN document_wdt_product wdtp ON wdtp.id_product = fp.id
                LEFT JOIN document_wdt wdt          ON wdt.id = wdtp.id_document_wdt
                    AND DATE_FORMAT(wdt.established_date, '%Y-%m') >= :date_from
                    AND DATE_FORMAT(wdt.established_date, '%Y-%m') <= :date_to
                GROUP BY ps.id, ps.name
                ORDER BY total_value DESC";
        return $this->query($sql, ['date_from' => $dateFrom, 'date_to' => $dateTo]) ?: [];
    }
    /**
     * Trend miesięczny per dział.
     */
    public function getMonthlyTrendBySection(string $dateFrom, string $dateTo, ?int $sectionId = null): array
    {
        $params = ['date_from' => $dateFrom, 'date_to' => $dateTo];
        $sectionFilter = '';
        if ($sectionId !== null) {
            $sectionFilter = 'AND fp.id_section = :section_id';
            $params['section_id'] = $sectionId;
        }
        $sql = "SELECT
                    ps.id                                                       AS section_id,
                    ps.name                                                     AS section_name,
                    DATE_FORMAT(wdt.established_date, '%Y-%m')                 AS period,
                    COALESCE(SUM(wdtp.quantity), 0)                            AS total_quantity,
                    COALESCE(SUM(wdtp.quantity * wdtp.price_carton), 0)        AS total_value
                FROM document_wdt wdt
                INNER JOIN document_wdt_product wdtp ON wdt.id = wdtp.id_document_wdt
                INNER JOIN final_product fp          ON wdtp.id_product = fp.id
                INNER JOIN production_sections ps    ON fp.id_section = ps.id
                WHERE DATE_FORMAT(wdt.established_date, '%Y-%m') >= :date_from
                  AND DATE_FORMAT(wdt.established_date, '%Y-%m') <= :date_to
                  $sectionFilter
                GROUP BY ps.id, ps.name, DATE_FORMAT(wdt.established_date, '%Y-%m')
                ORDER BY ps.name ASC, period ASC";
        return $this->query($sql, $params) ?: [];
    }
}
