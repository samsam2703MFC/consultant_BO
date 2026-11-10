<?php

namespace App\Repositories\Ksef;

use App\Core\Helpers\BaseRepository;

/** Reads immutable snapshots already stored in document_wdt and document_wdt_product. */
final class KsefWdtInvoiceSourceRepository extends BaseRepository
{
    protected string $table = 'document_wdt';

    public function getForShipment(int $shipmentId): array
    {
        $rows = $this->query(
            'SELECT sl.id AS shipment_id, wdt.*
             FROM shipment_log sl
             INNER JOIN document_wdt wdt ON wdt.id = sl.id_wdt
             WHERE sl.id = :shipment_id
             LIMIT 1',
            ['shipment_id' => $shipmentId]
        );
        return is_array($rows) && isset($rows[0]) ? $rows[0] : [];
    }

    /** @return list<array<string,mixed>> */
    public function getLines(int $wdtId): array
    {
        $rows = $this->query(
            'SELECT id, id_product, name, code, quantity, price_carton, total_net, total_tva, price_brut, tva_perc, ppc, price_per_piece
             FROM document_wdt_product
             WHERE id_document_wdt = :wdt_id
             ORDER BY id ASC',
            ['wdt_id' => $wdtId]
        );
        return is_array($rows) ? $rows : [];
    }
}
