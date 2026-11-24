<?php
declare(strict_types=1);

namespace App\Repositories\Workshop;

/**
 * [416] Partie wstrzymane (n_qc_hold) : blocage enregistré ici seulement — carton_stock n'est jamais modifié. Les
 * lectures croisent les blocages actifs avec les cartons (produit + jour d'étiquette) : au magasin / sur palette, sur
 * une palette donnée, sur les palettes d'un client pour un jour de livraison.
 */
final class LotHoldRepository extends WorkshopRepository
{
    protected string $table = 'n_qc_hold';
    protected array $writable = ['id_output', 'lot_code', 'id_final_product', 'label_from', 'label_to', 'reason', 'held_at', 'held_by_employee', 'held_device',
        'released_at', 'released_by_employee', 'release_note'];

    private const SELECT = "SELECT h.*, fp.name AS product,
            TRIM(CONCAT(COALESCE(pe.name, ''), ' ', COALESCE(pe.surname, ''))) AS held_by_name,
            TRIM(CONCAT(COALESCE(re.name, ''), ' ', COALESCE(re.surname, ''))) AS released_by_name
        FROM n_qc_hold h
        LEFT JOIN final_product fp ON fp.id = h.id_final_product
        LEFT JOIN n_prod_employee e ON e.id = h.held_by_employee
        LEFT JOIN production_employee pe ON pe.id = e.id_production_employee
        LEFT JOIN n_prod_employee e2 ON e2.id = h.released_by_employee
        LEFT JOIN production_employee re ON re.id = e2.id_production_employee";

    public function hold(int $id): ?array
    {
        return $this->all(self::SELECT . ' WHERE h.id = ?', [$id])[0] ?? null;
    }

    /** Blocage actif posé depuis une partie. */
    public function activeForOutput(int $outputId): ?array
    {
        return $this->all(self::SELECT . ' WHERE h.id_output = ? AND h.released_at IS NULL ORDER BY h.id DESC LIMIT 1', [$outputId])[0] ?? null;
    }

    /** @return array<int, array> partie => blocage actif */
    public function activeByOutputs(array $outputIds): array
    {
        $ids = array_values(array_filter(array_map('intval', $outputIds)));
        if (!$ids) return [];
        $out = [];
        foreach ($this->all(self::SELECT . ' WHERE h.released_at IS NULL AND h.id_output IN (' . implode(',', $ids) . ') ORDER BY h.id') as $r) $out[(int) $r['id_output']] = $r;
        return $out;
    }

    /** Blocages actifs couvrant un produit et un jour d'étiquette (carton scanné). */
    public function activeForLabel(int $productId, string $day): array
    {
        return $this->all(self::SELECT . ' WHERE h.released_at IS NULL AND h.id_final_product = ? AND ? BETWEEN h.label_from AND h.label_to ORDER BY h.id', [$productId, $day]);
    }

    public function release(int $id, ?int $employeeId, string $note): void
    {
        $this->db->prepare('UPDATE n_qc_hold SET released_at = NOW(), released_by_employee = ?, release_note = ? WHERE id = ? AND released_at IS NULL')
            ->execute([$employeeId, $note, $id]);
    }

    /**
     * Cartons au magasin ou sur palette (statuts 1, 2) des parties bloquées, par produit.
     * @return array<int, list<array{hold_id:int, lot_code:?string, product:?string, label_from:string, label_to:string, cartons:int}>>
     */
    public function heldStock(array $productIds): array
    {
        $ids = array_values(array_filter(array_map('intval', $productIds)));
        if (!$ids || !$this->hasCartons()) return [];
        $out = [];
        foreach ($this->all('SELECT h.id AS hold_id, h.id_final_product, h.lot_code, h.label_from, h.label_to, fp.name AS product,
                    (SELECT COUNT(*) FROM carton_stock cs WHERE cs.id_product = h.id_final_product AND cs.date_add BETWEEN h.label_from AND h.label_to AND cs.status IN (1, 2)) AS cartons
                FROM n_qc_hold h LEFT JOIN final_product fp ON fp.id = h.id_final_product WHERE h.released_at IS NULL AND h.id_final_product IN (' . implode(',', $ids) . ') ORDER BY h.label_from') as $r) {
            $out[(int) $r['id_final_product']][] = ['hold_id' => (int) $r['hold_id'], 'lot_code' => $r['lot_code'], 'product' => $r['product'], 'label_from' => (string) $r['label_from'],
                'label_to' => (string) $r['label_to'], 'cartons' => (int) $r['cartons']];
        }
        return $out;
    }

    /**
     * Cartons de parties bloquées dont la dernière palette est l'une de celles-ci.
     * @return list<array{hold_id:int, lot_code:?string, product:?string, id_palet:int, cartons:int}>
     */
    public function heldOnPallets(array $palletIds): array
    {
        $ids = array_values(array_filter(array_map('intval', $palletIds)));
        if (!$ids || !$this->hasCartons()) return [];
        return $this->all('SELECT h.id AS hold_id, h.lot_code, fp.name AS product, cpc.id_palet, COUNT(*) AS cartons
            FROM n_qc_hold h
            JOIN carton_stock cs ON cs.id_product = h.id_final_product AND cs.date_add BETWEEN h.label_from AND h.label_to
            JOIN carton_pallet_connection cpc ON cpc.id_carton = cs.id_carton
                AND cpc.id_key = (SELECT MAX(x.id_key) FROM carton_pallet_connection x WHERE x.id_carton = cs.id_carton)
            LEFT JOIN final_product fp ON fp.id = h.id_final_product
            WHERE h.released_at IS NULL AND cpc.id_palet IN (' . implode(',', $ids) . ')
            GROUP BY h.id, h.lot_code, fp.name, cpc.id_palet ORDER BY cpc.id_palet, fp.name');
    }

    /** Palettes d'un client pour un jour de livraison (place de remorque sans palette nommée). @return list<int> */
    public function customerPallets(int $customerId, string $deliveryDate): array
    {
        if (!$this->hasCartons()) return [];
        return array_map(fn($r) => (int) $r['id_palet'], $this->all('SELECT id_palet FROM palets_delivery WHERE id_customer = ? AND delivery_date = ?', [$customerId, $deliveryDate]));
    }

    private function hasCartons(): bool
    {
        static $known = [];
        $key = spl_object_id($this->db);
        if (!isset($known[$key])) {
            $n = (int) ($this->all("SELECT COUNT(*) AS n FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME IN ('carton_stock', 'carton_pallet_connection', 'palets_delivery')")[0]['n'] ?? 0);
            $known[$key] = $n === 3;
        }
        return $known[$key];
    }
}
