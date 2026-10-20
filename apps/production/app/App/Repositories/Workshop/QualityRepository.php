<?php
declare(strict_types=1);

namespace App\Repositories\Workshop;

/** Kontrola jakości na tablecie : stan kontroli partii dnia i niezgodności zgłoszone z grupy. */
final class QualityRepository extends WorkshopRepository
{
    protected string $table = 'tfb_prod_output';

    /** Partie dnia grupy (bez anulowanych) z kartą i stanem kontroli. */
    public function batches(string $day, int $groupId): array
    {
        return $this->all("SELECT o.id, o.id_item, o.quantity, o.lot_code, o.created_at, o.qc_status, o.qc_at, o.qc_note, o.id_nc,
                                  COALESCE(fp.name, pr.name) AS name, i.base_unit, fp.ppc, i.id_final_product,
                                  TRIM(CONCAT(pe.name, ' ', pe.surname)) AS employee_name, TRIM(CONCAT(qpe.name, ' ', qpe.surname)) AS qc_employee_name, nc.number AS nc_number
                           FROM tfb_prod_output o
                           JOIN tfb_prod_item i ON i.id = o.id_item
                           LEFT JOIN final_product fp ON fp.id = i.id_final_product
                           LEFT JOIN product_recipe pr ON pr.id = i.id_recipe
                           LEFT JOIN tfb_prod_employee e ON e.id = o.id_employee
                           LEFT JOIN production_employee pe ON pe.id = e.id_production_employee
                           LEFT JOIN tfb_prod_employee qe ON qe.id = o.qc_employee
                           LEFT JOIN production_employee qpe ON qpe.id = qe.id_production_employee
                           LEFT JOIN tfb_nc nc ON nc.id = o.id_nc
                           WHERE o.work_date = ? AND o.id_group = ? AND o.cancelled_at IS NULL
                           ORDER BY o.created_at, o.id", [$day, $groupId]);
    }

    public function setQc(int $outputId, string $status, ?int $employeeId, ?string $note, ?int $ncId): void
    {
        $this->db->prepare('UPDATE tfb_prod_output SET qc_status = ?, qc_at = NOW(), qc_employee = ?, qc_note = ?, id_nc = ? WHERE id = ?')
            ->execute([$status, $employeeId, $note, $ncId, $outputId]);
    }

    public function setNcGroup(int $ncId, int $groupId): void
    {
        $this->db->prepare('UPDATE tfb_nc SET id_prod_group = ? WHERE id = ?')->execute([$groupId, $ncId]);
    }

    /** Niezgodności zgłoszone z grupy w ostatnich dniach (najnowsze najpierw). */
    public function reports(int $groupId, int $days): array
    {
        return $this->all("SELECT nc.id, nc.number, nc.reported_at, nc.description, nc.severity, nc.status, fp.name AS product
                           FROM tfb_nc nc LEFT JOIN final_product fp ON fp.id = nc.id_final_product
                           WHERE nc.id_prod_group = ? AND nc.reported_at >= CURDATE() - INTERVAL ? DAY
                           ORDER BY nc.reported_at DESC, nc.id DESC LIMIT 30", [$groupId, $days]);
    }
}
