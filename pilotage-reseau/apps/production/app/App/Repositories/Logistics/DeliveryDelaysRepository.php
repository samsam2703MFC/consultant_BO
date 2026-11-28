<?php
declare(strict_types=1);

namespace App\Repositories\Logistics;

use App\Domain\Client\Order\CustomerOrderStatus;
use App\Domain\Client\Order\OpenOrders;
use App\Repositories\Workshop\WorkshopRepository;

/**
 * Asystent « Opóźnienia dostaw » [374] : lecture des livraisons clients (customer_order, WDT document_wdt, départ
 * shipment_log), de leur préparation (palets_delivery : 1 = en cours, 2 = prête sans documents, 3 = expédiée ; listes
 * de colisage picking_list_generation), de la tablette chauffeur (n_delivery_stop, n_delivery_run), des chauffeurs des
 * véhicules et des tournées (n_vehicle.id_driver, n_tour.id_driver → n_prod_employee), de l'historique des dates
 * d'expédition (customer_order_changelog) et des décisions (n_delivery_delay_check, une ligne par commande et par jour).
 * N'écrit que n_delivery_delay_check.
 */
final class DeliveryDelaysRepository extends WorkshopRepository
{
    protected string $table = 'n_delivery_delay_check';

    /**
     * Commandes (sans archivées ni statut historique 4 : OpenOrders::loadingSql ; sans gratis — [408] le gratis part avec les
     * commandes du client, le suivi client se fait sur celles-ci) à expédier entre $today et $next,
     * et celles des jours [$from, $today) encore ouvertes (pas de WDT) ou parties (« W transporcie », statut 3 ou WDT).
     * Départ : première date du journal d'expédition de la WDT ; « en transport depuis » : update_status_date du statut 3.
     */
    public function orders(string $from, string $today, string $next): array
    {
        $open = OpenOrders::sql('co');
        $effective = OpenOrders::effectiveStatusSql('co');
        return $this->all("SELECT co.id, co.reference, co.id_customer, COALESCE(NULLIF(TRIM(cu.company), ''), TRIM(CONCAT(COALESCE(cu.name, ''), ' ', COALESCE(cu.surname, '')))) AS company,
                cu.fac_town AS town, DATE(co.expected_shipping_date) AS shipping_date, co.create_order_date, COALESCE(co.order_type, 'STANDARD') AS order_type,
                co.id_wdt, co.status AS raw_status, co.update_status_date, {$effective} AS status, IF({$open}, 1, 0) AS is_open,
                w.doc_num AS wdt_num, w.invoiced_date AS wdt_at,
                (SELECT MIN(sl.shipment_date) FROM shipment_log sl WHERE sl.id_wdt = co.id_wdt) AS shipped_at,
                (SELECT COALESCE(SUM(cop.quantity_carton), 0) FROM customer_order_product cop WHERE cop.id_order = co.id) AS cartons
            FROM customer_order co
            INNER JOIN customer cu ON cu.id = co.id_customer
            LEFT JOIN document_wdt w ON w.id = co.id_wdt
            WHERE " . OpenOrders::loadingSql('co') . " AND COALESCE(co.is_freebie, 0) = 0 AND co.expected_shipping_date IS NOT NULL
              AND (DATE(co.expected_shipping_date) BETWEEN ? AND ?
                   OR (DATE(co.expected_shipping_date) >= ? AND DATE(co.expected_shipping_date) < ? AND ({$open} OR {$effective} = " . CustomerOrderStatus::IN_TRANSIT . ")))
            ORDER BY co.expected_shipping_date, company, co.id", [$today, $next, $from, $today]);
    }

    /**
     * Palettes par client et jour de livraison. @return array<string, array{building:int, ready:int, shipped:int}> « client|jour »
     */
    public function pallets(string $from, string $to): array
    {
        $out = [];
        foreach ($this->all('SELECT id_customer, delivery_date, SUM(status = 1) AS building, SUM(status >= 2) AS ready, SUM(status = 3) AS shipped
                FROM palets_delivery WHERE delivery_date BETWEEN ? AND ? GROUP BY id_customer, delivery_date', [$from, $to]) as $r) {
            $out[(int) $r['id_customer'] . '|' . substr((string) $r['delivery_date'], 0, 10)] = ['building' => (int) $r['building'], 'ready' => (int) $r['ready'], 'shipped' => (int) $r['shipped']];
        }
        return $out;
    }

    /**
     * Première liste de colisage (production lancée) de chaque commande, générée depuis $since.
     * @return array<int, string> commande => generated_at
     */
    public function pickingLists(array $orderIds, string $since): array
    {
        $want = array_flip(array_map('intval', $orderIds));
        if (!$want) return [];
        $out = [];
        foreach ($this->all('SELECT generated_at, selected_order_ids_json FROM picking_list_generation WHERE generated_at >= ? ORDER BY generated_at, id', [$since]) as $r) {
            $ids = json_decode((string) $r['selected_order_ids_json'], true);
            if (!is_array($ids)) continue;
            foreach ($ids as $id) {
                if (!is_scalar($id) || !isset($want[(int) $id])) continue;
                $out[(int) $id] ??= (string) $r['generated_at'];
            }
        }
        return $out;
    }

    /**
     * Arrêts de la tablette chauffeur (un par client et par jour) : livré, arrivé, problème avec sa raison.
     * @return array<string, array> « client|jour »
     */
    public function stops(string $from, string $to): array
    {
        $out = [];
        foreach ($this->all('SELECT ship_date, id_customer, id_carrier_vehicle, status, reason, note, recorded_at FROM n_delivery_stop WHERE ship_date BETWEEN ? AND ?', [$from, $to]) as $r) {
            $out[(int) $r['id_customer'] . '|' . substr((string) $r['ship_date'], 0, 10)] = $r;
        }
        return $out;
    }

    /** Tournées déclarées sur la tablette chauffeur : départ et retour. @return array<string, array> « véhicule|jour » */
    public function runs(string $from, string $to): array
    {
        $out = [];
        foreach ($this->all('SELECT ship_date, id_carrier_vehicle, driver_name, started_at, finished_at FROM n_delivery_run WHERE ship_date BETWEEN ? AND ?', [$from, $to]) as $r) {
            $out[(int) $r['id_carrier_vehicle'] . '|' . substr((string) $r['ship_date'], 0, 10)] = $r;
        }
        return $out;
    }

    /** Chauffeurs des tournées (n_tour.id_driver). @return array<int, int> tournée => employé */
    public function tourDrivers(): array
    {
        $out = [];
        foreach ($this->all('SELECT id, id_driver FROM n_tour WHERE id_driver IS NOT NULL') as $r) $out[(int) $r['id']] = (int) $r['id_driver'];
        return $out;
    }

    /** Noms des employés (chauffeurs). @return array<int, string> */
    public function employeeNames(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($i) => $i > 0)));
        if (!$ids) return [];
        $in = implode(',', array_fill(0, count($ids), '?'));
        $out = [];
        foreach ($this->all("SELECT e.id, TRIM(CONCAT(COALESCE(pe.name, ''), ' ', COALESCE(pe.surname, ''))) AS name FROM n_prod_employee e
                JOIN production_employee pe ON pe.id = e.id_production_employee WHERE e.id IN ({$in})", $ids) as $r) {
            if (trim((string) $r['name']) !== '') $out[(int) $r['id']] = trim((string) $r['name']);
        }
        return $out;
    }

    /** Entrées « update » de l'historique des commandes (avant / après), dans l'ordre. @return array<int, list<array>> */
    public function changes(array $orderIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $orderIds)));
        if (!$ids) return [];
        $in = implode(',', array_fill(0, count($ids), '?'));
        $out = [];
        foreach ($this->all("SELECT id_order, changed_fields, created_at FROM customer_order_changelog WHERE id_order IN ({$in}) AND action_type = 'update'
                ORDER BY created_at, id", $ids) as $r) {
            $out[(int) $r['id_order']][] = $r;
        }
        return $out;
    }

    /** @return array<int, array> décisions du jour par commande */
    public function checks(string $day): array
    {
        $out = [];
        foreach ($this->all("SELECT c.id_order, c.decision, c.new_date, c.note, c.updated_at, COALESCE(NULLIF(TRIM(u.displayed_name), ''), u.login) AS by_name
                FROM n_delivery_delay_check c LEFT JOIN user u ON u.id = c.id_user WHERE c.day = ?", [$day]) as $r) {
            $out[(int) $r['id_order']] = $r;
        }
        return $out;
    }

    /** Dernière décision avant le jour donné, par commande (rappel : « nowy termin » déjà noté). @return array<int, array> */
    public function previous(array $orderIds, string $beforeDay): array
    {
        $ids = array_values(array_unique(array_map('intval', $orderIds)));
        if (!$ids) return [];
        $in = implode(',', array_fill(0, count($ids), '?'));
        $out = [];
        foreach ($this->all("SELECT c.id_order, c.day, c.decision, c.new_date, c.note, COALESCE(NULLIF(TRIM(u.displayed_name), ''), u.login) AS by_name
                FROM n_delivery_delay_check c LEFT JOIN user u ON u.id = c.id_user WHERE c.id_order IN ({$in}) AND c.day < ? ORDER BY c.day, c.id", [...$ids, $beforeDay]) as $r) {
            $out[(int) $r['id_order']] = $r;
        }
        return $out;
    }

    /** Une décision par commande et par jour : la dernière enregistrée remplace la précédente. */
    public function saveCheck(int $orderId, string $day, string $decision, ?string $newDate, ?string $note, string $flags, ?string $shipDate, ?int $userId): void
    {
        $this->db->prepare('INSERT INTO n_delivery_delay_check (id_order, day, decision, new_date, note, flags, ship_date, id_user) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE decision = VALUES(decision), new_date = VALUES(new_date), note = VALUES(note), flags = VALUES(flags), ship_date = VALUES(ship_date),
                id_user = VALUES(id_user), updated_at = NOW()')
            ->execute([$orderId, $day, $decision, $newDate, $note, mb_substr($flags, 0, 255), $shipDate, $userId]);
    }
}
