<?php
declare(strict_types=1);

namespace App\Repositories\Workshop;

/**
 * Kontrola faktur zakupowych : faktury, ich pliki i linie, przypisania nazw dostawcy do surowców,
 * NIP sprzedawcy → dostawca ERP, zamówienia do porównania.
 */
final class InvoiceRepository extends WorkshopRepository
{
    protected string $table = 'n_inv';
    protected array $writable = ['source', 'kind', 'cost_category', 'service_month', 'cost_amount', 'id_rule', 'ksef_number', 'id_supplier', 'seller_nip', 'seller_name', 'number',
        'issue_date', 'currency', 'total_net', 'total_gross', 'is_correction', 'id_po', 'status', 'note', 'decided_by', 'decided_at', 'prices_imported_at', 'prices_imported_by', 'created_by'];

    /**
     * Lista faktur : rodzaj goods (surowce i opakowania) albo service (koszty firmy), status (null = wszystkie poza odrzuconymi) ;
     * $kind null = odrzucone (status rejected, każdy rodzaj).
     */
    public function invoices(?string $status, ?string $kind = 'goods'): array
    {
        [$where, $params] = $kind === null ? [" WHERE i.status = 'rejected'", []]
            : ($status === null ? [" WHERE i.kind = ? AND i.status <> 'rejected'", [$kind]] : [' WHERE i.kind = ? AND i.status = ?', [$kind, $status]]);
        return $this->all(
            'SELECT i.*, s.name AS supplier_name, p.code AS po_code, p.status AS po_status, f.filename, f.mime,
                    (SELECT COUNT(*) FROM n_inv_line l WHERE l.id_inv = i.id) AS line_count,
                    (SELECT COUNT(*) FROM n_inv_line l WHERE l.id_inv = i.id AND l.id_raw_material IS NULL) AS unmapped
             FROM n_inv i
             LEFT JOIN supplier s ON s.id = i.id_supplier
             LEFT JOIN n_po p ON p.id = i.id_po
             LEFT JOIN n_inv_file f ON f.id_inv = i.id' . $where . '
             ORDER BY COALESCE(i.issue_date, DATE(i.created_at)) DESC, i.id DESC LIMIT 300',
            $params
        );
    }

    /** Liczby do przełącznika : rodzaj × status (bez odrzuconych) i odrzucone, z sumą netto. */
    public function counts(): array
    {
        return $this->all("SELECT IF(status = 'rejected', 'rejected', kind) AS kind, status, COUNT(*) AS n, COALESCE(SUM(total_net), 0) AS net FROM n_inv GROUP BY 1, 2");
    }

    /** Otwarte faktury surowców tego sprzedawcy (NIP), jeszcze nieprzetworzone : do reguły « zawsze dla dostawcy ». */
    public function openGoodsOfNip(string $nip, int $exceptId): array
    {
        return $this->all("SELECT * FROM n_inv WHERE seller_nip = ? AND id <> ? AND kind = 'goods' AND status = 'open' AND prices_imported_at IS NULL AND id_po IS NULL",
            [$nip, $exceptId]);
    }

    /** Linie bez przypisania surowca (faktura sklasyfikowana jako usługa / odrzucona : bez wpływu na ceny i stany). */
    public function unmapLines(int $invoiceId): void
    {
        $this->db->prepare('UPDATE n_inv_line SET id_raw_material = NULL, factor = NULL, price_decision = NULL, price_ok_price = NULL, price_ok_by = NULL, price_ok_at = NULL,
                check_status = NULL, check_note = NULL, checked_by = NULL, checked_at = NULL WHERE id_inv = ?')
            ->execute([$invoiceId]);
    }

    // --- Reguły dostawców (NIP → usługa albo odrzucona) ----------------------------------------------------------------

    public function rule(string $nip): ?array
    {
        return $this->all('SELECT * FROM n_inv_supplier_rule WHERE seller_nip = ?', [$nip])[0] ?? null;
    }

    public function saveRule(string $nip, ?string $name, string $action, ?string $category, ?int $userId): int
    {
        $this->db->prepare('INSERT INTO n_inv_supplier_rule (seller_nip, seller_name, action, cost_category, created_by) VALUES (?, ?, ?, ?, ?)
                            ON DUPLICATE KEY UPDATE seller_name = VALUES(seller_name), action = VALUES(action), cost_category = VALUES(cost_category)')
            ->execute([$nip, $name !== null ? mb_substr($name, 0, 255) : null, $action, $category, $userId]);
        return (int) $this->rule($nip)['id'];
    }

    public function rules(): array
    {
        return $this->all("SELECT r.*, COALESCE(NULLIF(u.displayed_name, ''), u.login) AS created_by_name,
                                  (SELECT COUNT(*) FROM n_inv i WHERE i.seller_nip = r.seller_nip) AS invoices,
                                  (SELECT MAX(i.issue_date) FROM n_inv i WHERE i.seller_nip = r.seller_nip) AS last_invoice
                           FROM n_inv_supplier_rule r LEFT JOIN user u ON u.id = r.created_by ORDER BY r.action, r.seller_name, r.seller_nip");
    }

    public function findRule(int $id): ?array
    {
        return $this->all('SELECT * FROM n_inv_supplier_rule WHERE id = ?', [$id])[0] ?? null;
    }

    public function deleteRule(int $id): void
    {
        $this->db->prepare('DELETE FROM n_inv_supplier_rule WHERE id = ?')->execute([$id]);
    }

    public function invoice(int $id): ?array
    {
        return $this->all('SELECT i.*, s.name AS supplier_name, p.code AS po_code, p.status AS po_status, f.filename, f.mime
                           FROM n_inv i LEFT JOIN supplier s ON s.id = i.id_supplier LEFT JOIN n_po p ON p.id = i.id_po
                           LEFT JOIN n_inv_file f ON f.id_inv = i.id WHERE i.id = ?', [$id])[0] ?? null;
    }

    public function findDuplicate(?string $ksefNumber, ?string $sellerNip, ?string $number): ?int
    {
        if ($ksefNumber !== null) {
            $id = $this->all('SELECT id FROM n_inv WHERE ksef_number = ?', [$ksefNumber])[0]['id'] ?? null;
            if ($id !== null) return (int) $id;
        }
        if ($sellerNip !== null && $number !== null) {
            $id = $this->all('SELECT id FROM n_inv WHERE seller_nip = ? AND number = ?', [$sellerNip, $number])[0]['id'] ?? null;
            if ($id !== null) return (int) $id;
        }
        return null;
    }

    /** Data wystawienia ostatniej faktury pobranej z KSeF (start « Pobierz nowe z KSeF »). */
    public function lastKsefIssueDate(): ?string
    {
        return $this->all("SELECT MAX(issue_date) AS d FROM n_inv WHERE source = 'ksef'")[0]['d'] ?? null;
    }

    /** @return list<string> numery KSeF już zaimportowane */
    public function importedKsef(array $numbers): array
    {
        if (!$numbers) return [];
        $in = implode(',', array_fill(0, count($numbers), '?'));
        return array_column($this->all("SELECT ksef_number FROM n_inv WHERE ksef_number IN ({$in})", array_values($numbers)), 'ksef_number');
    }

    public function lines(int $invoiceId): array
    {
        return $this->all(
            "SELECT l.*, rm.name AS material_name, rm.name_fr AS material_name_fr, u.name AS material_unit, rm.price_per_unit AS catalog_price, rm.package_size_in_base_unit AS package_size,
                    COALESCE(NULLIF(ok.displayed_name, ''), ok.login) AS price_ok_name, COALESCE(NULLIF(ck.displayed_name, ''), ck.login) AS checked_name
             FROM n_inv_line l
             LEFT JOIN raw_material rm ON rm.id = l.id_raw_material
             LEFT JOIN unit u ON u.id = rm.id_unit
             LEFT JOIN user ok ON ok.id = l.price_ok_by
             LEFT JOIN user ck ON ck.id = l.checked_by
             WHERE l.id_inv = ? ORDER BY l.line_no, l.id",
            [$invoiceId]
        );
    }

    public function line(int $invoiceId, int $lineId): ?array
    {
        return $this->all('SELECT * FROM n_inv_line WHERE id = ? AND id_inv = ?', [$lineId, $invoiceId])[0] ?? null;
    }

    public function insertLine(int $invoiceId, array $l): int
    {
        $this->db->prepare('INSERT INTO n_inv_line (id_inv, line_no, name, code, quantity, unit, unit_price, net_value, vat_rate, id_raw_material, factor) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$invoiceId, $l['line_no'], mb_substr($l['name'], 0, 500), $l['code'] ?? null, $l['quantity'], $l['unit'] !== null ? mb_substr((string) $l['unit'], 0, 20) : null,
                $l['unit_price'] ?? null, $l['net_value'] ?? null, $l['vat_rate'] !== null ? mb_substr((string) $l['vat_rate'], 0, 8) : null, $l['id_raw_material'] ?? null, $l['factor'] ?? null]);
        return (int) $this->db->lastInsertId();
    }

    public function deleteLines(int $invoiceId): void
    {
        $this->db->prepare('DELETE FROM n_inv_line WHERE id_inv = ?')->execute([$invoiceId]);
    }

    /** Nowe przypisanie albo przelicznik : decyzja o podwyżce ceny (inna cena za jednostkę) i sprawdzenie linii [217] przestają obowiązywać. */
    public function mapLine(int $lineId, ?int $materialId, ?float $factor): void
    {
        $this->db->prepare('UPDATE n_inv_line SET id_raw_material = ?, factor = ?, price_decision = NULL, price_ok_price = NULL, price_ok_by = NULL, price_ok_at = NULL,
                check_status = NULL, check_note = NULL, checked_by = NULL, checked_at = NULL WHERE id = ?')
            ->execute([$materialId, $factor, $lineId]);
    }

    /** Kreator [217] : linia sprawdzona (checked), sporna (disputed) albo do sprawdzenia (null) — kto, kiedy, notatka. */
    public function checkLine(int $lineId, ?string $status, ?string $note, ?int $userId): void
    {
        $this->db->prepare('UPDATE n_inv_line SET check_status = ?, check_note = ?, checked_by = ?, checked_at = ? WHERE id = ?')
            ->execute([$status, $status !== null ? $note : null, $status !== null ? $userId : null, $status !== null ? date('Y-m-d H:i:s') : null, $lineId]);
    }

    /** Ostatnie pobranie faktur z KSeF (data utworzenia najnowszej faktury ze źródła KSeF). */
    public function lastKsefImport(): ?string
    {
        return $this->all("SELECT MAX(created_at) AS at FROM n_inv WHERE source = 'ksef'")[0]['at'] ?? null;
    }

    /** Decyzja o podwyżce ceny surowca na fakturze (wszystkie jego linie) : accept | keep, cena zatwierdzona, kto, kiedy. */
    public function decidePrice(int $invoiceId, int $materialId, string $decision, float $price, ?int $userId): void
    {
        $this->db->prepare('UPDATE n_inv_line SET price_decision = ?, price_ok_price = ?, price_ok_by = ?, price_ok_at = NOW() WHERE id_inv = ? AND id_raw_material = ?')
            ->execute([$decision, round($price, 8), $userId, $invoiceId, $materialId]);
    }

    /** Liczba receptur (spłaszczone składniki) z danym surowcem : na ile receptur wpływa nowa cena bazowa. */
    public function recipesUsing(array $materialIds): array
    {
        if (!$materialIds) return [];
        $in = implode(',', array_fill(0, count($materialIds), '?'));
        $out = [];
        foreach ($this->all("SELECT material_id, COUNT(DISTINCT recipe_id) AS n FROM flattened_recipe_ingredient WHERE material_id IN ({$in}) GROUP BY material_id", array_values($materialIds)) as $r) {
            $out[(int) $r['material_id']] = (int) $r['n'];
        }
        return $out;
    }

    public function saveFile(int $invoiceId, string $filename, string $mime, string $content, ?string $text): void
    {
        $this->db->prepare('INSERT INTO n_inv_file (id_inv, filename, mime, content, text) VALUES (?, ?, ?, ?, ?)')
            ->execute([$invoiceId, mb_substr($filename, 0, 255), $mime, $content, $text]);
    }

    public function file(int $invoiceId): ?array
    {
        return $this->all('SELECT filename, mime, content FROM n_inv_file WHERE id_inv = ?', [$invoiceId])[0] ?? null;
    }

    public function fileText(int $invoiceId): ?string
    {
        return $this->all('SELECT text FROM n_inv_file WHERE id_inv = ?', [$invoiceId])[0]['text'] ?? null;
    }

    public function delete(int $id): void
    {
        $this->db->prepare('DELETE FROM n_inv WHERE id = ?')->execute([$id]);
    }

    // --- Przypisania nazw ---------------------------------------------------------------------------------------------

    public function mapping(string $supplierKey, string $nameKey, string $unitKey): ?array
    {
        return $this->all('SELECT * FROM n_inv_mapping WHERE supplier_key = ? AND name_norm = ? AND unit_norm = ?', [$supplierKey, $nameKey, $unitKey])[0] ?? null;
    }

    /** Ta sama nazwa (i jednostka) przypisana u innych dostawców [217] : surowiec => przelicznik (najczęstsze najpierw). @return array<int, float> */
    public function namedMappings(string $nameKey, string $unitKey): array
    {
        $out = [];
        foreach ($this->all('SELECT id_raw_material, MAX(factor) AS factor, COUNT(*) AS n FROM n_inv_mapping WHERE name_norm = ? AND unit_norm = ? GROUP BY id_raw_material ORDER BY n DESC',
            [$nameKey, $unitKey]) as $r) $out[(int) $r['id_raw_material']] = (float) $r['factor'];
        return $out;
    }

    public function saveMapping(string $supplierKey, ?int $supplierId, string $nameKey, string $unitKey, string $name, ?string $unit, int $materialId, float $factor, ?int $userId): void
    {
        $this->db->prepare('INSERT INTO n_inv_mapping (supplier_key, id_supplier, name_norm, unit_norm, supplier_name, supplier_unit, id_raw_material, factor, created_by)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                            ON DUPLICATE KEY UPDATE id_supplier = VALUES(id_supplier), supplier_name = VALUES(supplier_name), supplier_unit = VALUES(supplier_unit),
                                                    id_raw_material = VALUES(id_raw_material), factor = VALUES(factor)')
            ->execute([$supplierKey, $supplierId, $nameKey, $unitKey, mb_substr($name, 0, 500), $unit !== null ? mb_substr($unit, 0, 20) : null, $materialId, $factor, $userId]);
    }

    public function mappings(): array
    {
        return $this->all(
            'SELECT m.*, rm.name AS material_name, u.name AS material_unit, s.name AS supplier_name_erp
             FROM n_inv_mapping m
             LEFT JOIN raw_material rm ON rm.id = m.id_raw_material
             LEFT JOIN unit u ON u.id = rm.id_unit
             LEFT JOIN supplier s ON s.id = m.id_supplier
             ORDER BY s.name, m.supplier_name'
        );
    }

    public function deleteMapping(int $id): bool
    {
        $st = $this->db->prepare('DELETE FROM n_inv_mapping WHERE id = ?');
        $st->execute([$id]);
        return $st->rowCount() > 0;
    }

    // --- Dostawcy i zamówienia ----------------------------------------------------------------------------------------

    public function supplierForNip(string $nip): ?int
    {
        $id = $this->all('SELECT id_supplier FROM n_inv_supplier WHERE seller_nip = ?', [$nip])[0]['id_supplier'] ?? null;
        return $id !== null ? (int) $id : null;
    }

    public function rememberSupplier(string $nip, int $supplierId): void
    {
        $this->db->prepare('INSERT INTO n_inv_supplier (seller_nip, id_supplier) VALUES (?, ?) ON DUPLICATE KEY UPDATE id_supplier = VALUES(id_supplier)')->execute([$nip, $supplierId]);
    }

    public function suppliers(): array
    {
        return $this->all('SELECT id, name FROM supplier ORDER BY name');
    }

    public function supplierExists(int $id): bool
    {
        return (bool) $this->all('SELECT 1 AS x FROM supplier WHERE id = ?', [$id]);
    }

    /** Zamówienia dostawcy do powiązania (wysłane, częściowo / całkowicie przyjęte) i liczba faktur już powiązanych. */
    public function orderCandidates(int $supplierId): array
    {
        return $this->all(
            "SELECT p.id, p.code, p.status, p.week_start, p.created_at, p.delivery_date,
                    (SELECT COUNT(*) FROM n_inv i WHERE i.id_po = p.id) AS invoices,
                    (SELECT GROUP_CONCAT(l.id_raw_material) FROM n_po_line l WHERE l.id_po = p.id) AS materials
             FROM n_po p WHERE p.id_supplier = ? AND p.status IN ('sent', 'partial', 'received')
             ORDER BY p.created_at DESC, p.id DESC LIMIT 12",
            [$supplierId]
        );
    }

    public function orderOfSupplier(int $orderId, int $supplierId): bool
    {
        return (bool) $this->all("SELECT 1 AS x FROM n_po WHERE id = ? AND id_supplier = ? AND status <> 'cancelled'", [$orderId, $supplierId]);
    }

    /** Surowce dostawcy (bieżący dostawca) i wszystkie — do podpowiedzi przypisań. */
    public function materials(): array
    {
        return $this->all('SELECT rm.id, rm.name, rm.name_fr, rm.id_current_supplier, u.name AS unit, rm.package_size_in_base_unit AS package_size, rm.price_per_unit
                           FROM raw_material rm LEFT JOIN unit u ON u.id = rm.id_unit ORDER BY rm.name');
    }

    public function material(int $id): ?array
    {
        return $this->all('SELECT rm.id, rm.name, rm.id_unit, rm.id_current_supplier, rm.price_per_unit, u.name AS unit, rm.package_size_in_base_unit AS package_size
                           FROM raw_material rm LEFT JOIN unit u ON u.id = rm.id_unit WHERE rm.id = ?', [$id])[0] ?? null;
    }

    /** Surowiec do zmiany jednostki : jednostka, opakowanie, cena, dostawca, waga sztuki (BDO), liczba receptur. */
    public function materialUnitInfo(int $id): ?array
    {
        return $this->all('SELECT rm.id, rm.name, rm.id_unit, u.name AS unit, rm.package_size_in_base_unit AS package_size, rm.price_per_unit, rm.id_current_supplier,
                                  rm.packaging_unit_weight_g AS weight_g,
                                  (SELECT COUNT(DISTINCT c.parent_recipe_id) FROM product_recipe_connection c WHERE c.child_ingredient_id = rm.id) AS recipes
                           FROM raw_material rm LEFT JOIN unit u ON u.id = rm.id_unit WHERE rm.id = ?', [$id])[0] ?? null;
    }

    public function unitId(string $name): ?int
    {
        $id = $this->all('SELECT id FROM unit WHERE name = ?', [$name])[0]['id'] ?? null;
        return $id !== null ? (int) $id : null;
    }

    /** Przeliczniki w nowej jednostce : zapamiętane przypisania i linie faktur bez zaimportowanych cen. */
    public function scaleFactors(int $materialId, float $ratio): void
    {
        $this->db->prepare('UPDATE n_inv_mapping SET factor = ROUND(factor * ?, 4) WHERE id_raw_material = ?')->execute([$ratio, $materialId]);
        $this->db->prepare('UPDATE n_inv_line l JOIN n_inv i ON i.id = l.id_inv SET l.factor = ROUND(l.factor * ?, 4)
                            WHERE l.id_raw_material = ? AND l.factor IS NOT NULL AND i.prices_imported_at IS NULL AND i.status = \'open\'')->execute([$ratio, $materialId]);
    }

    public function companyNip(): ?string
    {
        $r = $this->all('SELECT wdt_company_nip, wz_company_nip FROM erp_company_data ORDER BY id LIMIT 1')[0] ?? null;
        return $r ? ($r['wdt_company_nip'] ?: $r['wz_company_nip']) : null;
    }
}
