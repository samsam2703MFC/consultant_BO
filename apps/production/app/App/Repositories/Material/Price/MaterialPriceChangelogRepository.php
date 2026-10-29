<?php
namespace App\Repositories\Material\Price;


use App\Core\Helpers\BaseRepository;

/**
 * User class
 */
class MaterialPriceChangelogRepository extends BaseRepository
{

    protected string $table = 'raw_material_price_changelog';

    protected $allowedColumns = [
        'id_supplier',
        'id_raw_material',
        'pack_price',
        'package_size_in_base_unit',
        'source',
        'id_user'
    ];

    protected $fetchAllowedColumns = [

    ];


    /** Ostatni wpis historii ceny surowca (kiedy ustalono obecną cenę bazową) ; null bez historii. */
    public function lastChange(int $materialId): ?array
    {
        $st = $this->db->prepare('SELECT timestamp, pack_price, package_size_in_base_unit, id_supplier, source FROM raw_material_price_changelog
            WHERE id_raw_material = ? ORDER BY timestamp DESC, id DESC LIMIT 1');
        $st->execute([$materialId]);
        return $st->fetch(\PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Ceny zatwierdzone od $since (zł za jednostkę bazową) : faktury zakupowe zaakceptowane albo z cenami przeniesionymi
     * (bez reklamowanych i bez podwyżek z decyzją « bez zmian »), cena ważona na fakturę ; oferty zaakceptowane.
     * @return list<array{kind: string, day: string, id_supplier: ?int, price: float}>
     */
    public function validatedPrices(int $materialId, string $since): array
    {
        $st = $this->db->prepare("SELECT 'invoice' AS kind, COALESCE(i.issue_date, DATE(i.created_at)) AS day, i.id_supplier,
                    SUM(COALESCE(l.net_value, l.unit_price * l.quantity)) / SUM(l.quantity * COALESCE(NULLIF(l.factor, 0), 1)) AS price
                FROM n_inv_line l JOIN n_inv i ON i.id = l.id_inv
                WHERE l.id_raw_material = ? AND (i.status = 'accepted' OR i.prices_imported_at IS NOT NULL) AND i.status <> 'disputed' AND i.kind = 'goods' AND i.is_correction = 0
                  AND COALESCE(l.price_decision, '') <> 'keep' AND l.quantity > 0 AND COALESCE(i.issue_date, DATE(i.created_at)) >= ?
                GROUP BY i.id
                UNION ALL
                SELECT 'offer', DATE(o.created_at), o.id_supplier, COALESCE(NULLIF(o.price_per_base, 0), o.pack_price / NULLIF(o.pack_size_base, 0))
                FROM n_mp_offer o WHERE o.id_raw_material = ? AND o.status = 'accepted' AND o.created_at >= ?");
        $st->execute([$materialId, $since, $materialId, $since . ' 00:00:00']);
        return array_values(array_filter(array_map(fn($r) => ['kind' => $r['kind'], 'day' => $r['day'], 'id_supplier' => $r['id_supplier'] !== null ? (int) $r['id_supplier'] : null,
            'price' => (float) $r['price']], $st->fetchAll(\PDO::FETCH_ASSOC)), fn($r) => $r['price'] > 0));
    }

    /** Historia cen surowca (P21) : dostawca, cena opakowania, opakowanie, źródło, użytkownik — najnowsze najpierw. */
    public function history(int $materialId, int $limit = 30): array
    {
        $st = $this->db->prepare("SELECT c.timestamp, c.pack_price, c.package_size_in_base_unit, c.source, s.name AS supplier,
                COALESCE(NULLIF(u.displayed_name, ''), u.login) AS user_name
            FROM raw_material_price_changelog c
            LEFT JOIN supplier s ON s.id = c.id_supplier
            LEFT JOIN user u ON u.id = c.id_user
            WHERE c.id_raw_material = ? ORDER BY c.timestamp DESC LIMIT " . max(1, $limit));
        $st->execute([$materialId]);
        return $st->fetchAll(\PDO::FETCH_ASSOC);
    }
}
