<?php
namespace App\Repositories\Production\Cost;

use App\Core\Helpers\BaseRepository;

class CostMonthSectionRepository extends BaseRepository
{
    protected string $table = 'cost_month_section';

    protected $allowedColumns = [
        'month_id','section_id','cartons_qty','source'
    ];

    public function getByMonthId(int $monthId): array
    {
        $sql = "
            SELECT cms.*, ps.name AS section_name
            FROM {$this->table} cms
            INNER JOIN production_sections ps ON ps.id = cms.section_id
            WHERE cms.month_id = :mid
            ORDER BY ps.id ASC
        ";
        return $this->query($sql, ['mid'=>$monthId]) ?: [];
    }

    public function getOne(int $monthId, int $sectionId): ?array
    {
        $sql = "SELECT * FROM {$this->table} WHERE month_id=:m AND section_id=:s LIMIT 1";
        $res = $this->query($sql, ['m'=>$monthId,'s'=>$sectionId]);
        return !empty($res) ? $res[0] : null;
    }

    public function upsert(int $monthId, int $sectionId, int $cartonsQty, string $source = 'AUTO'): bool
    {
        $cartonsQty = max(0, (int)$cartonsQty);
        $source = strtoupper($source) === 'MANUAL' ? 'MANUAL' : 'AUTO';

        $sql = "
            INSERT INTO {$this->table} (month_id, section_id, cartons_qty, source)
            VALUES (:m, :s, :q, :src)
            ON DUPLICATE KEY UPDATE
                cartons_qty = VALUES(cartons_qty),
                source      = VALUES(source)
        ";
        return (bool)$this->executeStatement($sql, [
            'm'=>$monthId,'s'=>$sectionId,'q'=>$cartonsQty,'src'=>$source
        ]);
    }

    public function updateCartonsManual(int $monthId, int $sectionId, int $cartonsQty): bool
    {
        return $this->upsert($monthId, $sectionId, $cartonsQty, 'MANUAL');
    }

    /**
     * Pobiera ilości kartonów sprzedanych per dział z faktur WDT
     * w podanym zakresie dat (established_date jako TEXT YYYY-MM-DD).
     *
     * Zwraca tablicę: [['section_id' => int, 'section_name' => string, 'cartons_qty' => int], ...]
     */
    public function getWdtCartonsBySection(string $dateFrom, string $dateTo): array
    {
        $sql = "
            SELECT
                fp.id_section                    AS section_id,
                ps.name                          AS section_name,
                COALESCE(SUM(wdp.quantity), 0)   AS cartons_qty
            FROM document_wdt wdt
            INNER JOIN document_wdt_product wdp  ON wdp.id_document_wdt = wdt.id
            INNER JOIN final_product fp           ON fp.id = wdp.id_product
            INNER JOIN production_sections ps     ON ps.id = fp.id_section
            WHERE wdt.established_date >= :date_from
              AND wdt.established_date <= :date_to
            GROUP BY fp.id_section, ps.name
            ORDER BY ps.name ASC
        ";
        return $this->query($sql, [
            'date_from' => $dateFrom,
            'date_to'   => $dateTo,
        ]) ?: [];
    }
}
