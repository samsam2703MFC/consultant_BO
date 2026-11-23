<?php
declare(strict_types=1);

namespace App\Repositories\Workshop;

/**
 * Asystent « Dokumenty wysyłek miesiąca » [402] : lecture, pour les wysyłki d'une période (shipment_log.shipment_date),
 *  - de leurs documents de transport, comme ShippingDocumentService::buildPaper les imprime : WZ (document_wz,
 *    document_wz_product), CMR (document_cmr : carrier_name, carrier_regis_num ; document_cmr_packages), WDT
 *    (document_wdt, document_wdt_product ; remplacée = n_wdt_superseded), document de różnice (document_order_shipping_
 *    difference_products : lignes avec un écart de quantité ou de prix, comme ShippingIssueRepository::differenceLines) ;
 *  - des quantités par produit de la WZ et de la WDT (comparées dans le service) ;
 *  - de la file « Różnice i problemy dostaw » (n_shipping_issue : status de l'écart du document et du problème de l'arrêt) ;
 *  - de la confirmation de livraison de la tablette chauffeur (n_delivery_run du jour, n_delivery_stop du client et du
 *    jour, signature n_delivery_media) ;
 *  - du dowód opakowań (shipment_packaging_record) : seulement compté — il est contrôlé par « PPWR i opakowania ».
 * Une source dont la table manque (base partielle) est rendue null / non vérifiée. N'écrit que n_shipping_docs_review.
 */
final class ShippingDocsMonthRepository extends WorkshopRepository
{
    protected string $table = 'n_shipping_docs_review';

    /** @var array<string, bool> */
    private array $tables = [];

    public function hasTable(string $table): bool
    {
        return $this->tables[$table] ??= (bool) $this->all('SELECT 1 AS x FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$table]);
    }

    /** Tables présentes par contrôle (sans table, le contrôle n'est pas fait et l'écran le dit). */
    public function checks(): array
    {
        $h = fn(string ...$t) => array_reduce($t, fn($ok, $x) => $ok && $this->hasTable($x), true);
        return ['wz' => $h('document_wz', 'document_wz_product'), 'cmr' => $h('document_cmr', 'document_cmr_packages'), 'wdt' => $h('document_wdt', 'document_wdt_product'),
            'diff' => $h('document_order_shipping_difference_products', 'n_shipping_issue'), 'receipt' => $h('n_delivery_run', 'n_delivery_stop', 'n_delivery_media'),
            'issues' => $h('n_delivery_stop', 'n_shipping_issue'), 'superseded' => $h('n_wdt_superseded'), 'packaging' => $h('shipment_packaging_record')];
    }

    /** Wysyłki du $from au $to inclus avec l'état de leurs documents (null : shipment_log absente). */
    public function shipments(string $from, string $to): ?array
    {
        if (!$this->hasTable('shipment_log')) return null;
        $c = $this->checks();
        $customer = $this->hasTable('customer') ? "COALESCE(NULLIF(TRIM(cu.company), ''), TRIM(CONCAT(COALESCE(cu.name, ''), ' ', COALESCE(cu.surname, ''))))" : 'NULL';
        $f = [
            'wz_found' => $c['wz'] ? '(SELECT COUNT(*) FROM document_wz w WHERE w.id = sl.id_wz)' : 'NULL',
            'wz_num' => $c['wz'] ? '(SELECT w.doc_num FROM document_wz w WHERE w.id = sl.id_wz)' : 'NULL',
            'wz_lines' => $c['wz'] ? '(SELECT COUNT(*) FROM document_wz_product p WHERE p.id_document_wz = sl.id_wz)' : 'NULL',
            'cmr_found' => $c['cmr'] ? '(SELECT COUNT(*) FROM document_cmr m WHERE m.id = sl.id_cmr)' : 'NULL',
            'cmr_num' => $c['cmr'] ? '(SELECT m.doc_num FROM document_cmr m WHERE m.id = sl.id_cmr)' : 'NULL',
            'cmr_carrier' => $c['cmr'] ? "(SELECT CONCAT(COALESCE(TRIM(m.carrier_name), ''), '|', COALESCE(TRIM(m.carrier_regis_num), '')) FROM document_cmr m WHERE m.id = sl.id_cmr)" : 'NULL',
            'cmr_packages' => $c['cmr'] ? '(SELECT COUNT(*) FROM document_cmr_packages k WHERE k.id_document_cmr = sl.id_cmr)' : 'NULL',
            'wdt_found' => $c['wdt'] ? '(SELECT COUNT(*) FROM document_wdt d WHERE d.id = sl.id_wdt)' : 'NULL',
            'wdt_num' => $c['wdt'] ? '(SELECT d.doc_num FROM document_wdt d WHERE d.id = sl.id_wdt)' : 'NULL',
            'wdt_lines' => $c['wdt'] ? '(SELECT COUNT(*) FROM document_wdt_product p WHERE p.id_document_wdt = sl.id_wdt)' : 'NULL',
            'wdt_superseded' => $c['superseded'] ? '(SELECT COUNT(*) FROM n_wdt_superseded s WHERE s.id_document_wdt = sl.id_wdt)' : '0',
            'diff_lines' => $c['diff'] ? '(SELECT COUNT(*) FROM document_order_shipping_difference_products p WHERE p.id_document_order_shipping_difference = sl.id_diff
                AND (p.quantity_ordered <> p.invoiced_quantity OR p.price_during_ordering <> p.price_during_invoicing))' : 'NULL',
            'diff_issue' => $c['diff'] ? "(SELECT i.status FROM n_shipping_issue i WHERE i.id_difference = sl.id_diff LIMIT 1)" : 'NULL',
            'run_day' => $c['receipt'] ? '(SELECT COUNT(*) FROM n_delivery_run r WHERE r.ship_date = sl.shipment_date)' : 'NULL',
            'stop_status' => $c['receipt'] ? '(SELECT s.status FROM n_delivery_stop s WHERE s.ship_date = sl.shipment_date AND s.id_customer = sl.id_customer LIMIT 1)' : 'NULL',
            'stop_signatures' => $c['receipt'] ? "(SELECT COUNT(*) FROM n_delivery_stop s JOIN n_delivery_media dm ON dm.id_stop = s.id AND dm.kind = 'signature'
                WHERE s.ship_date = sl.shipment_date AND s.id_customer = sl.id_customer)" : 'NULL',
            'stop_issue' => $c['issues'] ? "(SELECT i.status FROM n_shipping_issue i JOIN n_delivery_stop s ON s.id = i.id_delivery_stop
                WHERE s.ship_date = sl.shipment_date AND s.id_customer = sl.id_customer ORDER BY i.status = 'done', i.id LIMIT 1)" : 'NULL',
            'packaging_records' => $c['packaging'] ? '(SELECT COUNT(*) FROM shipment_packaging_record spr WHERE spr.id_shipment_log = sl.id)' : 'NULL',
        ];
        $cols = implode(",\n                ", array_map(fn($k, $v) => "{$v} AS {$k}", array_keys($f), $f));
        return $this->all("SELECT sl.id, sl.shipment_date, sl.id_customer, {$customer} AS customer, sl.id_wz, sl.id_cmr, sl.id_wdt, sl.id_diff,
                {$cols}
            FROM shipment_log sl
            " . ($this->hasTable('customer') ? 'LEFT JOIN customer cu ON cu.id = sl.id_customer' : '') . "
            WHERE sl.shipment_date >= ? AND sl.shipment_date <= ?
            ORDER BY sl.shipment_date, sl.id", [$from, $to]);
    }

    /** @return array<int, array<int, float>> wysyłka → produit → quantité de la WZ ($doc = 'wz') ou de la WDT ($doc = 'wdt'). */
    public function quantities(string $doc, string $from, string $to): array
    {
        [$table, $fk, $col] = $doc === 'wz' ? ['document_wz_product', 'id_document_wz', 'id_wz'] : ['document_wdt_product', 'id_document_wdt', 'id_wdt'];
        if (!$this->hasTable('shipment_log') || !$this->hasTable($table)) return [];
        $out = [];
        foreach ($this->all("SELECT sl.id, p.id_product, SUM(p.quantity) AS q FROM shipment_log sl JOIN {$table} p ON p.{$fk} = sl.{$col}
                WHERE sl.shipment_date >= ? AND sl.shipment_date <= ? GROUP BY sl.id, p.id_product", [$from, $to]) as $r) {
            $out[(int) $r['id']][(int) $r['id_product']] = (float) $r['q'];
        }
        return $out;
    }

    /** @return array<string, array> élément → décision du mois examiné */
    public function decisions(string $monthStart): array
    {
        $out = [];
        foreach ($this->all('SELECT r.*, COALESCE(NULLIF(TRIM(u.displayed_name), \'\'), u.login) AS decided_by
                FROM n_shipping_docs_review r LEFT JOIN user u ON u.id = r.id_user WHERE r.month_start = ?', [$monthStart]) as $r) {
            $out[(string) $r['item_key']] = $r;
        }
        return $out;
    }

    /** Une décision par (mois examiné, wysyłka) : la dernière enregistrée remplace la précédente. */
    public function saveDecision(string $monthStart, string $key, int $sourceId, string $decision, ?string $note, string $flags, ?int $userId): void
    {
        $this->db->prepare('INSERT INTO n_shipping_docs_review (month_start, item_key, source_id, decision, note, flags, id_user) VALUES (?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE decision = VALUES(decision), note = VALUES(note), flags = VALUES(flags), id_user = VALUES(id_user), updated_at = NOW()')
            ->execute([$monthStart, $key, $sourceId, $decision, $note, mb_substr($flags, 0, 255), $userId]);
    }
}
