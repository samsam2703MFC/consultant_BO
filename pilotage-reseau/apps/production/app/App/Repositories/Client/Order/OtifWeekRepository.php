<?php
declare(strict_types=1);

namespace App\Repositories\Client\Order;

use App\Repositories\Workshop\WorkshopRepository;

/**
 * Asystent « Terminowość tygodnia (OTIF) » [375] : lecture des commandes clients (customer_order, lignes
 * customer_order_product), de leur expédition (WDT document_wdt et ses lignes document_wdt_product — construites à
 * partir des cartons réellement mis sur les palettes du client et du jour, WDTProductRepository::insertSelectForStandardShipping ;
 * journal d'expédition shipment_log : date du document et heure réelle d'enregistrement insert_timestamp), de
 * l'historique (customer_order_changelog : date d'expédition avant / après, passage « W transporcie »), de la tablette
 * chauffeur (n_delivery_stop), des réclamations (complaint), des décisions des assistants n°1, 3 et 5 (n_order_check,
 * n_supplement_check, n_delivery_delay_check — lecture seule) et des postes (n_position).
 * N'écrit que n_otif_week_action et n_otif_week.
 */
final class OtifWeekRepository extends WorkshopRepository
{
    protected string $table = 'n_otif_week_action';

    private const SELECT = "SELECT co.id, co.reference, co.id_customer, COALESCE(NULLIF(TRIM(cu.company), ''), TRIM(CONCAT(COALESCE(cu.name, ''), ' ', COALESCE(cu.surname, '')))) AS company,
            DATE(co.expected_shipping_date) AS shipping_date, COALESCE(co.order_type, 'STANDARD') AS order_type, co.status AS raw_status, co.id_wdt,
            w.doc_num AS wdt_num, w.invoiced_date AS wdt_at,
            (SELECT MIN(sl.shipment_date) FROM shipment_log sl WHERE sl.id_wdt = co.id_wdt) AS shipment_date,
            (SELECT MIN(sl.insert_timestamp) FROM shipment_log sl WHERE sl.id_wdt = co.id_wdt) AS shipment_logged_at,
            (SELECT COALESCE(SUM(cop.quantity_carton), 0) FROM customer_order_product cop WHERE cop.id_order = co.id) AS cartons
        FROM customer_order co
        INNER JOIN customer cu ON cu.id = co.id_customer
        LEFT JOIN document_wdt w ON w.id = co.id_wdt";

    /**
     * Commandes (sans gratis) dues entre $from et $to : « Oczekujące », « W transporcie », « Zrealizowane », et
     * « Zarchiwizowane » (5) — celles-ci sont comptées à part quand elles n'ont pas de WDT (réponse « Nie » : pas
     * expédiées, hors mesure). Le statut historique 4 est exclu, comme partout (OpenOrders).
     */
    public function orders(string $from, string $to): array
    {
        return $this->all(self::SELECT . " WHERE COALESCE(co.is_freebie, 0) = 0 AND co.status IN (1, 2, 3, 5) AND co.expected_shipping_date IS NOT NULL
              AND DATE(co.expected_shipping_date) BETWEEN ? AND ?
            ORDER BY co.expected_shipping_date, company, co.id", [$from, $to]);
    }

    /** Mêmes colonnes, par identifiant (commandes dont la date a été déplacée hors de la fenêtre). */
    public function ordersByIds(array $ids): array
    {
        $ids = self::ids($ids);
        if (!$ids) return [];
        $in = implode(',', array_fill(0, count($ids), '?'));
        return $this->all(self::SELECT . " WHERE COALESCE(co.is_freebie, 0) = 0 AND co.status IN (1, 2, 3, 5) AND co.id IN ({$in}) ORDER BY co.id", $ids);
    }

    /**
     * Changements de la date d'expédition enregistrés depuis $since (toutes commandes) : sert à retrouver les commandes
     * dont la date tombait dans la fenêtre et a été déplacée plus tard. @return list<array{id_order:int, changed_fields:string, created_at:string}>
     */
    public function dateChangesSince(string $since): array
    {
        return $this->all("SELECT id_order, changed_fields, created_at FROM customer_order_changelog
            WHERE action_type = 'update' AND created_at >= ? AND changed_fields LIKE '%expected_shipping_date%' ORDER BY created_at, id", [$since]);
    }

    /** Entrées « update » de l'historique des commandes (date d'expédition, statut), dans l'ordre. @return array<int, list<array>> */
    public function changes(array $orderIds): array
    {
        $ids = self::ids($orderIds);
        if (!$ids) return [];
        $in = implode(',', array_fill(0, count($ids), '?'));
        $out = [];
        foreach ($this->all("SELECT id_order, changed_fields, created_at FROM customer_order_changelog WHERE id_order IN ({$in}) AND action_type = 'update'
                ORDER BY created_at, id", $ids) as $r) {
            $out[(int) $r['id_order']][] = $r;
        }
        return $out;
    }

    /** Lignes commandées par commande. @return array<int, list<array{id_product:int, name:string, qty:float}>> */
    public function orderLines(array $orderIds): array
    {
        $ids = self::ids($orderIds);
        if (!$ids) return [];
        $in = implode(',', array_fill(0, count($ids), '?'));
        $out = [];
        foreach ($this->all("SELECT cop.id_order, cop.id_product, COALESCE(MAX(fp.name), CONCAT('#', cop.id_product)) AS name, SUM(cop.quantity_carton) AS qty
                FROM customer_order_product cop LEFT JOIN final_product fp ON fp.id = cop.id_product
                WHERE cop.id_order IN ({$in}) GROUP BY cop.id_order, cop.id_product ORDER BY name", $ids) as $r) {
            $out[(int) $r['id_order']][] = ['id_product' => (int) $r['id_product'], 'name' => (string) $r['name'], 'qty' => (float) $r['qty']];
        }
        return $out;
    }

    /**
     * Cartons commandés par WDT et produit : toutes les commandes (sans gratis — ni scannés ni palettisés) rattachées à
     * la WDT, car une WDT couvre toutes les commandes du client pour le jour (updateWdtIdByShippingDateAndCustomer).
     * @return array<int, array<int, float>> wdt => produit => cartons
     */
    public function orderedByWdt(array $wdtIds): array
    {
        $ids = self::ids($wdtIds);
        if (!$ids) return [];
        $in = implode(',', array_fill(0, count($ids), '?'));
        $out = [];
        foreach ($this->all("SELECT co.id_wdt, cop.id_product, SUM(cop.quantity_carton) AS qty FROM customer_order co JOIN customer_order_product cop ON cop.id_order = co.id
                WHERE co.id_wdt IN ({$in}) AND COALESCE(co.is_freebie, 0) = 0 GROUP BY co.id_wdt, cop.id_product", $ids) as $r) {
            $out[(int) $r['id_wdt']][(int) $r['id_product']] = (float) $r['qty'];
        }
        return $out;
    }

    /** Cartons expédiés par WDT et produit (document_wdt_product.quantity). @return array<int, array<int, float>> */
    public function shippedByWdt(array $wdtIds): array
    {
        $ids = self::ids($wdtIds);
        if (!$ids) return [];
        $in = implode(',', array_fill(0, count($ids), '?'));
        $out = [];
        foreach ($this->all("SELECT id_document_wdt, id_product, SUM(quantity) AS qty FROM document_wdt_product WHERE id_document_wdt IN ({$in})
                GROUP BY id_document_wdt, id_product", $ids) as $r) {
            $out[(int) $r['id_document_wdt']][(int) $r['id_product']] = (float) $r['qty'];
        }
        return $out;
    }

    /** Arrêts de la tablette chauffeur (un par client et par jour d'expédition). @return array<string, array> « client|jour » */
    public function stops(string $from, string $to): array
    {
        $out = [];
        foreach ($this->all('SELECT ship_date, id_customer, status, reason, note, recorded_at FROM n_delivery_stop WHERE ship_date BETWEEN ? AND ?', [$from, $to]) as $r) {
            $out[(int) $r['id_customer'] . '|' . substr((string) $r['ship_date'], 0, 10)] = $r;
        }
        return $out;
    }

    /** Réclamations rattachées aux commandes. @return array<int, list<array>> */
    public function complaints(array $orderIds): array
    {
        $ids = self::ids($orderIds);
        if (!$ids) return [];
        $in = implode(',', array_fill(0, count($ids), '?'));
        $out = [];
        foreach ($this->all("SELECT c.id_order, c.complaint_type, c.complaint_reason, c.acceptance_status, c.reported_at, p.name AS product
                FROM complaint c LEFT JOIN final_product p ON p.id = c.id_product WHERE c.id_order IN ({$in}) ORDER BY c.id", $ids) as $r) {
            $out[(int) $r['id_order']][] = $r;
        }
        return $out;
    }

    /**
     * Dernière décision des assistants n°1 (n_order_check), n°3 (n_supplement_check) et n°5 (n_delivery_delay_check)
     * pour chaque commande — lecture seule. @return array<int, list<array{source:string, day:string, value:string, note:?string, new_date:?string, by:?string}>>
     */
    public function priorChecks(array $orderIds): array
    {
        $ids = self::ids($orderIds);
        if (!$ids) return [];
        $in = implode(',', array_fill(0, count($ids), '?'));
        $out = [];
        $sources = [
            'orders_day' => "SELECT c.id_order, c.day, c.result AS value, c.note, NULL AS new_date, COALESCE(NULLIF(TRIM(u.displayed_name), ''), u.login) AS by_name
                FROM n_order_check c LEFT JOIN user u ON u.id = c.id_user WHERE c.id_order IN ({$in}) ORDER BY c.day, c.id",
            'supplements_day' => "SELECT c.id_order, c.day, c.result AS value, c.note, NULL AS new_date, COALESCE(NULLIF(TRIM(u.displayed_name), ''), u.login) AS by_name
                FROM n_supplement_check c LEFT JOIN user u ON u.id = c.id_user WHERE c.id_order IN ({$in}) ORDER BY c.day, c.id",
            'delivery_delays' => "SELECT c.id_order, c.day, c.decision AS value, c.note, c.new_date, COALESCE(NULLIF(TRIM(u.displayed_name), ''), u.login) AS by_name
                FROM n_delivery_delay_check c LEFT JOIN user u ON u.id = c.id_user WHERE c.id_order IN ({$in}) ORDER BY c.day, c.id",
        ];
        foreach ($sources as $source => $sql) {
            $last = [];
            foreach ($this->all($sql, $ids) as $r) $last[(int) $r['id_order']] = $r; // la dernière compte
            foreach ($last as $id => $r) {
                $out[$id][] = ['source' => $source, 'day' => substr((string) $r['day'], 0, 10), 'value' => (string) $r['value'],
                    'note' => $r['note'] !== null && trim((string) $r['note']) !== '' ? (string) $r['note'] : null,
                    'new_date' => $r['new_date'] ? substr((string) $r['new_date'], 0, 10) : null, 'by' => $r['by_name']];
            }
        }
        return $out;
    }

    /** Postes actifs (responsable d'une action). @return list<array{id:int, name:string}> */
    public function positions(): array
    {
        return array_map(fn($r) => ['id' => (int) $r['id'], 'name' => (string) $r['name_pl']],
            $this->all('SELECT id, name_pl FROM n_position WHERE active = 1 ORDER BY sort_order, name_pl'));
    }

    /** @return array<string, array> actions de la semaine par cause */
    public function actions(string $weekStart): array
    {
        $out = [];
        foreach ($this->all("SELECT a.cause, a.decision, a.note, a.id_responsible, a.id_position, a.orders, a.updated_at,
                    COALESCE(NULLIF(TRIM(r.displayed_name), ''), r.login) AS responsible_name, p.name_pl AS position_name,
                    COALESCE(NULLIF(TRIM(u.displayed_name), ''), u.login) AS by_name
                FROM n_otif_week_action a LEFT JOIN user r ON r.id = a.id_responsible LEFT JOIN n_position p ON p.id = a.id_position
                LEFT JOIN user u ON u.id = a.id_user WHERE a.week_start = ?", [$weekStart]) as $r) {
            $out[(string) $r['cause']] = $r;
        }
        return $out;
    }

    /** Une action par semaine et par cause : la dernière enregistrée remplace la précédente. */
    public function saveAction(string $weekStart, string $cause, string $decision, ?string $note, ?int $responsible, ?int $position, int $orders, ?int $userId): void
    {
        $this->db->prepare('INSERT INTO n_otif_week_action (week_start, cause, decision, note, id_responsible, id_position, orders, id_user) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE decision = VALUES(decision), note = VALUES(note), id_responsible = VALUES(id_responsible), id_position = VALUES(id_position),
                orders = VALUES(orders), id_user = VALUES(id_user), updated_at = NOW()')
            ->execute([$weekStart, $cause, $decision, $note, $responsible, $position, $orders, $userId]);
    }

    /** Résultats figés à « Zakończ ». @return array<string, array> semaine => ligne */
    public function weeks(array $weekStarts): array
    {
        $w = array_values(array_unique($weekStarts));
        if (!$w) return [];
        $in = implode(',', array_fill(0, count($w), '?'));
        return array_column($this->all("SELECT * FROM n_otif_week WHERE week_start IN ({$in})", $w), null, 'week_start');
    }

    public function saveWeek(string $weekStart, array $t, ?float $targetPct, string $summary, ?int $userId): void
    {
        $this->db->prepare('INSERT INTO n_otif_week (week_start, orders, on_time, measured_full, in_full, measured_otif, otif, otif_pct, target_pct, summary, id_user, closed_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE orders = VALUES(orders), on_time = VALUES(on_time), measured_full = VALUES(measured_full), in_full = VALUES(in_full),
                measured_otif = VALUES(measured_otif), otif = VALUES(otif), otif_pct = VALUES(otif_pct), target_pct = VALUES(target_pct), summary = VALUES(summary),
                id_user = VALUES(id_user), closed_at = NOW()')
            ->execute([$weekStart, $t['orders'], $t['on_time'], $t['measured_full'], $t['in_full'], $t['measured_otif'], $t['otif'], $t['otif_pct'], $targetPct,
                mb_substr($summary, 0, 255), $userId]);
    }

    private static function ids(array $ids): array
    {
        return array_values(array_unique(array_filter(array_map('intval', $ids), fn($i) => $i > 0)));
    }
}
