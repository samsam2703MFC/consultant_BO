<?php
declare(strict_types=1);

namespace App\Repositories\Purchasing;

use App\Repositories\Workshop\WorkshopRepository;

/**
 * Koszty surowców i oszczędności [247] (makieta 22) : ostatnie ceny innych dostawców (dziennik cen), dostawcy surowca
 * (z e-mailem — do zapytania ofertowego), decyzje miesiąca (n_cost_review).
 */
final class CostReviewRepository extends WorkshopRepository
{
    protected string $table = 'n_cost_review';

    /** @return array<int, array<int, array{price:float, at:string, name:string}>> surowiec => dostawca => ostatnia cena (zł / jednostka bazowa) od $since */
    public function lastPrices(string $since): array
    {
        $out = [];
        foreach ($this->all("SELECT c.id_raw_material, c.id_supplier, TRIM(s.name) AS name, c.pack_price / NULLIF(c.package_size_in_base_unit, 0) AS price, c.timestamp
                             FROM raw_material_price_changelog c JOIN supplier s ON s.id = c.id_supplier
                             WHERE c.timestamp >= ? AND c.pack_price > 0 ORDER BY c.timestamp", [$since]) as $r) {
            if ($r['price'] === null) continue;
            $out[(int) $r['id_raw_material']][(int) $r['id_supplier']] = ['price' => (float) $r['price'], 'at' => substr((string) $r['timestamp'], 0, 10), 'name' => (string) $r['name']];
        }
        return $out;
    }

    /** @return array<int, list<int>> surowiec => dostawcy (lista dostawców surowca) */
    public function materialSuppliers(array $materialIds): array
    {
        if (!$materialIds) return [];
        $in = implode(',', array_fill(0, count($materialIds), '?'));
        $out = [];
        foreach ($this->all("SELECT id_raw_material, id_supplier FROM n_material_supplier WHERE id_raw_material IN ($in) ORDER BY sort", array_values($materialIds)) as $r) {
            $out[(int) $r['id_raw_material']][] = (int) $r['id_supplier'];
        }
        return $out;
    }

    /** @return array<int, string> dostawca => nazwa (tylko aktywni z poprawnym e-mailem) */
    public function mailable(array $supplierIds): array
    {
        if (!$supplierIds) return [];
        $in = implode(',', array_fill(0, count($supplierIds), '?'));
        $out = [];
        foreach ($this->all("SELECT id, TRIM(name) AS name, email FROM supplier WHERE is_active = 1 AND id IN ($in)", array_values($supplierIds)) as $r) {
            if (filter_var(trim((string) $r['email']), FILTER_VALIDATE_EMAIL)) $out[(int) $r['id']] = (string) $r['name'];
        }
        return $out;
    }

    /** @return array<int, array> decyzje miesiąca (surowiec => wiersz) */
    public function decisions(string $month): array
    {
        $out = [];
        foreach ($this->all('SELECT * FROM n_cost_review WHERE month = ?', [$month]) as $r) $out[(int) $r['id_raw_material']] = $r;
        return $out;
    }

    public function saveDecision(string $month, int $materialId, array $d, ?int $userId): void
    {
        $this->db->prepare('INSERT INTO n_cost_review (month, id_raw_material, decision, id_supplier_from, id_supplier_to, price_from, price_to, saving_month, id_offer, id_tender, decided_by, decided_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE decision = VALUES(decision), id_supplier_from = VALUES(id_supplier_from), id_supplier_to = VALUES(id_supplier_to), price_from = VALUES(price_from),
                price_to = VALUES(price_to), saving_month = VALUES(saving_month), id_offer = VALUES(id_offer), id_tender = VALUES(id_tender), decided_by = VALUES(decided_by), decided_at = NOW()')
            ->execute([$month, $materialId, $d['decision'], $d['from'] ?? null, $d['to'] ?? null, $d['price_from'] ?? null, $d['price_to'] ?? null, $d['saving'] ?? null,
                $d['offer'] ?? null, $d['tender'] ?? null, $userId]);
    }

    public function tenderCode(int $id): ?string
    {
        return $this->all('SELECT code FROM n_tender WHERE id = ?', [$id])[0]['code'] ?? null;
    }
}
