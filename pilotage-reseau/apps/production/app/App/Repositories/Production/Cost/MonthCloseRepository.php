<?php
namespace App\Repositories\Production\Cost;

use App\Core\Helpers\BaseRepository;

/**
 * Assistant « Zamknięcie miesiąca » (audit 2, P14) : lectures pour l'état des 8 étapes (personnel du mois face au
 * budget et à M-1, heures des tablettes, coûts par catégorie, inventaire matières, dernier recalcul des marges, rapport
 * envoyé) et copie d'une catégorie de coûts depuis le dernier mois qui en a.
 */
class MonthCloseRepository extends BaseRepository
{
    protected string $table = 'cost_month';

    /** Catégories copiables : table, colonne du mois. */
    public const CATEGORIES = [
        'media' => ['cost_media_month', 'cost_month_id'],
        'fixed' => ['cost_month_fixed_cost', 'month_id'],
        'logistics' => ['cost_month_logistics', 'month_id'],
    ];

    public function month(int $year, int $month): ?array
    {
        $rows = $this->query("SELECT m.*, u.displayed_name AS locked_by_name FROM cost_month m LEFT JOIN user u ON u.id = m.locked_by
                              WHERE m.year = :y AND m.month = :m LIMIT 1", ['y' => $year, 'm' => $month]);
        return $rows ? $rows[0] : null;
    }

    /** Les 12 mois avant $ym (inclus) avec leur statut (null = mois pas encore créé dans le calculateur). */
    public function statuses(array $yms): array
    {
        $out = array_fill_keys($yms, null);
        foreach ($this->query("SELECT CONCAT(year, '-', LPAD(month, 2, '0')) AS ym, status FROM cost_month") ?: [] as $r) {
            if (array_key_exists($r['ym'], $out)) $out[$r['ym']] = $r['status'];
        }
        return $out;
    }

    /** Personnel du mois : coût du mois, coût du budget (fiche salarié), coût de M-1. */
    public function staff(int $monthId, ?int $prevMonthId): array
    {
        return $this->query(
            "SELECT e.employee_ref_id, TRIM(CONCAT(e.name, ' ', e.surname)) AS name, ps.name AS section, e.monthly_cost,
                    pe.cost AS budget_cost, p.monthly_cost AS prev_cost
             FROM cost_month_employee e
             LEFT JOIN production_employee pe ON pe.id = e.employee_ref_id
             LEFT JOIN production_sections ps ON ps.id = e.section_id
             LEFT JOIN cost_month_employee p ON p.month_id = :prev AND p.employee_ref_id = e.employee_ref_id AND p.is_included = 1
             WHERE e.month_id = :m AND e.is_included = 1
             ORDER BY ps.name, e.surname, e.name",
            ['m' => $monthId, 'prev' => $prevMonthId ?? -1]
        ) ?: [];
    }

    /** Salariés de la liste du personnel (avec un département, comme l'ajout automatique) absents du mois (ni inclus ni exclus). */
    public function staffMissing(int $monthId): int
    {
        $rows = $this->query("SELECT COUNT(*) AS n FROM production_employee pe JOIN production_sections ps ON ps.id = pe.id_section
                              WHERE NOT EXISTS (SELECT 1 FROM cost_month_employee e WHERE e.month_id = :m AND e.employee_ref_id = pe.id)", ['m' => $monthId]);
        return (int) ($rows[0]['n'] ?? 0);
    }

    /** Somme et nombre de salariés inclus d'un mois. */
    public function staffTotal(int $monthId): array
    {
        $rows = $this->query("SELECT COUNT(*) AS n, COALESCE(SUM(monthly_cost), 0) AS total FROM cost_month_employee WHERE month_id = :m AND is_included = 1", ['m' => $monthId]);
        return ['count' => (int) ($rows[0]['n'] ?? 0), 'total' => round((float) ($rows[0]['total'] ?? 0), 2)];
    }

    /**
     * Heures des tablettes du mois (pointages fermés, coupés aux bornes du mois) par salarié de l'ERP
     * (n_prod_employee.id_production_employee ; clé 0 = personne de la tablette sans fiche ERP) et pointages ouverts.
     * @return array<int, array{hours: float, open: int, people: int}>
     */
    public function tabletHours(string $from, string $toExclusive): array
    {
        $out = [];
        foreach ($this->query(
            "SELECT COALESCE(ne.id_production_employee, 0) AS ref, COUNT(DISTINCT c.id_employee) AS people,
                    COALESCE(SUM(TIMESTAMPDIFF(SECOND, GREATEST(c.clock_in, :f1), LEAST(c.clock_out, :t1))), 0) / 3600 AS hours,
                    SUM(c.clock_out IS NULL) AS open_clocks
             FROM n_prod_clock c JOIN n_prod_employee ne ON ne.id = c.id_employee
             WHERE c.clock_in < :t2 AND (c.clock_out IS NULL OR c.clock_out > :f2)
             GROUP BY COALESCE(ne.id_production_employee, 0)",
            ['f1' => $from, 't1' => $toExclusive, 'f2' => $from, 't2' => $toExclusive]
        ) ?: [] as $r) {
            $out[(int) $r['ref']] = ['hours' => round((float) $r['hours'], 1), 'open' => (int) $r['open_clocks'], 'people' => (int) $r['people']];
        }
        return $out;
    }

    /** Nombre de lignes et total d'une catégorie de coûts pour un mois. */
    public function categoryTotal(string $category, int $monthId): array
    {
        [$table, $col] = self::CATEGORIES[$category];
        $rows = $this->query("SELECT COUNT(*) AS n, COALESCE(SUM(amount), 0) AS total FROM {$table} WHERE {$col} = :m", ['m' => $monthId]);
        return ['count' => (int) ($rows[0]['n'] ?? 0), 'total' => round((float) ($rows[0]['total'] ?? 0), 2)];
    }

    /** Dernier mois avant (année, mois) qui a des lignes dans la catégorie : la source de la copie. */
    public function latestWith(string $category, int $year, int $month): ?array
    {
        [$table, $col] = self::CATEGORIES[$category];
        $rows = $this->query(
            "SELECT m.id, m.year, m.month FROM cost_month m
             WHERE (m.year * 12 + m.month) < :ym AND EXISTS (SELECT 1 FROM {$table} c WHERE c.{$col} = m.id)
             ORDER BY m.year DESC, m.month DESC LIMIT 1",
            ['ym' => $year * 12 + $month]
        );
        return $rows ? $rows[0] : null;
    }

    /**
     * Copie une catégorie d'un mois vers un autre (mois cible vide dans cette catégorie, contrôlé par le service).
     * Coûts fixes : mois de service et de comptabilisation = mois cible. Médias : montants et répartition par département.
     */
    public function copyCategory(string $category, int $fromId, int $toId, string $toYm): bool
    {
        if ($category === 'fixed') {
            return (bool) $this->executeStatement(
                "INSERT INTO cost_month_fixed_cost (month_id, name, amount, category, service_month, posting_month)
                 SELECT :to, name, amount, category, :ym1, :ym2 FROM cost_month_fixed_cost WHERE month_id = :from ORDER BY id",
                ['to' => $toId, 'ym1' => $toYm, 'ym2' => $toYm, 'from' => $fromId]
            );
        }
        if ($category === 'logistics') {
            return (bool) $this->executeStatement(
                "INSERT INTO cost_month_logistics (month_id, logistics_type_id, amount, note)
                 SELECT :to, logistics_type_id, amount, note FROM cost_month_logistics WHERE month_id = :from ORDER BY id",
                ['to' => $toId, 'from' => $fromId]
            );
        }
        $ok = (bool) $this->executeStatement(
            "INSERT INTO cost_media_month (cost_month_id, media_type_id, amount, note)
             SELECT :to, media_type_id, amount, note FROM cost_media_month WHERE cost_month_id = :from ORDER BY id",
            ['to' => $toId, 'from' => $fromId]
        );
        // Répartition des médias par département : seulement si le mois cible n'en a pas encore.
        $this->executeStatement(
            "INSERT INTO cost_media_section_allocation (cost_month_id, media_type_id, section_id, allocation_percent, note)
             SELECT :to, a.media_type_id, a.section_id, a.allocation_percent, a.note FROM cost_media_section_allocation a
             WHERE a.cost_month_id = :from AND NOT EXISTS (SELECT 1 FROM cost_media_section_allocation x WHERE x.cost_month_id = :to2)",
            ['to' => $toId, 'from' => $fromId, 'to2' => $toId]
        );
        return $ok;
    }

    /** Dernier inventaire complet des matières (feuille d'inventaire, journal « material_inventory »). */
    public function lastInventories(int $limit = 3): array
    {
        return $this->query(
            "SELECT l.entity_id AS count_date, l.created_at, u.displayed_name AS by_name,
                    (SELECT COUNT(DISTINCT c.id_raw_material) FROM n_mp_count c WHERE c.count_date = l.entity_id) AS materials
             FROM n_prod_audit_log l LEFT JOIN user u ON u.id = l.id_user
             WHERE l.entity = 'material_inventory' ORDER BY l.entity_id DESC, l.id DESC LIMIT " . max(1, $limit)
        ) ?: [];
    }

    /** Matières avec un comptage (n'importe lequel) entre deux dates. */
    public function countedMaterials(string $from, string $to): int
    {
        $rows = $this->query("SELECT COUNT(DISTINCT id_raw_material) AS n FROM n_mp_count WHERE count_date BETWEEN :f AND :t", ['f' => $from, 't' => $to]);
        return (int) ($rows[0]['n'] ?? 0);
    }

    /** Changements du coût matières par carton au dernier recalcul (le plus fort en % d'abord). */
    public function marginChanges(string $runAt, int $limit = 10): array
    {
        return $this->query(
            "SELECT r.id_product, fp.name, r.old_cost_carton, r.new_cost_carton,
                    CASE WHEN r.old_cost_carton > 0 THEN (r.new_cost_carton - r.old_cost_carton) / r.old_cost_carton * 100 END AS delta_pct
             FROM n_margin_recalc r JOIN final_product fp ON fp.id = r.id_product
             WHERE r.recalculated_at = :at AND ABS(r.new_cost_carton - r.old_cost_carton) >= 0.00005
             ORDER BY ABS(COALESCE((r.new_cost_carton - r.old_cost_carton) / NULLIF(r.old_cost_carton, 0), 1)) DESC, fp.name LIMIT " . max(1, $limit),
            ['at' => $runAt]
        ) ?: [];
    }

    /** Produits sur stock (MTS) : date du plus ancien et du dernier calcul des stocks depuis les ventes. */
    public function stockCalc(): array
    {
        $rows = $this->query("SELECT COUNT(*) AS mts, MIN(stock_calc_at) AS oldest, MAX(stock_calc_at) AS latest, SUM(stock_calc_at IS NULL) AS never
                              FROM n_prod_product_settings WHERE mode = 'MTS'");
        $r = $rows[0] ?? [];
        return ['mts' => (int) ($r['mts'] ?? 0), 'oldest' => $r['oldest'] ?? null, 'latest' => $r['latest'] ?? null, 'never' => (int) ($r['never'] ?? 0)];
    }

    /** Rapports mensuels envoyés pour le mois (envoi automatique ou manuel, pièce jointe RAPORT_MIESIECZNY_AAAA-MM_*). */
    public function reportsSent(string $ym): array
    {
        return $this->query(
            "SELECT m.id, m.recipient_email, m.status, m.created_by, m.created_at, m.sent_at
             FROM communication_message m JOIN communication_message_attachment a ON a.id_message = m.id
             WHERE m.message_type = 'management_report' AND a.filename LIKE :f ORDER BY m.id DESC LIMIT 10",
            ['f' => 'RAPORT_MIESIECZNY_' . $ym . '_%']
        ) ?: [];
    }
}
