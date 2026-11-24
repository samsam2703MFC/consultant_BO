<?php
declare(strict_types=1);

namespace App\Repositories\Logistics;

use App\Repositories\Workshop\WorkshopRepository;

/**
 * Asystent « Powiadomienia o dostawie » [382] : lecture
 *  - des avis de livraison d'un jour d'expédition (n_delivery_notice : id, ship_date, id_customer, channel 'mail' / 'sms',
 *    recipient, status queued / logged / sent / failed, error, id_message, created_by, created_at — migration 062), avec
 *    l'état du message e-mail dans la file (communication_message.status, comme DeliveryNoticeRepository::forDay), lu
 *    seulement si la table existe ;
 *  - des contacts des clients (customer.email_head, email_salesman, phone_head, phone_alt — mêmes colonnes que l'écran
 *    Powiadomienia klientów, DeliveryNoticeService) ;
 *  - de la règle automatique de l'avis (n_mail_rule : event = 'delivery_planned', enabled, days_before — « dzień przed
 *    dostawą » de la Dystrybucja dokumentów, MailEventSource::candidates).
 * Une source dont la table manque (base partielle) est rendue null (« brak danych »). N'écrit que n_delivery_notice_check.
 */
final class DeliveryNoticesCheckRepository extends WorkshopRepository
{
    protected string $table = 'n_delivery_notice_check';

    /** @var array<string, bool> */
    private array $tables = [];

    public function hasTable(string $table): bool
    {
        return $this->tables[$table] ??= (bool) $this->all('SELECT 1 AS x FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$table]);
    }

    /** Avis des jours donnés, du plus ancien au plus récent ; null si la table manque. */
    public function notices(array $days): ?array
    {
        if (!$this->hasTable('n_delivery_notice')) return null;
        if (!$days) return [];
        $mail = $this->hasTable('communication_message');
        $in = implode(', ', array_fill(0, count($days), '?'));
        return $this->all('SELECT n.id, n.ship_date, n.id_customer, n.channel, n.recipient, n.status, n.error, n.id_message, n.created_by, n.created_at, '
            . ($mail ? 'm.status AS mail_status' : 'NULL AS mail_status') . '
            FROM n_delivery_notice n ' . ($mail ? 'LEFT JOIN communication_message m ON m.id = n.id_message ' : '') . "
            WHERE n.ship_date IN ({$in}) ORDER BY n.ship_date, n.id", array_values($days));
    }

    /** Contacts des clients : id => [email_head, email_salesman, phone_head, phone_alt]. */
    public function contacts(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if (!$ids) return [];
        $out = [];
        foreach ($this->all('SELECT id, email_head, email_salesman, phone_head, phone_alt FROM customer WHERE id IN (' . implode(', ', array_fill(0, count($ids), '?')) . ')', $ids) as $r) {
            $out[(int) $r['id']] = $r;
        }
        return $out;
    }

    /** Règle automatique de l'avis : null si la table manque, sinon [enabled, days_before] (règle active la plus large). */
    public function noticeRule(): ?array
    {
        if (!$this->hasTable('n_mail_rule')) return null;
        $r = $this->all("SELECT MAX(enabled) AS enabled, MAX(CASE WHEN enabled = 1 THEN days_before END) AS days_before FROM n_mail_rule WHERE event = 'delivery_planned'")[0] ?? [];
        return ['enabled' => (int) ($r['enabled'] ?? 0) === 1, 'days_before' => $r['days_before'] !== null ? (int) $r['days_before'] : null];
    }

    /** Décisions des jours d'expédition donnés : « jour|client » => décision, avec le nom de l'auteur. */
    public function decisions(array $days): array
    {
        if (!$days) return [];
        $out = [];
        foreach ($this->all('SELECT c.ship_date, c.id_customer, c.checked_on, c.decision, c.note, c.id_notice, c.flags, c.updated_at,
                COALESCE(NULLIF(TRIM(u.displayed_name), \'\'), u.login) AS decided_by
            FROM n_delivery_notice_check c LEFT JOIN user u ON u.id = c.id_user
            WHERE c.ship_date IN (' . implode(', ', array_fill(0, count($days), '?')) . ')', array_values($days)) as $r) {
            $out[substr((string) $r['ship_date'], 0, 10) . '|' . (int) $r['id_customer']] = $r;
        }
        return $out;
    }

    /** Une décision par (jour d'expédition, client) : la dernière enregistrée remplace la précédente. */
    public function saveDecision(string $shipDate, int $customerId, string $checkedOn, string $decision, ?string $note, ?int $noticeId, string $flags, ?int $userId): void
    {
        $this->db->prepare('INSERT INTO n_delivery_notice_check (ship_date, id_customer, checked_on, decision, note, id_notice, flags, id_user) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE checked_on = VALUES(checked_on), decision = VALUES(decision), note = VALUES(note), id_notice = VALUES(id_notice), flags = VALUES(flags),
                id_user = VALUES(id_user), updated_at = NOW()')
            ->execute([$shipDate, $customerId, $checkedOn, $decision, $note, $noticeId, mb_substr($flags, 0, 255), $userId]);
    }
}
