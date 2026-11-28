<?php
declare(strict_types=1);

namespace App\Repositories\Client\Complaint;

use App\Repositories\Complaint\ComplaintRepository;
use App\Repositories\Workshop\WorkshopRepository;

/**
 * Asystent « Reklamacje klientów » [379] : lecture des réclamations (complaint : complaint_type, complaint_reason,
 * id_customer, id_product, product_quantity, accepted_quantity, acceptance_status, reported_at, acceptance_timestamp,
 * production_response, client_comment — ComplaintRepository::getAll / detailRow, TodayRepository::getOpenComplaints), du
 * client (customer.company), du produit (final_product.name), du groupe de production du produit (comme
 * ComplaintBoardRepository : n_prod_product_settings.id_primary_group de l'article « product », sinon le premier groupe
 * actif de la section du produit — c'est le tablet de ce groupe qui montre la réclamation acceptée), des « Widziane » des
 * groupes (n_complaint_seen, migration 215, comme ComplaintBoardRepository::seen) et des NC « klient » (n_nc.source =
 * 'customer' : number, reported_at, id_final_product, status). Valeur (cartons entamés × prix du carton,
 * ComplaintRepository::valueSql) lue seulement quand elle peut être montrée ([312]).
 * N'écrit que n_complaint_review.
 */
final class ComplaintsReviewRepository extends WorkshopRepository
{
    protected string $table = 'n_complaint_review';

    /** Groupe de production du produit de la réclamation $a (SQL), même règle que ComplaintBoardRepository::FROM. */
    private static function groupSql(string $a): string
    {
        return "COALESCE(
                    (SELECT s.id_primary_group FROM n_prod_item i JOIN n_prod_product_settings s ON s.id_item = i.id
                      WHERE i.id_final_product = {$a}.id_product AND i.item_type = 'product' AND s.id_primary_group IS NOT NULL ORDER BY i.id LIMIT 1),
                    (SELECT MIN(g.id) FROM n_prod_group g JOIN final_product f ON f.id = {$a}.id_product WHERE g.active = 1 AND g.id_section = f.id_section))";
    }

    /**
     * Réclamations utiles à la semaine examinée : toutes les ouvertes (NEW / IN_REVIEW, statut vide = NEW), les acceptées
     * depuis $acceptedFrom, les signalées depuis $reportedFrom (semaine et récurrences sur 8 semaines) et celles qui ont
     * une décision pour la semaine $weekStart. $values : colonnes de valeur (sinon absentes de la requête).
     */
    public function complaints(string $acceptedFrom, string $reportedFrom, string $weekStart, bool $values): array
    {
        $v = $values ? ', ' . ComplaintRepository::valueSql('c', 'product_quantity') . ' AS value, ' . ComplaintRepository::valueSql('c', 'accepted_quantity') . ' AS accepted_value' : '';
        return $this->all("SELECT c.id, c.complaint_type, c.complaint_reason, c.id_customer, cu.company AS customer, c.id_product, p.name AS product,
                c.product_quantity, c.accepted_quantity, COALESCE(c.acceptance_status, 'NEW') AS status, c.reported_at, c.acceptance_timestamp,
                c.production_response, c.client_comment, " . self::groupSql('c') . " AS group_id{$v},
                r.decision, r.note, r.flags, r.status_at, r.updated_at AS decided_at, COALESCE(NULLIF(TRIM(u.displayed_name), ''), u.login) AS decided_by
            FROM complaint c
            LEFT JOIN customer cu ON cu.id = c.id_customer
            LEFT JOIN final_product p ON p.id = c.id_product
            LEFT JOIN n_complaint_review r ON r.id_complaint = c.id AND r.week_start = ?
            LEFT JOIN user u ON u.id = r.id_user
            WHERE COALESCE(c.acceptance_status, 'NEW') IN ('NEW', 'IN_REVIEW')
               OR (c.acceptance_status = 'ACCEPTED' AND c.acceptance_timestamp >= ?)
               OR COALESCE(c.reported_at, c.acceptance_timestamp) >= ?
               OR r.id IS NOT NULL
            ORDER BY COALESCE(c.reported_at, c.acceptance_timestamp), c.id", [$weekStart, $acceptedFrom . ' 00:00:00', $reportedFrom . ' 00:00:00']);
    }

    /** @return array<int, array<int, array{seen_at:string, name:?string}>> réclamation → groupe → premier « Widziane » */
    public function seen(array $complaintIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $complaintIds))));
        if (!$ids) return [];
        $out = [];
        foreach ($this->all('SELECT s.id_complaint, s.id_group, s.seen_at, TRIM(CONCAT(COALESCE(pe.name, \'\'), \' \', COALESCE(pe.surname, \'\'))) AS name
                FROM n_complaint_seen s LEFT JOIN n_prod_employee e ON e.id = s.id_employee LEFT JOIN production_employee pe ON pe.id = e.id_production_employee
                WHERE s.id_complaint IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY s.seen_at', $ids) as $r) {
            $out[(int) $r['id_complaint']][(int) $r['id_group']] ??= ['seen_at' => (string) $r['seen_at'], 'name' => $r['name'] !== '' ? $r['name'] : null];
        }
        return $out;
    }

    /** @return array<int, string> groupes de production (id → nom) */
    public function groups(): array
    {
        return array_column($this->all('SELECT id, name_pl FROM n_prod_group ORDER BY sort_order, name_pl'), 'name_pl', 'id');
    }

    /** NC d'origine client (n_nc.source = 'customer') enregistrées depuis $from (rapprochées par produit et date). */
    public function customerNcs(string $from): array
    {
        return $this->all("SELECT id, number, reported_at, id_final_product, status FROM n_nc WHERE source = 'customer' AND reported_at >= ? ORDER BY reported_at, id", [$from]);
    }

    /** Une décision par (semaine examinée, réclamation) : la dernière enregistrée remplace la précédente. */
    public function saveDecision(string $weekStart, int $complaintId, string $decision, ?string $note, string $flags, ?string $status, ?int $userId): void
    {
        $this->db->prepare('INSERT INTO n_complaint_review (week_start, id_complaint, decision, note, flags, status_at, id_user) VALUES (?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE decision = VALUES(decision), note = VALUES(note), flags = VALUES(flags), status_at = VALUES(status_at), id_user = VALUES(id_user), updated_at = NOW()')
            ->execute([$weekStart, $complaintId, $decision, $note, mb_substr($flags, 0, 255), $status, $userId]);
    }
}
