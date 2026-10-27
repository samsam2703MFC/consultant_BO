<?php
declare(strict_types=1);

namespace App\Repositories\Material;

use App\Repositories\Workshop\WorkshopRepository;

/** Wartości odżywcze z internetu : kategorie OFF, mediany kategorii (cache), wartości i źródło surowca. */
final class NutritionRepository extends WorkshopRepository
{
    protected string $table = 'n_nutrition_category';

    public const FIELDS = ['energy_100g', 'fat_100g', 'saturated_fat_100g', 'carbohydrates_100g', 'sugars_100g', 'fiber_100g', 'proteins_100g', 'salt_100g'];

    /** @return list<array{tag: string, lang: string, name: string}> */
    public function categories(): array
    {
        return $this->all('SELECT tag, lang, name FROM n_nutrition_category');
    }

    public function categoriesLoadedAt(): ?string
    {
        return $this->all('SELECT MAX(loaded_at) AS at FROM n_nutrition_category')[0]['at'] ?? null;
    }

    public function categoryName(string $tag): ?array
    {
        $rows = $this->all("SELECT lang, name FROM n_nutrition_category WHERE tag = ? ORDER BY FIELD(lang, 'pl', 'fr', 'en')", [$tag]);
        return $rows[0] ?? null;
    }

    /** @param list<array{tag: string, lang: string, name: string}> $rows */
    public function replaceCategories(array $rows, string $at): void
    {
        $this->db->beginTransaction();
        try {
            $this->db->exec('DELETE FROM n_nutrition_category');
            foreach (array_chunk($rows, 400) as $chunk) {
                $sql = 'INSERT IGNORE INTO n_nutrition_category (tag, lang, name, loaded_at) VALUES ' . implode(',', array_fill(0, count($chunk), '(?, ?, ?, ?)'));
                $params = [];
                foreach ($chunk as $r) array_push($params, $r['tag'], $r['lang'], $r['name'], $at);
                $this->db->prepare($sql)->execute($params);
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function cache(string $tag): ?array
    {
        $r = $this->all('SELECT * FROM n_nutrition_off_cache WHERE tag = ?', [$tag])[0] ?? null;
        if ($r) $r['values'] = json_decode((string) $r['values_json'], true) ?: [];
        return $r;
    }

    public function saveCache(string $tag, int $products, int $sample, array $values, string $at): void
    {
        $this->db->prepare('INSERT INTO n_nutrition_off_cache (tag, products, sample, values_json, fetched_at) VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE products = VALUES(products), sample = VALUES(sample), values_json = VALUES(values_json), fetched_at = VALUES(fetched_at)')
            ->execute([$tag, $products, $sample, json_encode($values), $at]);
    }

    public function material(int $id): ?array
    {
        return $this->all('SELECT id, name, ' . implode(', ', self::FIELDS) . ' FROM raw_material WHERE id = ?', [$id])[0] ?? null;
    }

    /** @param array<string, float> $values tylko kolumny FIELDS */
    public function saveValues(int $id, array $values): void
    {
        $values = array_intersect_key($values, array_flip(self::FIELDS));
        if (!$values) return;
        $set = implode(', ', array_map(fn($c) => "{$c} = :{$c}", array_keys($values)));
        $this->db->prepare("UPDATE raw_material SET {$set} WHERE id = :id")->execute($values + ['id' => $id]);
    }

    /** @return array<int, array> źródło wartości według surowca */
    public function sources(): array
    {
        $out = [];
        foreach ($this->all('SELECT * FROM n_material_nutrition_source') as $r) $out[(int) $r['id_raw_material']] = $r;
        return $out;
    }

    public function setSource(int $materialId, string $ref, string $label, int $sample, ?int $by, string $at): void
    {
        $this->db->prepare('INSERT INTO n_material_nutrition_source (id_raw_material, source, ref, label, sample, applied_by, applied_at) VALUES (?, \'off\', ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE source = VALUES(source), ref = VALUES(ref), label = VALUES(label), sample = VALUES(sample), applied_by = VALUES(applied_by), applied_at = VALUES(applied_at)')
            ->execute([$materialId, $ref, mb_substr($label, 0, 255), $sample, $by, $at]);
    }

    public function deleteSource(int $materialId): void
    {
        $this->db->prepare('DELETE FROM n_material_nutrition_source WHERE id_raw_material = ?')->execute([$materialId]);
    }
}
