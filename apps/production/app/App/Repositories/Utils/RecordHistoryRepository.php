<?php
declare(strict_types=1);

namespace App\Repositories\Utils;

use PDO;

/**
 * Historia rekordu przed usunięciem : produkt, klient lub surowiec z zamówieniami, dokumentami czy recepturami
 * nie znika (inaczej linie dokumentów zostają bez produktu / klienta). Zwraca tylko niezerowe liczby.
 */
final class RecordHistoryRepository
{
    private const PRODUCT = [
        'zamówienia' => 'SELECT COUNT(DISTINCT id_order) FROM customer_order_product WHERE id_product = ?',
        'WDT' => 'SELECT COUNT(DISTINCT id_document_wdt) FROM document_wdt_product WHERE id_product = ?',
        'WZ' => 'SELECT COUNT(DISTINCT id_document_wz) FROM document_wz_product WHERE id_product = ?',
        'proformy' => 'SELECT COUNT(DISTINCT id_proforma) FROM document_proforma_product WHERE id_product = ?',
        'faktury' => 'SELECT COUNT(DISTINCT id_document_invoice) FROM document_invoice_product WHERE id_product = ?',
        'kartony w magazynie' => 'SELECT COUNT(*) FROM carton_stock WHERE id_product = ?',
        'reklamacje' => 'SELECT COUNT(*) FROM complaint WHERE id_product = ?',
    ];

    private const CUSTOMER = [
        'zamówienia' => 'SELECT COUNT(*) FROM customer_order WHERE id_customer = ?',
        'WDT' => 'SELECT COUNT(*) FROM document_wdt WHERE id_customer = ?',
        'WZ' => 'SELECT COUNT(*) FROM document_wz WHERE id_customer = ?',
        'CMR' => 'SELECT COUNT(*) FROM document_cmr WHERE id_customer = ?',
        'proformy' => 'SELECT COUNT(*) FROM document_proforma WHERE id_customer = ?',
        'faktury' => 'SELECT COUNT(*) FROM document_invoice WHERE id_customer = ?',
        'palety' => 'SELECT COUNT(*) FROM palets_delivery WHERE id_customer = ?',
        'reklamacje' => 'SELECT COUNT(*) FROM complaint WHERE id_customer = ?',
    ];

    private const MATERIAL = [
        'receptury' => 'SELECT COUNT(DISTINCT parent_recipe_id) FROM product_recipe_connection WHERE child_ingredient_id = ?',
        'zamówienia u dostawców' => 'SELECT COUNT(*) FROM n_po_line WHERE id_raw_material = ?',
        'faktury zakupowe' => 'SELECT COUNT(*) FROM n_inv_line WHERE id_raw_material = ?',
        'dokumenty zakupu' => 'SELECT COUNT(*) FROM purchase_item WHERE raw_material_id = ?',
        'opakowania wysyłek' => 'SELECT COUNT(*) FROM shipment_packaging_item WHERE id_raw_material = ?',
    ];

    public function __construct(private PDO $db)
    {
    }

    /** @return array<string, int> */
    public function product(int $id): array
    {
        return $this->counts(self::PRODUCT, $id);
    }

    /** @return array<string, int> */
    public function customer(int $id): array
    {
        return $this->counts(self::CUSTOMER, $id);
    }

    /** @return array<string, int> */
    public function material(int $id): array
    {
        return $this->counts(self::MATERIAL, $id);
    }

    /** « 12 zamówienia, 30 WDT » — do komunikatu. */
    public static function describe(array $counts): string
    {
        return implode(', ', array_map(fn($label, $n) => $n . ' ' . $label, array_keys($counts), $counts));
    }

    private function counts(array $queries, int $id): array
    {
        $out = [];
        foreach ($queries as $label => $sql) {
            try {
                $stmt = $this->db->prepare($sql);
                $stmt->execute([$id]);
                $n = (int) $stmt->fetchColumn();
            } catch (\PDOException) {
                continue; // tabela nieobecna (kopia częściowa) : nie blokuje
            }
            if ($n > 0) $out[$label] = $n;
        }
        return $out;
    }
}
