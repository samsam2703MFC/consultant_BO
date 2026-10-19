<?php

namespace App\Services\Production;

use App\Core\TransactionManager;
use App\Exceptions\CustomException;
use App\Repositories\Client\Order\OrderRepository;
use App\Repositories\Document\DocumentRepository;
use App\Repositories\Employee\Section\ProductionSectionRepository;
use App\Repositories\Production\ComplaintProductionListRepository;
use App\Repositories\Production\ProductionListRepository;
use App\Repositories\Production\ProductionRepository;
use App\Services\Employee\Section\ProductionSectionService;
use App\Services\Utils\TCPDFService;

class ProductionListService
{
    private ProductionRepository $productionRepository;
    private OrderRepository $orderRepository;
    private TransactionManager $transactionManager;
    private ProductionListRepository $productionListRepository;
    private ComplaintProductionListRepository $complaintProductionListRepository;
    private ProductionSectionRepository $productionSectionRepository;

    public function __construct(
        ProductionRepository              $productionRepository,
        OrderRepository                   $orderRepository,
        ProductionListRepository          $productionListRepository,
        ComplaintProductionListRepository $complaintProductionListRepository,
        TransactionManager                $transactionManager,
        ProductionSectionRepository       $productionSectionRepository
    )
    {
        $this->productionRepository = $productionRepository;
        $this->orderRepository = $orderRepository;
        $this->productionListRepository = $productionListRepository;
        $this->complaintProductionListRepository = $complaintProductionListRepository;
        $this->transactionManager = $transactionManager;
        $this->productionSectionRepository = $productionSectionRepository;
    }

    public function getProductionListData($data)
    {
        return $this->transactionManager->execute(function () use ($data) {

            $dates = $this->orderRepository->getDatesOfSelectedOrders($data);
            $respArray = [];

            $respArray['dates'] = $dates;
            $respArray['section_data'] = [];

            if (!isset($data['order_id']) || empty($data['order_id'])) {
                $orderIdStr = '0';
            } else {
                $orderIdStr = implode(',', $data['order_id']);
            }


            $resCreateTemporary = $this->productionListRepository->createTemporaryTable();
            if (!$resCreateTemporary) {
                throw new CustomException("CREATE_TEMPORARY_ERROR", 'errors', 500);
            }

            if (!isset($data['order_id']) || empty($data['order_id'])) {

                $resFillTemporary = $this->productionListRepository->fillTemporaryTableWithoutOrder();
                if (!$resFillTemporary) {
                    throw new CustomException("FILL_TEMPORARY_ERROR", 'errors', 500);
                }

                $sections = $this->productionSectionRepository->findAll();
                if (!$sections || count($sections) == 0) {
                    throw new CustomException("NO_SECTIONS_FOUND", 'errors', 404);
                }

                foreach ($sections as $section) {
                    $sectionProductData = $this->productionListRepository->fetchFromTemporaryByIdSectionWithoutOrder($section['id']);
                    if (!$sectionProductData || count($sectionProductData) == 0) continue;

                    $respArray['section_data'][] = [
                        'section_name' => $section['name'],
                        'section_products' => $sectionProductData
                    ];
                }
            } else {

                $data['formated_shipping_date_1'] = ($dates[0]['expected_shipping_date'] == "-") ? "0000-00-01" : $dates[0]['expected_shipping_date'];
                $data['formated_shipping_date_2'] = ($dates[1]['expected_shipping_date'] == "-") ? "0000-00-01" : $dates[1]['expected_shipping_date'];
                $data['formated_shipping_date_3'] = ($dates[2]['expected_shipping_date'] == "-") ? "0000-00-01" : $dates[2]['expected_shipping_date'];
                $data['formated_shipping_date_4'] = ($dates[3]['expected_shipping_date'] == "-") ? "0000-00-01" : $dates[3]['expected_shipping_date'];


                $resFillTemporary = $this->productionListRepository->fillTemporaryTableWithOrder($data, $orderIdStr);
                if (!$resFillTemporary) {
                    throw new CustomException("FILL_TEMPORARY_ERROR", 'errors', 500);
                }

                $sections = $this->productionSectionRepository->findAll();
                if (!$sections || count($sections) == 0) {
                    throw new CustomException("NO_SECTIONS_FOUND", 'errors', 404);
                }

                if (isset($data['stock_minimum']) && $data['stock_minimum']) {
                    $condition = "(date_1 + date_2 + date_3 + date_4 + stock_minimum + complaint)";
                } else {
                    $condition = "(date_1 + date_2 + date_3 + date_4 + complaint)";
                }


                foreach ($sections as $section) {

                    $sectionProductData = $this->productionListRepository->fetchFromTemporaryByIdSectionWithOrder($section['id'], $condition);
                    if (!$sectionProductData || count($sectionProductData) == 0) continue;

                    $respArray['section_data'][] = [
                        'section_name' => $section['name'],
                        'section_products' => $sectionProductData
                    ];
                }
            }

            return $respArray;
        });


    }

    public function getProductionListPDF($data)
    {
        $productionData = $this->getProductionListDocumentData($data);
        $hide_stock_flag = $productionData['pdf']['hide_stock_flag'];
        $header = $productionData['pdf']['header'];
        $secHeader = $productionData['pdf']['section_header'];


        $pdf = new TCPDFService('L', 'mm', 'a4', true, 'UTF-8', false);
        $pdf->setMargins(5, 5, 5);

        foreach ($productionData['section_data'] as $section_data) {
            $header_data = ["header_title" => $section_data['section_name'], "header_subtitle" => date("d-m-Y")];

            $pdf->AddPage();
            $pdf->customHeader($header_data, false, "l");
            $pdf->Footer();
            $pdf->customFooter(["info_1" => ""]);

            $pdf->SetTextColor(0, 0, 0);
            $pdf->SetFont('helvetica', '', 12);

            $pdf->Ln(10);
            $pdf->fillProductionListForSection($header, $secHeader, $section_data['section_products'], $productionData['stock_minimum_flag'], $hide_stock_flag);
            $pdf->Ln(7);
        }

        $base = $pdf->Output('PRODUCTION_LIST.pdf', 'S');
        $filename = "PRODUCTION_LIST_" . date("d_m_Y");

        return [
            'filename' => $filename . '.pdf',
            'file_string' => $base
        ];
    }

    /**
     * Prepares every business value and PDF-layout option used by the standard
     * production-list renderer. It is shared by the PDF and internal API preview.
     */
    public function getProductionListDocumentData(array $data): array
    {
        $productionData = $this->getProductionListData($data);
        $productionData['stock_minimum_flag'] = isset($data['stock_minimum']) && $data['stock_minimum'] == 1;

        $hideStockFlag = $productionData['stock_minimum_flag'] && empty($data['order_id']);
        $productionData['pdf'] = [
            'orientation' => 'landscape',
            'hide_stock_flag' => $hideStockFlag,
            'header' => [
                '',
                'W magazynie',
                'Zamówienie',
                'Do produkcji',
                $productionData['dates'][1]['expected_shipping_date'],
                $productionData['dates'][2]['expected_shipping_date'],
                $productionData['dates'][3]['expected_shipping_date'],
            ],
            'section_header' => [
                'Produkt',
                'Kart.',
                'Szt.',
                'Kart.',
                'Szt.',
                'Szt.',
                'Kart.',
                'Szt.',
                'Kart.',
                'Szt.',
                'Kart.',
                'Szt.',
            ],
        ];

        return $productionData;
    }

    public function getComplaintProductionListPDF()
    {
        $productionData = $this->getComplaintProductionListDocumentData();
        $header = $productionData['pdf']['header'];
        $secHeader = $productionData['pdf']['section_header'];


        $pdf = new TCPDFService('P', 'mm', 'a4', true, 'UTF-8', false);
        $pdf->setMargins(5, 5, 5);

        foreach ($productionData['section_data'] as $section_data) {
            $header_data = ["header_title" => $section_data['section_name'], "header_subtitle" => date("d-m-Y")];

            $pdf->AddPage();
            $pdf->customHeader($header_data, false, "p");
            $pdf->Footer();
            $pdf->customFooter(["info_1" => ""]);

            $pdf->SetFont('helvetica', '', 12);
            $pdf->writeHTMLCell(180, 11, 15, '', "(REKLAMACJE)", 0, 1, 0, true, 'C');


            $pdf->SetTextColor(0, 0, 0);
            $pdf->SetFont('helvetica', '', 12);

            $pdf->Ln(10);
            $pdf->fillComplaintProductionListForSection($header, $secHeader, $section_data['section_products']);
            $pdf->Ln(7);
        }

        $base = $pdf->Output('PRODUCTION_LIST_COMPLAINT.pdf', 'S');
        $filename = "PRODUCTION_LIST_COMPLAINT_" . date("d_m_Y");

        return [
            'filename' => $filename . '.pdf',
            'file_string' => $base
        ];
    }

    /** Shared input data and PDF-layout options for the complaint-list renderer. */
    public function getComplaintProductionListDocumentData(): array
    {
        $productionData = $this->getComplaintProductionListData();
        $productionData['pdf'] = [
            'orientation' => 'portrait',
            'header' => ['', 'Zwrot'],
            'section_header' => ['Produkt', 'Kart.', 'Szt.'],
        ];

        return $productionData;
    }

    public function getComplaintProductionListData()
    {
        $respArray = [];

        $respArray['section_data'] = [];

        $resCreateTemporary = $this->complaintProductionListRepository->createTemporaryTable();
        if (!$resCreateTemporary) {
            throw new CustomException("CREATE_TEMPORARY_ERROR", 'errors', 500);
        }

        $resFillTemporary = $this->complaintProductionListRepository->fillTemporaryTable();
        if (!$resFillTemporary) {
            throw new CustomException("FILL_TEMPORARY_ERROR", 'errors', 500);
        }

        $sections = $this->productionSectionRepository->findAll();
        if (!$sections || count($sections) == 0) {
            throw new CustomException("NO_SECTIONS_FOUND", 'errors', 404);
        }

        foreach ($sections as $section) {

            $sectionProductData = $this->complaintProductionListRepository->fetchFromTemporaryByIdSectionWithoutOrder($section['id']);

            if (!$sectionProductData || count($sectionProductData) == 0) continue;

            $respArray['section_data'][] = [
                'section_name' => $section['name'],
                'section_products' => $sectionProductData
            ];
        }

        return $respArray;
    }


}
