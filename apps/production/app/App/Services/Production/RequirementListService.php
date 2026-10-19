<?php

namespace App\Services\Production;

use App\Exceptions\CustomException;
use App\Repositories\Production\RequirementListRepository;
use App\Services\Utils\TCPDFService;
use App\Services\Production\RequirementListPdfBuilder;

/**
 * Serwis do generowania PDF listy zapotrzebowania materiałowego.
 *
 * Kontrakt (wejście):
 *  - order_id: int[]|string[]
 *  - stock_minimum: 0|1 (czy uwzględniać stany magazynowe / minimum)
 *
 * Kontrakt (wyjście):
 *  - ['filename' => string, 'file_string' => string]
 */
class RequirementListService
{
    public function __construct(
        private RequirementListPdfBuilder $pdfBuilder,
        private RequirementListRepository $requirementListRepository
    ) {
    }

    /**
     * @param array $data
     * @return array{filename:string,file_string:string}
     * @throws CustomException
     */
    public function getRequirementListPDF(array $data): array
    {
        $requirementData = $this->getRequirementListData($data);

        $pdf = new TCPDFService('P', 'mm', 'a4', true, 'UTF-8', false);
        $pdf->setMargins(8, 8, 8);

        $this->pdfBuilder->build($pdf, $requirementData);

        $base = $pdf->Output('REQUIREMENT_LIST.pdf', 'S');
        $filename = 'REQUIREMENT_LIST_' . date('d_m_Y');

        return [
            'filename' => $filename . '.pdf',
            'file_string' => $base,
        ];
    }

    /**
     * Returns the same material rows that are rendered in the requirement-list PDF.
     *
     * @param array{order_id?: list<int|string>, stock_minimum?: mixed, rows?: array<int, array<string, mixed>>} $data
     * @return array{order_id:list<int|string>, stock_minimum:bool, rows:array<int, array<string, mixed>>}
     * @throws CustomException
     */
    public function getRequirementListData(array $data): array
    {
        $orderIds = $data['order_id'] ?? [];
        if (!is_array($orderIds) || count($orderIds) === 0) {
            throw new CustomException('NO_ORDERS', 'errors', 400);
        }

        $stockMinimum = !empty($data['stock_minimum']);

        $rows = $data['rows'] ?? null;
        if (!is_array($rows) || count($rows) === 0) {

            // Przykładowa paczka danych (żeby zobaczyć render PDF bez integracji z DB)
            $rows = $this->getRequirementPositions($orderIds, $stockMinimum);
        }

        return [
            'order_id' => array_values($orderIds),
            'stock_minimum' => $stockMinimum,
            'rows' => $rows,
        ];
    }

    private function getRequirementPositions($orderIds, $includeStockMinimum = false): array
    {
        if(!$includeStockMinimum){
            return $this->requirementListRepository->getRequirementPositionsByOrderIds($orderIds);
        }

        return $this->requirementListRepository->getRequirementPositionsByOrderIdsWithStockMinimum($orderIds);

    }

}
