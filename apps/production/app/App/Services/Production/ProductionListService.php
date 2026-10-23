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
use App\Services\Workshop\Planning\WeekService;

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
        ProductionSectionRepository       $productionSectionRepository,
        private WeekService               $week
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
        // Tylko liczby całkowite (identyfikatory zamówień trafiają do SQL listy).
        $data['order_id'] = array_values(array_unique(array_filter(array_map('intval', (array)($data['order_id'] ?? [])), fn($id) => $id > 0)));
        $productionData = $this->transactionManager->execute(function () use ($data) {

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

        return $this->withPlan($productionData);
    }

    /**
     * « Do produkcji » = plan dnia produkcji (pierwszy wybrany dzień, inaczej dziś) z planu tygodnia — ta sama
     * formuła co Arkusz tygodnia, Plan zespołów i tablet. Tydzień rozłożony na dni : ilość dnia (+ nierozłożona
     * reszta tygodnia) ; tydzień nierozłożony : ilość tygodnia. Produkty z planem bez zamówień też trafiają na listę.
     */
    private function withPlan(array $productionData): array
    {
        $first = $productionData['dates'][0]['expected_shipping_date'] ?? '-';
        $day = $first !== '-' ? $first : date('Y-m-d');
        $plan = [];
        foreach ($this->week->quantities($day)['items'] as $p) {
            if ($p['id_final_product'] !== null) $plan[$p['id_final_product']] = $p;
        }
        $productionData['plan_day'] = $day;
        $sections = array_column($this->productionSectionRepository->findAll() ?: [], 'name', 'id');
        $bySection = [];
        foreach ($productionData['section_data'] as $sd) $bySection[$sd['section_name']] = $sd;
        $missing = array_diff_key($plan, array_flip(array_merge(...array_map(fn($sd) => array_map(fn($r) => (int)$r['id_product'], $sd['section_products']), array_values($bySection)) ?: [[]])));
        foreach ($this->productionListRepository->productsForPlan(array_keys($missing)) as $row) {
            $name = $sections[(int)$row['id_section']] ?? null;
            if ($name === null) continue;
            $bySection[$name] ??= ['section_name' => $name, 'section_products' => []];
            $bySection[$name]['section_products'][] = $row;
        }
        foreach ($bySection as &$sd) {
            foreach ($sd['section_products'] as &$row) {
                $p = $plan[(int)$row['id_product']] ?? null;
                $qty = 0.0;
                $text = '-';
                if ($p !== null) {
                    $fmt = fn(float $v) => rtrim(rtrim(number_format($v, 1, ',', ' '), '0'), ',');
                    if ($p['slots']) {
                        $qty = (float)($p['slots'][$day] ?? 0.0);
                        $text = ($qty > 0 ? $fmt($qty) : '-') . ($p['unassigned'] > 0 ? ' (+' . $fmt($p['unassigned']) . ')' : '');
                    } else {
                        $qty = (float)$p['quantity'];
                        $text = $qty > 0 ? $fmt($qty) . ' tydz.' : '-';
                    }
                }
                $row['to_produce_pcs'] = $qty;
                $row['to_produce_text'] = $text;
                $row['plan_week_pcs'] = $p['quantity'] ?? 0.0;
                $row['plan_unassigned_pcs'] = $p['unassigned'] ?? 0.0;
            }
            unset($row);
            usort($sd['section_products'], fn($a, $b) => strcmp((string)$a['name'], (string)$b['name']));
        }
        unset($sd);
        $productionData['section_data'] = array_values($bySection);
        return $productionData;
    }

    public function getProductionListPDF($data)
    {
        $productionData = $this->getProductionListDocumentData($data);
        $hide_stock_flag = $productionData['pdf']['hide_stock_flag'];
        $header = $productionData['pdf']['header'];
        $secHeader = $productionData['pdf']['section_header'];
        $planDay = date('d.m.Y', strtotime($productionData['plan_day']));

        $pdf = new TCPDFService('L', 'mm', 'a4', true, 'UTF-8', false);

        // jeden dokument na dział : własna numeracja stron, kartka do zespołu
        foreach ($productionData['section_data'] as $section_data) {
            $pdf->brandPage('Lista produkcyjna · ' . $section_data['section_name'], ['orientation' => 'L', 'ref' => $planDay]);
            $pdf->brandHeader($section_data['section_name'], [
                ['Plan na', $planDay],
                ['Wydruk', date('d.m.Y H:i')],
            ], 'Lista produkcyjna');

            $pdf->brandTable($this->productionListColumns($header, $secHeader), $this->productionListRows($section_data['section_products'], $hide_stock_flag), ['font' => 9, 'head_font' => 7, 'min_h' => 7]);
            $pdf->brandNote('Do produkcji = plan na ' . $planDay . ' z Planu zespołów (te same ilości co Arkusz tygodnia i tablet) ; (+N) = ilość tygodnia jeszcze nierozłożona na dni ; « tydz. » = tydzień bez podziału na dni.');
        }

        $base = $pdf->Output('PRODUCTION_LIST.pdf', 'S');
        $filename = "PRODUCTION_LIST_" . date("d_m_Y");

        return [
            'filename' => $filename . '.pdf',
            'file_string' => $base
        ];
    }

    /**
     * Kolumny listy produkcyjnej : produkt, potem pary kartony / sztuki (nagłówek grupy nad jednostką) ;
     * « Do produkcji » tylko w sztukach. $header i $secHeader jak w getProductionListDocumentData().
     */
    private function productionListColumns(array $header, array $secHeader): array
    {
        // kolumna danych => grupa z $header (0 = produkt) ; daty wysyłki skrócone do « dd.mm »
        $groups = [0, 1, 1, 2, 2, 3, 4, 4, 5, 5, 6, 6];
        $label = fn($h) => preg_match('/^\d{4}-\d{2}-\d{2}/', (string) $h) ? date('d.m', strtotime((string) $h)) : (string) $h;
        $columns = [];
        foreach ($secHeader as $i => $unit) {
            if ($i === 0) {
                $columns[] = ['label' => $unit, 'w' => 64];
                continue;
            }
            $columns[] = ['label' => $label($header[$groups[$i]]), 'sub' => $unit, 'w' => $groups[$i] === 3 ? 22 : 18.7, 'align' => 'C'];
        }
        return $columns;
    }

    /** Wiersze listy produkcyjnej ; zero = « - », stany ukryte przy liście bez zamówień (stan minimalny). */
    private function productionListRows(array $products, bool $hideStock): array
    {
        $dash = fn($v) => ($v == 0) ? '-' : $v;
        $rows = [];
        foreach ($products as $p) {
            $rows[] = [
                $p['name'],
                $hideStock ? '-' : $p['in_stock_carton'],
                $hideStock ? '-' : $p['in_stock_pcs'],
                $dash($p['day_1_carton']), $dash($p['day_1_pcs']),
                // Do produkcji : plan dnia z planu tygodnia (withPlan) — jedna formuła z Arkuszem tygodnia
                $p['to_produce_text'] ?? '-',
                $dash($p['day_2_carton']), $dash($p['day_2_pcs']),
                $dash($p['day_3_carton']), $dash($p['day_3_pcs']),
                $dash($p['day_4_carton']), $dash($p['day_4_pcs']),
            ];
        }
        return $rows;
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

        foreach ($productionData['section_data'] as $section_data) {
            $pdf->brandPage('Lista produkcyjna (reklamacje) · ' . $section_data['section_name'], ['ref' => date('d.m.Y')]);
            $pdf->brandHeader($section_data['section_name'], [
                ['Wydruk', date('d.m.Y H:i')],
            ], 'Lista produkcyjna — reklamacje');

            $columns = [
                ['label' => $secHeader[0], 'w' => 120],
                ['label' => $header[1], 'sub' => $secHeader[1], 'w' => 30, 'align' => 'C'],
                ['label' => $header[1], 'sub' => $secHeader[2], 'w' => 30, 'align' => 'C'],
            ];
            $rows = array_map(fn($p) => [$p['name'], $p['complaint_carton'], $p['complaint_pcs']], $section_data['section_products']);
            $pdf->brandTable($columns, $rows, ['font' => 9, 'head_font' => 7.5, 'min_h' => 7]);
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
