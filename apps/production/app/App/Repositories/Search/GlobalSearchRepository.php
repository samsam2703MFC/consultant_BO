<?php

namespace App\Repositories\Search;

use App\Core\Helpers\BaseRepository;

/**
 * Wyszukiwarka z górnego paska: po kilka trafień na typ, tylko odczyt.
 * Wzorzec LIKE przychodzi już z ucieczką znaków % _ \ (GlobalSearchService).
 * Bez GROUP BY (zgodne z ONLY_FULL_GROUP_BY na MySQL 8 i z MariaDB).
 */
class GlobalSearchRepository extends BaseRepository
{
    protected string $table = 'customer_order';

    private function like(string $pattern, int $count): array
    {
        $params = [];
        for ($i = 1; $i <= $count; $i++) {
            $params['t' . $i] = $pattern;
        }
        return $params;
    }

    public function orders(string $pattern, string $exact, int $limit): array
    {
        $sql = "SELECT co.id, co.reference, co.expected_shipping_date, co.status,
                       cost.name AS status_name, COALESCE(c.company, '') AS company
                FROM customer_order co
                LEFT JOIN customer c ON c.id = co.id_customer
                LEFT JOIN customer_order_status_type cost ON cost.id = co.status
                WHERE co.reference LIKE :t1 OR c.company LIKE :t2 OR co.invoice_num LIKE :t3 OR CAST(co.id AS CHAR) LIKE :t4
                ORDER BY (CAST(co.id AS CHAR) = :exact) DESC, co.id DESC
                LIMIT " . (int) $limit;
        return $this->query($sql, $this->like($pattern, 4) + ['exact' => $exact]) ?: [];
    }

    public function clients(string $pattern, int $limit): array
    {
        $sql = "SELECT id, company, tax_number, franchisee_code, active,
                       (franchisee_uid IS NOT NULL) AS franchise
                FROM customer
                WHERE company LIKE :t1 OR name LIKE :t2 OR surname LIKE :t3 OR tax_number LIKE :t4 OR franchisee_code LIKE :t5
                ORDER BY active DESC, company
                LIMIT " . (int) $limit;
        return $this->query($sql, $this->like($pattern, 5)) ?: [];
    }

    public function products(string $pattern, string $exact, int $limit): array
    {
        $sql = "SELECT id, name, active, id_recipe
                FROM final_product
                WHERE name LIKE :t1 OR CAST(id AS CHAR) = :exact
                ORDER BY (CAST(id AS CHAR) = :exact_order) DESC, active DESC, name
                LIMIT " . (int) $limit;
        return $this->query($sql, $this->like($pattern, 1) + ['exact' => $exact, 'exact_order' => $exact]) ?: [];
    }

    public function materials(string $pattern, int $limit): array
    {
        $sql = "SELECT rm.id, rm.name, rm.name_fr, unit.name AS unit_name
                FROM raw_material rm
                LEFT JOIN unit ON unit.id = rm.id_unit
                WHERE rm.name LIKE :t1 OR rm.name_fr LIKE :t2
                ORDER BY rm.name
                LIMIT " . (int) $limit;
        return $this->query($sql, $this->like($pattern, 2)) ?: [];
    }

    public function proformas(string $pattern, int $limit): array
    {
        $sql = "SELECT p.id, p.doc_num, p.consignee_name, p.status, p.established_date,
                       (SELECT MIN(co.id) FROM customer_order co WHERE co.id_proforma = p.id) AS order_id
                FROM document_proforma p
                WHERE p.doc_num LIKE :t1 OR p.consignee_name LIKE :t2 OR p.consignee_nip LIKE :t3
                ORDER BY p.id DESC
                LIMIT " . (int) $limit;
        return $this->query($sql, $this->like($pattern, 3)) ?: [];
    }

    public function invoices(string $pattern, int $limit): array
    {
        $sql = "SELECT id, doc_num, consignee_name, invoiced_date
                FROM document_invoice
                WHERE doc_num LIKE :t1 OR consignee_name LIKE :t2 OR consignee_nip LIKE :t3
                ORDER BY id DESC
                LIMIT " . (int) $limit;
        return $this->query($sql, $this->like($pattern, 3)) ?: [];
    }

    public function wdt(string $pattern, int $limit): array
    {
        $sql = "SELECT w.id, w.doc_num, w.consignee_name, w.invoiced_date,
                       (SELECT MIN(co.id) FROM customer_order co WHERE co.id_wdt = w.id) AS order_id
                FROM document_wdt w
                WHERE w.doc_num LIKE :t1 OR w.consignee_name LIKE :t2
                ORDER BY w.id DESC
                LIMIT " . (int) $limit;
        return $this->query($sql, $this->like($pattern, 2)) ?: [];
    }

    /** Receptury (produkt gotowy, półprodukt, kompozycja) z grupą, która przygotowuje półprodukt / kompozycję. */
    public function recipes(string $pattern, string $exact, int $limit): array
    {
        $sql = "SELECT pr.id, pr.name, pr.id_type, g.name_pl AS producer
                FROM product_recipe pr
                LEFT JOIN n_recipe_producer rp ON rp.id_recipe = pr.id
                LEFT JOIN n_prod_group g ON g.id = rp.id_group
                WHERE pr.name LIKE :t1 OR CAST(pr.id AS CHAR) = :exact
                ORDER BY (CAST(pr.id AS CHAR) = :exact_order) DESC, pr.name
                LIMIT " . (int) $limit;
        return $this->query($sql, $this->like($pattern, 1) + ['exact' => $exact, 'exact_order' => $exact]) ?: [];
    }

    public function suppliers(string $pattern, int $limit): array
    {
        $sql = "SELECT id, name, phone, email, is_active
                FROM supplier
                WHERE name LIKE :t1 OR email LIKE :t2 OR phone LIKE :t3
                ORDER BY is_active DESC, name
                LIMIT " . (int) $limit;
        return $this->query($sql, $this->like($pattern, 3)) ?: [];
    }

    public function employees(string $pattern, int $limit): array
    {
        $sql = "SELECT e.id, e.name, e.surname, s.name AS section
                FROM production_employee e
                LEFT JOIN production_sections s ON s.id = e.id_section
                WHERE e.name LIKE :t1 OR e.surname LIKE :t2 OR CONCAT(e.name, ' ', e.surname) LIKE :t3 OR CONCAT(e.surname, ' ', e.name) LIKE :t4
                ORDER BY e.surname, e.name
                LIMIT " . (int) $limit;
        return $this->query($sql, $this->like($pattern, 4)) ?: [];
    }
}
