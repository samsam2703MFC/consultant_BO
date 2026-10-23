<?php
namespace App\Services\Document;

use App\Exceptions\CustomException;
use App\Interfaces\IDocument;
use App\Repositories\Document\Wz\WZProductRepository;
use App\Repositories\Document\Wz\WZRepository;
use App\Repositories\Client\ClientRepository;
use App\Services\Utils\DocumentLabels;
use App\Services\Utils\TCPDFService;

class WZService implements IDocument
{
    private WZRepository $wzRepository;
    private WZProductRepository $wzProductRepository;
    private ClientRepository $clientRepository;

    public function __construct(
        WZRepository $wzRepository,
        WZProductRepository $wzProductRepository,
        ClientRepository $clientRepository
    )
    {
        $this->wzRepository = $wzRepository;
        $this->wzProductRepository = $wzProductRepository;
        $this->clientRepository = $clientRepository;
    }


    public function renderDocument(TCPDFService $pdf, array $data): TCPDFService
    {
        $wz = $data['wzData'];
        $lang = $data['lang'] ?? 'fr';
        $L = fn(string $key) => DocumentLabels::get($key);
        $S = fn(string $key) => DocumentLabels::sub($key, $lang);

        $pdf->brandPage($L('doc_wz'), ['ref' => $wz['doc_num'], 'lang' => $lang, 'company' => DocumentLabels::companyLine($wz)]);
        $pdf->brandHeader($L('doc_wz'), [
            [DocumentLabels::pair('number', $lang), $wz['doc_num']],
            [DocumentLabels::pair('issue_date', $lang), $wz['established_date']],
        ], $S('doc_wz'));

        $pdf->brandParties([
            'label' => DocumentLabels::pair('consignor', $lang),
            'name' => $wz['consignor_name'],
            'lines' => [$wz['consignor_address_1'], $wz['consignor_address_2'], 'NIP: ' . DocumentLabels::taxId($wz['consignor_nip']),
                trim(DocumentLabels::get('bank') . ': ' . trim($wz['consignor_bank_1'] . ' ' . $wz['consignor_bank_2'])), 'SWIFT: ' . $wz['consignor_swift']],
        ], [
            'label' => DocumentLabels::pair('consignee', $lang),
            'name' => $wz['consignee_name'],
            'lines' => [$wz['consignee_address_1'], $wz['consignee_address_2'], 'NIP/TVA: ' . $wz['consignee_nip']],
        ]);

        $columns = [
            ['label' => $L('code'), 'sub' => $S('code'), 'w' => 12],
            ['label' => $L('description'), 'sub' => $S('description'), 'w' => 48],
            ['label' => $L('stat_code'), 'sub' => $S('stat_code'), 'w' => 12],
            ['label' => $L('cartons'), 'sub' => $S('cartons'), 'w' => 10, 'align' => 'R'],
            ['label' => $L('weight_unit'), 'sub' => $S('weight_unit'), 'w' => 12, 'align' => 'R'],
            ['label' => $L('weight'), 'sub' => $S('weight'), 'w' => 13, 'align' => 'R'],
            ['label' => $L('ppc'), 'sub' => $S('ppc'), 'w' => 13, 'align' => 'R'],
        ];
        $rows = [];
        $cartons = 0;
        $weight = 0.0;
        foreach ($data['wzProductData'] as $item) {
            $rows[] = [$item['id_product'], $item['name'], $item['code'], DocumentLabels::number($item['quantity']),
                DocumentLabels::number($item['weight_unit'], 2) . ' kg', DocumentLabels::number($item['weight_total'], 2) . ' kg', DocumentLabels::number($item['ppc'])];
            $cartons += (float) $item['quantity'];
            $weight += (float) $item['weight_total'];
        }
        $rows[] = ['_total' => ['', DocumentLabels::pair('total', $lang), '', DocumentLabels::number($cartons), '', DocumentLabels::number($weight, 2) . ' kg', '']];
        $pdf->brandTable($columns, $rows);

        $pdf->brandSignatures([DocumentLabels::pair('issued_by', $lang), DocumentLabels::pair('received_by', $lang)]);

        return $pdf;
    }

    public function buildDocumentFromId(TCPDFService $pdf, int $id): array
    {

        $wzData = $this->wzRepository->first(['id' => $id]);
        if (!$wzData) {
            throw new CustomException("WZ_NOT_FOUND", 'errors', 404);
        }

        $wzProductData = $this->wzProductRepository->getByIdWz($id);
        if (!$wzProductData) {
            throw new CustomException("WZ_PRODUCTS_NOT_FOUND", 'errors', 404);
        }


        $this->renderDocument($pdf, ['wzData' => $wzData, 'wzProductData' => $wzProductData,
            'lang' => $this->clientRepository->communicationLang((int) $wzData['id_customer'])]);

        return ['file_string' => $pdf, "filename" => 'WZ_' . $wzData['doc_num'] . '.pdf'];
    }

    public function generateDocumentPDF(int $id): array
    {
        $pdf = new TCPDFService('P', 'mm', 'a4', true, 'UTF-8', false);

        $documentData = $this->buildDocumentFromId($pdf, $id);

        return [
            'filename' => $documentData['filename'],
            'file_string' => $documentData['file_string']->Output('', 'S')
        ];
    }
}