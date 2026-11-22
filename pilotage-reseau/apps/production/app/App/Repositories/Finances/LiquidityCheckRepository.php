<?php
declare(strict_types=1);

namespace App\Repositories\Finances;

use App\Domain\Client\Order\OpenOrders;
use App\Repositories\Workshop\WorkshopRepository;

/**
 * Asystent « Płynność i gotówka » [373] : lecture des sources de trésorerie qui existent dans l'ERP et écriture des
 * deux tables de l'assistant (n_liquidity_balance, n_liquidity_decision). Rien d'autre n'est écrit.
 *  - créances : proformas non payées (document_proforma, même condition que ProformaRepository::getUnpaidForReminder),
 *    montant net des lignes (document_proforma_product.total_price_net), délai du client (customer.payment_termin),
 *    devise du client (customer.default_currency, comme OrderRepository : EUR par défaut), rappels envoyés
 *    (communication_message, même règle que CommunicationMessageRepository::proformaReminderLevels) ;
 *  - commandes ouvertes (OpenOrders::quantitySql, sans gratis) : valeur kartony × purchase_price (comme la colonne
 *    « Wartość » de la liste des commandes, [312]) ; sans proforma active = encaissement futur ;
 *  - factures d'achat (n_inv, sauf rejected) : brut, devise, fournisseur (supplier.name ou seller_name) ;
 *  - commandes fournisseurs (n_po envoyées / partielles / reçues, sans facture n_inv.id_po) : valeur quantité ×
 *    prix attendu (n_po_line, comme SupplyRepository::summaries), date de livraison ;
 *  - liste de paie (n_pay_run approuvée : totaux JSON de PayrollService::totals, date d'exécution des virements) ;
 *  - cours EUR (erp_company_other_settings.exchange_rate_euro, zł pour 1 €).
 */
final class LiquidityCheckRepository extends WorkshopRepository
{
    protected string $table = 'n_liquidity_decision';

    /** Cours EUR → zł des réglages de la société (0 = absent). */
    public function eurRate(): float
    {
        try {
            return (float) ($this->db->query('SELECT exchange_rate_euro FROM erp_company_other_settings ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        } catch (\Throwable) {
            return 0.0;
        }
    }

    /** Proformas non payées (ni payées, ni annulées, ni archivées) avec le délai et la devise du client. */
    public function unpaidProformas(): array
    {
        return $this->all("SELECT dp.id, dp.doc_num, dp.established_date, dp.id_customer, c.company, c.payment_termin,
                COALESCE(NULLIF(c.default_currency, ''), 'EUR') AS currency, COALESCE(SUM(dpp.total_price_net), 0) AS amount
            FROM document_proforma dp
            INNER JOIN customer c ON c.id = dp.id_customer
            LEFT JOIN document_proforma_product dpp ON dpp.id_proforma = dp.id
            WHERE LOWER(COALESCE(dp.status, 'issued')) NOT IN ('paid', 'cancelled', 'archived') AND dp.established_date IS NOT NULL
            GROUP BY dp.id, dp.doc_num, dp.established_date, dp.id_customer, c.company, c.payment_termin, c.default_currency
            ORDER BY dp.established_date, dp.id");
    }

    /** Dernier niveau de rappel envoyé par proforma (1–5) et sa date. @return array<int, array{level:int, last_at:string}> */
    public function reminderLevels(array $proformaIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $proformaIds), fn($id) => $id > 0)));
        if (!$ids) return [];
        $in = implode(',', array_fill(0, count($ids), '?'));
        $out = [];
        foreach ($this->all("SELECT id_proforma, message_type, MAX(created_at) AS last_at FROM communication_message
                WHERE id_proforma IN ({$in}) AND (message_type LIKE 'proforma\\_reminder\\_%' OR message_type LIKE 'proforma\\_payment\\_reminder%')
                  AND status NOT IN ('failed', 'cancelled', 'draft') GROUP BY id_proforma, message_type", $ids) as $r) {
            $level = preg_match('/^proforma_reminder_([1-5])$/', (string) $r['message_type'], $m) ? (int) $m[1] : ($r['message_type'] === 'proforma_payment_reminder_final' ? 5 : 1);
            $id = (int) $r['id_proforma'];
            if (!isset($out[$id]) || $level > $out[$id]['level']) $out[$id] = ['level' => $level, 'last_at' => (string) $r['last_at']];
        }
        return $out;
    }

    /**
     * Commandes ouvertes (sans gratis) : client, date d'expédition, valeur nette, devise, délai du client et proforma
     * active (null = sans proforma ou proforma annulée / archivée : l'encaissement viendra après l'expédition).
     */
    public function openOrders(): array
    {
        return $this->all("SELECT co.id, co.id_customer, c.company, c.payment_termin, DATE(co.expected_shipping_date) AS shipping_date,
                COALESCE(NULLIF(c.default_currency, ''), 'EUR') AS currency,
                ROUND((SELECT COALESCE(SUM(cop.quantity_carton * cop.purchase_price), 0) FROM customer_order_product cop WHERE cop.id_order = co.id), 2) AS value,
                IF(dp.id IS NOT NULL AND LOWER(COALESCE(dp.status, 'issued')) NOT IN ('cancelled', 'archived'), dp.id, NULL) AS id_proforma
            FROM customer_order co
            INNER JOIN customer c ON c.id = co.id_customer
            LEFT JOIN document_proforma dp ON dp.id = co.id_proforma
            WHERE " . OpenOrders::quantitySql('co') . "
            ORDER BY co.expected_shipping_date, co.id");
    }

    /** Factures d'achat (hors rejetées) émises entre deux jours inclus : brut (sinon net), devise, fournisseur. */
    public function supplierInvoices(string $fromDay, string $toDay): array
    {
        return $this->all("SELECT i.id, i.number, i.issue_date, i.currency, COALESCE(i.total_gross, i.total_net, 0) AS amount, i.kind, i.id_po,
                i.id_supplier, i.seller_nip, COALESCE(NULLIF(TRIM(s.name), ''), NULLIF(TRIM(i.seller_name), ''), i.seller_nip, 'dostawca') AS supplier
            FROM n_inv i LEFT JOIN supplier s ON s.id = i.id_supplier
            WHERE i.status <> 'rejected' AND i.issue_date BETWEEN ? AND ?
            ORDER BY i.issue_date, i.id", [$fromDay, $toDay]);
    }

    /** Commandes fournisseurs envoyées, partielles ou reçues, livrées entre deux jours, sans facture rattachée. */
    public function purchaseOrdersWithoutInvoice(string $fromDay, string $toDay): array
    {
        return $this->all("SELECT p.id, p.code, p.id_supplier, p.status, p.delivery_date, COALESCE(NULLIF(TRIM(s.name), ''), 'dostawca') AS supplier,
                COALESCE((SELECT SUM(l.quantity * l.unit_price) FROM n_po_line l WHERE l.id_po = p.id), 0) AS amount
            FROM n_po p LEFT JOIN supplier s ON s.id = p.id_supplier
            WHERE p.status IN ('sent', 'partial', 'received') AND p.delivery_date BETWEEN ? AND ?
              AND NOT EXISTS (SELECT 1 FROM n_inv i WHERE i.id_po = p.id AND i.status <> 'rejected')
            ORDER BY p.delivery_date, p.id", [$fromDay, $toDay]);
    }

    /** Mois de paie (RRRR-MM) entre deux mois inclus : statut, totaux (JSON décodé), date d'exécution des virements. @return array<string, array> */
    public function payRuns(string $fromMonth, string $toMonth): array
    {
        $out = [];
        try {
            foreach ($this->all('SELECT month, status, totals, exec_date FROM n_pay_run WHERE month BETWEEN ? AND ? ORDER BY month', [$fromMonth, $toMonth]) as $r) {
                $out[(string) $r['month']] = ['status' => (string) $r['status'], 'totals' => json_decode((string) ($r['totals'] ?? ''), true) ?: null, 'exec_date' => $r['exec_date']];
            }
        } catch (\PDOException) {
            return [];
        }
        return $out;
    }

    /** Dernier mois de paie approuvé avant un mois (base de l'estimation d'un mois pas encore approuvé). */
    public function lastApprovedRun(string $beforeMonth): ?array
    {
        try {
            $r = $this->all("SELECT month, totals FROM n_pay_run WHERE status = 'approved' AND month < ? AND totals IS NOT NULL ORDER BY month DESC LIMIT 1", [$beforeMonth])[0] ?? null;
        } catch (\PDOException) {
            return null;
        }
        return $r ? ['month' => (string) $r['month'], 'totals' => json_decode((string) $r['totals'], true) ?: null] : null;
    }

    /** Saldo saisi pour la semaine (lundi), avec l'auteur. */
    public function balance(string $weekStart): ?array
    {
        return $this->all("SELECT b.*, COALESCE(NULLIF(TRIM(u.displayed_name), ''), u.login) AS by_name
            FROM n_liquidity_balance b LEFT JOIN user u ON u.id = b.id_user WHERE b.week_start = ?", [$weekStart])[0] ?? null;
    }

    /** Une ligne par semaine : la dernière saisie remplace la précédente. */
    public function saveBalance(string $weekStart, string $balanceDate, array $v, ?string $note, ?int $userId): void
    {
        $this->db->prepare('INSERT INTO n_liquidity_balance (week_start, balance_date, bank_pln, bank_eur, cash_pln, other_out_1, other_out_2, other_out_3, other_out_4, note, id_user)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE balance_date = VALUES(balance_date), bank_pln = VALUES(bank_pln), bank_eur = VALUES(bank_eur), cash_pln = VALUES(cash_pln),
                other_out_1 = VALUES(other_out_1), other_out_2 = VALUES(other_out_2), other_out_3 = VALUES(other_out_3), other_out_4 = VALUES(other_out_4),
                note = VALUES(note), id_user = VALUES(id_user), updated_at = NOW()')
            ->execute([$weekStart, $balanceDate, $v['bank_pln'], $v['bank_eur'], $v['cash_pln'], $v['other'][0], $v['other'][1], $v['other'][2], $v['other'][3], $note, $userId]);
    }

    /** @return array<string, array> décisions de la semaine par clé de signal */
    public function decisions(string $weekStart): array
    {
        $out = [];
        foreach ($this->all("SELECT d.signal_key, d.decision, d.note, d.updated_at, COALESCE(NULLIF(TRIM(u.displayed_name), ''), u.login) AS by_name
                FROM n_liquidity_decision d LEFT JOIN user u ON u.id = d.id_user WHERE d.week_start = ?", [$weekStart]) as $r) {
            $out[(string) $r['signal_key']] = $r;
        }
        return $out;
    }

    /** Une décision par signal et par semaine : la dernière enregistrée remplace la précédente. */
    public function saveDecision(string $weekStart, array $signal, string $decision, ?string $note, ?int $userId): void
    {
        $this->db->prepare('INSERT INTO n_liquidity_decision (week_start, signal_key, signal_type, label, codes, amount_pln, decision, note, id_user) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE signal_type = VALUES(signal_type), label = VALUES(label), codes = VALUES(codes), amount_pln = VALUES(amount_pln),
                decision = VALUES(decision), note = VALUES(note), id_user = VALUES(id_user), updated_at = NOW()')
            ->execute([$weekStart, mb_substr((string) $signal['key'], 0, 80), $signal['type'], mb_substr((string) $signal['title'], 0, 160),
                mb_substr(implode(',', $signal['codes']), 0, 120), $signal['amount'], $decision, $note, $userId]);
    }
}
