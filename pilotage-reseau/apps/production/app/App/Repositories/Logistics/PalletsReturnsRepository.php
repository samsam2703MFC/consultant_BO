<?php
declare(strict_types=1);

namespace App\Repositories\Logistics;

use App\Repositories\Workshop\WorkshopRepository;

/**
 * Asystent « Palety zwrotne » [381] : lecture
 *  - des arrêts confirmés de la tablette chauffeur (n_delivery_stop : ship_date, id_customer, status 'delivered' /
 *    'problem', pallets_delivered, pallets_returned, note, driver_name — migration 082 ; pallets_note — migration 195,
 *    lue seulement si la colonne existe) avec le nom du client (customer.company, sinon name + surname, comme
 *    DeliveryRepository::stops) ;
 *  - des palettes abîmées signalées à la livraison (n_delivery_incident : kind = 'pallet_damaged', note, recorded_at,
 *    driver_name — migration 195, comme DeliveryRepository::incidents).
 * Le seul suivi existant des palettes rendues est la somme du rapport mensuel (DeliveryRepository::summary, « Palety
 * zwrotne ») : sans client ni palettes livrées, il ne donne pas de solde ; d'où la lecture par arrêt ici.
 * Une source dont la table manque (base partielle) est rendue null (« brak danych »). N'écrit que n_pallet_return_review.
 */
final class PalletsReturnsRepository extends WorkshopRepository
{
    protected string $table = 'n_pallet_return_review';

    /** @var array<string, bool> */
    private array $tables = [];

    public function hasTable(string $table): bool
    {
        return $this->tables[$table] ??= (bool) $this->all('SELECT 1 AS x FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$table]);
    }

    private function hasColumn(string $table, string $column): bool
    {
        return $this->tables[$table . '.' . $column] ??= (bool) $this->all('SELECT 1 AS x FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?', [$table, $column]);
    }

    /** Arrêts confirmés (livré ou problème) du $from au $to inclus ; null si la table manque. */
    public function stops(string $from, string $to): ?array
    {
        if (!$this->hasTable('n_delivery_stop')) return null;
        $note = $this->hasColumn('n_delivery_stop', 'pallets_note') ? 's.pallets_note' : 'NULL';
        return $this->all("SELECT s.id, s.ship_date, s.id_customer, s.status, s.reason, s.pallets_delivered, s.pallets_returned, {$note} AS pallets_note, s.note,
                s.driver_name, COALESCE(NULLIF(TRIM(cu.company), ''), TRIM(CONCAT(COALESCE(cu.name, ''), ' ', COALESCE(cu.surname, '')))) AS customer
            FROM n_delivery_stop s LEFT JOIN customer cu ON cu.id = s.id_customer
            WHERE s.ship_date BETWEEN ? AND ? AND s.status IN ('delivered', 'problem')
            ORDER BY s.ship_date, s.id", [$from, $to]);
    }

    /** Palettes abîmées signalées sur les arrêts du $from au $to inclus (jour d'expédition) ; null si la table manque. */
    public function damaged(string $from, string $to): ?array
    {
        if (!$this->hasTable('n_delivery_incident') || !$this->hasTable('n_delivery_stop')) return null;
        return $this->all("SELECT i.id, i.id_stop, i.note, i.recorded_at, i.driver_name, s.ship_date, s.id_customer,
                COALESCE(NULLIF(TRIM(cu.company), ''), TRIM(CONCAT(COALESCE(cu.name, ''), ' ', COALESCE(cu.surname, '')))) AS customer
            FROM n_delivery_incident i JOIN n_delivery_stop s ON s.id = i.id_stop LEFT JOIN customer cu ON cu.id = s.id_customer
            WHERE i.kind = 'pallet_damaged' AND s.ship_date BETWEEN ? AND ?
            ORDER BY s.ship_date, i.recorded_at, i.id", [$from, $to]);
    }

    /** Décisions de la semaine examinée (client → décision), avec le nom de l'auteur. */
    public function decisions(string $weekStart): array
    {
        $out = [];
        foreach ($this->all("SELECT r.id_customer, r.decision, r.note, r.balance, r.flags, r.updated_at, COALESCE(NULLIF(TRIM(u.displayed_name), ''), u.login) AS decided_by,
                COALESCE(NULLIF(TRIM(cu.company), ''), TRIM(CONCAT(COALESCE(cu.name, ''), ' ', COALESCE(cu.surname, '')))) AS customer
            FROM n_pallet_return_review r LEFT JOIN user u ON u.id = r.id_user LEFT JOIN customer cu ON cu.id = r.id_customer
            WHERE r.week_start = ?", [$weekStart]) as $r) $out[(int) $r['id_customer']] = $r;
        return $out;
    }

    /** Une décision par (semaine examinée, client) : la dernière enregistrée remplace la précédente. */
    public function saveDecision(string $weekStart, int $customerId, string $decision, ?string $note, int $balance, string $flags, ?int $userId): void
    {
        $this->db->prepare('INSERT INTO n_pallet_return_review (week_start, id_customer, decision, note, balance, flags, id_user) VALUES (?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE decision = VALUES(decision), note = VALUES(note), balance = VALUES(balance), flags = VALUES(flags), id_user = VALUES(id_user), updated_at = NOW()')
            ->execute([$weekStart, $customerId, $decision, $note, $balance, mb_substr($flags, 0, 255), $userId]);
    }
}
