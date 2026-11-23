<?php
declare(strict_types=1);

namespace App\Repositories\Client;

use App\Domain\Client\Order\CustomerOrderStatus;
use App\Repositories\Workshop\WorkshopRepository;

/**
 * Asystent « Dane klientów » [385] : lecture
 *  - des clients actifs : au moins une commande non archivée depuis une date (customer_order.create_order_date, status
 *    <> ARCHIVED — comme CustomersNoOrderRepository::orders, domówienia compris : elles aussi font des documents) ;
 *  - de leur fiche (customer) : seulement les colonnes de la fiche (ClientRepository::$allowedColumns, formulaire
 *    Klienci › Edytuj) dont l'assistant a besoin, et seulement celles qui existent dans la base (CARD_COLUMNS ∩
 *    information_schema) — jamais credentials ;
 *  - des pays (country.name, la liste du formulaire) ;
 *  - des clients livrés en WDT sur la période (document_wdt.id_customer, established_date) ;
 *  - du cennik client (final_product_customer_price : nombre de lignes, produits commandés sans prix client > 0 —
 *    customer_order_product.id_product, quantity_carton ; nom du produit final_product.name) ;
 *  - des e-mails envoyés au client (communication_message : id_customer, message_type, recipient_email, status 'sent' /
 *    'failed', created_at — jamais le sujet ni le texte) ;
 *  - des décisions de l'assistant (n_customers_data_check) — la seule table écrite ici.
 * Une source dont la table (ou la colonne) manque est rendue vide : le service la dit « brak danych ».
 */
final class CustomersDataCheckRepository extends WorkshopRepository
{
    protected string $table = 'n_customers_data_check';

    /** Colonnes de la fiche client lues par l'assistant (toutes affichées par le formulaire de la fiche, sauf franchisee_uid : intégration). */
    public const CARD_COLUMNS = ['company', 'name', 'surname', 'fac_street', 'fac_street_num', 'fac_zip', 'fac_town', 'id_country', 'tax_number', 'active',
        'email_head', 'email_salesman', 'email_accountant', 'phone_head', 'phone_alt', 'payment_termin', 'communication_lang', 'franchisee_uid'];

    /** @var array<string, bool> */
    private array $tables = [];
    /** @var array<string, list<string>> */
    private array $columns = [];

    public function hasTable(string $table): bool
    {
        return $this->tables[$table] ??= (bool) $this->all('SELECT 1 AS x FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$table]);
    }

    /** @return list<string> colonnes existantes d'une table (vide si la table manque) */
    public function columns(string $table): array
    {
        return $this->columns[$table] ??= array_column($this->all('SELECT COLUMN_NAME AS c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION', [$table]), 'c');
    }

    /** @return array<int, array{orders:int, last_order:string}> clients avec une commande non archivée depuis $since */
    public function activeCustomers(string $since): array
    {
        if (!$this->hasTable('customer_order')) return [];
        $out = [];
        foreach ($this->all('SELECT co.id_customer, COUNT(*) AS orders, MAX(co.create_order_date) AS last_order FROM customer_order co
                WHERE co.create_order_date >= ? AND co.status <> ' . CustomerOrderStatus::ARCHIVED . ' AND co.id_customer IS NOT NULL
                GROUP BY co.id_customer', [$since]) as $r) {
            $out[(int) $r['id_customer']] = ['orders' => (int) $r['orders'], 'last_order' => substr((string) $r['last_order'], 0, 10)];
        }
        return $out;
    }

    /** @return array<int, array> fiches des clients (colonnes CARD_COLUMNS présentes dans la base) par id */
    public function cards(array $ids): array
    {
        $ids = self::ids($ids);
        if (!$ids || !$this->hasTable('customer')) return [];
        $cols = array_values(array_intersect(self::CARD_COLUMNS, $this->columns('customer')));
        $out = [];
        foreach ($this->all('SELECT id' . ($cols ? ', `' . implode('`, `', $cols) . '`' : '') . ' FROM customer WHERE id IN (' . self::in($ids) . ')', $ids) as $r) {
            $out[(int) $r['id']] = $r;
        }
        return $out;
    }

    /** @return array<int, string> pays de la liste de la fiche : id => nom */
    public function countries(): array
    {
        if (!$this->hasTable('country')) return [];
        return array_map('strval', array_column($this->all('SELECT id, name FROM country'), 'name', 'id'));
    }

    /** @return list<int>|null clients avec un WDT établi depuis $since ; null si la table manque */
    public function wdtCustomers(string $since): ?array
    {
        if (!$this->hasTable('document_wdt')) return null;
        return array_map('intval', array_column($this->all('SELECT DISTINCT id_customer FROM document_wdt WHERE established_date >= ? AND id_customer IS NOT NULL', [$since]), 'id_customer'));
    }

    /** @return array<int, int>|null nombre de lignes du cennik client par client ; null si la table manque */
    public function priceListRows(array $ids): ?array
    {
        if (!$this->hasTable('final_product_customer_price')) return null;
        $ids = self::ids($ids);
        if (!$ids) return [];
        return array_map('intval', array_column($this->all('SELECT id_customer, COUNT(*) AS n FROM final_product_customer_price WHERE id_customer IN (' . self::in($ids) . ') GROUP BY id_customer', $ids), 'n', 'id_customer'));
    }

    /**
     * Produits commandés depuis $since (commandes non archivées, quantité > 0) sans prix dans le cennik du client
     * (aucune ligne final_product_customer_price, ou prix vide / ≤ 0) — le WDT les prend au prix standard.
     * @return array<int, list<array{id:int, name:string}>> par client
     */
    public function orderedWithoutPrice(array $ids, string $since): array
    {
        $ids = self::ids($ids);
        if (!$ids || !$this->hasTable('final_product_customer_price') || !$this->hasTable('customer_order_product')) return [];
        $out = [];
        foreach ($this->all('SELECT co.id_customer, cop.id_product, fp.name
                FROM customer_order co
                INNER JOIN customer_order_product cop ON cop.id_order = co.id
                INNER JOIN final_product fp ON fp.id = cop.id_product
                LEFT JOIN final_product_customer_price p ON p.id_customer = co.id_customer AND p.id_final_product = cop.id_product AND p.price > 0
                WHERE co.create_order_date >= ? AND co.status <> ' . CustomerOrderStatus::ARCHIVED . ' AND COALESCE(cop.quantity_carton, 0) > 0
                  AND co.id_customer IN (' . self::in($ids) . ') AND p.id_final_product IS NULL
                GROUP BY co.id_customer, cop.id_product, fp.name ORDER BY co.id_customer, fp.name', array_merge([$since], $ids)) as $r) {
            $out[(int) $r['id_customer']][] = ['id' => (int) $r['id_product'], 'name' => (string) $r['name']];
        }
        return $out;
    }

    /**
     * E-mails envoyés ou en erreur depuis $since (communication_message, statuts définitifs 'sent' / 'failed'), du plus
     * ancien au plus récent ; null si la table manque. Ni sujet, ni texte, ni message d'erreur.
     * @return array<int, list<array{id:int, type:string, to:string, status:string, at:string}>>|null
     */
    public function mails(array $ids, string $since): ?array
    {
        if (!$this->hasTable('communication_message')) return null;
        $ids = self::ids($ids);
        if (!$ids) return [];
        $out = [];
        foreach ($this->all("SELECT id, id_customer, message_type, recipient_email, status, created_at FROM communication_message
                WHERE id_customer IN (" . self::in($ids) . ") AND created_at >= ? AND status IN ('sent', 'failed') ORDER BY created_at, id", array_merge($ids, [$since])) as $r) {
            $out[(int) $r['id_customer']][] = ['id' => (int) $r['id'], 'type' => (string) $r['message_type'], 'to' => (string) $r['recipient_email'],
                'status' => (string) $r['status'], 'at' => (string) $r['created_at']];
        }
        return $out;
    }

    /** @return array<int, list<array>> client → décisions de l'assistant (la plus récente d'abord), tous mois confondus */
    public function decisions(array $ids): array
    {
        $ids = self::ids($ids);
        if (!$ids) return [];
        $out = [];
        foreach ($this->all("SELECT d.month_start, d.id_customer, d.decision, d.note, d.flags, d.updated_at, COALESCE(NULLIF(TRIM(u.displayed_name), ''), u.login) AS decided_by
                FROM n_customers_data_check d LEFT JOIN user u ON u.id = d.id_user
                WHERE d.id_customer IN (" . self::in($ids) . ') ORDER BY d.month_start DESC, d.updated_at DESC, d.id DESC', $ids) as $r) {
            $out[(int) $r['id_customer']][] = $r;
        }
        return $out;
    }

    /** @return list<int> clients décidés pour un mois (ils restent sur la liste du mois même sans problème) */
    public function decidedIn(string $monthStart): array
    {
        return array_map('intval', array_column($this->all('SELECT id_customer FROM n_customers_data_check WHERE month_start = ?', [$monthStart]), 'id_customer'));
    }

    /** Une décision par (mois du contrôle, client) : la dernière enregistrée remplace la précédente. */
    public function saveDecision(string $monthStart, int $customerId, string $decision, ?string $note, string $flags, ?int $userId): void
    {
        $this->db->prepare('INSERT INTO n_customers_data_check (month_start, id_customer, decision, note, flags, id_user) VALUES (?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE decision = VALUES(decision), note = VALUES(note), flags = VALUES(flags), id_user = VALUES(id_user), updated_at = NOW()')
            ->execute([$monthStart, $customerId, $decision, $note, mb_substr($flags, 0, 255), $userId]);
    }

    /** @return list<int> */
    private static function ids(array $ids): array
    {
        return array_values(array_unique(array_filter(array_map('intval', $ids), fn($i) => $i > 0)));
    }

    private static function in(array $ids): string
    {
        return implode(', ', array_fill(0, count($ids), '?'));
    }
}
