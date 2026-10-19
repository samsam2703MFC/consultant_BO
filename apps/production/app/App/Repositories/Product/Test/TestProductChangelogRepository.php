<?php
namespace App\Repositories\Product\Test;


use App\Core\Helpers\BaseRepository;

/**
 * User class
 */
class TestProductChangelogRepository extends BaseRepository
{

    protected string $table = 'test_product_changelog';

    protected $allowedColumns = [
        'id_test_product',
        'id_stage',
        'description',
        'deadline'
    ];


    public function selectChangelogForProduct($idProduct)
    {
        $sql = "SELECT 
                    tpc.id,
                    tpc.id_test_product, 
                    tps.name as title,
                    tpc.description,
                    tpc.deadline,
                    tpc.create_timestamp
                FROM $this->table tpc
                INNER JOIN test_product_stage tps
                ON tpc.id_stage = tps.id
                WHERE tpc.id_test_product = :id_test_product
                ORDER BY tpc.create_timestamp ASC";
        return $this->query($sql, ['id_test_product' => $idProduct]);
    }

    public function getChangelogById($data)
    {
        $sql = "SELECT 
                    id,
                    id_test_product, 
                    title,
                    description,
                    deadline,
                    create_timestamp
                FROM $this->table
                WHERE id = :id
                ORDER BY create_timestamp ASC";
        return $this->query($sql, $data);
    }



    public function getFullChangelogForProductPDF($data)
    {
        $testProductModel = new TestProductModel();

        $test_product_data = $testProductModel->getTestProductData(['id' => $data['id_test_product']]);
        $data['data'][0]['product'] = $test_product_data[0];

        $test_product_changelog_data = $this->selectChangelogForProduct($data['id_test_product']);
        $data['data'][0]['changelog'] = $test_product_changelog_data;

        $testProductModel->getTestProductDataSheet($data);
    }

    public function getSelectedChangelogPDF($data)
    {
        $test_product_changelog_data = $this->getChangelogById($data);
        $data['data'][0]['changelog'] = $test_product_changelog_data;

        $testProductModel = new TestProductModel();

        $test_product_data = $testProductModel->getTestProductData(['id' => $data['data'][0]['changelog'][0]->id_test_product]);
        $data['data'][0]['product'] = $test_product_data[0];
;
        $testProductModel->getTestProductDataSheet($data);
    }

    public function getChangelogPDF($data)
    {
        $pdf = new MYPDFModel('P', 'mm', 'a4', true, 'UTF-8', false);
        $pdf->setMargins(5, 5, 5);

        $header_data = ["header_title" => "Dziennik zmian", "header_subtitle" => "{$data['test_product_data']->name} ({$data['test_product_data']->category_name})"];

        $pdf->AddPage();
        $pdf->customHeader($header_data, true, "p");
//        $pdf->Footer();
//        $pdf->customFooter(["info_1" => ""]);
        $pdf->setPrintFooter(false);
        $pdf->SetTextColor(0,0,0);
        $pdf->SetFont('helvetica', '', 11);
        $pdf->Ln();
        $page_height = 297;
        $curr_page_height = 0;

        foreach ($data['changelog'] as $test_product_changelog_item)
        {
            if($pdf->PageNo() == 1){
                $curr_page_height += 50;
            }

            if($curr_page_height > $pdf->getPageHeight()){
                $pdf->AddPage();
                $curr_page_height = 0;
            }
            $pdf->MultiCell(200, 10, $test_product_changelog_item->title, 1, "C", 0, 0, '', '', true, false, false, true, 10, 'M');
            $pdf->Ln();

            $pdf->SetFont('helvetica', '', 9);
            $pdf->MultiCell(50, 10, "Data utworzenia wpisu:", 1, "C", 0, 0, '', '', true, false, false, true, 10, 'M');
            $pdf->SetFont('helvetica', '', 11);
            $pdf->MultiCell(50, 10, $test_product_changelog_item->create_timestamp, 1, "C", 0, 0, '', '', true, false, false, true, 10, 'M');

            $pdf->SetFont('helvetica', '', 9);
            $pdf->MultiCell(50, 10, "Ostateczny termin wykonania", 1, "C", 0, 0, '', '', true, false, false, true, 10, 'M');
            $pdf->SetFont('helvetica', '', 11);
            $pdf->MultiCell(50, 10, $test_product_changelog_item->deadline, 1, "C", 0, 0, '', '', true, false, false, true, 10, 'M');
            $pdf->Ln();


            $pdf->MultiCell(200, 10, "Opis zmian", 1, "C", 0, 0, '', '', true, false, false, true, 9, 'M');
            $pdf->Ln();
            $str_height = $pdf->getStringHeight(200, $test_product_changelog_item->description);
            $pdf->MultiCell(200, $str_height, $test_product_changelog_item->description, 1, "L", 0, 0, '', '', true, false, false, true);
            $pdf->Ln();

            $pdf->Ln(5);
            $style = array('width' => 0.5, 'cap' => 'butt', 'join' => 'miter', 'dash' => 2, 'color' => array(0, 0, 0));
            $pdf->Line(0, $pdf->GetY(), 210, $pdf->GetY(), $style);

            $pdf->Ln(5);
            $pdf->SetLineStyle(array('width' => 0.25, 'cap' => 'butt', 'join' => 'miter', 'dash' => 0, 'color' => array(0, 0, 0)));

            //80 = sum of height every cell
            $curr_page_height = $curr_page_height + $str_height + 50;
        }

        $base = $pdf->Output('CHANGELOG.pdf', 'S');
        $filename = "CHANGELOG_".date("d_m_Y");

        $documentModel = new DocumentModel();
        $documentModel->saveDocument($filename, $base);
    }

    public function findForFranchisee()
    {
        $sql = "SELECT tpc.id, tpc.id_test_product, tps.name as stage_name, tpc.description, tpc.deadline, tpc.create_timestamp
        FROM $this->table tpc 
        INNER JOIN test_product_stage tps
        ON tpc.id_stage = tps.id
        INNER JOIN test_product tp
        ON tpc.id_test_product = tp.id
        WHERE tp.id_type = 1";
        return $this->query($sql);
    }
}