<?php
declare(strict_types=1);

namespace App\Repositories\Workshop;

/**
 * Kontrola faktur zakupowych : faktury, ich pliki i linie, przypisania nazw dostawcy do surowców,
 * NIP sprzedawcy → dostawca ERP, zamówienia do porównania.
 */
final class InvoiceRepository extends WorkshopRepository
{
    protected string $table = 'tfb_inv';
    protected array $writable = ['source', 'ksef_number', 'id_supplier', 'seller_nip', 'seller_name', 'number', 'issue_date', 'currency', 'total_net', 'total_gross',
        'id_po', 'status', 'note', 'decided_by', 'decided_at', 'prices_imported_at', 'prices_imported_by', 'created_by'];

    public function invoices(?string $status): array
    {
        $where = $status === null ? '' : ' WHERE i.status = ?';
        return $this->all(
            'SELECT i.*, s.name AS supplier_name, p.code AS po_code, p.status AS po_status, f.filename, f.mime,
                    (SELECT COUNT(*) FROM tfb_inv_line l WHERE l.id_inv = i.id) AS line_count,
                    (SELECT COUNT(*) FROM tfb_inv_line l WHERE l.id_inv = i.id AND l.id_raw_material IS NULL) AS unmapped
             FROM tfb_inv i
             LEFT JOIN supplier s ON s.id = i.id_supplier
             LEFT JOIN tfb_po p ON p.id = i.id_po
             LEFT JOIN tfb_inv_file f ON f.id_inv = i.id' . $where . '
             ORDER BY COALESCE(i.issue_date, DATE(i.created_at)) DESC, i.id DESC LIMIT 300',
            $status === null ? [] : [$status]
        );
    }

    public function invoice(int $id): ?array
    {
        return $this->all('SELECT i.*, s.name AS supplier_name, p.code AS po_code, p.status AS po_status, f.filename, f.mime
                           FROM tfb_inv i LEFT JOIN supplier s ON s.id = i.id_supplier LEFT JOIN tfb_po p ON p.id = i.id_po
                           LEFT JOIN tfb_inv_file f ON f.id_inv = i.id WHERE i.id = ?', [$id])[0] ?? null;
    }

    public function findDuplicate(?string $ksefNumber, ?string $sellerNip, ?string $number): ?int
    {
        if ($ksefNumber !== null) {
            $id = $this->all('SELECT id FROM tfb_inv WHERE ksef_number = ?', [$ksefNumber])[0]['id'] ?? null;
            if ($id !== null) return (int) $id;
        }
        if ($sellerNip !== null && $number !== null) {
            $id = $this->all('SELECT id FROM tfb_inv WHERE seller_nip = ? AND number = ?', [$sellerNip, $number])[0]['id'] ?? null;
            if ($id !== null) return (int) $id;
        }
        return null;
    }

    /** @return list<string> numery KSeF już zaimportowane */
    public function importedKsef(array $numbers): array
    {
        if (!$numbers) return [];
        $in = implode(',', array_fill(0, count($numbers), '?'));
        return array_column($this->all("SELECT ksef_number FROM tfb_inv WHERE ksef_number IN ({$in})", array_values($numbers)), 'ksef_number');
    }

    public function lines(int $invoiceId): array
    {
        return $this->all(
            'SELECT l.*, rm.name AS material_name, rm.name_fr AS material_name_fr, u.name AS material_unit, rm.price_per_unit AS catalog_price, rm.package_size_in_base_unit AS package_size
             FROM tfb_inv_line l
             LEFT JOIN raw_material rm ON rm.id = l.id_raw_material
             LEFT JOIN unit u ON u.id = rm.id_unit
             WHERE l.id_inv = ? ORDER BY l.line_no, l.id',
            [$invoiceId]
        );
    }

    public function line(int $invoiceId, int $lineId): ?array
    {
        return $this->all('SELECT * FROM tfb_inv_line WHERE id = ? AND id_inv = ?', [$lineId, $invoiceId])[0] ?? null;
    }

    public function insertLine(int $invoiceId, array $l): int
    {
        $this->db->prepare('INSERT INTO tfb_inv_line (id_inv, line_no, name, code, quantity, unit, unit_price, net_value, vat_rate, id_raw_material, factor) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$invoiceId, $l['line_no'], mb_substr($l['name'], 0, 500), $l['code'] ?? null, $l['quantity'], $l['unit'] !== null ? mb_substr((string) $l['unit'], 0, 20) : null,
                $l['unit_price'] ?? null, $l['net_value'] ?? null, $l['vat_rate'] !== null ? mb_substr((string) $l['vat_rate'], 0, 8) : null, $l['id_raw_material'] ?? null, $l['factor'] ?? null]);
        return (int) $this->db->lastInsertId();
    }

    public function deleteLines(int $invoiceId): void
    {
        $this->db->prepare('DELETE FROM tfb_inv_line WHERE id_inv = ?')->execute([$invoiceId]);
    }

    public function mapLine(int $lineId, ?int $materialId, ?float $factor): void
    {
        $this->db->prepare('UPDATE tfb_inv_line SET id_raw_material = ?, factor = ? WHERE id = ?')->execute([$materialId, $factor, $lineId]);
    }

    public function saveFile(int $invoiceId, string $filename, string $mime, string $content, ?string $text): void
    {
        $this->db->prepare('INSERT INTO tfb_inv_file (id_inv, filename, mime, content, text) VALUES (?, ?, ?, ?, ?)')
            ->execute([$invoiceId, mb_substr($filename, 0, 255), $mime, $content, $text]);
    }

    public function file(int $invoiceId): ?array
    {
        return $this->all('SELECT filename, mime, content FROM tfb_inv_file WHERE id_inv = ?', [$invoiceId])[0] ?? null;
    }

    public function fileText(int $invoiceId): ?string
    {
        return $this->all('SELECT text FROM tfb_inv_file WHERE id_inv = ?', [$invoiceId])[0]['text'] ?? null;
    }

    public function delete(int $id): void
    {
        $this->db->prepare('DELETE FROM tfb_inv WHERE id = ?')->execute([$id]);
    }

    // --- Przypisania nazw ---------------------------------------------------------------------------------------------

    public function mapping(string $supplierKey, string $nameKey, string $unitKey): ?array
    {
        return $this->all('SELECT * FROM tfb_inv_mapping WHERE supplier_key = ? AND name_norm = ? AND unit_norm = ?', [$supplierKey, $nameKey, $unitKey])[0] ?? null;
    }

    public function saveMapping(string $supplierKey, ?int $supplierId, string $nameKey, string $unitKey, string $name, ?string $unit, int $materialId, float $factor, ?int $userId): void
    {
        $this->db->prepare('INSERT INTO tfb_inv_mapping (supplier_key, id_supplier, name_norm, unit_norm, supplier_name, supplier_unit, id_raw_material, factor, created_by)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                            ON DUPLICATE KEY UPDATE id_supplier = VALUES(id_supplier), supplier_name = VALUES(supplier_name), supplier_unit = VALUES(supplier_unit),
                                                    id_raw_material = VALUES(id_raw_material), factor = VALUES(factor)')
            ->execute([$supplierKey, $supplierId, $nameKey, $unitKey, mb_substr($name, 0, 500), $unit !== null ? mb_substr($unit, 0, 20) : null, $materialId, $factor, $userId]);
    }

    public function mappings(): array
    {
        return $this->all(
            'SELECT m.*, rm.name AS material_name, u.name AS material_unit, s.name AS supplier_name_erp
             FROM tfb_inv_mapping m
             LEFT JOIN raw_material rm ON rm.id = m.id_raw_material
             LEFT JOIN unit u ON u.id = rm.id_unit
             LEFT JOIN supplier s ON s.id = m.id_supplier
             ORDER BY s.name, m.supplier_name'
        );
    }

    public function deleteMapping(int $id): bool
    {
        $st = $this->db->prepare('DELETE FROM tfb_inv_mapping WHERE id = ?');
        $st->execute([$id]);
        return $st->rowCount() > 0;
    }

    // --- Dostawcy i zamówienia ----------------------------------------------------------------------------------------

    public function supplierForNip(string $nip): ?int
    {
        $id = $this->all('SELECT id_supplier FROM tfb_inv_supplier WHERE seller_nip = ?', [$nip])[0]['id_supplier'] ?? null;
        return $id !== null ? (int) $id : null;
    }

    public function rememberSupplier(string $nip, int $supplierId): void
    {
        $this->db->prepare('INSERT INTO tfb_inv_supplier (seller_nip, id_supplier) VALUES (?, ?) ON DUPLICATE KEY UPDATE id_supplier = VALUES(id_supplier)')->execute([$nip, $supplierId]);
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
                    (SELECT COUNT(*) FROM tfb_inv i WHERE i.id_po = p.id) AS invoices,
                    (SELECT GROUP_CONCAT(l.id_raw_material) FROM tfb_po_line l WHERE l.id_po = p.id) AS materials
             FROM tfb_po p WHERE p.id_supplier = ? AND p.status IN ('sent', 'partial', 'received')
             ORDER BY p.created_at DESC, p.id DESC LIMIT 12",
            [$supplierId]
        );
    }

    public function orderOfSupplier(int $orderId, int $supplierId): bool
    {
        return (bool) $this->all("SELECT 1 AS x FROM tfb_po WHERE id = ? AND id_supplier = ? AND status <> 'cancelled'", [$orderId, $supplierId]);
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

    public function companyNip(): ?string
    {
        $r = $this->all('SELECT wdt_company_nip, wz_company_nip FROM erp_company_data ORDER BY id LIMIT 1')[0] ?? null;
        return $r ? ($r['wdt_company_nip'] ?: $r['wz_company_nip']) : null;
    }
}
