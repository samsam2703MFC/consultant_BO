<?php
declare(strict_types=1);

namespace App\Repositories\Communication;

use App\Repositories\Workshop\WorkshopRepository;

/**
 * Stan wysyłek : les files d'envoi de l'ERP lues ensemble — e-mails clients (communication_message), envois aux
 * franchisés (outbox : proforma.upload / proforma.notify / complaint.update), webhooks d'expédition
 * (webhook_outbox_shipments), notifications de surveillance (cron_notification_outbox), e-mails automatiques
 * (n_mail_dispatch), récupération des commandes des boutiques (n_order_collect_issue), domówienia transmises aux
 * boutiques (customer_order.supplement_push_status) — et le journal des actions (n_send_issue_action). Les payloads ne sont jamais renvoyés tels quels :
 * ils contiennent des secrets (signature, jeton, URL de rappel) ; seules les clés utiles sont extraites.
 */
final class SendStatusRepository extends WorkshopRepository
{
    protected string $table = 'n_send_issue_action';

    /** Une tentative de plus après « Ponów » : nombre de tentatives remis à (maximum du worker − 1). */
    public const EMAIL_ATTEMPTS = 5;       // CommunicationEmailDeliveryWorker::RETRY_DELAYS
    public const FRANCHISE_ATTEMPTS = 6;   // MAX_ATTEMPTS de Bin/worker_outbox.php
    public const SHIPMENT_ATTEMPTS = 6;    // $maxAttempts de Bin/shipments_retry.php
    public const MONITORING_ATTEMPTS = 8;  // CronDiscordNotificationWorker::MAX_ATTEMPTS

    /** Clés du payload outbox qu'on peut montrer (jamais secret, auth_token, callback_url, s3_key). */
    private const SAFE_KEYS = ['order_key', 'franchisee_uid', 'franchisee_code', 'proforma_uuid', 'complaint_key', 'acceptance_status'];

    /**
     * E-mails en erreur (dernière tentative faite) ou bloqués (en file après $stuckBefore, ou en cours depuis), depuis $since.
     */
    public function emails(string $since, string $stuckBefore): array
    {
        return $this->all("SELECT m.id, m.message_type AS kind, m.status, m.attempts, m.error_message AS error, m.created_at,
                COALESCE(m.next_try_at, m.queued_at, m.created_at) AS last_at, m.next_try_at, m.id_customer, c.company AS customer,
                m.recipient_email AS recipient, m.subject_text AS subject, m.id_proforma, m.id_wdt, m.id_invoice
            FROM communication_message m LEFT JOIN customer c ON c.id = m.id_customer
            WHERE (m.status = 'failed' OR (m.status = 'queued' AND m.next_try_at < ?) OR (m.status = 'processing' AND m.locked_at < ?))
              AND COALESCE(m.next_try_at, m.created_at) >= ?
            ORDER BY m.id DESC LIMIT 500", [$stuckBefore, $stuckBefore, $since]);
    }

    /** Envois aux franchisés (outbox) morts ou bloqués, avec les seules clés sûres du payload. */
    public function franchise(string $since, string $stuckBefore): array
    {
        $rows = $this->all("SELECT id, type AS kind, status, attempts, last_error AS error, created_at, COALESCE(updated_at, created_at) AS last_at, next_try_at, payload_json
            FROM outbox
            WHERE (status IN ('dead', 'failed') OR (status = 'pending' AND next_try_at < ?) OR (status = 'processing' AND locked_at < ?))
              AND COALESCE(updated_at, created_at) >= ?
            ORDER BY id DESC LIMIT 500", [$stuckBefore, $stuckBefore, $since]);
        foreach ($rows as &$r) {
            $payload = json_decode((string) $r['payload_json'], true);
            $r['payload'] = is_array($payload) ? array_intersect_key(array_change_key_case($payload, CASE_LOWER), array_flip(self::SAFE_KEYS)) : [];
            unset($r['payload_json']);
        }
        return $rows;
    }

    /** Webhooks « wysyłka » vers les boutiques en échec ou bloqués. */
    public function shipments(string $since, string $stuckBefore): array
    {
        return $this->all("SELECT w.id, 'shipment.sent' AS kind, w.status, w.attempts, w.last_error AS error, w.created_at, COALESCE(w.updated_at, w.created_at) AS last_at,
                w.next_try AS next_try_at, w.id_order, c.id AS id_customer, c.company AS customer
            FROM webhook_outbox_shipments w LEFT JOIN customer c ON c.franchisee_uid = w.shop_uid
            WHERE (w.status = 'failed' OR (w.status = 'pending' AND w.next_try < ?) OR (w.status = 'processing' AND w.updated_at < ?))
              AND COALESCE(w.updated_at, w.created_at) >= ?
            ORDER BY w.id DESC LIMIT 500", [$stuckBefore, $stuckBefore, $since]);
    }

    /** Notifications de surveillance (Discord) mortes ou bloquées, avec la tâche planifiée concernée. */
    public function monitoring(string $since, string $stuckBefore): array
    {
        return $this->all("SELECT n.id, 'cron.notify' AS kind, n.status, n.attempts, n.last_error AS error, n.created_at, COALESCE(n.next_try_at, n.created_at) AS last_at,
                n.next_try_at, r.job_name, r.status AS run_status
            FROM cron_notification_outbox n LEFT JOIN cron_runs r ON r.id = n.cron_run_id
            WHERE (n.status = 'dead' OR (n.status = 'pending' AND n.next_try_at < ?) OR (n.status = 'processing' AND n.locked_at < ?))
              AND n.created_at >= ?
            ORDER BY n.id DESC LIMIT 500", [$stuckBefore, $stuckBefore, $since]);
    }

    /** E-mails automatiques non partis : échec, ou cas sauté pour une raison à corriger (sauf $ignoredReasons). */
    public function automation(string $since, array $ignoredReasons): array
    {
        $ignore = $ignoredReasons ? ' AND d.reason NOT IN (' . implode(',', array_fill(0, count($ignoredReasons), '?')) . ')' : '';
        return $this->all("SELECT d.id, r.template_type AS kind, d.status, NULL AS attempts, d.reason AS error, d.created_at, d.created_at AS last_at, NULL AS next_try_at,
                d.ref_key, r.code AS rule_code, r.event
            FROM n_mail_dispatch d JOIN n_mail_rule r ON r.id = d.id_rule
            WHERE d.created_at >= ? AND (d.status = 'failed' OR (d.status = 'skipped' AND d.reason IS NOT NULL{$ignore}))
            ORDER BY d.id DESC LIMIT 500", array_merge([$since], $ignoredReasons));
    }

    /** Commandes des boutiques : cas ouverts (configuration, récupération, import, produit inconnu) vus depuis $since. */
    public function shops(string $since): array
    {
        return $this->all("SELECT i.id, i.kind, 'failed' AS status, i.occurrences AS attempts, i.message AS error, i.first_at AS created_at, i.last_at,
                NULL AS next_try_at, i.id_customer, c.company AS customer, i.order_key, i.id_order
            FROM n_order_collect_issue i LEFT JOIN customer c ON c.id = i.id_customer
            WHERE i.closed_at IS NULL AND i.last_at >= ?
            ORDER BY i.id DESC LIMIT 500", [$since]);
    }

    /** Domówienia non transmises à la boutique : échec, ou en attente depuis $stuckBefore ; motif = dernier changelog système. */
    public function supplements(string $since, string $stuckBefore): array
    {
        return $this->all("SELECT co.id, 'supplement.push' AS kind, co.supplement_push_status AS status, NULL AS attempts,
                (SELECT l.changed_fields FROM customer_order_changelog l WHERE l.id_order = co.id AND l.source = 'system' ORDER BY l.id DESC LIMIT 1) AS error,
                co.create_order_date AS created_at, COALESCE(co.supplement_push_at, co.create_order_date) AS last_at,
                CASE WHEN co.supplement_push_status = 'pending' THEN COALESCE(co.supplement_push_at, co.create_order_date) END AS next_try_at,
                co.id_customer, c.company AS customer, co.supplement_order_key AS order_key
            FROM customer_order co LEFT JOIN customer c ON c.id = co.id_customer
            WHERE co.order_type = 'SUPPLEMENT'
              AND (co.supplement_push_status = 'failed' OR (co.supplement_push_status = 'pending' AND COALESCE(co.supplement_push_at, co.create_order_date) < ?))
              AND COALESCE(co.supplement_push_at, co.create_order_date) >= ?
            ORDER BY co.id DESC LIMIT 500", [$stuckBefore, $since]);
    }

    /** Dernier envoi réussi par file (santé des tâches planifiées). */
    public function lastSuccess(): array
    {
        $one = fn(string $sql) => $this->all($sql)[0]['at'] ?? null;
        return [
            'email' => $one("SELECT MAX(sent_at) AS at FROM communication_message WHERE status = 'sent'"),
            'franchise' => $one("SELECT MAX(updated_at) AS at FROM outbox WHERE status = 'done'"),
            'shipment' => $one("SELECT MAX(updated_at) AS at FROM webhook_outbox_shipments WHERE status = 'sent'"),
            'monitoring' => $one("SELECT MAX(sent_at) AS at FROM cron_notification_outbox WHERE status = 'sent'"),
            'automation' => $one("SELECT MAX(created_at) AS at FROM n_mail_dispatch WHERE status = 'queued'"),
            'shops' => $one("SELECT MAX(created_at) AS at FROM customer_order_changelog WHERE source = 'api' AND action_type = 'create'"),
            'supplement' => $one("SELECT MAX(supplement_push_at) AS at FROM customer_order WHERE supplement_push_status = 'sent'"),
        ];
    }

    /** Clients franchisés par identifiant de boutique. */
    public function customersByShop(array $uids): array
    {
        $uids = array_values(array_unique(array_filter($uids, 'is_string')));
        if (!$uids) return [];
        $rows = $this->all('SELECT id, company, franchisee_uid FROM customer WHERE franchisee_uid IN (' . implode(',', array_fill(0, count($uids), '?')) . ')', $uids);
        return array_column($rows, null, 'franchisee_uid');
    }

    /** Numéros des proformas par uuid. */
    public function proformasByUuid(array $uuids): array
    {
        $uuids = array_values(array_unique(array_filter($uuids, 'is_string')));
        if (!$uuids) return [];
        return array_column($this->all('SELECT uuid, id, doc_num FROM document_proforma WHERE uuid IN (' . implode(',', array_fill(0, count($uuids), '?')) . ')', $uuids), null, 'uuid');
    }

    /** Numéros des documents liés aux e-mails. */
    public function documentNumbers(string $table, array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids || !in_array($table, ['document_proforma', 'document_wdt', 'document_invoice'], true)) return [];
        return array_column($this->all("SELECT id, doc_num FROM {$table} WHERE id IN (" . implode(',', $ids) . ')'), 'doc_num', 'id');
    }

    /** Dernière action par position : [source][id] => action, raison, auteur, date. */
    public function lastActions(string $source, array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if (!$ids) return [];
        $rows = $this->all("SELECT a.id_item, a.action, a.reason, a.created_at, COALESCE(NULLIF(u.displayed_name, ''), u.login) AS user_name
            FROM n_send_issue_action a LEFT JOIN user u ON u.id = a.id_user
            WHERE a.source = ? AND a.id_item IN (" . implode(',', $ids) . ")
              AND a.id = (SELECT MAX(b.id) FROM n_send_issue_action b WHERE b.source = a.source AND b.id_item = a.id_item)", [$source]);
        return array_column($rows, null, 'id_item');
    }

    public function record(string $source, int $id, string $action, ?string $reason, ?int $userId): void
    {
        $this->db->prepare('INSERT INTO n_send_issue_action (source, id_item, action, reason, id_user) VALUES (?, ?, ?, ?, ?)')
            ->execute([$source, $id, $action, $reason, $userId]);
    }

    /** État d'une position (pour décider si « Ponów » / « Rozwiązane » s'appliquent). */
    public function status(string $source, int $id): ?string
    {
        $sql = match ($source) {
            'email' => 'SELECT status FROM communication_message WHERE id = ?',
            'franchise' => 'SELECT status FROM outbox WHERE id = ?',
            'shipment' => 'SELECT status FROM webhook_outbox_shipments WHERE id = ?',
            'monitoring' => 'SELECT status FROM cron_notification_outbox WHERE id = ?',
            'automation' => 'SELECT status FROM n_mail_dispatch WHERE id = ?',
            'shops' => "SELECT IF(closed_at IS NULL, 'failed', 'closed') AS status FROM n_order_collect_issue WHERE id = ?",
            'supplement' => "SELECT supplement_push_status AS status FROM customer_order WHERE id = ? AND order_type = 'SUPPLEMENT'",
        };
        $row = $this->all($sql, [$id])[0] ?? null;
        return $row ? (string) $row['status'] : null;
    }

    /**
     * Remet une position en erreur dans sa file pour une seule tentative de plus (le worker la reprend à son prochain
     * passage ; s'il échoue encore, elle repasse en erreur). Renvoie false si la position n'est plus en erreur.
     */
    public function requeue(string $source, int $id): bool
    {
        [$sql, $params] = match ($source) {
            'email' => ["UPDATE communication_message SET status = 'queued', attempts = ?, next_try_at = NOW(), locked_at = NULL, locked_by = NULL
                         WHERE id = ? AND status = 'failed'", [self::EMAIL_ATTEMPTS - 1, $id]],
            'franchise' => ["UPDATE outbox SET status = 'pending', attempts = ?, next_try_at = NOW(), locked_at = NULL, locked_by = NULL, updated_at = NOW()
                         WHERE id = ? AND status IN ('dead', 'failed')", [self::FRANCHISE_ATTEMPTS - 1, $id]],
            'shipment' => ["UPDATE webhook_outbox_shipments SET status = 'pending', attempts = ?, next_try = NOW(), updated_at = NOW()
                         WHERE id = ? AND status = 'failed'", [self::SHIPMENT_ATTEMPTS - 1, $id]],
            'monitoring' => ["UPDATE cron_notification_outbox SET status = 'pending', attempts = ?, next_try_at = NOW(), locked_at = NULL, locked_by = NULL
                         WHERE id = ? AND status = 'dead'", [self::MONITORING_ATTEMPTS - 1, $id]],
            // Domówienie : « pending » + date du jour = repris une fois par Bin/mail_dispatch.php (OrderSupplementPusherService::pushRequeued).
            'supplement' => ["UPDATE customer_order SET supplement_push_status = 'pending', supplement_push_at = NOW()
                         WHERE id = ? AND order_type = 'SUPPLEMENT' AND supplement_push_status = 'failed'", [$id]],
        };
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount() > 0;
    }

    public function inTransaction(callable $fn): mixed
    {
        $this->db->beginTransaction();
        try {
            $out = $fn();
            $this->db->commit();
            return $out;
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }
}
