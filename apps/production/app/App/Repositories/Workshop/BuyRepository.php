<?php
declare(strict_types=1);

namespace App\Repositories\Workshop;

use App\Repositories\Analysis\WdtSalesRepository;

/**
 * Zakupy tygodnia : dostawcy pozycji (n_material_supplier), zakupy tygodnia (n_buy_round, n_buy_line), zapytania do dostawców
 * (n_buy_request, n_buy_request_line), oferty (n_mp_offer, source = weekly), decyzje i oszczędności (n_buy_order).
 */
final class BuyRepository extends WorkshopRepository
{
    protected string $table = 'n_buy_round';

    // --- Dostawcy pozycji ------------------------------------------------------------------------------------------

    /** @return list<array> dostawcy pozycji (kolejność : sort, potem nazwa) */
    public function materialSuppliers(?array $materialIds = null): array
    {
        [$in, $params] = $this->in('ms.id_raw_material', $materialIds);
        return $this->all("SELECT ms.id_raw_material, ms.id_supplier, ms.sort, ms.source, TRIM(s.name) AS name, s.email, s.order_lang, s.is_active
                           FROM n_material_supplier ms JOIN supplier s ON s.id = ms.id_supplier
                           WHERE 1 = 1{$in} ORDER BY ms.id_raw_material, ms.sort, s.name", $params);
    }

    public function replaceMaterialSuppliers(int $materialId, array $supplierIds, string $source, ?int $userId): void
    {
        $this->db->prepare('DELETE FROM n_material_supplier WHERE id_raw_material = ?')->execute([$materialId]);
        $ins = $this->db->prepare('INSERT INTO n_material_supplier (id_raw_material, id_supplier, sort, source, created_by) VALUES (?, ?, ?, ?, ?)');
        foreach (array_values($supplierIds) as $i => $sid) $ins->execute([$materialId, (int) $sid, $i, $source, $userId]);
    }

    /** Dopisuje dostawcę do pozycji, gdy ma mniej niż $max dostawców. */
    public function addMaterialSupplier(int $materialId, int $supplierId, string $source, ?int $userId, int $max): bool
    {
        $rows = $this->all('SELECT id_supplier, sort FROM n_material_supplier WHERE id_raw_material = ?', [$materialId]);
        if (in_array($supplierId, array_map('intval', array_column($rows, 'id_supplier')), true) || count($rows) >= $max) return false;
        $sort = $rows ? max(array_map('intval', array_column($rows, 'sort'))) + 1 : 0;
        $this->db->prepare('INSERT INTO n_material_supplier (id_raw_material, id_supplier, sort, source, created_by) VALUES (?, ?, ?, ?, ?)')
            ->execute([$materialId, $supplierId, $sort, $source, $userId]);
        return true;
    }

    public function suppliers(): array
    {
        return $this->all('SELECT id, TRIM(name) AS name, email, order_lang, delivery_weekdays FROM supplier WHERE is_active = 1 ORDER BY name');
    }

    /** @return array<int, array> dostawcy według id (także nieaktywni : historia) */
    public function suppliersById(array $ids): array
    {
        [$in, $params] = $this->in('id', $ids);
        $out = [];
        foreach ($this->all("SELECT id, TRIM(name) AS name, email, order_lang, delivery_weekdays, is_active FROM supplier WHERE 1 = 1{$in}", $params) as $s) $out[(int) $s['id']] = $s;
        return $out;
    }

    /** Kategoria surowca → dostawcy, którzy sprzedają inne surowce tej kategorii (propozycja nowego dostawcy). */
    public function categorySuppliers(): array
    {
        return $this->all('SELECT rm.id_category, ms.id_supplier, COUNT(*) AS n FROM n_material_supplier ms JOIN raw_material rm ON rm.id = ms.id_raw_material
                           JOIN supplier s ON s.id = ms.id_supplier AND s.is_active = 1 WHERE rm.id_category IS NOT NULL GROUP BY rm.id_category, ms.id_supplier');
    }

    public function materialCategories(array $materialIds): array
    {
        [$in, $params] = $this->in('id', $materialIds);
        return array_map('intval', array_column($this->all("SELECT id, COALESCE(id_category, 0) AS c FROM raw_material WHERE 1 = 1{$in}", $params), 'c', 'id'));
    }

    // --- Zakupy tygodnia -------------------------------------------------------------------------------------------

    public function round(int $id): ?array
    {
        return $this->all('SELECT r.*, COALESCE(NULLIF(u.displayed_name, \'\'), u.login) AS user_name FROM n_buy_round r LEFT JOIN user u ON u.id = r.id_user WHERE r.id = ?', [$id])[0] ?? null;
    }

    /** Ostatnie zakupy tygodnia (każdy status). */
    public function roundForWeek(string $weekStart): ?array
    {
        $id = $this->all('SELECT id FROM n_buy_round WHERE week_start = ? ORDER BY id DESC LIMIT 1', [$weekStart])[0]['id'] ?? null;
        return $id !== null ? $this->round((int) $id) : null;
    }

    public function openRounds(): array
    {
        return $this->all("SELECT id, code, week_start, status, reply_by, sent_at FROM n_buy_round WHERE status IN ('draft', 'sent', 'ordered') ORDER BY week_start DESC, id DESC LIMIT 20");
    }

    /** Postęp zakupów : pozycje, zamówione, oferty, zapytania, odpowiedzi. */
    public function progress(int $roundId): array
    {
        $row = $this->all("SELECT (SELECT COUNT(*) FROM n_buy_line WHERE id_round = ?) AS line_count, (SELECT COUNT(*) FROM n_buy_order WHERE id_round = ?) AS ordered,
                (SELECT COUNT(*) FROM n_mp_offer o JOIN n_buy_line l ON l.id = o.id_buy_line WHERE l.id_round = ? AND o.source = 'weekly') AS offers,
                (SELECT COUNT(*) FROM n_buy_request WHERE id_round = ?) AS requests, (SELECT COUNT(*) FROM n_buy_request WHERE id_round = ? AND responded_at IS NOT NULL) AS responded",
            array_fill(0, 5, $roundId))[0];
        return array_map('intval', $row);
    }

    public function insertRound(array $data): int
    {
        $cols = array_keys($data);
        $this->db->prepare('INSERT INTO n_buy_round (' . implode(', ', $cols) . ') VALUES (?' . str_repeat(', ?', count($cols) - 1) . ')')->execute(array_values($data));
        return (int) $this->db->lastInsertId();
    }

    public function updateRound(int $id, array $data): void
    {
        if (!$data) return;
        $this->db->prepare('UPDATE n_buy_round SET ' . implode(', ', array_map(fn($c) => "{$c} = ?", array_keys($data))) . ' WHERE id = ?')
            ->execute([...array_values($data), $id]);
    }

    public function deleteRound(int $id): void
    {
        $this->db->prepare('DELETE FROM n_mp_offer WHERE id_buy_line IN (SELECT id FROM n_buy_line WHERE id_round = ?)')->execute([$id]);
        $this->db->prepare('DELETE FROM n_buy_round WHERE id = ?')->execute([$id]);
    }

    /** ZT-RRRR-TT (tydzień ISO), kolejne zakupy tego samego tygodnia : -2, -3… */
    public function nextCode(string $weekStart): string
    {
        $base = 'ZT-' . date('o', strtotime($weekStart)) . '-' . date('W', strtotime($weekStart));
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM n_buy_round WHERE code = ? OR code LIKE ?');
        $stmt->execute([$base, $base . '-%']);
        $n = (int) $stmt->fetchColumn();
        return $n === 0 ? $base : $base . '-' . ($n + 1);
    }

    public function lines(int $roundId): array
    {
        return $this->all('SELECT * FROM n_buy_line WHERE id_round = ? ORDER BY id', [$roundId]);
    }

    public function insertLine(array $data): int
    {
        $cols = array_keys($data);
        $this->db->prepare('INSERT INTO n_buy_line (' . implode(', ', $cols) . ') VALUES (?' . str_repeat(', ?', count($cols) - 1) . ')')->execute(array_values($data));
        return (int) $this->db->lastInsertId();
    }

    public function deleteLines(int $roundId, array $keepIds): void
    {
        $keep = array_map('intval', $keepIds);
        $sql = 'DELETE FROM n_buy_line WHERE id_round = ?' . ($keep ? ' AND id NOT IN (' . implode(',', $keep) . ')' : '');
        $this->db->prepare($sql)->execute([$roundId]);
    }

    public function updateLine(int $id, array $data): void
    {
        $this->db->prepare('UPDATE n_buy_line SET ' . implode(', ', array_map(fn($c) => "{$c} = ?", array_keys($data))) . ' WHERE id = ?')
            ->execute([...array_values($data), $id]);
    }

    // --- Zapytania -------------------------------------------------------------------------------------------------

    public function requests(int $roundId): array
    {
        return $this->all('SELECT q.*, TRIM(s.name) AS supplier_name FROM n_buy_request q JOIN supplier s ON s.id = q.id_supplier WHERE q.id_round = ? ORDER BY s.name', [$roundId]);
    }

    /** @return array<int, list<int>> zapytanie → pozycje */
    public function requestLines(int $roundId): array
    {
        $out = [];
        foreach ($this->all('SELECT rl.id_request, rl.id_line FROM n_buy_request_line rl JOIN n_buy_request q ON q.id = rl.id_request WHERE q.id_round = ? ORDER BY rl.id_line', [$roundId]) as $r) {
            $out[(int) $r['id_request']][] = (int) $r['id_line'];
        }
        return $out;
    }

    public function insertRequest(array $data): int
    {
        $cols = array_keys($data);
        $this->db->prepare('INSERT INTO n_buy_request (' . implode(', ', $cols) . ') VALUES (?' . str_repeat(', ?', count($cols) - 1) . ')')->execute(array_values($data));
        return (int) $this->db->lastInsertId();
    }

    public function updateRequest(int $id, array $data): void
    {
        $this->db->prepare('UPDATE n_buy_request SET ' . implode(', ', array_map(fn($c) => "{$c} = ?", array_keys($data))) . ' WHERE id = ?')
            ->execute([...array_values($data), $id]);
    }

    public function deleteRequest(int $id): void
    {
        $this->db->prepare('DELETE FROM n_buy_request WHERE id = ?')->execute([$id]);
    }

    public function setRequestLines(int $requestId, array $lineIds): void
    {
        $this->db->prepare('DELETE FROM n_buy_request_line WHERE id_request = ?')->execute([$requestId]);
        $ins = $this->db->prepare('INSERT INTO n_buy_request_line (id_request, id_line) VALUES (?, ?)');
        foreach (array_unique(array_map('intval', $lineIds)) as $l) $ins->execute([$requestId, $l]);
    }

    public function requestByToken(string $token): ?array
    {
        return $this->all('SELECT q.*, TRIM(s.name) AS supplier_name FROM n_buy_request q JOIN supplier s ON s.id = q.id_supplier WHERE q.token = ?', [$token])[0] ?? null;
    }

    // --- Oferty i decyzje ------------------------------------------------------------------------------------------

    public function offers(int $roundId): array
    {
        return $this->all("SELECT o.*, TRIM(s.name) AS supplier_name FROM n_mp_offer o JOIN n_buy_line l ON l.id = o.id_buy_line JOIN supplier s ON s.id = o.id_supplier
                           WHERE l.id_round = ? AND o.source = 'weekly' ORDER BY o.price_per_base", [$roundId]);
    }

    public function offer(int $id): ?array
    {
        return $this->all("SELECT * FROM n_mp_offer WHERE id = ? AND source = 'weekly'", [$id])[0] ?? null;
    }

    /** Jedna oferta dostawcy na pozycję : nowa albo poprawiona (ponowne wpisanie w linku). */
    public function saveOffer(array $data): int
    {
        $id = $this->all("SELECT id FROM n_mp_offer WHERE id_buy_line = ? AND id_supplier = ? AND source = 'weekly'", [$data['id_buy_line'], $data['id_supplier']])[0]['id'] ?? null;
        if ($id !== null) {
            $set = $data;
            // Poprawka z linku dostawcy (created_by = null) nie zmienia autora ; wpisana w ERP — zapisuje operatora.
            unset($set['id_buy_line'], $set['id_supplier']);
            if ($set['created_by'] === null) unset($set['created_by']);
            $this->db->prepare('UPDATE n_mp_offer SET ' . implode(', ', array_map(fn($c) => "{$c} = ?", array_keys($set))) . ' WHERE id = ?')->execute([...array_values($set), (int) $id]);
            return (int) $id;
        }
        $cols = array_keys($data);
        $this->db->prepare('INSERT INTO n_mp_offer (' . implode(', ', $cols) . ') VALUES (?' . str_repeat(', ?', count($cols) - 1) . ')')->execute(array_values($data));
        return (int) $this->db->lastInsertId();
    }

    public function setOfferStatuses(int $lineId, ?int $acceptedId): void
    {
        $this->db->prepare("UPDATE n_mp_offer SET status = IF(id = ?, 'accepted', 'rejected') WHERE id_buy_line = ? AND source = 'weekly' AND status = 'received'")
            ->execute([$acceptedId ?? 0, $lineId]);
    }

    public function orders(int $roundId): array
    {
        return $this->all('SELECT o.*, TRIM(s.name) AS supplier_name, p.code AS po_code, p.status AS po_status FROM n_buy_order o JOIN supplier s ON s.id = o.id_supplier
                           LEFT JOIN n_po p ON p.id = o.id_po WHERE o.id_round = ?', [$roundId]);
    }

    public function insertOrder(array $data): int
    {
        $cols = array_keys($data);
        $this->db->prepare('INSERT INTO n_buy_order (' . implode(', ', $cols) . ') VALUES (?' . str_repeat(', ?', count($cols) - 1) . ')')->execute(array_values($data));
        return (int) $this->db->lastInsertId();
    }

    // --- Wyniki ----------------------------------------------------------------------------------------------------

    /** Na operatora : zakupy wysłane, zapytania i przypomnienia, oferty, decyzje, udane, oszczędność — w okresie. */
    public function stats(string $from, string $to): array
    {
        $out = [];
        $add = function (array $rows, string $key) use (&$out) {
            foreach ($rows as $r) $out[(int) $r['id_user']][$key] = (float) $r['v'];
        };
        $add($this->all('SELECT id_user, COUNT(*) AS v FROM n_buy_round WHERE sent_at BETWEEN ? AND ? GROUP BY id_user', [$from, $to]), 'rounds');
        $add($this->all('SELECT r.id_user, COUNT(*) + COALESCE(SUM(q.reminders), 0) AS v FROM n_buy_request q JOIN n_buy_round r ON r.id = q.id_round
                         WHERE q.sent_at BETWEEN ? AND ? GROUP BY r.id_user', [$from, $to]), 'emails');
        $add($this->all("SELECT r.id_user, COUNT(*) AS v FROM n_mp_offer o JOIN n_buy_line l ON l.id = o.id_buy_line JOIN n_buy_round r ON r.id = l.id_round
                         WHERE o.source = 'weekly' AND o.created_at BETWEEN ? AND ? GROUP BY r.id_user", [$from, $to]), 'offers');
        $add($this->all('SELECT id_user, COUNT(*) AS v FROM n_buy_order WHERE decided_at BETWEEN ? AND ? GROUP BY id_user', [$from, $to]), 'decisions');
        $add($this->all('SELECT id_user, COUNT(*) AS v FROM n_buy_order WHERE decided_at BETWEEN ? AND ? AND saving > 0 GROUP BY id_user', [$from, $to]), 'successful');
        $add($this->all('SELECT id_user, COALESCE(SUM(saving), 0) AS v FROM n_buy_order WHERE decided_at BETWEEN ? AND ? GROUP BY id_user', [$from, $to]), 'savings');
        $add($this->all('SELECT id_user, COUNT(DISTINCT id_po) AS v FROM n_buy_order WHERE decided_at BETWEEN ? AND ? AND id_po IS NOT NULL GROUP BY id_user', [$from, $to]), 'po');
        // Czas w kreatorze zakupów (rejestrator aktywności ERP).
        $add($this->all("SELECT id_user, COALESCE(SUM(active_s), 0) AS v FROM n_activity_view WHERE started_at BETWEEN ? AND ? AND route LIKE '/workshop/buy%' GROUP BY id_user", [$from, $to]), 'active_s');
        return $out;
    }

    /** Oszczędność według miesiąca (RRRR-MM) i operatora od dnia. */
    public function monthlySavings(string $from): array
    {
        return $this->all("SELECT DATE_FORMAT(decided_at, '%Y-%m') AS ym, id_user, SUM(saving) AS savings, COUNT(*) AS decisions, SUM(saving > 0) AS successful
                           FROM n_buy_order WHERE decided_at >= ? GROUP BY ym, id_user ORDER BY ym", [$from]);
    }

    /** @return array<int, array{name:string, hourly_cost:?float}> */
    public function users(array $ids): array
    {
        [$in, $params] = $this->in('u.id', $ids);
        $out = [];
        foreach ($this->all("SELECT u.id, COALESCE(NULLIF(u.displayed_name, ''), u.login) AS name, c.hourly_cost FROM user u LEFT JOIN n_user_cost c ON c.id_user = u.id WHERE 1 = 1{$in}", $params) as $u) {
            $out[(int) $u['id']] = ['name' => $u['name'], 'hourly_cost' => $u['hourly_cost'] !== null ? (float) $u['hourly_cost'] : null];
        }
        return $out;
    }

    /** Sprzedaż WDT w okresie w zł (EUR × kurs z ustawień firmy) — mianownik « marży ». */
    public function revenuePln(string $fromDay, string $toDay): float
    {
        $eur = (float) ($this->all('SELECT COALESCE(SUM(s.value_eur), 0) AS v FROM (' . WdtSalesRepository::linesSql() . ') s WHERE s.sale_date BETWEEN ? AND ?', [$fromDay, $toDay])[0]['v'] ?? 0);
        $rate = (float) ($this->db->query('SELECT exchange_rate_euro FROM erp_company_other_settings ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        return $eur * ($rate > 0 ? $rate : 4.3);
    }

    /** @return array{0: string, 1: list<int>} warunek « AND kolumna IN (…) » (pusty dla null) */
    private function in(string $column, ?array $ids): array
    {
        if ($ids === null) return ['', []];
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if (!$ids) return [' AND 1 = 0', []];
        return [" AND {$column} IN (" . implode(',', array_fill(0, count($ids), '?')) . ')', $ids];
    }
}
