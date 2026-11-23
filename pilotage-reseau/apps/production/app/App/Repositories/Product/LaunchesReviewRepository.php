<?php
declare(strict_types=1);

namespace App\Repositories\Product;

use App\Repositories\Workshop\WorkshopRepository;

/**
 * Asystent « Nowe produkty » [384] : lecture
 *  - de l'état des objectifs de lancement du module Kierownik produkcji (n_mgr_launch : lignes, lignes avec au moins un
 *    objectif target_cost / target_price / min_margin_pct / expected_week_shop, auteurs updated_by — migration 041
 *    renommée par 069) ; les lancements eux-mêmes sont lus par ManagerRepository::launches ;
 *  - des produits créés depuis une date quand n_mgr_launch manque (base partielle) : mêmes colonnes que
 *    ManagerRepository::launches (final_product.create_timestamp = date de création), objectifs vides ;
 *  - des décisions de l'assistant (n_launch_review) — la seule table écrite ici.
 * Une source dont la table manque est rendue null (« brak danych »).
 */
final class LaunchesReviewRepository extends WorkshopRepository
{
    protected string $table = 'n_launch_review';

    /** @var array<string, bool> */
    private array $tables = [];

    public function hasTable(string $table): bool
    {
        return $this->tables[$table] ??= (bool) $this->all('SELECT 1 AS x FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$table]);
    }

    /** Nombre de lignes d'une source (0 = jamais rien enregistré, « brak danych »), null si la table manque. */
    public function sourceRows(string $table, string $where = '1 = 1'): ?int
    {
        if (!$this->hasTable($table)) return null;
        return (int) ($this->all("SELECT COUNT(*) AS n FROM {$table} WHERE {$where}")[0]['n'] ?? 0);
    }

    /** Condition SQL « au moins un objectif de lancement saisi ». */
    public const HAS_TARGETS = 'target_cost IS NOT NULL OR target_price IS NOT NULL OR min_margin_pct IS NOT NULL OR expected_week_shop IS NOT NULL';

    /** @return list<int> auteurs des objectifs de lancement (n_mgr_launch.updated_by), null si la table manque */
    public function launchAuthors(): ?array
    {
        if (!$this->hasTable('n_mgr_launch')) return null;
        return array_map('intval', array_column($this->all('SELECT DISTINCT updated_by FROM n_mgr_launch WHERE updated_by IS NOT NULL AND (' . self::HAS_TARGETS . ')'), 'updated_by'));
    }

    /** Produits créés depuis $since (sans n_mgr_launch) : colonnes de ManagerRepository::launches, objectifs vides. */
    public function createdProducts(string $since): array
    {
        return $this->all(
            "SELECT fp.id AS product_id, fp.name, fp.ppc, DATE(fp.create_timestamp) AS created, fp.active,
                    NULL AS launch_date, NULL AS target_cost, NULL AS target_price, NULL AS min_margin_pct, NULL AS expected_week_shop,
                    NULL AS decision, NULL AS decision_note, NULL AS updated_by
             FROM final_product fp
             WHERE fp.create_timestamp >= ?
             ORDER BY DATE(fp.create_timestamp) DESC, fp.name",
            [$since]
        );
    }

    /** @return array<int, list<array>> produit → décisions de l'assistant (la plus récente d'abord), tous mois confondus */
    public function decisions(array $productIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $productIds))));
        if (!$ids) return [];
        $out = [];
        foreach ($this->all('SELECT r.*, COALESCE(NULLIF(TRIM(u.displayed_name), \'\'), u.login) AS decided_by
                FROM n_launch_review r LEFT JOIN user u ON u.id = r.id_user
                WHERE r.id_product IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY r.month_start DESC, r.updated_at DESC, r.id DESC', $ids) as $r) {
            $out[(int) $r['id_product']][] = $r;
        }
        return $out;
    }

    /** Une décision par (mois du contrôle, produit) : la dernière enregistrée remplace la précédente. */
    public function saveDecision(string $monthStart, int $productId, ?string $launchDate, string $decision, ?string $fixArea, ?string $note, string $flags, ?int $userId): void
    {
        $this->db->prepare('INSERT INTO n_launch_review (month_start, id_product, launch_date, decision, fix_area, note, flags, id_user) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE launch_date = VALUES(launch_date), decision = VALUES(decision), fix_area = VALUES(fix_area), note = VALUES(note),
                flags = VALUES(flags), id_user = VALUES(id_user), updated_at = NOW()')
            ->execute([$monthStart, $productId, $launchDate, $decision, $fixArea, $note, mb_substr($flags, 0, 255), $userId]);
    }
}
