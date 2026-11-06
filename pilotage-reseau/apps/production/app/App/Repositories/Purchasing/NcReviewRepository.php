<?php
declare(strict_types=1);

namespace App\Repositories\Purchasing;

use App\Repositories\Workshop\WorkshopRepository;

/**
 * Niezgodności miesiąca [247] (makieta 21) : NC miesiąca i otwarte starsze (z dostawcą, surowcem, produktem, grupą,
 * osobą, zdjęciami), NC dostaw na dostawcę w 3 miesiącach, ostatnia ocena A / B / C, audyty dostawców kwartału.
 */
final class NcReviewRepository extends WorkshopRepository
{
    protected string $table = 'n_nc';

    /** NC zgłoszone w [from, to) + wcześniejsze wciąż otwarte. */
    public function ncs(string $from, string $to): array
    {
        return $this->all(
            "SELECT n.id, n.number, n.reported_at, n.source, n.id_supplier, n.id_raw_material, n.id_final_product, n.id_prod_group, n.description, n.severity,
                    n.quantity, n.unit, n.immediate_action, n.root_cause, n.cause_cat, n.corrective_action, n.cost_impact, n.status, n.owner_user, n.due_date, n.closed_at,
                    TRIM(s.name) AS supplier_name, s.email AS supplier_email, m.name AS material_name, fp.name AS product_name, g.name_pl AS group_name,
                    COALESCE(NULLIF(u.displayed_name, ''), u.login) AS owner_name, (SELECT COUNT(*) FROM n_nc_photo p WHERE p.id_nc = n.id) AS photos,
                    (SELECT MIN(p.id) FROM n_nc_photo p WHERE p.id_nc = n.id) AS photo_id
             FROM n_nc n LEFT JOIN supplier s ON s.id = n.id_supplier LEFT JOIN raw_material m ON m.id = n.id_raw_material
             LEFT JOIN final_product fp ON fp.id = n.id_final_product LEFT JOIN n_prod_group g ON g.id = n.id_prod_group LEFT JOIN user u ON u.id = n.owner_user
             WHERE (n.reported_at >= ? AND n.reported_at < ?) OR (n.reported_at < ? AND n.status <> 'closed')
             ORDER BY n.reported_at DESC, n.id DESC", [$from, $to, $from]);
    }

    /** @return array<int, array{nc:int, cost:float, name:string, email:?string, lang:?string}> NC dostaw na dostawcę w [from, to) */
    public function supplierNcs(string $from, string $to): array
    {
        $out = [];
        foreach ($this->all("SELECT n.id_supplier, COUNT(*) AS nc, COALESCE(SUM(n.cost_impact), 0) AS cost, TRIM(s.name) AS name, s.email, s.order_lang
                             FROM n_nc n JOIN supplier s ON s.id = n.id_supplier
                             WHERE n.source = 'supplier_delivery' AND n.reported_at >= ? AND n.reported_at < ? GROUP BY n.id_supplier, s.name, s.email, s.order_lang", [$from, $to]) as $r) {
            $out[(int) $r['id_supplier']] = ['nc' => (int) $r['nc'], 'cost' => (float) $r['cost'], 'name' => (string) $r['name'], 'email' => $r['email'], 'lang' => $r['order_lang']];
        }
        return $out;
    }

    /** @return array<int, array{grade:?string, quarter:string, decision:?string}> ostatnia ocena dostawcy */
    public function lastGrades(): array
    {
        $out = [];
        foreach ($this->all('SELECT r.id_supplier, r.grade, r.quarter, r.decision FROM n_supplier_review r
                             JOIN (SELECT id_supplier, MAX(quarter) AS q FROM n_supplier_review GROUP BY id_supplier) l ON l.id_supplier = r.id_supplier AND l.q = r.quarter') as $r) {
            $out[(int) $r['id_supplier']] = ['grade' => $r['grade'], 'quarter' => $r['quarter'], 'decision' => $r['decision']];
        }
        return $out;
    }

    /** @return array<int, array> audyty kwartału (dostawca => wiersz) */
    public function audits(string $quarter): array
    {
        $out = [];
        foreach ($this->all('SELECT * FROM n_supplier_audit WHERE quarter = ?', [$quarter]) as $r) $out[(int) $r['id_supplier']] = $r;
        return $out;
    }

    public function saveAudit(string $quarter, int $supplierId, array $a, ?int $userId): void
    {
        $this->db->prepare('INSERT INTO n_supplier_audit (quarter, id_supplier, reason, planned_on, done_on, result, note, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE reason = COALESCE(VALUES(reason), reason), planned_on = VALUES(planned_on), done_on = VALUES(done_on), result = VALUES(result), note = VALUES(note)')
            ->execute([$quarter, $supplierId, $a['reason'] ?? null, $a['planned_on'] ?? null, $a['done_on'] ?? null, $a['result'] ?? null, $a['note'] ?? null, $userId]);
    }

    public function setCause(int $id, ?string $cat): void
    {
        $this->db->prepare('UPDATE n_nc SET cause_cat = ? WHERE id = ?')->execute([$cat, $id]);
    }

    /** @return list<array{mime:string, content:string}> zdjęcia NC (najwyżej $limit) */
    public function photos(array $ncIds, int $limit = 6): array
    {
        if (!$ncIds) return [];
        $in = implode(',', array_fill(0, count($ncIds), '?'));
        return $this->all("SELECT id_nc, mime, content FROM n_nc_photo WHERE id_nc IN ($in) ORDER BY id LIMIT " . max(1, $limit), array_values($ncIds));
    }

    public function supplier(int $id): ?array
    {
        return $this->all('SELECT id, TRIM(name) AS name, email, order_lang FROM supplier WHERE id = ?', [$id])[0] ?? null;
    }
}
