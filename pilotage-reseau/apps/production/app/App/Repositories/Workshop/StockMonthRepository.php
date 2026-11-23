<?php
declare(strict_types=1);

namespace App\Repositories\Workshop;

use App\Services\Material\MaterialLoss;

/**
 * Asystent « Stany magazynowe na koniec miesiąca » [403] : lecture
 *  - des produits finis : le stock en début et en fin de période vient de StockLedgerRepository::summary (cartons,
 *    règle du rejestr ruchów) ; ici, pour la fin de période : produit actif ou non (final_product.active) et cartons en
 *    stock dont la date limite est passée (carton_stock.expiration_date, « 2000-01-01 » = non renseignée — règle
 *    StockLedgerRepository::expired) ;
 *  - des surowce à une date : la règle de Zaopatrzenie (SupplyRepository / SupplyService::levels) bornée à cette date —
 *    dernière inwentaryzacja jusqu'à la date (n_mp_count), przyjęcia après elle jusqu'à la date (n_po_receipt → n_po_line),
 *    zużycie = cartons produits après elle jusqu'à la date × recette du carton en poids acheté (MaterialLoss::factorSql) ;
 *    sans inwentaryzacja : przyjęcia seules (comme l'écran) ; prix à la date = dernière ligne de raw_material_price_changelog
 *    (pack_price ÷ package_size_in_base_unit) sinon prix actuel (raw_material.price_per_unit) ;
 *  - de l'instantané enregistré par l'assistant (n_stock_month_statement, n_stock_month_item) et des décisions
 *    (n_stock_month_review) — les seules tables écrites ici.
 * Une source dont la table manque (base partielle) est rendue null (« brak danych »).
 */
final class StockMonthRepository extends WorkshopRepository
{
    protected string $table = 'n_stock_month_review';

    /** @var array<string, bool> */
    private array $tables = [];

    public function hasTable(string $table): bool
    {
        return $this->tables[$table] ??= (bool) $this->all('SELECT 1 AS x FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$table]);
    }

    /** Tables présentes : produits finis (carton_stock), surowce (n_mp_count / n_po_receipt), historique des prix. */
    public function sources(): array
    {
        return ['products' => $this->hasTable('carton_stock') && $this->hasTable('final_product'),
            'materials' => $this->hasTable('raw_material') && ($this->hasTable('n_mp_count') || ($this->hasTable('n_po_receipt') && $this->hasTable('n_po_line'))),
            'consumption' => $this->hasTable('n_mp_count') && $this->hasTable('flattened_recipe_ingredient') && $this->hasTable('carton_stock') && $this->hasTable('final_product'),
            'price_log' => $this->hasTable('raw_material_price_changelog'), 'expiry' => $this->hasTable('carton_stock')];
    }

    /** @return array<int, array{active:int, expired:int}> produit → actif, cartons en stock périmés à la fin du jour $day */
    public function productState(string $day): array
    {
        if (!$this->hasTable('carton_stock') || !$this->hasTable('final_product')) return [];
        $out = [];
        foreach ($this->all('SELECT id, active FROM final_product') as $r) $out[(int) $r['id']] = ['active' => (int) $r['active'], 'expired' => 0];
        foreach ($this->all("SELECT id_product, COUNT(*) AS n FROM carton_stock
                WHERE date_add <= ? AND NOT (status = 3 AND date_pallet <= ?) AND expiration_date < ? AND expiration_date > '2000-01-01'
                GROUP BY id_product", [$day, $day . ' 23:59:59', $day]) as $r) {
            $out[(int) $r['id_product']] = ['active' => $out[(int) $r['id_product']]['active'] ?? 0, 'expired' => (int) $r['n']];
        }
        return $out;
    }

    /**
     * Surowce à la fin du jour $day (règle de Zaopatrzenie bornée) : stock, base (count | receipts), date de l'inwentaryzacja,
     * nom, unité, prix à la date et son origine. Seuls les surowce avec une inwentaryzacja ou un przyjęcie jusqu'à $day.
     * @return array<int, array> null : tables absentes
     */
    public function materialsAt(string $day): ?array
    {
        if (!$this->sources()['materials']) return null;
        $counts = [];
        if ($this->hasTable('n_mp_count')) {
            foreach ($this->all('SELECT id_raw_material, count_date, quantity FROM n_mp_count WHERE count_date <= ? ORDER BY id_raw_material, count_date, id', [$day]) as $r) {
                $counts[(int) $r['id_raw_material']] = ['date' => (string) $r['count_date'], 'qty' => (float) $r['quantity']];
            }
        }
        $in = [];
        if ($this->hasTable('n_po_receipt') && $this->hasTable('n_po_line')) {
            foreach ($this->all('SELECT l.id_raw_material, r.received_on, SUM(r.quantity) AS qty FROM n_po_receipt r JOIN n_po_line l ON l.id = r.id_po_line
                    WHERE r.received_on <= ? GROUP BY l.id_raw_material, r.received_on', [$day]) as $r) {
                $id = (int) $r['id_raw_material'];
                if (isset($counts[$id]) && (string) $r['received_on'] <= $counts[$id]['date']) continue;
                $in[$id] = ($in[$id] ?? 0.0) + (float) $r['qty'];
            }
        }
        $out = [];
        if ($counts && $this->sources()['consumption']) {
            // Même calcul que SupplyRepository::consumedSinceCount, borné à $day : cartons produits après l'inwentaryzacja jusqu'à $day.
            foreach ($this->all('SELECT fri.material_id, SUM(c.n * fri.quantity * ' . MaterialLoss::factorSql('rm') . ') AS qty
                    FROM (SELECT id_raw_material, MAX(count_date) AS d FROM n_mp_count WHERE count_date <= ? GROUP BY id_raw_material) lc
                    JOIN flattened_recipe_ingredient fri ON fri.material_id = lc.id_raw_material
                    JOIN raw_material rm ON rm.id = fri.material_id
                    JOIN final_product fp ON fp.id_recipe = fri.recipe_id
                    JOIN (SELECT id_product, date_add, COUNT(*) AS n FROM carton_stock WHERE date_add <= ? GROUP BY id_product, date_add) c
                         ON c.id_product = fp.id AND c.date_add > lc.d
                    GROUP BY fri.material_id', [$day, $day]) as $r) $out[(int) $r['material_id']] = (float) $r['qty'];
        }
        $ids = array_values(array_unique(array_merge(array_keys($counts), array_keys($in))));
        if (!$ids) return [];
        $hist = [];
        if ($this->hasTable('raw_material_price_changelog')) {
            foreach ($this->all('SELECT c.id_raw_material, c.pack_price, c.package_size_in_base_unit FROM raw_material_price_changelog c
                    WHERE c.timestamp <= ? AND c.id_raw_material IN (' . implode(',', $ids) . ') ORDER BY c.id_raw_material, c.timestamp, c.id', [$day . ' 23:59:59']) as $r) {
                $size = (float) $r['package_size_in_base_unit'];
                if ($size > 0 && (float) $r['pack_price'] > 0) $hist[(int) $r['id_raw_material']] = (float) $r['pack_price'] / $size;
            }
        }
        $unit = $this->hasTable('unit') ? '(SELECT u.name FROM unit u WHERE u.id = rm.id_unit)' : 'NULL';
        $rows = [];
        foreach ($this->all("SELECT rm.id, rm.name, rm.price_per_unit, {$unit} AS unit FROM raw_material rm WHERE rm.id IN (" . implode(',', $ids) . ')') as $m) {
            $id = (int) $m['id'];
            $c = $counts[$id] ?? null;
            $qty = $c ? $c['qty'] + ($in[$id] ?? 0.0) - ($out[$id] ?? 0.0) : ($in[$id] ?? 0.0);
            $current = $m['price_per_unit'] !== null && (float) $m['price_per_unit'] > 0 ? (float) $m['price_per_unit'] : null;
            $rows[$id] = ['id' => $id, 'name' => (string) $m['name'], 'unit' => $m['unit'] !== null ? (string) $m['unit'] : null, 'quantity' => round($qty, 3),
                'basis' => $c ? 'count' : 'receipts', 'count_date' => $c['date'] ?? null,
                'price' => $hist[$id] ?? $current, 'price_source' => isset($hist[$id]) ? 'history' : ($current !== null ? 'current' : null)];
        }
        return $rows;
    }

    // --- Instantané de l'assistant --------------------------------------------------------------------------------

    public function statement(string $monthStart): ?array
    {
        return $this->all('SELECT s.*, COALESCE(NULLIF(TRIM(u.displayed_name), \'\'), u.login) AS taken_by_name FROM n_stock_month_statement s LEFT JOIN user u ON u.id = s.taken_by
            WHERE s.month_start = ?', [$monthStart])[0] ?? null;
    }

    /** @return list<array> lignes de l'instantané (produits puis surowce, par nom) */
    public function items(string $monthStart): array
    {
        return $this->all("SELECT * FROM n_stock_month_item WHERE month_start = ? ORDER BY kind = 'material', name, item_id", [$monthStart]);
    }

    /** Remplace l'instantané du mois (en-tête et lignes), en une transaction. */
    public function saveSnapshot(string $monthStart, array $head, array $items, ?int $userId): void
    {
        $own = !$this->db->inTransaction();
        if ($own) $this->db->beginTransaction();
        try {
            $this->writeSnapshot($monthStart, $head, $items, $userId);
            if ($own) $this->db->commit();
        } catch (\Throwable $e) {
            if ($own) $this->db->rollBack();
            throw $e;
        }
    }

    private function writeSnapshot(string $monthStart, array $head, array $items, ?int $userId): void
    {
        $this->db->prepare('DELETE FROM n_stock_month_item WHERE month_start = ?')->execute([$monthStart]);
        $ins = $this->db->prepare('INSERT INTO n_stock_month_item (month_start, kind, item_id, name, unit, quantity, opening, unit_price, price_source, value, opening_value, basis, count_date, flags, detail)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        foreach ($items as $i) {
            $ins->execute([$monthStart, $i['kind'], $i['item_id'], mb_substr($i['name'], 0, 160), $i['unit'], $i['quantity'], $i['opening'], $i['unit_price'], $i['price_source'],
                $i['value'], $i['opening_value'], $i['basis'], $i['count_date'], mb_substr(implode(',', $i['flags']), 0, 255), $i['detail'] !== null ? mb_substr($i['detail'], 0, 255) : null]);
        }
        $this->db->prepare('INSERT INTO n_stock_month_statement (month_start, taken_at, taken_by, products_value, materials_value, products_rows, materials_rows, note)
            VALUES (?, NOW(), ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE taken_at = NOW(), taken_by = VALUES(taken_by), products_value = VALUES(products_value),
            materials_value = VALUES(materials_value), products_rows = VALUES(products_rows), materials_rows = VALUES(materials_rows), note = VALUES(note)')
            ->execute([$monthStart, $userId, $head['products_value'], $head['materials_value'], $head['products_rows'], $head['materials_rows'], $head['note']]);
    }

    /** @return array<string, array> élément → décision du mois examiné */
    public function decisions(string $monthStart): array
    {
        $out = [];
        foreach ($this->all('SELECT r.*, COALESCE(NULLIF(TRIM(u.displayed_name), \'\'), u.login) AS decided_by
                FROM n_stock_month_review r LEFT JOIN user u ON u.id = r.id_user WHERE r.month_start = ?', [$monthStart]) as $r) {
            $out[(string) $r['item_key']] = $r;
        }
        return $out;
    }

    /** Une décision par (mois examiné, élément) : la dernière enregistrée remplace la précédente. */
    public function saveDecision(string $monthStart, string $key, string $decision, ?string $note, string $flags, ?int $userId): void
    {
        $this->db->prepare('INSERT INTO n_stock_month_review (month_start, item_key, decision, note, flags, id_user) VALUES (?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE decision = VALUES(decision), note = VALUES(note), flags = VALUES(flags), id_user = VALUES(id_user), updated_at = NOW()')
            ->execute([$monthStart, $key, $decision, $note, mb_substr($flags, 0, 255), $userId]);
    }
}
