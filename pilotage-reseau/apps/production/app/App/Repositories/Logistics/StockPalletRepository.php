<?php
declare(strict_types=1);

namespace App\Repositories\Logistics;

use App\Domain\Client\Order\OpenOrders;
use App\Domain\Stock\StockLevels;
use App\Repositories\Workshop\WorkshopRepository;

/**
 * [296] Stany i palety : la route d'un carton, depuis les cartons scannés ; seule écriture : suppression d'une palette [333].
 * carton_stock.status : 1 = magasin, 2 = sur palette (encore chez nous), 3 = parti.
 * palets_delivery.status : 1 = palette en préparation (tablette pakowania), 2 = prête (attend les documents), 3 = partie.
 * Un carton déplacé d'une palette à l'autre compte sur sa dernière palette (carton_pallet_connection, plus grand id_key).
 * Partie = produit + jour d'étiquette (date_add) + date limite : carton_stock.batch_number vaut presque toujours la même
 * valeur par défaut (10000 / 10001 pour ~95 % des cartons), il ne distingue pas les fabrications.
 */
final class StockPalletRepository extends WorkshopRepository
{
    protected string $table = 'palets_delivery';

    /** Cartons chez nous par groupe de production (section du produit) : magasin et sur palette. */
    public function stockByGroup(): array
    {
        return $this->all("SELECT COALESCE(ps.name, 'Bez grupy') AS grp, SUM(cs.status = 1) AS in_store, SUM(cs.status = 2) AS on_pallet,
                COUNT(DISTINCT CASE WHEN cs.status = 1 THEN cs.id_product END) AS products
            FROM carton_stock cs
            JOIN final_product fp ON fp.id = cs.id_product
            LEFT JOIN production_sections ps ON ps.id = fp.id_section
            WHERE cs.status IN (1, 2)
            GROUP BY ps.id, ps.name
            ORDER BY in_store DESC");
    }

    /** Parties chez nous qui expirent avant $limit (date par défaut 2000-01-01 exclue). */
    public function expiring(string $today, string $limit): array
    {
        return $this->all("SELECT cs.id_product, fp.name, cs.date_add AS made_on, MIN(cs.expiration_date) AS expires, COUNT(*) AS cartons,
                SUM(cs.status = 2) AS on_pallet
            FROM carton_stock cs JOIN final_product fp ON fp.id = cs.id_product
            WHERE cs.status IN (1, 2) AND cs.expiration_date > '2000-01-01' AND cs.expiration_date <= ?
            GROUP BY cs.id_product, fp.name, cs.date_add
            ORDER BY expires, cartons DESC", [$limit]);
    }

    /**
     * Palettes encore chez nous (préparation, prêtes) : client, jour, cartons et produits (dernière palette du carton),
     * premier et dernier carton posé.
     */
    public function openPallets(?string $client = null): array
    {
        [$where, $params] = $this->clientFilter($client);
        return $this->all("SELECT pd.id_palet, pd.status, pd.delivery_date, pd.id_customer, c.company AS customer, pd.last_update_date,
                COUNT(cs.id_carton) AS cartons, COUNT(DISTINCT cs.id_product) AS products, MIN(cs.date_pallet) AS first_at, MAX(cs.date_pallet) AS last_at
            FROM palets_delivery pd
            LEFT JOIN customer c ON c.id = pd.id_customer
            LEFT JOIN carton_pallet_connection cpc ON cpc.id_palet = pd.id_palet
                AND cpc.id_key = (SELECT MAX(x.id_key) FROM carton_pallet_connection x WHERE x.id_carton = cpc.id_carton)
            LEFT JOIN carton_stock cs ON cs.id_carton = cpc.id_carton
            WHERE pd.status IN (1, 2){$where}
            GROUP BY pd.id_palet, pd.status, pd.delivery_date, pd.id_customer, c.company, pd.last_update_date
            ORDER BY pd.delivery_date, pd.id_palet DESC", $params);
    }

    /**
     * [346] Zamówienia do palet : ten sam klient i ta sama data wysyłki (zamówienie nie zarchiwizowane).
     * @param list<array{0:int,1:string}> $pairs [id_customer, data]
     * @return array<string, list<int>> "klient|data" => numery zamówień
     */
    public function ordersForPallets(array $pairs): array
    {
        $pairs = array_values(array_filter($pairs, fn($p) => $p[0] > 0 && $p[1] !== ''));
        if (!$pairs) return [];
        $customers = array_values(array_unique(array_map(fn($p) => (int) $p[0], $pairs)));
        $dates = array_values(array_unique(array_map(fn($p) => substr((string) $p[1], 0, 10), $pairs)));
        $rows = $this->all('SELECT id, id_customer, DATE(expected_shipping_date) AS d FROM customer_order
            WHERE status <> 5 AND id_customer IN (' . implode(',', array_fill(0, count($customers), '?')) . ')
              AND DATE(expected_shipping_date) IN (' . implode(',', array_fill(0, count($dates), '?')) . ')
            ORDER BY id', array_merge($customers, $dates));
        $out = [];
        foreach ($rows as $r) $out[(int) $r['id_customer'] . '|' . $r['d']][] = (int) $r['id'];
        return $out;
    }

    /** Palettes parties depuis $from : par jour et client (nombre de palettes, cartons). */
    public function shippedSince(string $from, ?string $client = null): array
    {
        [$where, $params] = $this->clientFilter($client);
        return $this->all("SELECT pd.delivery_date, pd.id_customer, c.company AS customer, COUNT(DISTINCT pd.id_palet) AS pallets
            FROM palets_delivery pd LEFT JOIN customer c ON c.id = pd.id_customer
            WHERE pd.status = 3 AND pd.delivery_date >= ?{$where}
            GROUP BY pd.delivery_date, pd.id_customer, c.company
            ORDER BY pd.delivery_date DESC, pallets DESC", array_merge([$from], $params));
    }

    /** Une palette : en-tête (client, jour, état, cartons, premier et dernier carton posé). */
    public function pallet(int $id): ?array
    {
        $rows = $this->all("SELECT pd.id_palet, pd.status, pd.delivery_date, pd.id_customer, c.company AS customer, pd.last_update_date,
                COUNT(cs.id_carton) AS cartons, COUNT(DISTINCT cs.id_product) AS products, MIN(cs.date_pallet) AS first_at, MAX(cs.date_pallet) AS last_at
            FROM palets_delivery pd
            LEFT JOIN customer c ON c.id = pd.id_customer
            LEFT JOIN carton_pallet_connection cpc ON cpc.id_palet = pd.id_palet
                AND cpc.id_key = (SELECT MAX(x.id_key) FROM carton_pallet_connection x WHERE x.id_carton = cpc.id_carton)
            LEFT JOIN carton_stock cs ON cs.id_carton = cpc.id_carton
            WHERE pd.id_palet = ?
            GROUP BY pd.id_palet, pd.status, pd.delivery_date, pd.id_customer, c.company, pd.last_update_date", [$id]);
        return $rows[0] ?? null;
    }

    /** Composition d'une palette : produit, partie, date limite, cartons. */
    public function composition(int $id): array
    {
        return $this->all("SELECT cs.id_product, fp.name, cs.date_add AS made_on, MIN(cs.expiration_date) AS expires, COUNT(*) AS cartons
            FROM carton_pallet_connection cpc
            JOIN carton_stock cs ON cs.id_carton = cpc.id_carton
            JOIN final_product fp ON fp.id = cs.id_product
            WHERE cpc.id_palet = ? AND cpc.id_key = (SELECT MAX(x.id_key) FROM carton_pallet_connection x WHERE x.id_carton = cpc.id_carton)
            GROUP BY cs.id_product, fp.name, cs.date_add
            ORDER BY cartons DESC, fp.name, cs.date_add", [$id]);
    }

    /** Départ du camion qui emporte les palettes d'un client un jour donné (tablette chauffeur), et livraison. */
    public function departures(array $pairs): array
    {
        if (!$pairs) return [];
        $or = implode(' OR ', array_fill(0, count($pairs), '(a.ship_date = ? AND a.id_customer = ?)'));
        $params = [];
        foreach ($pairs as [$day, $customer]) { $params[] = $day; $params[] = $customer; }
        return $this->all("SELECT a.ship_date, a.id_customer, v.regis_num AS vehicle,
                (SELECT MIN(r.started_at) FROM n_delivery_run r WHERE r.ship_date = a.ship_date AND r.id_carrier_vehicle = a.id_carrier_vehicle) AS left_at,
                (SELECT MAX(s.recorded_at) FROM n_delivery_stop s WHERE s.ship_date = a.ship_date AND s.id_customer = a.id_customer AND s.status = 'delivered') AS delivered_at
            FROM n_shipping_assignment a
            LEFT JOIN carrier_vehicle v ON v.id = a.id_carrier_vehicle
            WHERE {$or}", $params);
    }

    /** Jours de fabrication d'un produit (derniers d'abord) : cartons au magasin, sur palette, partis, date limite. */
    public function lotDays(int $productId, int $limit = 12): array
    {
        return $this->all("SELECT cs.date_add AS made_on, COUNT(*) AS cartons, SUM(cs.status = 1) AS in_store, SUM(cs.status = 2) AS on_pallet,
                SUM(cs.status = 3) AS shipped, MIN(cs.expiration_date) AS expires
            FROM carton_stock cs WHERE cs.id_product = ?
            GROUP BY cs.date_add ORDER BY cs.date_add DESC LIMIT " . max(1, $limit), [$productId]);
    }

    /** Une partie (produit + jour d'étiquette) : palettes (dernière palette de chaque carton) avec client, jour et état. */
    public function lotPallets(int $productId, string $madeOn): array
    {
        return $this->all("SELECT pd.id_palet, pd.status, pd.delivery_date, pd.id_customer, c.company AS customer, COUNT(*) AS cartons
            FROM carton_stock cs
            JOIN carton_pallet_connection cpc ON cpc.id_carton = cs.id_carton
                AND cpc.id_key = (SELECT MAX(x.id_key) FROM carton_pallet_connection x WHERE x.id_carton = cs.id_carton)
            JOIN palets_delivery pd ON pd.id_palet = cpc.id_palet
            LEFT JOIN customer c ON c.id = pd.id_customer
            WHERE cs.id_product = ? AND cs.date_add = ? AND cs.status IN (2, 3)
            GROUP BY pd.id_palet, pd.status, pd.delivery_date, pd.id_customer, c.company
            ORDER BY pd.delivery_date DESC, pd.id_palet DESC", [$productId, $madeOn]);
    }

    /** Produits qui ont des cartons (liste du suivi de partie). */
    public function productsWithCartons(): array
    {
        return $this->all("SELECT fp.id, fp.name FROM final_product fp
            WHERE EXISTS (SELECT 1 FROM carton_stock cs WHERE cs.id_product = fp.id)
            ORDER BY fp.active DESC, fp.name");
    }

    public function productName(int $id): ?string
    {
        $r = $this->all('SELECT name FROM final_product WHERE id = ?', [$id]);
        return $r ? (string) $r[0]['name'] : null;
    }

    /** @return array{0: string, 1: list<string>} */
    /**
     * [334] Stan ogólny : par produit en stock ou commandé — cartons au magasin (1), sur palette (2), commandes ouvertes
     * (OpenOrders, comme Stan magazynu), stocks min. / max. en vigueur (pièces, StockLevels) et pièces par carton.
     */
    public function generalStock(): array
    {
        return $this->all("SELECT fp.id, fp.name, COALESCE(cp.name, 'Bez kategorii') AS category, fp.ppc,
                COALESCE(cs.free, 0) AS free, COALESCE(cs.pallet, 0) AS pallet, COALESCE(oo.cartons, 0) AS ordered,
                " . StockLevels::columns('ps') . "
            FROM final_product fp
            LEFT JOIN category_product cp ON cp.id = fp.id_category
            LEFT JOIN n_prod_item i ON i.id_final_product = fp.id AND i.item_type = 'product'
            LEFT JOIN n_prod_product_settings ps ON ps.id_item = i.id
            " . StockLevels::join('i') . "
            LEFT JOIN (SELECT id_product, SUM(status = 1) AS free, SUM(status = 2) AS pallet FROM carton_stock
                       WHERE status IN (" . OpenOrders::STOCK_STATUSES . ") GROUP BY id_product) cs ON cs.id_product = fp.id
            LEFT JOIN (SELECT cop.id_product, SUM(cop.quantity_carton) AS cartons FROM customer_order co
                       JOIN customer_order_product cop ON cop.id_order = co.id WHERE " . OpenOrders::quantitySql('co') . " GROUP BY cop.id_product) oo ON oo.id_product = fp.id
            WHERE cs.id_product IS NOT NULL OR oo.id_product IS NOT NULL
            ORDER BY cp.name IS NULL, cp.name, fp.name");
    }

    /** [333] Cartons dont la dernière palette est $id. @return list<int> */
    public function palletCartonIds(int $id): array
    {
        return array_map('intval', array_column($this->all("SELECT cpc.id_carton FROM carton_pallet_connection cpc
            WHERE cpc.id_palet = ? AND cpc.id_key = (SELECT MAX(x.id_key) FROM carton_pallet_connection x WHERE x.id_carton = cpc.id_carton)", [$id]), 'id_carton'));
    }

    /**
     * [333] Supprime une palette encore chez nous (statut 1 ou 2) : ses cartons retournent au magasin ($deleteCartons = false)
     * ou sont supprimés ; liens carton ↔ palette des cartons effacés ; trace dans n_pallet_deletion. Une transaction.
     * @param list<int> $cartonIds
     */
    public function deletePallet(int $id, array $cartonIds, bool $deleteCartons, array $log): void
    {
        $own = !$this->db->inTransaction();
        if ($own) $this->db->beginTransaction();
        try {
            $st = $this->db->prepare('SELECT status FROM palets_delivery WHERE id_palet = ? FOR UPDATE');
            $st->execute([$id]);
            $status = $st->fetchColumn();
            if ($status === false || !in_array((int) $status, [1, 2], true)) throw new \RuntimeException('pallet-not-open');
            foreach (array_chunk($cartonIds, 500) as $chunk) {
                $in = implode(',', array_fill(0, count($chunk), '?'));
                if ($deleteCartons) {
                    $this->db->prepare("DELETE FROM carton_stock WHERE id_carton IN ({$in}) AND status IN (1, 2)")->execute($chunk);
                } else {
                    $this->db->prepare("UPDATE carton_stock SET status = 1, date_pallet = NULL WHERE id_carton IN ({$in}) AND status = 2")->execute($chunk);
                }
                $this->db->prepare("DELETE FROM carton_pallet_connection WHERE id_carton IN ({$in})")->execute($chunk);
            }
            $this->db->prepare('DELETE FROM carton_pallet_connection WHERE id_palet = ?')->execute([$id]);
            $this->db->prepare('DELETE FROM palets_delivery WHERE id_palet = ? AND status IN (1, 2)')->execute([$id]);
            $this->db->prepare('INSERT INTO n_pallet_deletion (id_palet, id_customer, customer, delivery_date, pallet_status, mode, cartons, detail, reason, created_by)
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$id, $log['id_customer'], $log['customer'], $log['delivery_date'], (int) $status, $deleteCartons ? 'delete' : 'stock',
                    count($cartonIds), $log['detail'], $log['reason'], $log['user']]);
            if ($own) $this->db->commit();
        } catch (\Throwable $e) {
            if ($own && $this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    private function clientFilter(?string $client): array
    {
        $client = trim((string) $client);
        return $client === '' ? ['', []] : [' AND c.company LIKE ?', ['%' . addcslashes($client, '%_\\') . '%']];
    }
}
