<?php
namespace App\Repositories\Product\Test;

use App\Core\Helpers\BaseRepository;

/**
 * User class
 */
class TestProductRepository extends BaseRepository
{

    protected string $table = 'test_product';

    protected $allowedColumns = [
        'name',
        'id_category',
        'id_section',
        'id_recipe',
        'id_status',
        'id_type',
        'label_type'
    ];

    protected $fetchAllowedColumns = [
        'id',
        'name',
        'id_category',
        'id_section',
        'id_recipe',
        'status',
        'label_type',
        'create_timestamp'
    ];

    public function getAll()
    {
        $sql = "SELECT DISTINCT 
                    tp.id,
                    tp.id as id_test_product_subquery,
                    tp.name,
                    tp.label_type,
                    IFNULL(cat.name, '-') as category_name,
                    tp.id_status,
                    stat.name as status_name,
                    (SELECT MAX(deadline) FROM test_product_changelog WHERE id_test_product = id_test_product_subquery) as closest_deadline,
                    tp.id_type,
                    tpt.name as type_name
                FROM $this->table tp
                LEFT JOIN category_product cat
                    ON tp.id_category = cat.id
                LEFT JOIN test_product_status stat
                    ON tp.id_status = stat.id
                INNER JOIN test_product_type tpt
                    ON tp.id_type = tpt.id
                ORDER BY cat.name, tp.id_status";
        return $this->query($sql);
    }

    public function selectWithChangelog()
    {
        $sql = "SELECT x.*
                FROM (SELECT DISTINCT 
                        tp.id,
                        tp.id as id_test_product_subquery,
                        tp.name,
                        tp.label_type,
                        cat.name as category_name,
                        tp.id_status,
                        stat.name as status_name,
                        (SELECT MAX(deadline) FROM test_product_changelog WHERE id_test_product = id_test_product_subquery) as closest_deadline,
                        tp.id_type,
                        tpt.name as type_name
                        
                    FROM test_product tp
                    INNER JOIN category_product cat
                        ON tp.id_category = cat.id
                    INNER JOIN test_product_status stat
                        ON tp.id_status = stat.id
                    INNER JOIN test_product_type tpt
                        ON tp.id_type = tpt.id
                    LEFT JOIN test_product_changelog tpc
                        ON tp.id = tpc.id_test_product
                    ORDER BY cat.name, tp.id_status) x
                 WHERE x.closest_deadline IS NOT NULL";
        return $this->query($sql);
    }

    public function getTestProductData($data)
    {
        $sql = "SELECT 
                    tp.id,
                    tp.name,
                    tp.label_type,
                    cat.name as category_name,
                    stat.name as status_name
                 FROM $this->table tp
                 INNER JOIN category_product cat
                    ON tp.id_category = cat.id
                INNER JOIN test_product_status stat
                    ON tp.id_status = stat.id
                WHERE tp.id = :id";
        return $this->query($sql, $data);
    }

    public function getDataBySection($data)
    {
        $resp_array = [];
        $sql = "SELECT 
                    tp.id,
                    tp.name,
                    tp.id_category,
                    tp.create_timestamp,
                    cat.name as category_name,
                    stage.stage_name,
                    stage.description as stage_description,
                    stage.deadline as stage_deadline
                FROM test_product tp
                INNER JOIN category_product cat
                    ON tp.id_category = cat.id
                LEFT JOIN (
                    SELECT 
                        t.id_test_product,
                        t.description,
                        ts.name as stage_name,
                        t.deadline
                        FROM test_product_changelog t
                        INNER JOIN test_product_stage ts
                        ON t.id_stage = ts.id
                        INNER JOIN (SELECT id_test_product, MAX(deadline) as TopDate FROM test_product_changelog
                                   GROUP BY id_test_product) as eachItem ON 
                               eachItem.id_test_product = t.id_test_product AND eachItem.TopDate = t.deadline
                ) stage
                ON tp.id = stage.id_test_product
                WHERE tp.id_section = :id_section
                ORDER BY tp.id_category, tp.name";
        $resp = $this->query($sql, $data);

        return $resp;
    }

    public function getDataByType($data)
    {
        $resp_array = [];
        $sql = "SELECT 
                    tp.id,
                    tp.name,
                    tp.id_category,
                    tp.create_timestamp,
                    cat.name as category_name,
                    stage.stage_name,
                    stage.description as stage_description,
                    stage.deadline as stage_deadline
                FROM test_product tp
                LEFT JOIN category_product cat
                    ON tp.id_category = cat.id
                LEFT JOIN (
                    SELECT 
                        t.id_test_product,
                        t.description,
                        ts.name as stage_name,
                        t.deadline
                        FROM test_product_changelog t
                        INNER JOIN test_product_stage ts
                        ON t.id_stage = ts.id
                        INNER JOIN (SELECT id_test_product, MAX(deadline) as TopDate FROM test_product_changelog
                                   GROUP BY id_test_product) as eachItem ON 
                               eachItem.id_test_product = t.id_test_product AND eachItem.TopDate = t.deadline
                ) stage
                ON tp.id = stage.id_test_product
                WHERE tp.id_type = :id_type
                ORDER BY tp.id_category, tp.name";
        $resp = $this->query($sql, $data);

        return $resp;
    }

    public function findForFranchisee()
    {
        $sql = "SELECT 
                    tp.id,
                    tp.name,
                    tp.id_category,
                    cp.name as category_name,
                    tp.create_timestamp,
                    stage.stage_name,
                    stage.deadline as stage_deadline,
                    tp.id_type
                FROM test_product tp
                LEFT JOIN category_product cp
                    ON tp.id_category = cp.id
                LEFT JOIN (
                SELECT 
                    t.id_test_product,
                    t.description,
                    ts.name as stage_name,
                    t.deadline
                    FROM test_product_changelog t
                    INNER JOIN test_product_stage ts
                    ON t.id_stage = ts.id
                    INNER JOIN (SELECT id_test_product, MAX(deadline) as TopDate FROM test_product_changelog
                               GROUP BY id_test_product) as eachItem ON 
                               eachItem.id_test_product = t.id_test_product AND eachItem.TopDate = t.deadline
                ) stage
                ON tp.id = stage.id_test_product";
        return $this->query($sql);
    }

    public function getDataForFranchisee()
    {
        $testProductChangelogModel = new TestProductChangelogRepository();

        $resp['test_product'] = [];
        //$resp['test_product_changelog'] = [];

        $resp['test_product'] = convert_std_to_assoc($this->findForFranchisee());
       // $resp['test_product_changelog'] = convert_std_to_assoc($testProductChangelogModel->findForFranchisee());

        return $resp;
    }
}