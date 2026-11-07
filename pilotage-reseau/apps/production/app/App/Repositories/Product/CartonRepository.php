<?php
declare(strict_types=1);

namespace App\Repositories\Product;

use App\Repositories\Workshop\WorkshopRepository;

/**
 * Kartony i palety [205] : katalog kartonów (n_carton) na surowcach-opakowaniach (raw_material : nazwa, cena, waga
 * kartonu = packaging_unit_weight_g jak w BDO) ; wymiary także w carton_dimension (tabela ERP) ; produkty i ich karton.
 */
final class CartonRepository extends WorkshopRepository
{
    protected string $table = 'n_carton';

    private const SELECT = "SELECT c.id_raw_material AS id, rm.name, rm.id_category, cm.name AS category, rm.price_per_unit, rm.packaging_unit_weight_g AS tare_g,
            c.length_mm, c.width_mm, c.height_mm, c.max_load_kg, c.pallet_max_height_mm, c.pallet_max_kg, c.kind, c.note, c.active, c.updated_at
        FROM n_carton c
        JOIN raw_material rm ON rm.id = c.id_raw_material
        LEFT JOIN category_material cm ON cm.id = rm.id_category";

    public function cartons(): array
    {
        return $this->all(self::SELECT . ' ORDER BY c.active DESC, rm.name');
    }

    public function carton(int $id): ?array
    {
        return $this->all(self::SELECT . ' WHERE c.id_raw_material = ?', [$id])[0] ?? null;
    }

    /**
     * Aktywne produkty ERP i ich karton : wybrany w karcie (krok Paleta), karton sprzedażowy PPWR albo jedyny karton
     * z katalogu w recepturze (koszt kartonu w recepturze).
     */
    public function products(): array
    {
        $rows = $this->all(
            "SELECT fp.id, fp.name, fp.ppc, fp.weight, fp.cpl, fp.lpp, fp.cpp, i.id AS item_id, lg.id_carton, lg.pallet_type,
                    lg.pallet_max_height_mm, lg.pallet_max_kg, lg.pallet_overhang_mm, sp.id_outer_raw_material AS ppwr_carton
             FROM final_product fp
             LEFT JOIN n_prod_item i ON i.id_final_product = fp.id AND i.item_type = 'product'
             LEFT JOIN n_prod_item_logistics lg ON lg.id_item = i.id
             LEFT JOIN final_product_ppwr_sales_packaging sp ON sp.id_final_product = fp.id
             WHERE fp.active = 1
             ORDER BY fp.name"
        );
        $recipe = [];
        foreach ($this->all(
            "SELECT fp.id, MIN(c.id_raw_material) AS carton, COUNT(DISTINCT c.id_raw_material) AS n
             FROM final_product fp
             JOIN flattened_recipe_ingredient fri ON fri.recipe_id = fp.id_recipe
             JOIN n_carton c ON c.id_raw_material = fri.material_id
             WHERE fp.active = 1
             GROUP BY fp.id"
        ) as $r) if ((int) $r['n'] === 1) $recipe[(int) $r['id']] = (int) $r['carton'];
        $catalog = array_flip(array_map('intval', array_column($this->all('SELECT id_raw_material FROM n_carton'), 'id_raw_material')));
        foreach ($rows as &$r) {
            $card = $r['id_carton'] !== null ? (int) $r['id_carton'] : null;
            $ppwr = $r['ppwr_carton'] !== null && isset($catalog[(int) $r['ppwr_carton']]) ? (int) $r['ppwr_carton'] : null;
            [$r['carton_id'], $r['carton_source']] = match (true) {
                $card !== null => [$card, 'card'],
                $ppwr !== null => [$ppwr, 'ppwr'],
                isset($recipe[(int) $r['id']]) => [$recipe[(int) $r['id']], 'recipe'],
                default => [null, null],
            };
        }
        return $rows;
    }

    /** Surowce-opakowania jeszcze poza katalogiem (do « Dodaj karton »). */
    public function candidates(): array
    {
        return $this->all(
            "SELECT rm.id, rm.name, cm.name AS category
             FROM raw_material rm
             LEFT JOIN category_material cm ON cm.id = rm.id_category
             WHERE (rm.is_part_of_package = 1 OR rm.id_category IN (103, 200, 202, 203, 205))
               AND NOT EXISTS (SELECT 1 FROM n_carton c WHERE c.id_raw_material = rm.id)
             ORDER BY rm.name"
        );
    }

    public function materialExists(int $id): bool
    {
        return (bool) $this->all('SELECT 1 FROM raw_material WHERE id = ?', [$id]);
    }

    /** Nowy karton : wymiary z carton_dimension albo z PPWR, rodzaj z kategorii surowca. */
    public function add(int $id, ?int $userId): void
    {
        $this->db->prepare(
            "INSERT IGNORE INTO n_carton (id_raw_material, length_mm, width_mm, height_mm, kind, updated_by)
             SELECT rm.id, COALESCE(cd.length, ROUND(pd.length_mm)), COALESCE(cd.width, ROUND(pd.width_mm)), COALESCE(cd.height, ROUND(pd.height_mm)),
                    CASE rm.id_category WHEN 200 THEN 'klapowy' WHEN 203 THEN 'fasonowy' WHEN 205 THEN 'klejony' ELSE NULL END, ?
             FROM raw_material rm
             LEFT JOIN carton_dimension cd ON cd.id = rm.id
             LEFT JOIN (SELECT p.id_raw_material, d.length_mm, d.width_mm, d.height_mm
                        FROM raw_material_ppwr_profile p
                        JOIN raw_material_ppwr_dimension d ON d.id = (SELECT MAX(d2.id) FROM raw_material_ppwr_dimension d2
                                                                     WHERE d2.id_raw_material_ppwr_profile = p.id AND d2.is_active = 1)) pd ON pd.id_raw_material = rm.id
             WHERE rm.id = ?"
        )->execute([$userId, $id]);
    }

    /** Zapis kartonu : katalog, waga kartonu (surowiec, jak w BDO), wymiary w carton_dimension (ERP), gdy pełne. */
    public function save(int $id, array $carton, ?float $tareG, bool $tareGiven, ?int $userId): void
    {
        $cols = array_keys($carton);
        if ($cols) {
            $this->db->prepare('UPDATE n_carton SET ' . implode(', ', array_map(fn($c) => "{$c} = ?", $cols)) . ', updated_by = ? WHERE id_raw_material = ?')
                ->execute([...array_values($carton), $userId, $id]);
        }
        if ($tareGiven) $this->db->prepare('UPDATE raw_material SET packaging_unit_weight_g = ? WHERE id = ?')->execute([$tareG, $id]);
        $row = $this->carton($id);
        if ($row && $row['length_mm'] && $row['width_mm'] && $row['height_mm']) {
            $this->db->prepare('INSERT INTO carton_dimension (id, length, width, height, name) VALUES (?, ?, ?, ?, ?)
                                ON DUPLICATE KEY UPDATE length = VALUES(length), width = VALUES(width), height = VALUES(height), name = VALUES(name)')
                ->execute([$id, (int) $row['length_mm'], (int) $row['width_mm'], (int) $row['height_mm'], $row['name']]);
        }
    }
}
