<?php
declare(strict_types=1);

namespace App\Repositories\Purchasing;

use App\Repositories\Workshop\WorkshopRepository;

/**
 * Ocena dostawców co kwartał [230] : dane kwartału [from, to) — przyjęcia z zamówień (wartość, dostawy), faktury
 * zakupowe (wartość), terminowość (pierwsze przyjęcie ≤ termin dostawy zamówienia), niezgodności dostaw, ceny
 * (ostatnia cena dostawcy na początek i koniec kwartału), dostawcy surowca ; zapis ocen i zakończenia kreatora.
 */
final class SupplierReviewRepository extends WorkshopRepository
{
    protected string $table = 'n_supplier_review';

    /** @return array<int, array{value:float, deliveries:int}> dostawca => przyjęcia */
    public function receipts(string $from, string $to): array
    {
        $out = [];
        foreach ($this->all(
            "SELECT p.id_supplier, SUM(r.quantity * COALESCE(r.unit_price, l.unit_price, 0)) AS value, COUNT(DISTINCT p.id, r.received_on) AS deliveries
             FROM n_po_receipt r JOIN n_po_line l ON l.id = r.id_po_line JOIN n_po p ON p.id = l.id_po
             WHERE r.received_on >= ? AND r.received_on < ? GROUP BY p.id_supplier", [$from, $to]) as $r) {
            $out[(int) $r['id_supplier']] = ['value' => (float) $r['value'], 'deliveries' => (int) $r['deliveries']];
        }
        return $out;
    }

    /** @return array<int, array{value:float, count:int}> dostawca => faktury towarowe (bez odrzuconych) */
    public function invoices(string $from, string $to): array
    {
        $out = [];
        foreach ($this->all(
            "SELECT id_supplier, SUM(total_net) AS value, COUNT(*) AS n FROM n_inv
             WHERE kind = 'goods' AND status <> 'rejected' AND id_supplier IS NOT NULL AND issue_date >= ? AND issue_date < ? GROUP BY id_supplier", [$from, $to]) as $r) {
            $out[(int) $r['id_supplier']] = ['value' => (float) $r['value'], 'count' => (int) $r['n']];
        }
        return $out;
    }

    /** @return array<int, array{orders:int, on_time:int}> zamówienia z terminem w kwartale : na czas = pierwsze przyjęcie ≤ termin */
    public function onTime(string $from, string $to): array
    {
        $out = [];
        foreach ($this->all(
            "SELECT p.id_supplier, COUNT(*) AS n, SUM(fr.first_on IS NOT NULL AND fr.first_on <= p.delivery_date) AS ok
             FROM n_po p
             LEFT JOIN (SELECT l.id_po, MIN(r.received_on) AS first_on FROM n_po_receipt r JOIN n_po_line l ON l.id = r.id_po_line GROUP BY l.id_po) fr ON fr.id_po = p.id
             WHERE p.status IN ('sent', 'partial', 'received') AND p.delivery_date >= ? AND p.delivery_date < ? GROUP BY p.id_supplier", [$from, $to]) as $r) {
            $out[(int) $r['id_supplier']] = ['orders' => (int) $r['n'], 'on_time' => (int) $r['ok']];
        }
        return $out;
    }

    /** @return array<int, int> dostawca => niezgodności dostaw w kwartale */
    public function ncs(string $from, string $to): array
    {
        $out = [];
        foreach ($this->all("SELECT id_supplier, COUNT(*) AS n FROM n_nc WHERE source = 'supplier_delivery' AND id_supplier IS NOT NULL AND reported_at >= ? AND reported_at < ? GROUP BY id_supplier", [$from, $to]) as $r) {
            $out[(int) $r['id_supplier']] = (int) $r['n'];
        }
        return $out;
    }

    /** Ostatnia cena jednostkowa (cena opakowania ÷ wielkość) dostawcy i surowca przed datą. @return array<string, float> "dostawca:surowiec" => cena */
    public function pricesBefore(string $at): array
    {
        $out = [];
        foreach ($this->all(
            "SELECT c.id_supplier, c.id_raw_material, c.pack_price / NULLIF(c.package_size_in_base_unit, 0) AS price
             FROM raw_material_price_changelog c
             JOIN (SELECT id_supplier, id_raw_material, MAX(timestamp) AS t FROM raw_material_price_changelog WHERE timestamp < ? GROUP BY id_supplier, id_raw_material) m
               ON m.id_supplier = c.id_supplier AND m.id_raw_material = c.id_raw_material AND m.t = c.timestamp
             ORDER BY c.id", [$at]) as $r) {
            if ($r['price'] !== null && (float) $r['price'] > 0) $out[(int) $r['id_supplier'] . ':' . (int) $r['id_raw_material']] = (float) $r['price'];
        }
        return $out;
    }

    /** Dostawcy z nową ceną w kwartale. @return int[] */
    public function pricedIn(string $from, string $to): array
    {
        return array_map('intval', array_column($this->all('SELECT DISTINCT id_supplier FROM raw_material_price_changelog WHERE timestamp >= ? AND timestamp < ?', [$from, $to]), 'id_supplier'));
    }

    /** @return array<int, float> surowiec => wartość zakupów w kwartale (przyjęcia albo pozycje faktur — większa) */
    public function materialValues(string $from, string $to): array
    {
        $rec = [];
        foreach ($this->all(
            "SELECT l.id_raw_material, SUM(r.quantity * COALESCE(r.unit_price, l.unit_price, 0)) AS v FROM n_po_receipt r JOIN n_po_line l ON l.id = r.id_po_line
             WHERE r.received_on >= ? AND r.received_on < ? GROUP BY l.id_raw_material", [$from, $to]) as $r) $rec[(int) $r['id_raw_material']] = (float) $r['v'];
        $inv = [];
        foreach ($this->all(
            "SELECT l.id_raw_material, SUM(l.net_value) AS v FROM n_inv_line l JOIN n_inv i ON i.id = l.id_inv
             WHERE i.kind = 'goods' AND i.status <> 'rejected' AND l.id_raw_material IS NOT NULL AND i.issue_date >= ? AND i.issue_date < ? GROUP BY l.id_raw_material", [$from, $to]) as $r) $inv[(int) $r['id_raw_material']] = (float) $r['v'];
        $out = [];
        foreach ($rec + $inv as $id => $_) $out[$id] = max($rec[$id] ?? 0.0, $inv[$id] ?? 0.0);
        return $out;
    }

    /** Dostawcy (aktywni albo z danymi) z adresem e-mail. */
    public function suppliers(): array
    {
        $out = [];
        foreach ($this->all('SELECT id, name, email, is_active FROM supplier ORDER BY name, id') as $r) $out[(int) $r['id']] = $r;
        return $out;
    }

    /** Surowiec ↔ dostawcy (lista dostawców pozycji + bieżący dostawca). @return array<int, int[]> surowiec => dostawcy */
    public function materialSuppliers(): array
    {
        $out = [];
        foreach ($this->all(
            'SELECT id_raw_material, id_supplier FROM n_material_supplier
             UNION SELECT id, id_current_supplier FROM raw_material WHERE id_current_supplier IS NOT NULL') as $r) {
            $out[(int) $r['id_raw_material']][(int) $r['id_supplier']] = true;
        }
        return array_map(fn($s) => array_keys($s), $out);
    }

    /** Surowce : nazwa, kategoria, bieżący dostawca. @return array<int, array> */
    public function materials(): array
    {
        $out = [];
        foreach ($this->all('SELECT rm.id, rm.name, rm.id_category, rm.id_current_supplier, cm.name AS category, COALESCE(cm.id_group, 0) = 1 AS packaging
                             FROM raw_material rm LEFT JOIN category_material cm ON cm.id = rm.id_category') as $r) $out[(int) $r['id']] = $r;
        return $out;
    }

    /** @return array<int, array> dostawca => zapisana ocena kwartału */
    public function reviews(string $quarter): array
    {
        $out = [];
        foreach ($this->all('SELECT * FROM n_supplier_review WHERE quarter = ?', [$quarter]) as $r) $out[(int) $r['id_supplier']] = $r;
        return $out;
    }

    public function saveReview(string $quarter, int $supplierId, ?float $score, ?string $grade, string $decision, array $metrics, ?string $note, ?int $userId): void
    {
        $this->db->prepare(
            'INSERT INTO n_supplier_review (quarter, id_supplier, score, grade, decision, metrics, note, reviewed_by, reviewed_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE score = VALUES(score), grade = VALUES(grade), decision = VALUES(decision), metrics = VALUES(metrics), note = VALUES(note),
                                     reviewed_by = VALUES(reviewed_by), reviewed_at = VALUES(reviewed_at)'
        )->execute([$quarter, $supplierId, $score, $grade, $decision, mb_substr(json_encode($metrics, JSON_UNESCAPED_UNICODE), 0, 1000), $note, $userId]);
    }

    public function tenderCode(int $id): ?string
    {
        $v = $this->all('SELECT code FROM n_tender WHERE id = ?', [$id])[0]['code'] ?? null;
        return $v === null ? null : (string) $v;
    }

    public function done(string $code, string $from, string $to): ?array
    {
        return $this->all('SELECT summary, day, done_at FROM n_wizard_done WHERE code = ? AND day >= ? AND day < ? ORDER BY day DESC LIMIT 1', [$code, $from, $to])[0] ?? null;
    }

    public function markDone(string $code, string $day, string $summary, ?int $userId): void
    {
        $this->db->prepare(
            'INSERT INTO n_wizard_done (code, day, summary, done_by, done_at) VALUES (?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE summary = VALUES(summary), done_by = VALUES(done_by), done_at = VALUES(done_at)'
        )->execute([$code, $day, mb_substr($summary, 0, 255), $userId]);
    }
}
