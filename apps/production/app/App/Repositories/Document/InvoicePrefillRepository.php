<?php
namespace App\Repositories\Document;

use App\Repositories\Workshop\WorkshopRepository;

/**
 * Faktura « inna » wypełniona z zamówienia albo z WZ (audyt 2, P10) : linie dokumentu źródłowego tego samego klienta
 * (produkt, kartony, cena z zamówienia, sztuk w kartonie). Ceny i VAT z cennika klienta mają pierwszeństwo w kontrolerze.
 */
final class InvoicePrefillRepository extends WorkshopRepository
{
    protected string $table = 'customer_order_product';
    protected array $writable = [];

    /** @return array{label:string, lines:list<array>}|null zamówienie klienta (null : inne konto albo brak) */
    public function fromOrder(int $orderId, int $customerId): ?array
    {
        $head = $this->all('SELECT id, reference FROM customer_order WHERE id = ? AND id_customer = ?', [$orderId, $customerId])[0] ?? null;
        if ($head === null) return null;
        $lines = $this->all('SELECT cop.id_product, fp.name, SUM(cop.quantity_carton) AS quantity, MAX(cop.purchase_price) AS price, fp.ppc
            FROM customer_order_product cop JOIN final_product fp ON fp.id = cop.id_product
            WHERE cop.id_order = ? AND cop.quantity_carton > 0 GROUP BY cop.id_product, fp.name, fp.ppc ORDER BY fp.name', [$orderId]);
        return ['label' => 'zamówienia #' . $head['id'] . ($head['reference'] ? ' (' . $head['reference'] . ')' : ''), 'lines' => $lines];
    }

    /** @return array{label:string, lines:list<array>}|null WZ klienta */
    public function fromWz(int $wzId, int $customerId): ?array
    {
        $head = $this->all('SELECT id, doc_num FROM document_wz WHERE id = ? AND id_customer = ?', [$wzId, $customerId])[0] ?? null;
        if ($head === null) return null;
        $lines = $this->all('SELECT p.id_product, COALESCE(fp.name, p.name) AS name, SUM(p.quantity) AS quantity, NULL AS price, COALESCE(NULLIF(p.ppc, 0), fp.ppc) AS ppc
            FROM document_wz_product p LEFT JOIN final_product fp ON fp.id = p.id_product
            WHERE p.id_document_wz = ? AND p.quantity > 0 GROUP BY p.id_product, name, ppc ORDER BY name', [$wzId]);
        return ['label' => 'WZ ' . $head['doc_num'], 'lines' => $lines];
    }

    /** WZ klienta z ostatnich dni (lista wyboru na stronie faktury). */
    public function recentWz(int $customerId, int $limit = 15): array
    {
        return $this->all('SELECT id, doc_num, established_date FROM document_wz WHERE id_customer = ? ORDER BY id DESC LIMIT ' . max(1, $limit), [$customerId]);
    }

    /** Zamówienia klienta z ostatnich dni (lista wyboru). */
    public function recentOrders(int $customerId, int $limit = 15): array
    {
        return $this->all('SELECT id, reference, expected_shipping_date FROM customer_order WHERE id_customer = ? ORDER BY id DESC LIMIT ' . max(1, $limit), [$customerId]);
    }
}
