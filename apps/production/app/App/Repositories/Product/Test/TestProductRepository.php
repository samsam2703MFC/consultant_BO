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



    public function getTestProductDataSheet($data)
    {

        $pdf = new MYPDFModel('P', 'mm', 'a4', true, 'UTF-8', false);
        $pdf->setMargins(5, 5, 5);

        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);

        $pdf->AddPage();

        $last_category = "";
        $iterator = 0;
        foreach ($data['data'] as $item){
            $pdf->setCellPaddings( $left = '', $top = 2, $right = '', $bottom = '');
            $pdf->MultiCell(40, 0, $item->name, "0", "L", 0, 1, '', '', true, false, false, true, 1);

            if($item->id_category != $last_category){

                $pdf->SetFont('helvetica', 'B', 15);
                $pdf->MultiCell(200, 8, $item->category_name, 0, "L", 0, 1, '', '', true, false, false, true, 10, 'M');
                $pdf->Ln(5);
            }

            $pdf->SetFont('helvetica', '', 9);
            $cellH = $pdf->getStringHeight(120, $item->stage_description);

            if ($cellH < 8) $cellH = 8;

            $pdf->MultiCell(40, $cellH, $item->name, "T", "L", 0, 0, '', '', true, false, false, true, 10);
            $pdf->MultiCell(120, $cellH, $item->stage_description, "T", "L", 0, 0, '', '', true, false, false);
            $pdf->MultiCell(40, $cellH, $item->stage_deadline, "T", "L", 0, 0, '', '', true, false, false, true, 10);

            $last_category = $item->id_category;

            $iterator++;
            $pdf->Ln($cellH+5);
        }


        $base = $pdf->Output('CHANGELOG.pdf', 'D');
        $filename = "CHANGELOG_".date("d_m_Y");

        $documentModel = new DocumentModel();
        $documentModel->saveDocument($filename, $base);
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