<?php
namespace App\Repositories\Analysis;

use App\Core\Helpers\BaseRepository;

class SectionFinanceDashboardRepository extends BaseRepository
{
    protected string $table = 'document_wdt';

    /**
     * Przychód brutto per dział w podanym zakresie dat (z WDT).
     * Łączy produkty → sekcje produkcyjne przez final_product.id_section.
     */
    public function getRevenueBySection(string $dateFrom, string $dateTo): array
    {
        $sql = "SELECT
                    ps.id                                               AS section_id,
                    ps.name                                             AS section_name,
                    IFNULL(SUM(filtered.revenue), 0)                    AS revenue_gross,
                    IFNULL(SUM(filtered.qty), 0)                        AS total_cartons
                FROM production_sections ps
                LEFT JOIN (
                    SELECT
                        fp2.id_section,
                        wdtp.quantity * wdtp.price_carton   AS revenue,
                        wdtp.quantity                       AS qty
                    FROM document_wdt wdt
                    INNER JOIN document_wdt_product wdtp ON wdtp.id_document_wdt = wdt.id
                    INNER JOIN final_product fp2           ON fp2.id = wdtp.id_product
                    WHERE DATE_FORMAT(wdt.established_date, '%Y-%m') >= :date_from
                      AND DATE_FORMAT(wdt.established_date, '%Y-%m') <= :date_to
                ) filtered ON filtered.id_section = ps.id
                GROUP BY ps.id, ps.name
                ORDER BY revenue_gross DESC";

        return $this->query($sql, [
            'date_from' => $dateFrom,
            'date_to'   => $dateTo,
        ]) ?: [];
    }

    /**
     * Suma wynagrodzeń per dział ze snapshotów miesięcy w zakresie dat.
     */
    public function getLaborCostBySection(string $dateFrom, string $dateTo): array
    {
        $sql = "SELECT
                    ps.id                                AS section_id,
                    ps.name                              AS section_name,
                    IFNULL(SUM(cme.monthly_cost), 0)     AS labor_total
                FROM production_sections ps
                LEFT JOIN (
                    SELECT cme2.section_id, cme2.monthly_cost
                    FROM cost_month_employee cme2
                    INNER JOIN cost_month cm2 ON cme2.month_id = cm2.id
                    WHERE cme2.is_included = 1
                      AND DATE_FORMAT(
                            STR_TO_DATE(CONCAT(cm2.year,'-',LPAD(cm2.month,2,'0'),'-01'), '%Y-%m-%d'),
                            '%Y-%m'
                          ) >= :date_from
                      AND DATE_FORMAT(
                            STR_TO_DATE(CONCAT(cm2.year,'-',LPAD(cm2.month,2,'0'),'-01'), '%Y-%m-%d'),
                            '%Y-%m'
                          ) <= :date_to
                ) cme ON cme.section_id = ps.id
                GROUP BY ps.id, ps.name
                ORDER BY labor_total DESC";

        return $this->query($sql, [
            'date_from' => $dateFrom,
            'date_to'   => $dateTo,
        ]) ?: [];
    }

    /**
     * Łączna suma wynagrodzeń wszystkich działów w zakresie dat.
     */
    public function getTotalLaborCost(string $dateFrom, string $dateTo): float
    {
        $sql = "SELECT IFNULL(SUM(cme.monthly_cost), 0) AS labor_total
                FROM cost_month_employee cme
                INNER JOIN cost_month cm ON cme.month_id = cm.id
                WHERE cme.is_included = 1
                  AND DATE_FORMAT(
                        STR_TO_DATE(CONCAT(cm.year,'-',LPAD(cm.month,2,'0'),'-01'), '%Y-%m-%d'),
                        '%Y-%m'
                      ) >= :date_from
                  AND DATE_FORMAT(
                        STR_TO_DATE(CONCAT(cm.year,'-',LPAD(cm.month,2,'0'),'-01'), '%Y-%m-%d'),
                        '%Y-%m'
                      ) <= :date_to";

        $rows = $this->query($sql, [
            'date_from' => $dateFrom,
            'date_to'   => $dateTo,
        ]);

        return (float)($rows[0]['labor_total'] ?? 0);
    }

    /**
     * Łączny przychód brutto firmy w zakresie dat.
     */
    public function getTotalRevenue(string $dateFrom, string $dateTo): float
    {
        $sql = "SELECT IFNULL(SUM(wdtp.quantity * wdtp.price_carton), 0) AS total_revenue
                FROM document_wdt wdt
                INNER JOIN document_wdt_product wdtp ON wdtp.id_document_wdt = wdt.id
                WHERE DATE_FORMAT(wdt.established_date, '%Y-%m') >= :date_from
                  AND DATE_FORMAT(wdt.established_date, '%Y-%m') <= :date_to";

        $rows = $this->query($sql, [
            'date_from' => $dateFrom,
            'date_to'   => $dateTo,
        ]);

        return (float)($rows[0]['total_revenue'] ?? 0);
    }

    /**
     * Miesięczny trend: przychód + wynagrodzenia per miesiąc (całość firmy).
     */
    public function getMonthlyTrend(string $dateFrom, string $dateTo): array
    {
        $sql = "SELECT
                    period,
                    IFNULL(SUM(revenue), 0)     AS revenue,
                    IFNULL(SUM(labor_cost), 0)  AS labor_cost
                FROM (
                    -- Przychód z WDT
                    SELECT
                        DATE_FORMAT(wdt.established_date, '%Y-%m')         AS period,
                        SUM(wdtp.quantity * wdtp.price_carton)              AS revenue,
                        0                                                   AS labor_cost
                    FROM document_wdt wdt
                    INNER JOIN document_wdt_product wdtp ON wdtp.id_document_wdt = wdt.id
                    WHERE DATE_FORMAT(wdt.established_date, '%Y-%m') >= :df1
                      AND DATE_FORMAT(wdt.established_date, '%Y-%m') <= :dt1
                    GROUP BY DATE_FORMAT(wdt.established_date, '%Y-%m')

                    UNION ALL

                    -- Wynagrodzenia ze snapshotów
                    SELECT
                        DATE_FORMAT(STR_TO_DATE(CONCAT(cm.year,'-',LPAD(cm.month,2,'0'),'-01'), '%Y-%m-%d'), '%Y-%m') AS period,
                        0                                                   AS revenue,
                        SUM(cme.monthly_cost)                               AS labor_cost
                    FROM cost_month_employee cme
                    INNER JOIN cost_month cm ON cme.month_id = cm.id
                    WHERE cme.is_included = 1
                      AND DATE_FORMAT(STR_TO_DATE(CONCAT(cm.year,'-',LPAD(cm.month,2,'0'),'-01'), '%Y-%m-%d'), '%Y-%m') >= :df2
                      AND DATE_FORMAT(STR_TO_DATE(CONCAT(cm.year,'-',LPAD(cm.month,2,'0'),'-01'), '%Y-%m-%d'), '%Y-%m') <= :dt2
                    GROUP BY DATE_FORMAT(STR_TO_DATE(CONCAT(cm.year,'-',LPAD(cm.month,2,'0'),'-01'), '%Y-%m-%d'), '%Y-%m')
                ) t
                GROUP BY period
                ORDER BY period ASC";

        return $this->query($sql, [
            'df1' => $dateFrom,
            'dt1' => $dateTo,
            'df2' => $dateFrom,
            'dt2' => $dateTo,
        ]) ?: [];
    }

    /**
     * Zakres dostępnych dat WDT (min/max jako YYYY-MM).
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
     * Kurs EUR z ustawień firmy.
     */
    public function getExchangeRateEuro(): float
    {
        $sql = "SELECT exchange_rate_euro FROM erp_company_other_settings LIMIT 1";
        $rows = $this->query($sql);
        return (float)($rows[0]['exchange_rate_euro'] ?? 0.0);
    }
}

