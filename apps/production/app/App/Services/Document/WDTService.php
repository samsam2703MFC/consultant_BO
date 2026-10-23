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
        $L = fn(string $key) => DocumentLabels::get($key);
        $S = fn(string $key) => DocumentLabels::sub($key, $lang);
        $P = fn(string $key) => DocumentLabels::pair($key, $lang);
        $cur = strtoupper((string) ($wdt['currency'] ?? 'EUR')) === 'EUR' || empty($wdt['currency']) ? '€' : strtoupper((string) $wdt['currency']);
        $money = fn($v) => DocumentLabels::money($v, $cur);

        $pdf->brandPage($L('doc_wdt'), ['ref' => $wdt['doc_num'], 'lang' => $lang, 'company' => DocumentLabels::companyLine($wdt, 'consignor', ['BDO ' . self::BDO])]);
        $pdf->brandHeader($L('doc_wdt'), [
            [$P('number'), $wdt['doc_num']],
            [$P('issue_date'), $wdt['established_date']],
            [$P('shipping_date'), $wdt['shipping_date']],
        ], $S('doc_wdt'));

        $pdf->brandParties([
            'label' => $P('seller'),
            'name' => $wdt['consignor_name'],
            'lines' => [$wdt['consignor_address_1'], $wdt['consignor_address_2'], 'NIP: ' . DocumentLabels::taxId($wdt['consignor_nip']), 'BDO: ' . self::BDO],
        ], [
            'label' => $P('buyer'),
            'name' => $wdt['consignee_name'],
            'lines' => [$wdt['consignee_address_1'], $wdt['consignee_address_2'], 'NIP/TVA: ' . $wdt['consignee_nip']],
        ]);

        $columns = [
            ['label' => $L('code'), 'sub' => $S('code'), 'w' => 10],
            ['label' => $L('description'), 'sub' => $S('description'), 'w' => 30],
            ['label' => $L('stat_code'), 'sub' => $S('stat_code'), 'w' => 11],
            ['label' => $L('cartons'), 'sub' => $S('cartons'), 'w' => 9, 'align' => 'R'],
            ['label' => $L('price_carton'), 'sub' => $S('price_carton'), 'w' => 12, 'align' => 'R'],
            ['label' => $L('net_value'), 'sub' => $S('net_value'), 'w' => 13, 'align' => 'R'],
            ['label' => $L('vat_rate'), 'sub' => $S('vat_rate'), 'w' => 8, 'align' => 'R'],
            ['label' => $L('weight_carton'), 'sub' => $S('weight_carton'), 'w' => 10, 'align' => 'R'],
            ['label' => $L('weight'), 'sub' => $S('weight'), 'w' => 12, 'align' => 'R'],
            ['label' => $L('ppc'), 'sub' => $S('ppc'), 'w' => 9, 'align' => 'R'],
            ['label' => $L('price_piece'), 'sub' => $S('price_piece'), 'w' => 10, 'align' => 'R'],
        ];
        $rows = [];
        $palette = null;
        $net = 0.0;
        $vat = 0.0;
        $cartons = 0;
        $weight = 0.0;
        foreach ((array) $data['wdtProductData'] as $item) {
            if ($palette !== $item['id_palette']) {
                $rows[] = ['_group' => $P('pallet') . ' ' . $item['id_palette']];
                $palette = $item['id_palette'];
            }
            $rows[] = [$item['id_product'], $item['name'], $item['code'], DocumentLabels::number($item['quantity']), $money($item['price_carton']),
                $money($item['total_net']), DocumentLabels::number($item['tva_perc'] ?? 0) . ' %', DocumentLabels::number($item['weight_carton'], 2) . ' kg',
                DocumentLabels::number($item['weight_carton_total'], 2) . ' kg', DocumentLabels::number($item['ppc']), $money($item['price_per_piece'])];
            $net += (float) $item['total_net'];
            $vat += (float) ($item['total_tva'] ?? 0);
            $cartons += (int) $item['quantity'];
            $weight += (float) $item['weight_carton_total'];
        }
        $rows[] = ['_total' => ['', $P('total'), '', DocumentLabels::number($cartons), '', $money($net), '', '', DocumentLabels::number($weight, 2) . ' kg', '', '']];
        $pdf->brandTable($columns, $rows, ['font' => 7, 'head_font' => 6.5]);

        $pdf->brandSummary([
            [$P('invoice_date'), $wdt['invoiced_date']],
            [$P('shipping_date'), $wdt['shipping_date']],
            [$P('total_weight'), DocumentLabels::number($wdt['total_weight'], 2) . ' kg'],
            [$P('pallets'), $wdt['palette_nums']],
            [$P('payment'), $P('payment_proforma')],
            [$P('bank'), $wdt['consignor_bank']],
            [$P('swift'), $wdt['consignor_swift']],
        ], [
            [$P('net'), $money($net)],
            [$P('vat'), $money($vat)],
            [$P('total'), $money($net + $vat)],
        ]);

        $this->renderKsefQr($pdf, $wdt, $data['ksefSubmission'] ?? []);
        return $pdf;
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
    private function renderKsefQr(TCPDFService $pdf, array $wdt, array $submission): void
    {
        if (($submission['status'] ?? null) !== 'accepted' || empty($submission['ksef_number']) || empty($submission['invoice_xml'])) return;
        $url = $this->ksefQrCodes->invoiceVerificationUrl((string)($submission['environment'] ?? 'test'), (string)$wdt['consignor_nip'], (string)$wdt['invoiced_date'], (string)$submission['invoice_xml']);
        if ($pdf->GetY() > 235) $pdf->AddPage();
        $y = $pdf->GetY() + 2;
        $x = $pdf->getPageWidth() - 12 - 32;
        $style = ['border' => false, 'padding' => 0, 'fgcolor' => [0, 0, 0], 'bgcolor' => false];
        $pdf->write2DBarcode($url, 'QRCODE,M', $x, $y, 32, 32, $style, 'N');
        $pdf->SetFont('dejavusans', '', 6);
        $pdf->MultiCell(42, 8, 'KSeF: ' . $submission['ksef_number'], 0, 'C', 0, 0, $x - 5, $y + 33, true);
    }
}
