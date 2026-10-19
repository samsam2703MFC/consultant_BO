<?php

namespace App\Repositories\Product;
use App\Core\Helpers\BaseRepository;

/**
 * User class
 */
class FinalProductRepository extends BaseRepository
{

    protected string $table = 'final_product';

    protected $allowedColumns = [
        'name',
        'id_category',
        'active',
        'weight',
        'ppc',
        'ppb',
        'bpc',
        'cpl',
        'lpp',
        'cpp',
        'id_section',
        'id_recipe',
        'label_type',
        'soft_delete',
        'stock_minimum',
        'shelf_life',
        'pre_calculated_raw_price_per_carton',
        'palette_cost',
        'marge_perc',
        'standard_price',
        'pre_calculated_raw_price_per_carton',
        'workload_points'
    ];

    protected $fetchAllowedColumns = [
        'id',
        'name',
        'id_category',
        'active',
        'weight',
        'ppc',
        'ppb',
        'bpc',
        'cpl',
        'lpp',
        'cpp',
        'id_section',
        'id_recipe',
        'soft_delete',
        'create_timestamp',
        'stock_minimum',
        'shelf_life',
        'pre_calculated_raw_price_per_carton',
        'palette_cost',
        'marge_perc',
        'standard_price',
        'pre_calculated_raw_price_per_carton',
        'workload_points'
    ];

    public function getByIdCategory($idCategory)
    {
        $sql = "SELECT
                    id,
                    name,
                    id_category,
                    active,
                    weight,
                    id_recipe,
                    stock_minimum,
                    workload_points
                FROM final_product
                WHERE id_category = :id_category
                ORDER BY name";
        return $this->query($sql, ['id_category' => $idCategory]);
    }

    public function getAll($order_by = "cp.name, fp.name")
    {
        if(!isset($order_by) || $order_by == "") $order_by = "cp.name, fp.name";

        $sql = "SELECT
                    fp.*,
                    sec.name as section_name,
                    cp.name as category_name
                FROM final_product fp
                INNER JOIN production_sections sec
                    ON fp.id_section = sec.id
                LEFT JOIN category_product cp
                    ON fp.id_category = cp.id
                 ORDER BY $order_by";
        return $this->query($sql);
    }

    public function getAllFiltered(bool $includeInactive, bool $includeZeroPrice, string $order_by = "cp.name, fp.name"): array
    {
        $conditions = [];
        if (!$includeInactive) {
            $conditions[] = "fp.active = 1";
        }
        if (!$includeZeroPrice) {
            $conditions[] = "(fp.standard_price IS NOT NULL AND fp.standard_price > 0)";
        }

        $where = !empty($conditions) ? "WHERE " . implode(" AND ", $conditions) : "";

        $sql = "SELECT
                    fp.*,
                    sec.name  AS section_name,
                    cp.name   AS category_name
                FROM final_product fp
                INNER JOIN production_sections sec ON fp.id_section = sec.id
                LEFT  JOIN category_product    cp  ON fp.id_category = cp.id
                {$where}
                ORDER BY {$order_by}";

        return $this->query($sql);
    }



    public function getById($id)
    {
        $sql = "SELECT
                    fp.id,
                    fp.name,
                    fp.weight,
                    fp.ppc,
                    cp.name as category_name,
                    fp.id_recipe,
                    fp.id_section,
                    sec.name as section_name
                FROM final_product fp
                INNER JOIN category_product cp
                    ON fp.id_category = cp.id
                INNER JOIN production_sections sec  
                    ON fp.id_section = sec.id
                WHERE fp.id = :id_product";

        $res = $this->query($sql, ['id_product' => $id]);
        if (count($res) > 0) return $res[0];

        return [];
    }



    public function getWithoutRecipe()
    {
        $sql = "SELECT 
                        id, 
                        name 
                    FROM final_product
                    WHERE active = 1 AND id_recipe IS NULL
                    ORDER BY name";
        return $this->query($sql);
    }

    public function getWithoutRecipeGroupedBySectionAndCategory(): array
    {
        $sql = "SELECT
                    fp.id,
                    fp.name,
                    fp.id_section,
                    fp.id_category,
                    sec.name AS section_name,
                    COALESCE(cp.name, 'Bez kategorii') AS category_name
                FROM final_product fp
                INNER JOIN production_sections sec ON fp.id_section = sec.id
                LEFT  JOIN category_product    cp  ON fp.id_category = cp.id
                WHERE fp.active = 1 AND fp.id_recipe IS NULL
                ORDER BY sec.name ASC, category_name ASC, fp.name ASC";

        return $this->query($sql);
    }

    public function getByIdRecipe($data)
    {
        $sql = "SELECT 
                        id, 
                        name, 
                        ppc
                    FROM final_product
                    WHERE active = 1 AND id_recipe = :id_recipe LIMIT 1";

        return $this->query($sql, $data);
    }


    public function getDataByRecipeId($idRecipe)
    {
        $sql = "SELECT * FROM final_product WHERE id_recipe = :id_recipe LIMIT 1";
        $res = $this->query($sql, ['id_recipe' => $idRecipe]);
        if (count($res) > 0) {
            return $res[0];
        }
        return $res;
    }

    /**
     * Aktualizuje standard_price i deaktywuje/aktywuje produkt.
     */
    public function updateStandardPrice(int $productId, float $price): int|bool
    {
        $sql = "UPDATE final_product SET standard_price = :price WHERE id = :id";
        return $this->query($sql, ['price' => $price, 'id' => $productId]);
    }

    public function deactivate(int $productId): int|bool
    {
        $sql = "UPDATE final_product SET active = 0 WHERE id = :id";
        return $this->query($sql, ['id' => $productId]);
    }

    public function getSupplementaryDataForRecipeCostCsv($idRecipe)
    {
        $sql = "SELECT
                    fp.*,
                    IFNULL(fpcp_berdiff.price, 0) as berdiff_price,
                    IFNULL(fpcp_max.price, 0) as max_price
                    FROM final_product fp
                LEFT JOIN final_product_customer_price fpcp_berdiff
                    ON fp.id = fpcp_berdiff.id_final_product AND fpcp_berdiff.id_customer = 59
                LEFT JOIN final_product_customer_price fpcp_max
                    ON fp.id = fpcp_max.id_final_product AND fpcp_max.id_customer = 87
                WHERE fp.id_recipe = :id_recipe LIMIT 1";
        return $this->query($sql, ['id_recipe' => $idRecipe])[0];
    }

    public function getWeightAuditPage(array $filters, int $limit, int $offset): array
    {
        [$where, $params] = $this->weightAuditWhere($filters, true);
        $limit = max(1, min(100, $limit));
        $offset = max(0, $offset);
        $statusSql = $this->weightAuditStatusSql();
        $sort = $this->weightAuditSort($filters['sort'] ?? 'status');
        $direction = strtolower((string) ($filters['direction'] ?? 'asc')) === 'desc' ? 'DESC' : 'ASC';

        $sql = "SELECT fp.id, fp.name, fp.active, fp.weight, fp.ppc,
                    COALESCE(cp.name, 'Bez kategorii') AS category_name,
                    {$statusSql} AS audit_status
                FROM final_product fp
                LEFT JOIN category_product cp ON cp.id = fp.id_category
                WHERE 1=1 {$where}
                ORDER BY {$sort} {$direction}, fp.name ASC, fp.id ASC
                LIMIT {$limit} OFFSET {$offset}";
        return $this->query($sql, $params) ?: [];
    }

    public function countWeightAudit(array $filters): int
    {
        [$where, $params] = $this->weightAuditWhere($filters, true);
        $result = $this->query("SELECT COUNT(*) AS cnt FROM final_product fp WHERE 1=1 {$where}", $params);
        return (int) ($result[0]['cnt'] ?? 0);
    }

    public function weightAuditCounts(array $filters): array
    {
        [$where, $params] = $this->weightAuditWhere($filters, false);
        $statusSql = $this->weightAuditStatusSql();
        $sql = "SELECT COUNT(*) AS all_count,
                    SUM({$statusSql} = 'ERROR') AS error_count,
                    SUM({$statusSql} = 'WARNING') AS warning_count,
                    SUM({$statusSql} = 'OK') AS ok_count
                FROM final_product fp WHERE 1=1 {$where}";
        $row = ($this->query($sql, $params) ?: [[]])[0];
        return [
            'all' => (int) ($row['all_count'] ?? 0),
            'error' => (int) ($row['error_count'] ?? 0),
            'warning' => (int) ($row['warning_count'] ?? 0),
            'ok' => (int) ($row['ok_count'] ?? 0),
        ];
    }

    private function weightAuditWhere(array $filters, bool $includeStatus): array
    {
        $where = '';
        $params = [];
        $activity = $filters['activity'] ?? 'active';
        if ($activity === 'active') $where .= ' AND fp.active = 1';
        if ($activity === 'inactive') $where .= ' AND fp.active = 0';
        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $where .= ' AND (fp.name LIKE :audit_search OR CAST(fp.id AS CHAR) LIKE :audit_search)';
            $params['audit_search'] = '%' . $search . '%';
        }
        if ($includeStatus) {
            $status = $filters['status'] ?? 'attention';
            $statusSql = $this->weightAuditStatusSql();
            if ($status === 'attention') $where .= " AND {$statusSql} IN ('ERROR', 'WARNING')";
            if (in_array($status, ['ERROR', 'WARNING', 'OK'], true)) $where .= " AND {$statusSql} = '{$status}'";
        }
        return [$where, $params];
    }

    private function weightAuditStatusSql(): string
    {
        return "CASE
            WHEN fp.weight IS NULL OR TRIM(CAST(fp.weight AS CHAR)) = '' THEN 'ERROR'
            WHEN TRIM(CAST(fp.weight AS CHAR)) NOT REGEXP '^-?[0-9]+([.][0-9]+)?$' THEN 'ERROR'
            WHEN CAST(fp.weight AS DECIMAL(20,4)) <= 0 THEN 'ERROR'
            WHEN CAST(fp.weight AS DECIMAL(20,4)) = 1 THEN 'WARNING'
            ELSE 'OK' END";
    }

    private function weightAuditSort(string $sort): string
    {
        return match ($sort) {
            'name' => 'fp.name',
            'id' => 'fp.id',
            'weight' => 'CAST(fp.weight AS DECIMAL(20,4))',
            'status' => "FIELD({$this->weightAuditStatusSql()}, 'ERROR', 'WARNING', 'OK')",
            default => "FIELD({$this->weightAuditStatusSql()}, 'ERROR', 'WARNING', 'OK')",
        };
    }

}
