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
