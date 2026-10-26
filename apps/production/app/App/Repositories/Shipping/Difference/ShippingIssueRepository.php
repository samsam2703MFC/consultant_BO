<?php
declare(strict_types=1);

namespace App\Repositories\Shipping\Difference;

use App\Repositories\Workshop\WorkshopRepository;

/**
 * File des écarts d'expédition (n_shipping_issue) : différences proforma payée ↔ WDT
 * (document_order_shipping_difference) et problèmes signalés par le chauffeur (n_delivery_stop, statut « problem »).
 */
final class ShippingIssueRepository extends WorkshopRepository
{
    protected string $table = 'n_shipping_issue';
    protected array $writable = ['source', 'id_difference', 'id_delivery_stop', 'id_customer', 'opened_on', 'due_on', 'id_owner', 'status',
        'decision', 'note', 'decided_by', 'decided_at', 'closed_by', 'closed_at'];

    /** Montant d'un document de différences (WDT − proforma payée, devise du WDT) : positif = le client doit payer. */
    private const AMOUNT = "SUM(COALESCE(p.invoiced_total_amount, p.invoiced_quantity * p.price_during_invoicing)
        - COALESCE(p.ordered_total_amount, p.quantity_ordered * p.price_during_ordering))";

    /**
     * Documents de différences depuis $since, pas encore dans la file, avec une proforma payée (au moins une ligne
     * commandée) et un montant d'au moins $minAmount en valeur absolue.
     */
    public function newDifferences(string $since, float $minAmount): array
    {
        return $this->all("SELECT d.id, d.id_customer, d.established_date, " . self::AMOUNT . " AS amount
            FROM document_order_shipping_difference d
            JOIN document_order_shipping_difference_products p ON p.id_document_order_shipping_difference = d.id
            WHERE d.established_date >= ? AND NOT EXISTS (SELECT 1 FROM n_shipping_issue i WHERE i.id_difference = d.id)
            GROUP BY d.id, d.id_customer, d.established_date
            HAVING SUM(p.quantity_ordered > 0) > 0 AND ABS(amount) >= ?
            ORDER BY d.established_date, d.id", [$since, $minAmount]);
    }

    /** Problèmes de livraison (tablette chauffeur) depuis $since, pas encore dans la file. */
    public function newDeliveryProblems(string $since): array
    {
        return $this->all("SELECT s.id, s.id_customer, s.ship_date FROM n_delivery_stop s
            WHERE s.status = 'problem' AND s.ship_date >= ? AND NOT EXISTS (SELECT 1 FROM n_shipping_issue i WHERE i.id_delivery_stop = s.id)
            ORDER BY s.ship_date, s.id", [$since]);
    }

    /** Premier utilisateur ERP au poste $code (responsable par défaut), sinon null. */
    public function ownerFor(string $code): ?int
    {
        $rows = $this->all("SELECT up.id_user FROM n_user_position up JOIN n_position p ON p.id = up.id_position WHERE p.code = ? ORDER BY up.id_user LIMIT 1", [$code]);
        return $rows ? (int) $rows[0]['id_user'] : null;
    }

    /** Écarts de la file avec client, document, montant, responsable et dernier message envoyé au client. */
    public function list(?string $status, ?int $ownerId, int $limit = 300): array
    {
        $where = [];
        $params = [];
        if ($status !== null) { $where[] = 'i.status = ?'; $params[] = $status; }
        if ($ownerId !== null) { $where[] = 'i.id_owner = ?'; $params[] = $ownerId; }
        $sql = "SELECT i.*, c.company AS customer, c.communication_lang,
                COALESCE(NULLIF(u.displayed_name, ''), u.login) AS owner_name,
                diff.amount, diff.lines_changed, wdt.wdt_id, wdt.wdt_number, wdt.currency,
                s.reason, s.note AS stop_note, s.ship_date, s.receiver_name, s.driver_name, s.pallets_delivered, s.pallets_returned,
                (SELECT MAX(COALESCE(m.sent_at, m.created_at)) FROM communication_message m WHERE i.id_difference IS NOT NULL AND m.id_difference = i.id_difference) AS last_message_at,
                (SELECT m.message_type FROM communication_message m WHERE i.id_difference IS NOT NULL AND m.id_difference = i.id_difference ORDER BY m.id DESC LIMIT 1) AS last_message_type
            FROM n_shipping_issue i
            LEFT JOIN customer c ON c.id = i.id_customer
            LEFT JOIN user u ON u.id = i.id_owner
            LEFT JOIN n_delivery_stop s ON s.id = i.id_delivery_stop
            LEFT JOIN (
                SELECT p.id_document_order_shipping_difference AS id, " . self::AMOUNT . " AS amount,
                    SUM(p.quantity_ordered <> p.invoiced_quantity OR p.price_during_ordering <> p.price_during_invoicing) AS lines_changed
                FROM document_order_shipping_difference_products p
                WHERE p.id_document_order_shipping_difference IN (SELECT id_difference FROM n_shipping_issue WHERE id_difference IS NOT NULL)
                GROUP BY p.id_document_order_shipping_difference
            ) diff ON diff.id = i.id_difference
            -- WDT à part : une différence peut avoir plusieurs lignes d'expédition (le montant ne doit pas être multiplié)
            LEFT JOIN (
                SELECT l.id_diff, MAX(w.id) AS wdt_id, MAX(w.doc_num) AS wdt_number, MAX(w.currency) AS currency
                FROM shipment_log l JOIN document_wdt w ON w.id = l.id_wdt
                WHERE l.id_diff IN (SELECT id_difference FROM n_shipping_issue WHERE id_difference IS NOT NULL)
                GROUP BY l.id_diff
            ) wdt ON wdt.id_diff = i.id_difference"
            . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . "
            ORDER BY FIELD(i.status, 'open', 'decided', 'done'), i.due_on, i.id LIMIT " . max(1, $limit);
        return $this->all($sql, $params);
    }

    public function one(int $id): ?array
    {
        $rows = $this->all("SELECT i.*, (SELECT " . self::AMOUNT . " FROM document_order_shipping_difference_products p
                WHERE p.id_document_order_shipping_difference = i.id_difference) AS amount
            FROM n_shipping_issue i WHERE i.id = ?", [$id]);
        return $rows[0] ?? null;
    }

    /** Lignes d'un document de différences qui diffèrent (quantité ou prix), avec la raison saisie. */
    public function differenceLines(int $differenceId): array
    {
        return $this->all("SELECT p.id_product, p.product_name, p.quantity_ordered, p.invoiced_quantity, p.price_during_ordering, p.price_during_invoicing,
                COALESCE(p.invoiced_total_amount, p.invoiced_quantity * p.price_during_invoicing)
                    - COALESCE(p.ordered_total_amount, p.quantity_ordered * p.price_during_ordering) AS amount, p.difference_reason
            FROM document_order_shipping_difference_products p
            WHERE p.id_document_order_shipping_difference = ? AND (p.quantity_ordered <> p.invoiced_quantity OR p.price_during_ordering <> p.price_during_invoicing)
            ORDER BY p.product_name", [$differenceId]);
    }

    /** Compteurs de la file : à décider, en cours, en retard ; montants ouverts à rembourser / à encaisser. */
    public function counters(string $today): array
    {
        $rows = $this->all("SELECT i.status, COUNT(*) AS n, SUM(i.status = 'open' AND i.due_on < ?) AS late,
                SUM(CASE WHEN x.amount < 0 THEN -x.amount ELSE 0 END) AS to_refund, SUM(CASE WHEN x.amount > 0 THEN x.amount ELSE 0 END) AS to_collect
            FROM n_shipping_issue i
            LEFT JOIN (SELECT p.id_document_order_shipping_difference AS id, " . self::AMOUNT . " AS amount
                FROM document_order_shipping_difference_products p
                WHERE p.id_document_order_shipping_difference IN (SELECT id_difference FROM n_shipping_issue WHERE id_difference IS NOT NULL AND status <> 'done')
                GROUP BY p.id_document_order_shipping_difference) x ON x.id = i.id_difference
            GROUP BY i.status", [$today]);
        $out = ['open' => 0, 'decided' => 0, 'done' => 0, 'late' => 0, 'to_refund' => 0.0, 'to_collect' => 0.0];
        foreach ($rows as $r) {
            $out[$r['status']] = (int) $r['n'];
            $out['late'] += (int) $r['late'];
            if ($r['status'] !== 'done') {
                $out['to_refund'] += (float) $r['to_refund'];
                $out['to_collect'] += (float) $r['to_collect'];
            }
        }
        return $out;
    }

    /** Utilisateurs ERP pouvant être responsables (avec leur poste). */
    public function users(): array
    {
        return $this->all("SELECT u.id, COALESCE(NULLIF(u.displayed_name, ''), u.login) AS name, p.name_pl AS position
            FROM user u LEFT JOIN n_user_position up ON up.id_user = u.id LEFT JOIN n_position p ON p.id = up.id_position
            ORDER BY name");
    }

    public function userExists(int $id): bool
    {
        return (bool) $this->all("SELECT 1 FROM user WHERE id = ?", [$id]);
    }
}
