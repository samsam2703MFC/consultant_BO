<?php
declare(strict_types=1);

namespace App\Repositories\Workshop;

/**
 * Asystent « PPWR i opakowania » [397] : lecture
 *  - des opakowania (raw_material.is_part_of_package = 1 : bdo_material_category, packaging_unit_weight_g,
 *    packaging_usage_type — migration 040) avec leur profil PPWR (raw_material_ppwr_profile, 043 / 049) et leurs
 *    wymiary actives (raw_material_ppwr_dimension), mêmes colonnes que RawMaterialPpwrRepository ; dostawca actuel
 *    (rm.id_current_supplier → supplier.name, email) pour la demande à préparer ; produits actifs qui l'utilisent
 *    (flattened_recipe_ingredient → final_product.active = 1) ;
 *  - des produits actifs (final_product : id_recipe, ppc, weight, create_timestamp) avec leur karton sprzedażowy PPWR
 *    (final_product_ppwr_sales_packaging, 047) et le nombre d'opakowania de leur recette, comme
 *    FinalProductPpwrSalesPackagingRepository::getProductAudit ;
 *  - des wysyłki d'une période (shipment_log.shipment_date) avec leur dowód opakowań (shipment_packaging_record : status ;
 *    shipment_packaging_item : ppwr_calculation_status — 040, 052), comme ShipmentPpwrReportRepository::getCoverage.
 * Une source dont la table manque (base partielle) est rendue null (« brak danych »). N'écrit que n_ppwr_month_review.
 */
final class PpwrMonthRepository extends WorkshopRepository
{
    protected string $table = 'n_ppwr_month_review';

    /** @var array<string, bool> */
    private array $tables = [];
    /** @var array<string, list<string>> */
    private array $columns = [];

    public function hasTable(string $table): bool
    {
        return $this->tables[$table] ??= (bool) $this->all('SELECT 1 AS x FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$table]);
    }

    private function hasColumn(string $table, string $column): bool
    {
        $this->columns[$table] ??= array_column($this->all('SELECT COLUMN_NAME AS c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$table]), 'c');
        return in_array($column, $this->columns[$table], true);
    }

    /** Opakowania avec profil PPWR, données BDO, dostawca et nombre de produits actifs qui les utilisent (null : tables absentes). */
    public function packagingMaterials(): ?array
    {
        if (!$this->hasTable('raw_material') || !$this->hasTable('raw_material_ppwr_profile')) return null;
        $dim = $this->hasTable('raw_material_ppwr_dimension');
        $sup = $this->hasTable('supplier') && $this->hasColumn('raw_material', 'id_current_supplier');
        $usage = $this->hasTable('flattened_recipe_ingredient') && $this->hasTable('final_product')
            ? '(SELECT COUNT(DISTINCT fp.id) FROM flattened_recipe_ingredient fri JOIN final_product fp ON fp.id_recipe = fri.recipe_id AND fp.active = 1 WHERE fri.material_id = rm.id)' : 'NULL';
        return $this->all("SELECT rm.id AS id_raw_material, rm.name AS material_name, rm.bdo_material_category, rm.packaging_unit_weight_g, rm.packaging_usage_type,
                p.id AS id_profile, p.identification_number, p.identification_source, p.packaging_level, p.packaging_type, p.dominant_material,
                p.recycled_content_status, p.recyclability_status, p.reuse_status, p.target_reuse_cycles, p.minimisation_status, p.verification_status,
                p.updated_at AS profile_updated_at,
                " . ($dim ? 'd.outer_volume_cm3' : 'NULL') . " AS outer_volume_cm3,
                " . ($sup ? 's.id' : 'NULL') . " AS id_supplier, " . ($sup ? 's.name' : 'NULL') . " AS supplier_name, " . ($sup ? 's.email' : 'NULL') . " AS supplier_email,
                {$usage} AS active_products
            FROM raw_material rm
            LEFT JOIN raw_material_ppwr_profile p ON p.id_raw_material = rm.id
            " . ($dim ? 'LEFT JOIN raw_material_ppwr_dimension d ON d.id_raw_material_ppwr_profile = p.id AND d.is_active = 1' : '') . "
            " . ($sup ? 'LEFT JOIN supplier s ON s.id = rm.id_current_supplier' : '') . "
            WHERE rm.is_part_of_package = 1
            ORDER BY rm.name, rm.id");
    }

    /** Produits actifs avec karton sprzedażowy PPWR, masse et date de création (null : tables absentes). */
    public function activeProducts(): ?array
    {
        if (!$this->hasTable('final_product') || !$this->hasTable('final_product_ppwr_sales_packaging')) return null;
        $fri = $this->hasTable('flattened_recipe_ingredient') && $this->hasTable('raw_material');
        $created = $this->hasColumn('final_product', 'create_timestamp') ? 'fp.create_timestamp' : 'NULL';
        return $this->all("SELECT fp.id AS id_final_product, fp.name AS product_name, fp.id_recipe, fp.ppc, fp.weight, {$created} AS created_at,
                c.id AS id_configuration, c.id_outer_raw_material, c.verification_status, c.updated_at AS config_updated_at,
                " . ($fri ? "(SELECT COUNT(DISTINCT rm.id) FROM flattened_recipe_ingredient fri JOIN raw_material rm ON rm.id = fri.material_id AND rm.is_part_of_package = 1
                    WHERE fri.recipe_id = fp.id_recipe)" : '0') . " AS packaging_candidate_count
            FROM final_product fp
            LEFT JOIN final_product_ppwr_sales_packaging c ON c.id_final_product = fp.id
            WHERE fp.active = 1
            ORDER BY fp.name, fp.id");
    }

    /** Wysyłki du $from au $to inclus avec l'état de leur dowód opakowań (null : tables absentes). */
    public function shipments(string $from, string $to): ?array
    {
        if (!$this->hasTable('shipment_log') || !$this->hasTable('shipment_packaging_record') || !$this->hasTable('shipment_packaging_item')) return null;
        $customer = $this->hasTable('customer')
            ? "COALESCE(NULLIF(TRIM(c.company), ''), TRIM(CONCAT(COALESCE(c.name, ''), ' ', COALESCE(c.surname, ''))))" : 'NULL';
        $ppwr = $this->hasColumn('shipment_packaging_item', 'ppwr_calculation_status') ? "spi.ppwr_calculation_status <> 'COMPLETE'" : '0';
        // Sous-requêtes par dowód (un seul par wysyłka, clé unique) : pas de GROUP BY sur les jointures (ONLY_FULL_GROUP_BY).
        return $this->all("SELECT sl.id, sl.shipment_date, sl.id_customer, {$customer} AS customer, spr.id AS id_record, spr.status AS record_status,
                (SELECT COUNT(*) FROM shipment_packaging_item spi WHERE spi.id_shipment_packaging_record = spr.id) AS items,
                (SELECT COUNT(*) FROM shipment_packaging_item spi WHERE spi.id_shipment_packaging_record = spr.id AND {$ppwr}) AS ppwr_incomplete,
                (SELECT COUNT(*) FROM shipment_packaging_item spi WHERE spi.id_shipment_packaging_record = spr.id AND spi.calculation_status <> 'COMPLETE') AS bdo_incomplete
            FROM shipment_log sl
            " . ($this->hasTable('customer') ? 'LEFT JOIN customer c ON c.id = sl.id_customer' : '') . "
            LEFT JOIN shipment_packaging_record spr ON spr.id_shipment_log = sl.id
            WHERE sl.shipment_date >= ? AND sl.shipment_date <= ?
            ORDER BY sl.shipment_date, sl.id", [$from, $to]);
    }

    /** @return array<string, list<array>> élément → décisions (la plus récente d'abord), tous mois confondus */
    public function decisions(array $keys): array
    {
        $keys = array_values(array_unique(array_filter($keys)));
        if (!$keys) return [];
        $out = [];
        foreach ($this->all('SELECT r.*, COALESCE(NULLIF(TRIM(u.displayed_name), \'\'), u.login) AS decided_by
                FROM n_ppwr_month_review r LEFT JOIN user u ON u.id = r.id_user
                WHERE r.item_key IN (' . implode(',', array_fill(0, count($keys), '?')) . ') ORDER BY r.month_start DESC, r.updated_at DESC, r.id DESC', $keys) as $r) {
            $out[(string) $r['item_key']][] = $r;
        }
        return $out;
    }

    /** @return list<string> éléments décidés pendant le mois (restent à l'écran même sans braki). */
    public function decidedIn(string $monthStart): array
    {
        return array_column($this->all('SELECT item_key FROM n_ppwr_month_review WHERE month_start = ?', [$monthStart]), 'item_key');
    }

    /** Une décision par (mois du contrôle, élément) : la dernière enregistrée remplace la précédente. */
    public function saveDecision(string $monthStart, string $key, string $kind, int $sourceId, string $decision, ?string $note, string $flags, ?int $userId): void
    {
        $this->db->prepare('INSERT INTO n_ppwr_month_review (month_start, item_key, kind, source_id, decision, note, flags, id_user) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE decision = VALUES(decision), note = VALUES(note), flags = VALUES(flags), id_user = VALUES(id_user), updated_at = NOW()')
            ->execute([$monthStart, $key, $kind, $sourceId, $decision, $note, mb_substr($flags, 0, 255), $userId]);
    }
}
