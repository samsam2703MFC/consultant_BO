<?php
namespace App\Services\Product\Price;

use App\Exceptions\CustomException;
use App\Interfaces\IDocument;
use App\Repositories\Client\ClientRepository;
use App\Repositories\Product\Price\PriceListRepository;
use App\Services\Utils\DocumentLabels;
use App\Services\Utils\TCPDFService;

class ClientPriceListService implements IDocument
{
    private PriceListRepository $priceListRepository;
    private ClientRepository $clientRepository;
    
    public function __construct(
        PriceListRepository $priceListRepository,
        ClientRepository $clientRepository
    )
    {
        $this->priceListRepository = $priceListRepository;
        $this->clientRepository = $clientRepository;
    }

    public function renderDocument(TCPDFService $pdf, array $data): TCPDFService
    {
        $client = $data['clientData'];
        $lang = $data['lang'] ?? 'fr';
        $L = fn(string $key) => DocumentLabels::get($key);
        $S = fn(string $key) => DocumentLabels::sub($key, $lang);

        $pdf->brandPage($L('doc_price_list'), ['ref' => (string) $client['company'], 'lang' => $lang]);
        $pdf->brandHeader($L('doc_price_list') . ($S('doc_price_list') !== '' ? ' / ' . $S('doc_price_list') : ''), [
            [DocumentLabels::pair('date', $lang), date('Y-m-d')],
        ], (string) $client['company']);

        $rows = [];
        $lastCategory = null;
        foreach ($data['productPriceData'] as $item) {
            if ($item['price'] < 10) continue;
            if ($item['id_category'] !== $lastCategory) {
                $rows[] = ['_group' => $item['category_name']];
                $lastCategory = $item['id_category'];
            }
            $rows[] = [$item['final_product_name'], DocumentLabels::money($item['price'])];
        }
        $pdf->brandTable([
            ['label' => $L('product'), 'sub' => $S('product'), 'w' => 80],
            ['label' => $L('price'), 'sub' => $S('price'), 'w' => 20, 'align' => 'R'],
        ], $rows, ['font' => 8.5, 'head_font' => 7.5]);

        return $pdf;
    }

    public function buildDocumentFromId(TCPDFService $pdf, int $id): array
    {
        $productPriceData = $this->priceListRepository->getPriceListData(['id_customer' => $id]);
        if (empty($productPriceData)) {
            throw new CustomException("PRICE_LIST_DATA_NOT_FOUND", 'errors', 404);
        }

        $clientData = $this->clientRepository->first(['id' => $id]);
        if (empty($clientData)) {
            throw new CustomException("CLIENT_NOT_FOUND", 'errors', 404);
        }

        // Kategoria → produkty z ceną ; język = język komunikacji klienta (polski + drugi język)
        $this->renderDocument($pdf, [
            'clientData' => $clientData,
            'lang' => $this->clientRepository->communicationLang($id),
            'productPriceData' => $productPriceData]);

        return ['file_string' => $pdf, "filename" => 'PRICE_LIST_' . $clientData['company'] . '.pdf'];
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