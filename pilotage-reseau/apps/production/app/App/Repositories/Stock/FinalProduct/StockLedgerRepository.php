<?php
declare(strict_types=1);

namespace App\Repositories\Stock\FinalProduct;

use App\Repositories\Workshop\WorkshopRepository;

/**
 * Registre des mouvements des produits finis, reconstruit depuis les cartons scannés (carton_stock), sans double
 * saisie : entrée = étiquette du carton (date_add), sortie = carton expédié (statut 3) à la date de sa palette
 * (date_pallet) — la même règle que le stock d'un jour du module Kierownik produkcji. En stock = statut 1 (magasin)
 * ou 2 (sur palette, pas encore parti), comme Stan magazynu.
 */
final class StockLedgerRepository extends WorkshopRepository
{
    protected string $table = 'carton_stock';

    /**
     * Par produit sur [from, to] : stock au début, entrées, sorties, stock à la fin (cartons), avec catégorie et coût
     * matières du carton (fiche produit).
     */
    public function summary(string $from, string $to): array
    {
        $end = $to . ' 23:59:59';
        $start = $from . ' 00:00:00';
        return $this->all("SELECT s.id_product, fp.name, fp.id_category, cp.name AS category, COALESCE(fp.pre_calculated_raw_price_per_carton, 0) AS card_cost,
                s.opening, s.produced, s.shipped, s.closing
            FROM (
                SELECT id_product,
                    SUM(date_add < ? AND NOT (status = 3 AND date_pallet < ?)) AS opening,
                    SUM(date_add BETWEEN ? AND ?) AS produced,
                    SUM(status = 3 AND date_pallet BETWEEN ? AND ?) AS shipped,
                    SUM(date_add <= ? AND NOT (status = 3 AND date_pallet <= ?)) AS closing
                FROM carton_stock GROUP BY id_product
            ) s
            LEFT JOIN final_product fp ON fp.id = s.id_product
            LEFT JOIN category_product cp ON cp.id = fp.id_category
            WHERE s.opening + s.produced + s.shipped + s.closing > 0
            ORDER BY fp.name", [$from, $start, $from, $to, $start, $end, $to, $end]);
    }

    /** Entrées d'un produit par jour sur [from, to] : cartons et numéros de lot. */
    public function inByDay(int $productId, string $from, string $to): array
    {
        return $this->all("SELECT date_add AS day, COUNT(*) AS n, MIN(batch_number) AS batch_min, MAX(batch_number) AS batch_max
            FROM carton_stock WHERE id_product = ? AND date_add BETWEEN ? AND ? GROUP BY date_add ORDER BY date_add", [$productId, $from, $to]);
    }

    /** Sorties d'un produit par jour et par client (client de la palette) sur [from, to]. */
    public function outByDay(int $productId, string $from, string $to): array
    {
        return $this->all("SELECT x.day, x.id_customer, c.company AS customer, COUNT(*) AS n
            FROM (
                SELECT DATE(cs.date_pallet) AS day,
                    (SELECT pd.id_customer FROM carton_pallet_connection cpc JOIN palets_delivery pd ON pd.id_palet = cpc.id_palet
                     WHERE cpc.id_carton = cs.id_carton ORDER BY cpc.id_key DESC LIMIT 1) AS id_customer
                FROM carton_stock cs
                WHERE cs.id_product = ? AND cs.status = 3 AND cs.date_pallet BETWEEN ? AND ?
            ) x
            LEFT JOIN customer c ON c.id = x.id_customer
            GROUP BY x.day, x.id_customer, c.company ORDER BY x.day, n DESC", [$productId, $from . ' 00:00:00', $to . ' 23:59:59']);
    }

    /** Stock d'un produit à la fin du jour $day (cartons). */
    public function stockAt(int $productId, string $day): int
    {
        return (int) ($this->all("SELECT COUNT(*) AS n FROM carton_stock WHERE id_product = ? AND date_add <= ? AND NOT (status = 3 AND date_pallet <= ?)",
            [$productId, $day, $day . ' 23:59:59'])[0]['n'] ?? 0);
    }

    /** Lots d'un produit en stock maintenant : numéro, date limite, magasin / sur palette (le plus ancien d'abord). */
    public function batches(int $productId): array
    {
        return $this->all("SELECT batch_number, expiration_date, SUM(status = 1) AS in_store, SUM(status = 2) AS on_pallet, MIN(date_add) AS made_on
            FROM carton_stock WHERE id_product = ? AND status IN (1, 2)
            GROUP BY batch_number, expiration_date ORDER BY expiration_date, batch_number", [$productId]);
    }

    /** Cartons en stock dont la date limite est passée (date « 2000-01-01 » = non renseignée), par produit. */
    public function expired(string $today): array
    {
        return $this->all("SELECT cs.id_product, fp.name, COUNT(*) AS n, MIN(cs.expiration_date) AS oldest
            FROM carton_stock cs LEFT JOIN final_product fp ON fp.id = cs.id_product
            WHERE cs.status IN (1, 2) AND cs.expiration_date < ? AND cs.expiration_date > '2000-01-01'
            GROUP BY cs.id_product, fp.name ORDER BY n DESC", [$today]);
    }

    /** Cartons sur palette dont la palette devait partir avant $before et qui ne sont pas expédiés, par client. */
    public function waitingPallets(string $before): array
    {
        return $this->all("SELECT pd.id_customer, c.company AS customer, MIN(DATE(pd.delivery_date)) AS since, COUNT(DISTINCT pd.id_palet) AS pallets, COUNT(*) AS n
            FROM carton_stock cs
            JOIN carton_pallet_connection cpc ON cpc.id_carton = cs.id_carton
            JOIN palets_delivery pd ON pd.id_palet = cpc.id_palet
            LEFT JOIN customer c ON c.id = pd.id_customer
            WHERE cs.status = 2 AND pd.delivery_date < ?
            GROUP BY pd.id_customer, c.company ORDER BY since", [$before]);
    }

    /**
     * Cartons expédiés avant d'être entrés (date de palette antérieure à l'étiquette) dont l'une des deux dates tombe
     * dans [from, to] : ce sont eux qui font différer le solde des mouvements.
     */
    public function inconsistent(string $from, string $to): int
    {
        return (int) ($this->all("SELECT COUNT(*) AS n FROM carton_stock WHERE status = 3 AND date_pallet < date_add
                AND (date_add BETWEEN ? AND ? OR date_pallet BETWEEN ? AND ?)",
            [$from, $to, $from . ' 00:00:00', $to . ' 23:59:59'])[0]['n'] ?? 0);
    }

    public function product(int $id): ?array
    {
        return $this->all("SELECT fp.id, fp.name, cp.name AS category, COALESCE(fp.pre_calculated_raw_price_per_carton, 0) AS card_cost, fp.ppc
            FROM final_product fp LEFT JOIN category_product cp ON cp.id = fp.id_category WHERE fp.id = ?", [$id])[0] ?? null;
    }

    public function categories(): array
    {
        return $this->all("SELECT id, name FROM category_product ORDER BY name");
    }
}
