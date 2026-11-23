<?php
declare(strict_types=1);

namespace App\Repositories\Workshop;

/**
 * Asystent « Partie i kontrola dnia » [377] : lecture des journées de groupe closes sur le kiosque (n_prod_day_close :
 * note, heure, personne), de leurs parties de production (n_prod_output : numéro lot_code, contrôle qualité qc_status /
 * qc_at / qc_note / id_nc, annulation cancelled_at), des lots matières de chaque partie (n_prod_output_lot : lot_number,
 * best_before, missing_reason, id_material_lot ; registre n_material_lot : lot_number, best_before, active, off_reason ;
 * surowiec śledzony n_material_trace), des compositions d'autres groupes (n_prod_output_component.id_source_output) et
 * des journées avec production mais pas encore closes. Les mêmes jointures que OutputRepository::day() et trace().
 * N'écrit que n_lots_qc_check.
 */
final class LotsQcDayRepository extends WorkshopRepository
{
    protected string $table = 'n_lots_qc_check';

    /** Jour de la dernière validation de l'assistant avant le jour donné (null = jamais). */
    public function lastDoneDay(string $code, string $beforeDay): ?string
    {
        $r = $this->all('SELECT day FROM n_wizard_done WHERE code = ? AND day < ? ORDER BY day DESC LIMIT 1', [$code, $beforeDay])[0] ?? null;
        return $r ? substr((string) $r['day'], 0, 10) : null;
    }

    /** Journées de groupe closes entre deux jours (inclus) : groupe, note de clôture, heure, personne. */
    public function closes(string $from, string $to): array
    {
        return $this->all("SELECT c.work_date, c.id_group, g.name_pl AS group_name, g.color, c.note, c.closed_at, TRIM(CONCAT(COALESCE(pe.name, ''), ' ', COALESCE(pe.surname, ''))) AS employee_name
            FROM n_prod_day_close c
            JOIN n_prod_group g ON g.id = c.id_group
            LEFT JOIN n_prod_employee e ON e.id = c.id_employee
            LEFT JOIN production_employee pe ON pe.id = e.id_production_employee
            WHERE c.work_date BETWEEN ? AND ?
            ORDER BY c.work_date DESC, g.sort_order, g.name_pl, c.id_group", [$from, $to]);
    }

    /**
     * Parties (non annulées) des journées closes : produit (carte ERP ou recette, comme OutputRepository::ITEM), recette,
     * contrôle qualité, nombre de lots renseignés (même sous-requête que OutputRepository::day : numéro ou motif, matières
     * suivies, compositions avec partie source).
     */
    public function outputs(string $from, string $to): array
    {
        return $this->all("SELECT o.id, o.work_date, o.id_group, o.id_item, o.lot_code, o.quantity, i.base_unit, o.qc_status, o.qc_at, o.qc_note, o.id_nc, o.created_at,
                COALESCE(fp.name, pr.name) AS item_name, COALESCE(i.id_recipe, fp.id_recipe) AS id_recipe,
                (SELECT COUNT(*) FROM n_prod_output_lot l LEFT JOIN n_material_trace t ON t.id_raw_material = l.id_material
                  WHERE l.id_output = o.id AND COALESCE(t.tracked, 1) = 1
                    AND ((l.lot_number IS NOT NULL AND l.lot_number <> '') OR l.missing_reason IS NOT NULL))
                + (SELECT COUNT(*) FROM n_prod_output_component k WHERE k.id_output = o.id AND k.id_source_output IS NOT NULL) AS lots_done
            FROM n_prod_output o
            JOIN n_prod_day_close c ON c.work_date = o.work_date AND c.id_group = o.id_group
            JOIN n_prod_item i ON i.id = o.id_item
            LEFT JOIN final_product fp ON fp.id = i.id_final_product
            LEFT JOIN product_recipe pr ON pr.id = i.id_recipe
            WHERE o.work_date BETWEEN ? AND ? AND o.cancelled_at IS NULL
            ORDER BY o.work_date, o.id_group, o.created_at, o.id", [$from, $to]);
    }

    /**
     * Lots matières des parties, avec la partie du registre choisie (n_material_lot) et le suivi du surowiec.
     * @return array<int, array<int, array>> id_output => id_material => ligne
     */
    public function lots(array $outputIds): array
    {
        $ids = self::ids($outputIds);
        if (!$ids) return [];
        $in = implode(',', array_fill(0, count($ids), '?'));
        $out = [];
        foreach ($this->all("SELECT l.id_output, l.id_material, rm.name AS material_name, l.lot_number, l.best_before, l.missing_reason, l.id_material_lot,
                    COALESCE(t.tracked, 1) AS tracked, ml.lot_number AS reg_lot_number, ml.best_before AS reg_best_before, ml.active AS reg_active, ml.off_reason AS reg_off_reason
                FROM n_prod_output_lot l
                LEFT JOIN raw_material rm ON rm.id = l.id_material
                LEFT JOIN n_material_trace t ON t.id_raw_material = l.id_material
                LEFT JOIN n_material_lot ml ON ml.id = l.id_material_lot
                WHERE l.id_output IN ({$in}) ORDER BY l.id_output, rm.name", $ids) as $r) {
            $out[(int) $r['id_output']][(int) $r['id_material']] = $r;
        }
        return $out;
    }

    /** @return array<int, array<int, ?int>> id_output => id_recipe (composition) => partie source (null = pas choisie) */
    public function components(array $outputIds): array
    {
        $ids = self::ids($outputIds);
        if (!$ids) return [];
        $in = implode(',', array_fill(0, count($ids), '?'));
        $out = [];
        foreach ($this->all("SELECT id_output, id_recipe, id_source_output FROM n_prod_output_component WHERE id_output IN ({$in})", $ids) as $r) {
            $out[(int) $r['id_output']][(int) $r['id_recipe']] = $r['id_source_output'] !== null ? (int) $r['id_source_output'] : null;
        }
        return $out;
    }

    /** Journées avec des parties (non annulées) mais pas encore closes sur le kiosque. */
    public function unclosed(string $from, string $to): array
    {
        return $this->all("SELECT o.work_date, o.id_group, g.name_pl AS group_name, COUNT(*) AS batches
            FROM n_prod_output o
            JOIN n_prod_group g ON g.id = o.id_group
            LEFT JOIN n_prod_day_close c ON c.work_date = o.work_date AND c.id_group = o.id_group
            WHERE o.work_date BETWEEN ? AND ? AND o.cancelled_at IS NULL AND c.id IS NULL
            GROUP BY o.work_date, o.id_group, g.name_pl, g.sort_order
            ORDER BY o.work_date DESC, g.sort_order, g.name_pl", [$from, $to]);
    }

    /** @return array<string, array> résultats par « jour|groupe » pour les journées de la fenêtre */
    public function checks(string $from, string $to): array
    {
        $out = [];
        foreach ($this->all("SELECT c.work_date, c.id_group, c.result, c.note, c.flags, c.gaps, c.checked_on, c.updated_at, COALESCE(NULLIF(TRIM(u.displayed_name), ''), u.login) AS by_name
                FROM n_lots_qc_check c LEFT JOIN user u ON u.id = c.id_user WHERE c.work_date BETWEEN ? AND ?", [$from, $to]) as $r) {
            $out[substr((string) $r['work_date'], 0, 10) . '|' . (int) $r['id_group']] = $r;
        }
        return $out;
    }

    /** Un résultat par journée de groupe : le dernier enregistré remplace le précédent. */
    public function saveCheck(string $workDate, int $groupId, string $result, ?string $note, string $flags, int $gaps, string $checkedOn, ?int $userId): void
    {
        $this->db->prepare('INSERT INTO n_lots_qc_check (work_date, id_group, result, note, flags, gaps, checked_on, id_user) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE result = VALUES(result), note = VALUES(note), flags = VALUES(flags), gaps = VALUES(gaps), checked_on = VALUES(checked_on),
                id_user = VALUES(id_user), updated_at = NOW()')
            ->execute([$workDate, $groupId, $result, $note, mb_substr($flags, 0, 255), $gaps, $checkedOn, $userId]);
    }

    /** @return list<int> */
    private static function ids(array $ids): array
    {
        return array_values(array_unique(array_filter(array_map('intval', $ids), fn($id) => $id > 0)));
    }
}
