<?php
declare(strict_types=1);

namespace App\Repositories\Analysis;

use App\Repositories\Workshop\WorkshopRepository;

/**
 * Jedna definicja sprzedaży z dokumentów WDT dla wszystkich stron (Analiza sprzedaży, Rentowność, eksport
 * i rentowność klienta, koszty, magazyn, planowanie) :
 *  - data sprzedaży = document_wdt.established_date (DATE), zakres od–do włącznie ;
 *  - WDT zastąpione innym (n_wdt_superseded) nigdy nie liczą się ;
 *  - wartość = linie dokumentu (ilość × cena kartonu, netto), nigdy nagłówek total_net ;
 *  - wartość w EUR : dokument w PLN dzielony przez kurs euro z ustawień firmy ;
 *  - linie produktu usuniętego z kartoteki zostają w sumach (« Nieznany produkt »).
 */
final class WdtSalesRepository extends WorkshopRepository
{
    protected string $table = 'document_wdt';

    public const GROUPS = ['month', 'customer', 'product', 'category', 'section', 'document'];

    /**
     * Linie sprzedaży (do osadzenia jako tabela pochodna) : id_document_wdt, id_customer, sale_date, ym,
     * id_product, cartons, value_eur, value_doc (w walucie dokumentu), currency.
     * Nazwa linii jako VARCHAR (CAST) : kolumna TEXT w MAX() przy grupowaniu wymusza tabelę tymczasową na dysku
     * (pomiar 26.10.2026 : 192 ms → 35 ms dla 18 miesięcy) ; najdłuższa nazwa ma 63 znaki.
     */
    public static function linesSql(): string
    {
        return "SELECT w.id AS id_document_wdt, w.id_customer, w.established_date AS sale_date, LEFT(w.established_date, 7) AS ym,
                       p.id_product, CAST(p.name AS CHAR(255)) AS line_name, p.quantity AS cartons, p.quantity * p.price_carton AS value_doc,
                       CASE WHEN UPPER(w.currency) = 'PLN'
                            THEN p.quantity * p.price_carton / COALESCE(NULLIF((SELECT s.exchange_rate_euro FROM erp_company_other_settings s ORDER BY s.id LIMIT 1), 0), 1)
                            ELSE p.quantity * p.price_carton END AS value_eur,
                       UPPER(w.currency) AS currency
                FROM document_wdt w
                JOIN document_wdt_product p ON p.id_document_wdt = w.id
                WHERE NOT EXISTS (SELECT 1 FROM n_wdt_superseded sup WHERE sup.id_document_wdt = w.id)";
    }

    /**
     * Sprzedaż z [from, to] (RRRR-MM-DD włącznie), z filtrami i grupowaniem.
     *
     * @param array{customer_id?: int|list<int>, product_id?: int|list<int>, section_id?: int|list<int>} $filters
     * @param list<string> $groupBy podzbiór GROUPS ([] = jeden wiersz sumy)
     * @return list<array<string, mixed>> kolumny grup + cartons, value_eur, documents, customers, months_with_sales
     */
    public function sales(string $from, string $to, array $filters = [], array $groupBy = []): array
    {
        $unknown = array_diff($groupBy, self::GROUPS);
        if ($unknown) throw new \InvalidArgumentException('Nieznane grupowanie sprzedaży: ' . implode(', ', $unknown));

        $select = [];
        $group = [];
        if (in_array('month', $groupBy, true)) { $select[] = 's.ym'; $group[] = 's.ym'; }
        if (in_array('customer', $groupBy, true)) { $select[] = 's.id_customer'; $group[] = 's.id_customer'; }
        if (in_array('document', $groupBy, true)) { $select[] = 's.id_document_wdt'; $group[] = 's.id_document_wdt'; }
        if (in_array('product', $groupBy, true)) {
            $select[] = "s.id_product AS product_id, COALESCE(MAX(fp.name), MAX(s.line_name), 'Nieznany produkt') AS product_name,
                         MAX(fp.id_recipe) AS id_recipe, MAX(fp.ppc) AS ppc, MAX(fp.weight) AS weight, MAX(fp.id_section) AS product_section_id,
                         MAX(fp.id_category) AS product_category_id";
            $group[] = 's.id_product';
        }
        if (in_array('category', $groupBy, true)) {
            $select[] = "fp.id_category AS category_id, COALESCE(MAX(cp.name), 'Bez kategorii') AS category_name";
            $group[] = 'fp.id_category';
        }
        if (in_array('section', $groupBy, true)) {
            $select[] = "fp.id_section AS section_id, COALESCE(MAX(sec.name), 'Bez działu') AS section_name";
            $group[] = 'fp.id_section';
        }

        $params = ['from' => $from, 'to' => $to];
        $where = ['s.sale_date >= :from', 's.sale_date <= :to'];
        foreach (['customer_id' => 's.id_customer', 'product_id' => 's.id_product', 'section_id' => 'fp.id_section'] as $key => $column) {
            if (!array_key_exists($key, $filters) || $filters[$key] === null) continue;
            $ids = array_values(array_unique(array_map('intval', (array) $filters[$key])));
            if (!$ids) { $where[] = '1 = 0'; continue; }
            $names = [];
            foreach ($ids as $i => $id) { $names[] = ":{$key}_{$i}"; $params["{$key}_{$i}"] = $id; }
            $where[] = "{$column} IN (" . implode(', ', $names) . ')';
        }

        $sql = 'SELECT ' . ($select ? implode(', ', $select) . ', ' : '') . "
                       COALESCE(SUM(s.cartons), 0) AS cartons,
                       COALESCE(SUM(s.value_eur), 0) AS value_eur,
                       COUNT(DISTINCT s.id_document_wdt) AS documents,
                       COUNT(DISTINCT s.id_customer) AS customers,
                       COUNT(DISTINCT s.ym) AS months_with_sales
                FROM (" . self::linesSql() . ") s
                LEFT JOIN final_product fp ON fp.id = s.id_product
                LEFT JOIN category_product cp ON cp.id = fp.id_category
                LEFT JOIN production_sections sec ON sec.id = fp.id_section
                WHERE " . implode(' AND ', $where)
            . ($group ? ' GROUP BY ' . implode(', ', $group) : '');
        return $this->all($sql, $params);
    }

    /** [pierwszy dzień miesiąca od, ostatni dzień miesiąca do] dla zakresu RRRR-MM. */
    public static function monthBounds(string $ymFrom, string $ymTo): array
    {
        return [$ymFrom . '-01', date('Y-m-t', strtotime($ymTo . '-01'))];
    }

    /** [najstarszy, najnowszy] miesiąc RRRR-MM ze sprzedażą (opcjonalnie klienta). */
    public function monthRange(?int $customerId = null): array
    {
        $params = [];
        $where = '';
        if ($customerId !== null) { $where = ' WHERE s.id_customer = :customer_id'; $params['customer_id'] = $customerId; }
        $row = $this->all('SELECT MIN(s.ym) AS date_min, MAX(s.ym) AS date_max FROM (' . self::linesSql() . ") s{$where}", $params)[0] ?? [];
        return ['date_min' => $row['date_min'] ?? null, 'date_max' => $row['date_max'] ?? null];
    }

    public function eurRate(): float
    {
        return (float) ($this->db->query('SELECT exchange_rate_euro FROM erp_company_other_settings ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
    }

    /** Karty produktów sprzedaży : kategoria i koszt surowców kartonu (jedno źródło kosztu). */
    public function productCards(array $productIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $productIds))));
        if (!$ids) return [];
        $rows = $this->all('SELECT fp.id, fp.id_category, cp.name AS category_name, COALESCE(fp.pre_calculated_raw_price_per_carton, 0) AS raw_cost
                            FROM final_product fp LEFT JOIN category_product cp ON cp.id = fp.id_category WHERE fp.id IN (' . implode(',', $ids) . ')');
        return array_column($rows, null, 'id');
    }
}
