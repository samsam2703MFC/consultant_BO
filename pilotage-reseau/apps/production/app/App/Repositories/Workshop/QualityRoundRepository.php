<?php
declare(strict_types=1);

namespace App\Repositories\Workshop;

/**
 * [415] Tablet jakości Kierownika produkcji : parties du jour de toutes les grupy (mêmes colonnes que
 * LotsQcDayRepository::outputs, sans exiger la journée close), contrôles n_qc_control, signaux des tablettes de groupe
 * (non-conformités signalées), demandes « Wyślij grupie » (rappels n_task_reminder du groupe), lots du registre
 * (fournisseur, réception) et destinations des cartons d'une partie (carton_stock → palette → client, WDT).
 * Écrit seulement n_qc_control ; le reste est lu.
 */
final class QualityRoundRepository extends WorkshopRepository
{
    protected string $table = 'n_qc_control';
    protected array $writable = ['id_output', 'work_date', 'round_no', 'id_employee', 'id_device', 'weights', 'target_g', 'tolerance_pct', 'weight_result',
        'appearance', 'id_photo', 'lots_total', 'lots_missing', 'lots_requested', 'sample', 'id_sample', 'result', 'id_nc', 'note'];
    /** Préfixe du lot des rappels « Wyślij grupie » (n_task_reminder.batch, CHAR(16)) : QC + numéro de partie. */
    public const REQUEST_BATCH = 'QC';

    private const OUTPUT = "SELECT o.id, o.work_date, o.id_group, o.id_item, o.lot_code, o.quantity, o.qc_status, o.qc_at, o.qc_note, o.id_nc, o.created_at, o.cancelled_at,
                i.base_unit, i.item_type, i.id_final_product, COALESCE(i.id_recipe, fp.id_recipe) AS id_recipe, COALESCE(fp.name, pr.name) AS item_name,
                fp.weight AS piece_weight, fp.ppc, g.name_pl AS group_name, g.name_fr AS group_name_fr, g.sort_order AS group_sort,
                TRIM(CONCAT(COALESCE(pe.name, ''), ' ', COALESCE(pe.surname, ''))) AS employee_name,
                (SELECT COUNT(*) FROM n_prod_output_lot l LEFT JOIN n_material_trace t ON t.id_raw_material = l.id_material
                  WHERE l.id_output = o.id AND COALESCE(t.tracked, 1) = 1
                    AND ((l.lot_number IS NOT NULL AND l.lot_number <> '') OR l.missing_reason IS NOT NULL))
                + (SELECT COUNT(*) FROM n_prod_output_component k WHERE k.id_output = o.id AND k.id_source_output IS NOT NULL) AS lots_done
            FROM n_prod_output o
            JOIN n_prod_item i ON i.id = o.id_item
            LEFT JOIN final_product fp ON fp.id = i.id_final_product
            LEFT JOIN product_recipe pr ON pr.id = i.id_recipe
            LEFT JOIN n_prod_group g ON g.id = o.id_group
            LEFT JOIN n_prod_employee e ON e.id = o.id_employee
            LEFT JOIN production_employee pe ON pe.id = e.id_production_employee";

    /** Parties du jour (toutes les grupy, sans les annulées), dans l'ordre de démarrage. */
    public function dayOutputs(string $day): array
    {
        return $this->all(self::OUTPUT . ' WHERE o.work_date = ? AND o.cancelled_at IS NULL ORDER BY o.created_at, o.id', [$day]);
    }

    public function outputRow(int $id): ?array
    {
        return $this->all(self::OUTPUT . ' WHERE o.id = ?', [$id])[0] ?? null;
    }

    public function outputByLotCode(string $code): ?array
    {
        return $this->all(self::OUTPUT . ' WHERE o.lot_code = ?', [$code])[0] ?? null;
    }

    /** Parties (non annulées) d'un produit fini fabriquées entre deux jours : carton → partie probable. */
    public function productOutputs(int $productId, string $from, string $to): array
    {
        return $this->all(self::OUTPUT . ' WHERE i.id_final_product = ? AND o.work_date BETWEEN ? AND ? AND o.cancelled_at IS NULL
            ORDER BY o.work_date DESC, o.created_at DESC', [$productId, $from, $to]);
    }

    /** Poste (n_position.code) d'une personne de la production. */
    public function positionCode(int $employeeId): ?string
    {
        $r = $this->all('SELECT p.code FROM n_prod_employee e JOIN n_position p ON p.id = e.id_position WHERE e.id = ?', [$employeeId])[0] ?? null;
        return $r !== null ? (string) $r['code'] : null;
    }

    /** @return array<int, array> partie => dernier contrôle (avec la personne) */
    public function lastControls(array $outputIds): array
    {
        $ids = self::ids($outputIds);
        if (!$ids) return [];
        $out = [];
        foreach ($this->all("SELECT c.*, TRIM(CONCAT(COALESCE(pe.name, ''), ' ', COALESCE(pe.surname, ''))) AS employee_name
                FROM n_qc_control c
                LEFT JOIN n_prod_employee e ON e.id = c.id_employee
                LEFT JOIN production_employee pe ON pe.id = e.id_production_employee
                WHERE c.id_output IN (" . implode(',', $ids) . ') ORDER BY c.created_at, c.id') as $r) {
            $out[(int) $r['id_output']] = $r;
        }
        return $out;
    }

    /**
     * Non-conformités signalées le jour même depuis la tablette d'un groupe (« Zgłoś niezgodność », n_nc.id_prod_group),
     * hors celles d'un contrôle de partie (n_prod_output.id_nc, n_qc_control.id_nc) : signal « pilne » du tour.
     */
    public function groupReports(string $day): array
    {
        return $this->all("SELECT nc.id, nc.number, nc.description, nc.id_final_product, nc.id_prod_group, nc.created_at
            FROM n_nc nc
            WHERE nc.id_prod_group IS NOT NULL AND nc.reported_at = ? AND nc.status <> 'closed'
              AND NOT EXISTS (SELECT 1 FROM n_prod_output o WHERE o.id_nc = nc.id)
              AND NOT EXISTS (SELECT 1 FROM n_qc_control c WHERE c.id_nc = nc.id)
            ORDER BY nc.created_at", [$day]);
    }

    /** Rappels « Wyślij grupie » encore ouverts d'une partie (non lus, non fermés). */
    public function openRequests(int $outputId): array
    {
        return $this->all("SELECT id, sent_at, message FROM n_task_reminder WHERE batch = ? AND target_kind = 'tablet' AND read_at IS NULL AND closed_at IS NULL
            ORDER BY sent_at DESC", [self::requestBatch($outputId)]);
    }

    /** @return array<int, int> partie => nombre de rappels envoyés (ouverts ou non) */
    public function requestCounts(array $outputIds): array
    {
        $ids = self::ids($outputIds);
        if (!$ids) return [];
        $keys = array_map(fn($id) => self::requestBatch($id), $ids);
        $out = [];
        foreach ($this->all("SELECT batch, COUNT(*) AS n FROM n_task_reminder WHERE target_kind = 'tablet' AND closed_at IS NULL AND batch IN ("
            . implode(',', array_fill(0, count($keys), '?')) . ') GROUP BY batch', $keys) as $r) {
            $out[(int) substr((string) $r['batch'], strlen(self::REQUEST_BATCH))] = (int) $r['n'];
        }
        return $out;
    }

    public static function requestBatch(int $outputId): string
    {
        return self::REQUEST_BATCH . str_pad((string) $outputId, 14, '0', STR_PAD_LEFT);
    }

    /**
     * Lots du registre « Partie surowców » choisis dans les parties : fournisseur, réception.
     * @return array<int, array{supplier:?string, received_on:?string, source:string}>
     */
    public function registryLots(array $lotIds): array
    {
        $ids = self::ids($lotIds);
        if (!$ids) return [];
        $out = [];
        foreach ($this->all('SELECT m.id, m.received_on, m.source, s.name AS supplier FROM n_material_lot m LEFT JOIN supplier s ON s.id = m.id_supplier
                WHERE m.id IN (' . implode(',', $ids) . ')') as $r) {
            $out[(int) $r['id']] = ['supplier' => $r['supplier'], 'received_on' => $r['received_on'], 'source' => (string) $r['source']];
        }
        return $out;
    }

    /** Carton du magasin (étiquette) : produit, jour d'étiquette, état. */
    public function carton(int $cartonId): ?array
    {
        if (!$this->hasTable('carton_stock')) return null;
        return $this->all('SELECT cs.id_carton, cs.id_product, cs.date_add, cs.status, cs.expiration_date, fp.name AS product
            FROM carton_stock cs LEFT JOIN final_product fp ON fp.id = cs.id_product WHERE cs.id_carton = ?', [$cartonId])[0] ?? null;
    }

    /**
     * Où sont les cartons d'un produit étiquetés entre deux jours : au magasin (1), sur une palette (2), partis (3) — client
     * et jour de livraison de la dernière palette du carton, WDT du client autour de ce jour contenant le produit.
     */
    public function cartonDestinations(int $productId, string $from, string $to): array
    {
        if (!$this->hasTable('carton_stock')) return [];
        $wdt = $this->hasTable('document_wdt') && $this->hasTable('document_wdt_product')
            ? "(SELECT MIN(w.established_date) FROM document_wdt w JOIN document_wdt_product wp ON wp.id_document_wdt = w.id AND wp.id_product = cs.id_product
                WHERE pd.id_customer IS NOT NULL AND w.id_customer = pd.id_customer
                  AND w.established_date BETWEEN pd.delivery_date - INTERVAL 2 DAY AND pd.delivery_date + INTERVAL 1 DAY)"
            : 'NULL';
        $wdtNum = $this->hasTable('document_wdt') && $this->hasTable('document_wdt_product')
            ? "(SELECT GROUP_CONCAT(DISTINCT w.doc_num ORDER BY w.doc_num SEPARATOR ', ') FROM document_wdt w JOIN document_wdt_product wp ON wp.id_document_wdt = w.id AND wp.id_product = cs.id_product
                WHERE pd.id_customer IS NOT NULL AND w.id_customer = pd.id_customer
                  AND w.established_date BETWEEN pd.delivery_date - INTERVAL 2 DAY AND pd.delivery_date + INTERVAL 1 DAY)"
            : 'NULL';
        return $this->all("SELECT cs.status, pd.id_customer, c.company AS customer, pd.delivery_date, COUNT(*) AS cartons, {$wdt} AS wdt_date, {$wdtNum} AS wdt_num
            FROM carton_stock cs
            LEFT JOIN carton_pallet_connection cpc ON cpc.id_carton = cs.id_carton
                AND cpc.id_key = (SELECT MAX(x.id_key) FROM carton_pallet_connection x WHERE x.id_carton = cs.id_carton)
            LEFT JOIN palets_delivery pd ON pd.id_palet = cpc.id_palet
            LEFT JOIN customer c ON c.id = pd.id_customer
            WHERE cs.id_product = ? AND cs.date_add BETWEEN ? AND ?
            GROUP BY cs.status, pd.id_customer, c.company, pd.delivery_date
            ORDER BY cs.status DESC, pd.delivery_date, c.company", [$productId, $from, $to]);
    }

    public function hasTable(string $table): bool
    {
        static $known = [];
        $key = spl_object_id($this->db) . '|' . $table;
        if (!isset($known[$key])) {
            $known[$key] = (bool) $this->all('SELECT 1 AS x FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$table]);
        }
        return $known[$key];
    }

    /** @return list<int> */
    private static function ids(array $ids): array
    {
        return array_values(array_unique(array_filter(array_map('intval', $ids), fn($i) => $i > 0)));
    }
}
