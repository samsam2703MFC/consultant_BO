<?php
declare(strict_types=1);

namespace App\Repositories\Client;

use App\Core\Helpers\BaseRepository;

/**
 * Strona klienta : opcje klienta (n_customer_space), współczynniki sprzedaży kategorii
 * (n_category_sales_coefficient), dokumenty klienta i zakupy z proform do celu obrotu.
 * Zakupy z faktur WDT : WdtSalesRepository (jedna definicja sprzedaży WDT).
 */
class ClientSpaceRepository extends BaseRepository
{
    protected string $table = 'n_customer_space';

    /** Typy dokumentów klienta : etykieta, tabela. */
    public const DOCUMENT_TYPES = [
        'proforma' => 'Proformy',
        'wdt' => 'Faktury WDT',
        'invoice' => 'Faktury',
        'wz' => 'WZ',
        'cmr' => 'CMR',
        'difference' => 'Różnice',
    ];

    public function settings(int $customerId): array
    {
        $rows = $this->query('SELECT * FROM n_customer_space WHERE id_customer = :id', ['id' => $customerId]);
        return is_array($rows) && $rows ? $rows[0] : [];
    }

    public function saveSettings(int $customerId, bool $docs, bool $goal, ?float $coefficient, ?int $openDays, ?string $author): void
    {
        $this->query(
            'INSERT INTO n_customer_space (id_customer, docs_enabled, goal_enabled, coefficient, open_days, updated_by)
             VALUES (:id, :docs, :goal, :coefficient, :open_days, :author)
             ON DUPLICATE KEY UPDATE docs_enabled = VALUES(docs_enabled), goal_enabled = VALUES(goal_enabled),
                 coefficient = VALUES(coefficient), open_days = VALUES(open_days), updated_by = VALUES(updated_by)',
            ['id' => $customerId, 'docs' => (int) $docs, 'goal' => (int) $goal, 'coefficient' => $coefficient, 'open_days' => $openDays, 'author' => $author]
        );
    }

    /** @return list<int> klienci z włączoną stroną (dokumenty albo cel obrotu) */
    public function enabledCustomerIds(): array
    {
        $rows = $this->query('SELECT id_customer FROM n_customer_space WHERE docs_enabled = 1 OR goal_enabled = 1');
        return array_map('intval', array_column(is_array($rows) ? $rows : [], 'id_customer'));
    }

    /** Domyślny współczynnik z Ustawień (cennik sklepów, mnożnik franczyzy). */
    public function defaultCoefficient(): ?float
    {
        $rows = $this->query('SELECT multiplier FROM n_store_price_config ORDER BY id LIMIT 1');
        $value = is_array($rows) && $rows ? (float) $rows[0]['multiplier'] : 0.0;
        return $value > 0 ? $value : null;
    }

    /** Kategorie produktów ze współczynnikiem (null = współczynnik klienta) i znacznikiem « poza celem ». */
    public function categoryCoefficients(): array
    {
        $rows = $this->query(
            'SELECT cp.id, cp.name, csc.coefficient, COALESCE(csc.excluded, 0) AS excluded,
                    (SELECT COUNT(*) FROM final_product fp WHERE fp.id_category = cp.id AND fp.active = 1) AS active_products
             FROM category_product cp
             LEFT JOIN n_category_sales_coefficient csc ON csc.id_category = cp.id
             ORDER BY cp.name'
        );
        return is_array($rows) ? $rows : [];
    }

    public function saveCategoryCoefficient(int $categoryId, ?float $coefficient, bool $excluded, ?string $author): void
    {
        if ($coefficient === null && !$excluded) {
            // Bez własnego współczynnika i nie « poza celem » : kategoria bierze współczynnik klienta.
            $this->query('DELETE FROM n_category_sales_coefficient WHERE id_category = :id', ['id' => $categoryId]);
            return;
        }
        $this->query(
            'INSERT INTO n_category_sales_coefficient (id_category, coefficient, excluded, updated_by)
             VALUES (:id, :coefficient, :excluded, :author)
             ON DUPLICATE KEY UPDATE coefficient = VALUES(coefficient), excluded = VALUES(excluded), updated_by = VALUES(updated_by)',
            ['id' => $categoryId, 'coefficient' => $coefficient, 'excluded' => (int) $excluded, 'author' => $author]
        );
    }

    /** Liczba dokumentów klienta według typu (od początku). */
    public function documentCounts(int $customerId): array
    {
        $rows = $this->query(
            "SELECT 'proforma' AS t, COUNT(*) AS n FROM document_proforma WHERE id_customer = :c1
             UNION ALL SELECT 'wdt', COUNT(*) FROM document_wdt WHERE id_customer = :c2
             UNION ALL SELECT 'invoice', COUNT(*) FROM document_invoice WHERE id_customer = :c3
             UNION ALL SELECT 'wz', COUNT(*) FROM document_wz WHERE id_customer = :c4
             UNION ALL SELECT 'cmr', COUNT(*) FROM document_cmr WHERE id_customer = :c5
             UNION ALL SELECT 'difference', COUNT(*) FROM document_order_shipping_difference WHERE id_customer = :c6
             UNION ALL SELECT 'complaint', COUNT(*) FROM complaint WHERE id_customer = :c7",
            array_combine(['c1', 'c2', 'c3', 'c4', 'c5', 'c6', 'c7'], array_fill(0, 7, $customerId))
        );
        $counts = [];
        foreach (is_array($rows) ? $rows : [] as $row) $counts[$row['t']] = (int) $row['n'];
        return $counts;
    }

    /**
     * Dokumenty klienta od daty $from (RRRR-MM-DD, null = wszystkie), najnowsze najpierw.
     * Wiersz : type, id, date, number, amount (netto, waluta dokumentu ; null = bez kwoty), currency, status, note.
     */
    public function documents(int $customerId, ?string $type, ?string $from, string $search, int $limit): array
    {
        $types = $type !== null && isset(self::DOCUMENT_TYPES[$type]) ? [$type] : array_keys(self::DOCUMENT_TYPES);
        $rows = [];
        foreach ($types as $t) {
            foreach ($this->documentsOfType($t, $customerId, $from ?? '0000-01-01', $search, $limit) as $row) $rows[] = ['type' => $t] + $row;
        }
        usort($rows, fn($a, $b) => [$b['date'], $b['id']] <=> [$a['date'], $a['id']]);
        return array_slice($rows, 0, $limit);
    }

    private function documentsOfType(string $type, int $customerId, string $from, string $search, int $limit): array
    {
        $params = ['c' => $customerId, 'from' => $from, 'q' => '%' . $search . '%'];
        $sql = match ($type) {
            'proforma' => "SELECT p.id, p.established_date AS date, p.doc_num AS number,
                                  (SELECT SUM(pp.total_price_net) FROM document_proforma_product pp WHERE pp.id_proforma = p.id) AS amount,
                                  'EUR' AS currency, p.status,
                                  (SELECT CONCAT('opłacona ', DATE_FORMAT(pay.paid_on, '%d.%m.%Y'),
                                          IF(EXISTS (SELECT 1 FROM n_payment_proof_proforma ppl WHERE ppl.id_proforma = p.id), ' · potwierdzenie przelewu z e-maila', ''))
                                   FROM n_proforma_payment pay WHERE pay.id_proforma = p.id) AS note
                           FROM document_proforma p
                           WHERE p.id_customer = :c AND p.established_date >= :from AND p.doc_num LIKE :q",
            'wdt' => "SELECT w.id, COALESCE(w.invoiced_date, w.established_date) AS date, w.doc_num AS number,
                             (SELECT SUM(l.quantity * l.price_carton) FROM document_wdt_product l WHERE l.id_document_wdt = w.id) AS amount,
                             UPPER(COALESCE(NULLIF(w.currency, ''), 'EUR')) AS currency, k.status,
                             IF(sup.id_document_wdt IS NULL, NULL, 'zastąpiona') AS note
                      FROM document_wdt w
                      LEFT JOIN ksef_submission k ON k.id_document_wdt = w.id
                      LEFT JOIN n_wdt_superseded sup ON sup.id_document_wdt = w.id
                      WHERE w.id_customer = :c AND COALESCE(w.invoiced_date, w.established_date) >= :from AND w.doc_num LIKE :q",
            'invoice' => "SELECT i.id, i.invoiced_date AS date, i.doc_num AS number,
                                 (SELECT SUM(ip.quantity * ip.single_price) FROM document_invoice_product ip WHERE ip.id_document_invoice = i.id) AS amount,
                                 'EUR' AS currency, NULL AS status, NULL AS note
                          FROM document_invoice i
                          WHERE i.id_customer = :c AND i.invoiced_date >= :from AND i.doc_num LIKE :q",
            'wz' => "SELECT z.id, DATE(z.established_date) AS date, z.doc_num AS number, NULL AS amount, NULL AS currency, NULL AS status, NULL AS note
                     FROM document_wz z WHERE z.id_customer = :c AND DATE(z.established_date) >= :from AND z.doc_num LIKE :q",
            'cmr' => "SELECT m.id, DATE(m.established_date) AS date, m.doc_num AS number, NULL AS amount, NULL AS currency, NULL AS status, NULL AS note
                      FROM document_cmr m WHERE m.id_customer = :c AND DATE(m.established_date) >= :from AND m.doc_num LIKE :q",
            'difference' => "SELECT d.id, DATE(d.established_date) AS date, CONCAT('Różnice ', COALESCE(w.doc_num, CONCAT('#', d.id))) AS number,
                                    (SELECT SUM(dp.invoiced_total_amount - dp.ordered_total_amount) FROM document_order_shipping_difference_products dp
                                     WHERE dp.id_document_order_shipping_difference = d.id) AS amount,
                                    'EUR' AS currency, NULL AS status, NULL AS note
                             FROM document_order_shipping_difference d
                             LEFT JOIN shipment_log sl ON sl.id_diff = d.id
                             LEFT JOIN document_wdt w ON w.id = sl.id_wdt
                             WHERE d.id_customer = :c AND DATE(d.established_date) >= :from AND COALESCE(w.doc_num, '') LIKE :q",
        };
        $rows = $this->query($sql . ' ORDER BY date DESC, id DESC LIMIT ' . max(1, $limit), $params);
        return is_array($rows) ? $rows : [];
    }

    /** Czy dokument należy do klienta (przed złożeniem PDF). */
    public function documentBelongs(string $type, int $id, int $customerId): bool
    {
        $table = match ($type) {
            'proforma' => 'document_proforma', 'wdt' => 'document_wdt', 'invoice' => 'document_invoice',
            'wz' => 'document_wz', 'cmr' => 'document_cmr', 'difference' => 'document_order_shipping_difference',
            default => null,
        };
        if ($table === null) return false;
        $rows = $this->query("SELECT 1 FROM {$table} WHERE id = :id AND id_customer = :c", ['id' => $id, 'c' => $customerId]);
        return is_array($rows) && $rows !== [];
    }

    /**
     * Zakupy klienta z proform (zamówione, bez anulowanych) w [from, to] według kategorii i produktu :
     * category_id, category_name, product_id, product_name, ppc, cartons, value_eur.
     */
    public function proformaPurchases(int $customerId, string $from, string $to): array
    {
        $rows = $this->query(
            "SELECT fp.id_category AS category_id, COALESCE(MAX(cp.name), 'Bez kategorii') AS category_name,
                    pp.id_product AS product_id, COALESCE(MAX(fp.name), MAX(pp.product_name)) AS product_name, MAX(fp.ppc) AS ppc,
                    SUM(pp.quantity) AS cartons, SUM(pp.total_price_net) AS value_eur
             FROM document_proforma_product pp
             JOIN document_proforma p ON p.id = pp.id_proforma
             LEFT JOIN final_product fp ON fp.id = pp.id_product
             LEFT JOIN category_product cp ON cp.id = fp.id_category
             WHERE p.id_customer = :c AND p.status <> 'cancelled' AND p.established_date BETWEEN :from AND :to
             GROUP BY fp.id_category, pp.id_product",
            ['c' => $customerId, 'from' => $from, 'to' => $to]
        );
        return is_array($rows) ? $rows : [];
    }
}
