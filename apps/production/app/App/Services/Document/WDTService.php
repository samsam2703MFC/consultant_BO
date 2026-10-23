<?php

namespace App\Services\Document;

use App\Exceptions\CustomException;
use App\Interfaces\IDocument;
use App\Repositories\Document\Wdt\WDTProductRepository;
use App\Repositories\Document\Wdt\WDTRepository;
use App\Repositories\Document\Wz\WZRepository;
use App\Repositories\Ksef\KsefSubmissionRepository;
use App\Services\Ksef\KsefQrCodeService;
use App\Repositories\Client\ClientRepository;
use App\Services\Utils\DocumentLabels;
use App\Services\Utils\TCPDFService;

class WDTService implements IDocument
{
    /** Numer rejestrowy BDO wydającego (wymagany na dokumentach dostawy opakowań). */
    public const BDO = '000507840';

    private WDTRepository $wdtRepository;
    private WDTProductRepository $wdtProductRepository;
    private KsefSubmissionRepository $ksefSubmissions;
    private KsefQrCodeService $ksefQrCodes;

    public function __construct(
        WDTRepository        $wdtRepository,
        WDTProductRepository $wdtProductRepository,
        KsefSubmissionRepository $ksefSubmissions,
        KsefQrCodeService $ksefQrCodes,
        private ClientRepository $clientRepository
    )
    {
        $this->wdtRepository = $wdtRepository;
        $this->wdtProductRepository = $wdtProductRepository;
        $this->ksefSubmissions = $ksefSubmissions;
        $this->ksefQrCodes = $ksefQrCodes;
    }

    public function getById($id)
    {
        return $this->wdtRepository->first(['id' => $id]);
    }

    public function renderDocument(TCPDFService $pdf, array $data): TCPDFService
    {
        $wdt = $data['wdtData'];
        $lang = $data['lang'] ?? 'fr';
        $P = fn(string $key) => DocumentLabels::pair($key, $lang);
        $cur = strtoupper((string) ($wdt['currency'] ?? 'EUR')) === 'EUR' || empty($wdt['currency']) ? '€' : strtoupper((string) $wdt['currency']);

        // Wiersze w kolejności KSeF (id rosnąco) : Lp. na wydruku = NrWierszaFa w XML.
        $items = array_values((array) $data['wdtProductData']);
        usort($items, fn($x, $y) => (int) ($x['id'] ?? 0) <=> (int) ($y['id'] ?? 0));
        $lines = [];
        foreach ($items as $item) {
            $vat = (float) ($item['total_tva'] ?? 0);
            $lines[] = ['code' => $item['id_product'], 'name' => $item['name'], 'stat' => $item['code'], 'quantity' => $item['quantity'],
                'price' => $item['price_carton'], 'net' => $item['total_net'], 'vat_rate' => $item['tva_perc'] ?? 0, 'vat' => $vat,
                'gross' => (float) $item['total_net'] + $vat, 'weight' => $item['weight_carton_total'], 'ppc' => $item['ppc'], 'price_piece' => $item['price_per_piece']];
        }

        $submission = $data['ksefSubmission'] ?? [];
        $qrUrl = $this->ksefQrUrl($wdt, $submission);
        return InvoiceLayout::render($pdf, [
            'title_key' => 'doc_wdt',
            'number' => $wdt['doc_num'],
            'issue_date' => $wdt['invoiced_date'] ?: $wdt['established_date'],
            'delivery_date' => $wdt['shipping_date'],
            'lang' => $lang,
            'currency' => $cur,
            'company_line' => DocumentLabels::companyLine($wdt, 'consignor', ['BDO ' . self::BDO]),
            'seller' => ['name' => $wdt['consignor_name'],
                'lines' => [$wdt['consignor_address_1'], $wdt['consignor_address_2'], 'BDO: ' . self::BDO, 'NIP: ' . DocumentLabels::taxId($wdt['consignor_nip'])]],
            'buyer' => ['name' => $wdt['consignee_name'],
                'lines' => [$wdt['consignee_address_1'], $wdt['consignee_address_2'], 'NIP/TVA: ' . $wdt['consignee_nip']]],
            'payment' => [
                [$P('pallets'), (string) $wdt['palette_nums']],
                [$P('payment'), $P('payment_proforma')],
                [$P('bank'), (string) $wdt['consignor_bank']],
                [$P('swift'), (string) $wdt['consignor_swift']],
            ],
            'qr' => $qrUrl ? fn(TCPDFService $pdf, float $top) => $this->drawKsefQr($pdf, $qrUrl, (string) $submission['ksef_number'], $top) : null,
        ], $lines);
    }

    public function buildDocumentFromId(TCPDFService $pdf, int $id): array
    {
        $wdtData = $this->wdtRepository->first(['id' => $id]);
        if (!$wdtData) {
            throw new CustomException("WDT_NOT_FOUND", 'errors', 404);
        }

        $wdtProductData = $this->wdtProductRepository->getByIdWdt($wdtData['id']);
        if (!$wdtProductData) {
            throw new CustomException("WDT_NOT_FOUND", 'errors', 404);
        }

        $this->renderDocument($pdf, [
            'wdtData' => $wdtData,
            'wdtProductData' => $wdtProductData,
            'ksefSubmission' => $this->ksefSubmissions->findByWdtId((int)$wdtData['id']),
            'lang' => $this->clientRepository->communicationLang((int) $wdtData['id_customer'])]);

        return ['file_string' => $pdf, "filename" => 'WDT_' . $wdtData['doc_num'] . '.pdf'];
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

    public function updateProduct($idWDT, $idProduct, $data)
    {
        $submission = $this->ksefSubmissions->findByWdtId((int)$idWDT);
        if (in_array($submission['status'] ?? null, ['submitted', 'processing', 'accepted', 'rejected'], true)) {
            throw new \RuntimeException('Nie można zmieniać pozycji WDT po przekazaniu dokumentu do KSeF. W razie potrzeby wystaw fakturę korygującą.');
        }
        return $this->wdtProductRepository->updateProduct($idWDT, $idProduct, $data);
    }

    public function isLockedForEditing(int $wdtId): bool
    {
        $submission = $this->ksefSubmissions->findByWdtId($wdtId);
        return in_array($submission['status'] ?? null, ['submitted', 'processing', 'accepted', 'rejected'], true);
    }

    public function getProductsByIdWdt($id)
    {
        return $this->wdtProductRepository->getProductsByIdWdt($id);
    }

    /** @param array<string,mixed> $wdt @param array<string,mixed> $submission */
    private function ksefQrUrl(array $wdt, array $submission): ?string
    {
        if (($submission['status'] ?? null) !== 'accepted' || empty($submission['ksef_number']) || empty($submission['invoice_xml'])) return null;
        return $this->ksefQrCodes->invoiceVerificationUrl((string)($submission['environment'] ?? 'test'), (string)$wdt['consignor_nip'], (string)$wdt['invoiced_date'], (string)$submission['invoice_xml']);
    }

    /** Kod QR KSeF po prawej, nad blokiem sum (strefa zarezerwowana przez InvoiceLayout). */
    private function drawKsefQr(TCPDFService $pdf, string $url, string $ksefNumber, float $top): void
    {
        $x = $pdf->getPageWidth() - 12 - 32;
        $style = ['border' => false, 'padding' => 0, 'fgcolor' => [0, 0, 0], 'bgcolor' => false];
        $pdf->write2DBarcode($url, 'QRCODE,M', $x, $top, 32, 32, $style, 'N');
        $pdf->SetFont('dejavusans', '', 6);
        $pdf->MultiCell(42, 5, 'KSeF: ' . $ksefNumber, 0, 'C', false, 0, $x - 5, $top + 33, true);
    }
}
