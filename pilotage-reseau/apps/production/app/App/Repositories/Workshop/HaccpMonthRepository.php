<?php
declare(strict_types=1);

namespace App\Repositories\Workshop;

/**
 * Asystent « HACCP miesiąca » [380] : lecture
 *  - des événements HACCP du module Kierownik produkcji (n_mgr_haccp_event : event_date, kind, authority, result, score,
 *    treated, treated_at, description, created_by — migration 041 renommée par 069, comme ManagerRepository::haccpEvents) et
 *    du seuil d'audit du plan de l'année (n_mgr_plan.audit_threshold, comme ManagerService::haccp) ;
 *  - des procédures SOP (n_sop : code, title, area, status, version, review_months, review_at — migration 210, comme
 *    SopRepository::list) ;
 *  - des exécutions avec écart (n_sop_run : result = 'deviation', steps JSON {step, title, confirm, ok, value, unit},
 *    finished_at, id_user, id_nc, source_path ; NC n_nc.number ; personne user — comme SopRepository::runs) et des
 *    étapes actuelles des procédures (n_sop_step : sort, title, tag — étapes HACCP tag = 'haccp') ;
 *  - des incidents de livraison « Temperatura » de la tablette chauffeur (n_delivery_incident : kind = 'temperature',
 *    note, recorded_at, driver_name — migration 195 ; arrêt n_delivery_stop : ship_date, id_customer ; client
 *    customer.company, comme DeliveryRepository) ;
 *  - des postes (n_position) pour l'action corrective (comme OtifWeekRepository::positions).
 * Une source dont la table manque (base partielle) est rendue null (« brak danych »). N'écrit que n_haccp_month_review.
 */
final class HaccpMonthRepository extends WorkshopRepository
{
    protected string $table = 'n_haccp_month_review';

    /** @var array<string, bool> */
    private array $tables = [];

    public function hasTable(string $table): bool
    {
        return $this->tables[$table] ??= (bool) $this->all('SELECT 1 AS x FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$table]);
    }

    /** Nombre de lignes d'une source (0 = jamais rien enregistré, « brak danych »), null si la table manque. */
    public function sourceRows(string $table, string $where = '1 = 1'): ?int
    {
        if (!$this->hasTable($table)) return null;
        return (int) ($this->all("SELECT COUNT(*) AS n FROM {$table} WHERE {$where}")[0]['n'] ?? 0);
    }

    /** Événements HACCP du $from au $to inclus, plus les ruptures de la chaîne du froid non traitées depuis $backlogFrom. */
    public function haccpEvents(string $from, string $to, string $backlogFrom): ?array
    {
        if (!$this->hasTable('n_mgr_haccp_event')) return null;
        return $this->all("SELECT e.id, e.event_date, e.kind, e.authority, e.result, e.score, e.treated, e.treated_at, e.description,
                COALESCE(NULLIF(TRIM(u.displayed_name), ''), u.login) AS created_by_name
            FROM n_mgr_haccp_event e LEFT JOIN user u ON u.id = e.created_by
            WHERE e.event_date BETWEEN ? AND ? OR (e.kind = 'cold_chain_break' AND e.treated = 0 AND e.event_date >= ? AND e.event_date <= ?)
            ORDER BY e.event_date, e.id", [$from, $to, $backlogFrom, $to]);
    }

    /** @return array<int, float> année → seuil d'audit indépendant du plan (n_mgr_plan.audit_threshold) */
    public function auditThresholds(): array
    {
        if (!$this->hasTable('n_mgr_plan')) return [];
        $out = [];
        foreach ($this->all('SELECT year, audit_threshold FROM n_mgr_plan WHERE audit_threshold IS NOT NULL') as $r) $out[(int) $r['year']] = (float) $r['audit_threshold'];
        return $out;
    }

    /** Procédures en vigueur (status = 'active') : date de revue, nombre d'étapes HACCP. */
    public function activeSops(): ?array
    {
        if (!$this->hasTable('n_sop')) return null;
        $haccp = $this->hasTable('n_sop_step') ? "(SELECT COUNT(*) FROM n_sop_step t WHERE t.id_sop = s.id AND t.tag = 'haccp')" : '0';
        return $this->all("SELECT s.id, s.code, s.title, s.area, s.status, s.version, s.review_months, s.review_at, s.approved_at, {$haccp} AS haccp_steps
            FROM n_sop s WHERE s.status = 'active' ORDER BY s.code");
    }

    /** Exécutions de SOP avec écart, terminées du $from au $to inclus. */
    public function deviationRuns(string $from, string $to): ?array
    {
        if (!$this->hasTable('n_sop_run') || !$this->hasTable('n_sop')) return null;
        $nc = $this->hasTable('n_nc') ? 'n.number' : 'NULL';
        $ncJoin = $this->hasTable('n_nc') ? 'LEFT JOIN n_nc n ON n.id = r.id_nc' : '';
        return $this->all("SELECT r.id, r.id_sop, r.version, r.source_path, r.finished_at, r.steps, r.id_nc, {$nc} AS nc_number, s.code, s.title,
                COALESCE(NULLIF(TRIM(u.displayed_name), ''), u.login) AS user_name
            FROM n_sop_run r JOIN n_sop s ON s.id = r.id_sop LEFT JOIN user u ON u.id = r.id_user {$ncJoin}
            WHERE r.result = 'deviation' AND r.finished_at >= ? AND r.finished_at < DATE_ADD(?, INTERVAL 1 DAY)
            ORDER BY r.finished_at, r.id", [$from, $to]);
    }

    /** @return array<int, list<array{sort:int, title:string, tag:?string}>> procédure → étapes actuelles (ordre sort, id) */
    public function sopSteps(array $sopIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $sopIds))));
        if (!$ids || !$this->hasTable('n_sop_step')) return [];
        $out = [];
        foreach ($this->all('SELECT id_sop, sort, title, tag FROM n_sop_step WHERE id_sop IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY id_sop, sort, id', $ids) as $r) {
            $out[(int) $r['id_sop']][] = ['sort' => (int) $r['sort'], 'title' => (string) $r['title'], 'tag' => $r['tag']];
        }
        return $out;
    }

    /** Incidents « Temperatura » de la tablette chauffeur enregistrés du $from au $to inclus. */
    public function temperatureIncidents(string $from, string $to): ?array
    {
        if (!$this->hasTable('n_delivery_incident') || !$this->hasTable('n_delivery_stop')) return null;
        return $this->all("SELECT i.id, i.id_stop, i.note, i.recorded_at, i.driver_name, s.ship_date, s.id_customer,
                COALESCE(NULLIF(TRIM(cu.company), ''), TRIM(CONCAT(COALESCE(cu.name, ''), ' ', COALESCE(cu.surname, '')))) AS customer
            FROM n_delivery_incident i JOIN n_delivery_stop s ON s.id = i.id_stop LEFT JOIN customer cu ON cu.id = s.id_customer
            WHERE i.kind = 'temperature' AND i.recorded_at >= ? AND i.recorded_at < DATE_ADD(?, INTERVAL 1 DAY)
            ORDER BY i.recorded_at, i.id", [$from, $to]);
    }

    /**
     * Tendance : par mois (YYYY-MM) du $from (1er du mois) au $to exclu — événements HACCP défavorables ou ruptures de la
     * chaîne du froid, exécutions avec écart, incidents de température. Source absente → clé absente.
     * @return array<string, array<string, int>> source → mois → nombre
     */
    public function monthlyCounts(string $from, string $to): array
    {
        $out = [];
        $by = function (string $source, string $sql) use (&$out, $from, $to) {
            $out[$source] = array_column(array_map(fn($r) => ['m' => (string) $r['m'], 'n' => (int) $r['n']], $this->all($sql, [$from, $to])), 'n', 'm');
        };
        if ($this->hasTable('n_mgr_haccp_event')) {
            $by('haccp', "SELECT DATE_FORMAT(event_date, '%Y-%m') AS m, COUNT(*) AS n FROM n_mgr_haccp_event
                WHERE event_date >= ? AND event_date < ? AND (kind = 'cold_chain_break' OR result = 'unfavorable') GROUP BY m");
        }
        if ($this->hasTable('n_sop_run')) {
            $by('sop_run', "SELECT DATE_FORMAT(finished_at, '%Y-%m') AS m, COUNT(*) AS n FROM n_sop_run WHERE result = 'deviation' AND finished_at >= ? AND finished_at < ? GROUP BY m");
        }
        if ($this->hasTable('n_delivery_incident')) {
            $by('temp', "SELECT DATE_FORMAT(recorded_at, '%Y-%m') AS m, COUNT(*) AS n FROM n_delivery_incident WHERE kind = 'temperature' AND recorded_at >= ? AND recorded_at < ? GROUP BY m");
        }
        return $out;
    }

    /** @return array<string, list<array>> élément → décisions (la plus récente d'abord), tous mois confondus */
    public function decisions(array $keys): array
    {
        $keys = array_values(array_unique(array_filter($keys)));
        if (!$keys) return [];
        $out = [];
        foreach ($this->all('SELECT r.*, COALESCE(NULLIF(TRIM(u.displayed_name), \'\'), u.login) AS decided_by, COALESCE(NULLIF(TRIM(o.displayed_name), \'\'), o.login) AS responsible_name,
                    p.name_pl AS position_name
                FROM n_haccp_month_review r LEFT JOIN user u ON u.id = r.id_user LEFT JOIN user o ON o.id = r.id_responsible LEFT JOIN n_position p ON p.id = r.id_position
                WHERE r.item_key IN (' . implode(',', array_fill(0, count($keys), '?')) . ') ORDER BY r.month_start DESC, r.updated_at DESC, r.id DESC', $keys) as $r) {
            $out[(string) $r['item_key']][] = $r;
        }
        return $out;
    }

    /** @return list<array{id:int, name:string}> postes actifs (responsable de l'action corrective) */
    public function positions(): array
    {
        return array_map(fn($r) => ['id' => (int) $r['id'], 'name' => (string) $r['name_pl']],
            $this->all('SELECT id, name_pl FROM n_position WHERE active = 1 ORDER BY sort_order, name_pl'));
    }

    /** Une décision par (mois examiné, élément) : la dernière enregistrée remplace la précédente. */
    public function saveDecision(string $monthStart, string $key, string $kind, int $sourceId, string $decision, ?string $note, ?int $responsible, ?int $position, string $flags, ?int $userId): void
    {
        $this->db->prepare('INSERT INTO n_haccp_month_review (month_start, item_key, kind, source_id, decision, note, id_responsible, id_position, flags, id_user) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE decision = VALUES(decision), note = VALUES(note), id_responsible = VALUES(id_responsible), id_position = VALUES(id_position),
                flags = VALUES(flags), id_user = VALUES(id_user), updated_at = NOW()')
            ->execute([$monthStart, $key, $kind, $sourceId, $decision, $note, $responsible, $position, mb_substr($flags, 0, 255), $userId]);
    }
}
