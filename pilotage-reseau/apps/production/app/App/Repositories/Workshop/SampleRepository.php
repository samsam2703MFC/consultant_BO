<?php
declare(strict_types=1);

namespace App\Repositories\Workshop;

/**
 * [352] Próbki produkcji (makieta 68 · 3) : reguły wg kategorii produktu, partie dnia do próbek, próbki (QR).
 */
final class SampleRepository extends WorkshopRepository
{
    protected string $table = 'n_prod_sample';
    protected array $writable = ['id_output', 'id_item', 'sample_date', 'keep_until', 'code', 'reason', 'status', 'location', 'printed_at',
        'stored_at', 'stored_by', 'created_by'];

    /** Kategorie produktów z regułą (NULL = domyślna). */
    public function rules(): array
    {
        return $this->all('SELECT c.id, c.name, r.frequency FROM category_product c LEFT JOIN n_sample_rule r ON r.id_category = c.id ORDER BY c.name');
    }

    public function setRule(int $categoryId, ?string $frequency, ?int $userId): void
    {
        if ($frequency === null) {
            $this->db->prepare('DELETE FROM n_sample_rule WHERE id_category = ?')->execute([$categoryId]);
            return;
        }
        $this->db->prepare('INSERT INTO n_sample_rule (id_category, frequency, updated_by) VALUES (?, ?, ?)
                            ON DUPLICATE KEY UPDATE frequency = VALUES(frequency), updated_by = VALUES(updated_by)')->execute([$categoryId, $frequency, $userId]);
    }

    public function categoryExists(int $id): bool
    {
        return (bool) $this->all('SELECT 1 AS x FROM category_product WHERE id = ?', [$id]);
    }

    /** Partie wyrobów gotowych dnia (bez anulowanych), z kategorią, trwałością karty, grupą, operatorem i próbką. */
    public function batches(string $day): array
    {
        return $this->all("SELECT o.id, o.lot_code, o.work_date, o.quantity, o.id_item, o.created_at, fp.id AS product_id, fp.name, fp.id_category,
                    c.name AS category, fp.shelf_life, g.name_pl AS group_name, TRIM(CONCAT(pe.name, ' ', pe.surname)) AS employee,
                    s.id AS sample_id, s.status AS sample_status, s.code AS sample_code
             FROM n_prod_output o
             JOIN n_prod_item i ON i.id = o.id_item AND i.item_type = 'product'
             JOIN final_product fp ON fp.id = i.id_final_product
             LEFT JOIN category_product c ON c.id = fp.id_category
             LEFT JOIN n_prod_group g ON g.id = o.id_group
             LEFT JOIN n_prod_employee e ON e.id = o.id_employee
             LEFT JOIN production_employee pe ON pe.id = e.id_production_employee
             LEFT JOIN n_prod_sample s ON s.id_output = o.id
             WHERE o.work_date = ? AND o.cancelled_at IS NULL
             ORDER BY o.created_at, o.id", [$day]);
    }

    /** Próbki w oknie (bez dnia $exceptDay) : produkt (karta) => liczba. @return array<int, int> */
    public function sampledItems(string $from, string $to, string $exceptDay): array
    {
        $out = [];
        foreach ($this->all('SELECT id_item, COUNT(*) AS n FROM n_prod_sample WHERE sample_date BETWEEN ? AND ? AND sample_date <> ? GROUP BY id_item', [$from, $to, $exceptDay]) as $r) {
            $out[(int) $r['id_item']] = (int) $r['n'];
        }
        return $out;
    }

    /** Próbki dnia (wydruk i potwierdzenie) z produktem, grupą i partiami surowców. */
    public function forDay(string $day): array
    {
        return $this->all("SELECT s.*, o.lot_code, o.work_date, fp.name, g.name_pl AS group_name, TRIM(CONCAT(pe.name, ' ', pe.surname)) AS employee,
                    (SELECT GROUP_CONCAT(CONCAT(rm.name, ' ', COALESCE(NULLIF(l.lot_number, ''), CONCAT('(brak : ', l.missing_reason, ')'), '—')) ORDER BY rm.name SEPARATOR ' · ')
                       FROM n_prod_output_lot l JOIN raw_material rm ON rm.id = l.id_material WHERE l.id_output = o.id) AS lots
             FROM n_prod_sample s
             JOIN n_prod_output o ON o.id = s.id_output
             JOIN n_prod_item i ON i.id = s.id_item
             JOIN final_product fp ON fp.id = i.id_final_product
             LEFT JOIN n_prod_group g ON g.id = o.id_group
             LEFT JOIN n_prod_employee e ON e.id = o.id_employee
             LEFT JOIN production_employee pe ON pe.id = e.id_production_employee
             WHERE s.sample_date = ? ORDER BY o.created_at, s.id", [$day]);
    }

    /** Partie surowców partii produkcji (arkusz próbki) : surowiec, partia, DLC, powód braku. @return array<int, list<array>> */
    public function lotsFor(array $outputIds): array
    {
        $ids = array_values(array_filter(array_map('intval', $outputIds)));
        if (!$ids) return [];
        $out = [];
        foreach ($this->all('SELECT l.id_output, rm.name, l.lot_number, l.best_before, l.missing_reason
                             FROM n_prod_output_lot l JOIN raw_material rm ON rm.id = l.id_material
                             WHERE l.id_output IN (' . implode(',', $ids) . ') ORDER BY rm.name') as $r) $out[(int) $r['id_output']][] = $r;
        return $out;
    }

    public function byCode(string $code): ?array
    {
        return $this->all('SELECT * FROM n_prod_sample WHERE code = ?', [$code])[0] ?? null;
    }

    public function codeExists(string $code): bool
    {
        return (bool) $this->all('SELECT 1 AS x FROM n_prod_sample WHERE code = ?', [$code]);
    }

    public function deleteSample(int $id): void
    {
        $this->db->prepare("DELETE FROM n_prod_sample WHERE id = ? AND status <> 'stored'")->execute([$id]);
    }

    public function markPrinted(array $ids): void
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (!$ids) return;
        $this->db->exec("UPDATE n_prod_sample SET printed_at = COALESCE(printed_at, NOW()), status = IF(status = 'planned', 'printed', status)
                         WHERE id IN (" . implode(',', $ids) . ')');
    }

    public function markDone(string $code, string $day, string $summary, ?int $userId): void
    {
        $this->db->prepare('INSERT INTO n_wizard_done (code, day, summary, done_by, done_at) VALUES (?, ?, ?, ?, NOW())
                            ON DUPLICATE KEY UPDATE summary = VALUES(summary), done_by = VALUES(done_by), done_at = VALUES(done_at)')
            ->execute([$code, $day, mb_substr($summary, 0, 255), $userId]);
    }

    public function wizardDone(string $code, string $day): ?array
    {
        return $this->all('SELECT summary, done_at FROM n_wizard_done WHERE code = ? AND day = ?', [$code, $day])[0] ?? null;
    }
}
