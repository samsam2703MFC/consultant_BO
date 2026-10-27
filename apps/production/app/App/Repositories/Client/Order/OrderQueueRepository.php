<?php
declare(strict_types=1);

namespace App\Repositories\Client\Order;

use App\Domain\Client\Order\OpenOrders;
use App\Repositories\Workshop\WorkshopRepository;

/**
 * File « Do obsłużenia » : lectures seulement — commandes ouvertes (même définition que Dzisiaj et la liste des
 * commandes : App\Domain\Client\Order\OpenOrders) avec leur proforma, palettes prêtes pour les documents, poste
 * de l'utilisateur.
 */
final class OrderQueueRepository extends WorkshopRepository
{
    protected string $table = 'customer_order';

    // --- Achats (P20) : commandes fournisseurs, factures d'achat, inventaire des matières ---------------------------

    /** Commandes fournisseurs en brouillon (pas encore envoyées). */
    public function poDrafts(): array
    {
        return $this->all("SELECT p.id, p.code, s.name AS supplier, p.delivery_date, DATE(p.created_at) AS created_on, COUNT(l.id) AS line_count
                           FROM n_po p JOIN supplier s ON s.id = p.id_supplier LEFT JOIN n_po_line l ON l.id_po = p.id
                           WHERE p.status = 'draft' GROUP BY p.id ORDER BY p.created_at");
    }

    /** Commandes envoyées (ou reçues en partie) dont la date de livraison est passée. */
    public function poLate(string $today): array
    {
        return $this->all("SELECT p.id, p.code, p.status, s.name AS supplier, p.delivery_date FROM n_po p JOIN supplier s ON s.id = p.id_supplier
                           WHERE p.status IN ('sent', 'partial') AND p.delivery_date IS NOT NULL AND p.delivery_date < ? ORDER BY p.delivery_date", [$today]);
    }

    /** Factures d'achat ouvertes sans commande liée ou avec des lignes non reliées à une matière. */
    public function invoicesToLink(): array
    {
        return $this->all("SELECT i.id, i.number, i.issue_date, COALESCE(s.name, i.seller_name) AS supplier, i.total_net, i.currency, i.id_po,
                                  (SELECT COUNT(*) FROM n_inv_line l WHERE l.id_inv = i.id AND l.id_raw_material IS NULL) AS unmapped
                           FROM n_inv i LEFT JOIN supplier s ON s.id = i.id_supplier
                           WHERE i.status = 'open' AND (i.id_po IS NULL OR EXISTS (SELECT 1 FROM n_inv_line l WHERE l.id_inv = i.id AND l.id_raw_material IS NULL))
                           ORDER BY i.issue_date, i.id");
    }

    /** Matières gérées sur stock dont le dernier comptage date d'avant $cutoff (ou jamais comptées). */
    public function staleInventory(string $cutoff): array
    {
        return $this->all("SELECT COUNT(*) AS materials, SUM(t.last_count IS NULL) AS never, MIN(t.last_count) AS oldest
                           FROM (SELECT sp.id_raw_material, MAX(c.count_date) AS last_count FROM n_mp_supply sp
                                 LEFT JOIN n_mp_count c ON c.id_raw_material = sp.id_raw_material
                                 WHERE sp.supply_mode = 'stock' GROUP BY sp.id_raw_material) t
                           WHERE t.last_count IS NULL OR t.last_count < ?", [$cutoff])[0] ?? ['materials' => 0];
    }

    /** Commandes ouvertes avec client, cartons et proforma (numéro, statut, envoi, montant). */
    public function openOrders(): array
    {
        return $this->all("SELECT co.id, co.id_customer, c.company AS customer, co.reference, co.order_description, DATE(co.expected_shipping_date) AS shipping_date,
                co.id_proforma, co.is_freebie, COALESCE(co.order_type, 'STANDARD') AS order_type, co.id_wdt,
                dp.doc_num AS proforma_number, LOWER(COALESCE(dp.status, 'issued')) AS proforma_status, dp.established_date AS proforma_date,
                COALESCE(dp.is_sent_to_client, 0) AS sent_to_client, COALESCE(dp.is_sent_to_franchise, 0) AS sent_to_franchise,
                (SELECT COALESCE(SUM(cop.quantity_carton), 0) FROM customer_order_product cop WHERE cop.id_order = co.id) AS cartons
            FROM customer_order co
            LEFT JOIN customer c ON c.id = co.id_customer
            LEFT JOIN document_proforma dp ON dp.id = co.id_proforma
            WHERE " . OpenOrders::sql('co') . "
            ORDER BY co.expected_shipping_date, co.id");
    }

    /** Lignes des commandes (mêmes champs que OrderProductRepository::getById) : id_order => lignes. */
    public function orderLines(array $orderIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $orderIds))));
        if (!$ids) return [];
        $out = [];
        foreach ($this->all('SELECT cop.id_order, cop.id_product, cop.quantity_carton, cop.purchase_price FROM customer_order_product cop
                INNER JOIN final_product fp ON fp.id = cop.id_product WHERE cop.id_order IN (' . implode(',', $ids) . ')') as $r) {
            $out[(int) $r['id_order']][] = $r;
        }
        return $out;
    }

    /** Lignes des proformas : id_proforma => lignes (produit, quantité, prix du carton). */
    public function proformaLines(array $proformaIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $proformaIds))));
        if (!$ids) return [];
        $out = [];
        foreach ($this->all('SELECT id_proforma, id_product, quantity, price_per_carton FROM document_proforma_product WHERE id_proforma IN (' . implode(',', $ids) . ')') as $r) {
            $out[(int) $r['id_proforma']][] = $r;
        }
        return $out;
    }

    /** Palettes prêtes pour les documents (statut 2) depuis $since, par jour et client. */
    public function readyPallets(string $since): array
    {
        return $this->all("SELECT pd.delivery_date AS day, pd.id_customer, c.company AS customer, COUNT(*) AS pallets
            FROM palets_delivery pd LEFT JOIN customer c ON c.id = pd.id_customer
            WHERE pd.status = 2 AND pd.delivery_date >= ?
            GROUP BY pd.delivery_date, pd.id_customer, c.company
            ORDER BY pd.delivery_date, c.company", [$since]);
    }

    /**
     * WDT à envoyer ou à reprendre dans KSeF depuis $since : pas encore envoyée (ou « ready »), refusée ou en erreur, ou
     * sans réponse depuis $stuckBefore. Seulement à partir de la première expédition envoyée à KSeF (avant : hors KSeF).
     */
    public function ksefPending(string $since, string $stuckBefore): array
    {
        return $this->all("SELECT sl.id, sl.shipment_date, sl.id_customer, c.company AS customer, w.doc_num AS wdt_number,
                k.status AS ksef_status, k.error_message, COALESCE(k.submitted_at, k.updated_at) AS submitted_at
            FROM shipment_log sl
            INNER JOIN document_wdt w ON w.id = sl.id_wdt
            LEFT JOIN customer c ON c.id = sl.id_customer
            LEFT JOIN ksef_submission k ON k.id_shipment_log = sl.id
            WHERE sl.shipment_date >= ?
              AND sl.shipment_date >= (SELECT COALESCE(MIN(s2.shipment_date), '9999-12-31') FROM ksef_submission k2 INNER JOIN shipment_log s2 ON s2.id = k2.id_shipment_log)
              AND (k.id IS NULL OR k.status IN ('ready', 'failed', 'rejected')
                   OR (k.status IN ('submitted', 'processing') AND COALESCE(k.submitted_at, k.updated_at) < ?))
            ORDER BY sl.shipment_date, sl.id", [$since, $stuckBefore]);
    }

    /** Code du poste de l'utilisateur ERP (n_position.code), sinon null. */
    public function positionCode(int $userId): ?string
    {
        $row = $this->all('SELECT p.code FROM n_user_position up JOIN n_position p ON p.id = up.id_position WHERE up.id_user = ? ORDER BY up.id_position LIMIT 1', [$userId])[0] ?? null;
        return $row ? (string) $row['code'] : null;
    }

    /** Noms des postes : code => nom. */
    public function positionNames(): array
    {
        return array_column($this->all('SELECT code, name_pl FROM n_position'), 'name_pl', 'code');
    }
}
