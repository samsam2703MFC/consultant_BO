<?php
declare(strict_types=1);

namespace App\Repositories\Notification;

use App\Repositories\Workshop\WorkshopRepository;

/**
 * Échéances dépassées : réclamations sans décision, essais de nouveaux produits planifiés et pas faits, date visée
 * d'un nouveau produit passée, coûts d'un mois non saisis (aucun salaire compté dans le calculateur de coûts).
 */
final class DeadlineAlertRepository extends WorkshopRepository
{
    protected string $table = 'app_notification';

    /** Réclamations nouvelles ou en vérification reçues avant $before (les plus anciennes d'abord). */
    public function lateComplaints(string $before): array
    {
        return $this->all("SELECT c.id, COALESCE(c.acceptance_status, 'NEW') AS status, c.acceptance_timestamp AS received_at, c.complaint_reason,
                cu.company AS customer, fp.name AS product
            FROM complaint c
            LEFT JOIN customer cu ON cu.id = c.id_customer
            LEFT JOIN final_product fp ON fp.id = c.id_product
            WHERE COALESCE(c.acceptance_status, 'NEW') IN ('NEW', 'IN_REVIEW') AND c.acceptance_timestamp IS NOT NULL
              AND c.acceptance_timestamp < ?
            ORDER BY c.acceptance_timestamp, c.id", [$before]);
    }

    /** Écarts d'expédition sans décision dont l'échéance est avant $today (file Wysyłki › Różnice i problemy dostaw). */
    public function lateShippingIssues(string $today): array
    {
        return $this->all("SELECT i.id, i.source, i.opened_on, i.due_on, c.company AS customer, s.reason, COALESCE(NULLIF(u.displayed_name, ''), u.login) AS owner
            FROM n_shipping_issue i
            LEFT JOIN customer c ON c.id = i.id_customer
            LEFT JOIN n_delivery_stop s ON s.id = i.id_delivery_stop
            LEFT JOIN user u ON u.id = i.id_owner
            WHERE i.status = 'open' AND i.due_on < ?
            ORDER BY i.due_on, i.id", [$today]);
    }

    /** Écarts d'expédition pas encore clos : à décider et en cours. */
    public function openShippingIssues(): array
    {
        return $this->all("SELECT status, COUNT(*) AS n FROM n_shipping_issue WHERE status IN ('open', 'decided') GROUP BY status");
    }

    /** Essais planifiés au bureau dont la date est avant $today, produit toujours en développement. */
    public function lateTrials(string $today): array
    {
        return $this->all("SELECT tr.id, tr.trial_no, tr.planned_date, tp.id AS product_id, tp.name, g.name_pl AS group_name
            FROM n_dev_trial tr
            JOIN test_product tp ON tp.id = tr.id_test_product
            LEFT JOIN n_dev_product d ON d.id_test_product = tp.id
            LEFT JOIN n_prod_group g ON g.id = tr.id_group
            WHERE tr.status = 'planned' AND tr.planned_date < ? AND COALESCE(d.stage, 'idea') NOT IN ('launch', 'launched', 'stopped')
            ORDER BY tr.planned_date, tr.id", [$today]);
    }

    /** Nouveaux produits dont la date visée est avant $today, sans lancement. */
    public function lateTargets(string $today): array
    {
        return $this->all("SELECT tp.id, tp.name, d.target_date, d.stage
            FROM n_dev_product d JOIN test_product tp ON tp.id = d.id_test_product
            WHERE d.target_date IS NOT NULL AND d.target_date < ? AND d.stage NOT IN ('launched', 'stopped')
            ORDER BY d.target_date, tp.id", [$today]);
    }

    /** Nouveaux produits dont la recette est validée par l'équipe et qui attendent le lancement au bureau. */
    public function waitingLaunch(): int
    {
        return (int) ($this->all("SELECT COUNT(*) AS n FROM n_dev_product WHERE stage = 'launch'")[0]['n'] ?? 0);
    }

    /** Mois (AAAA-MM) dont les coûts sont saisis : au moins un salaire compté dans le calculateur de coûts. */
    public function costMonthsEntered(): array
    {
        return array_column($this->all("SELECT CONCAT(LPAD(m.year, 4, '0'), '-', LPAD(m.month, 2, '0')) AS ym
            FROM cost_month m WHERE EXISTS (SELECT 1 FROM cost_month_employee e WHERE e.month_id = m.id AND e.is_included = 1)
            ORDER BY m.year, m.month"), 'ym');
    }
}
