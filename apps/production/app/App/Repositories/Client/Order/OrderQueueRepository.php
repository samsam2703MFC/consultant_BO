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
