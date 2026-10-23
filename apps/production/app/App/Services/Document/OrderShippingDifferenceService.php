<?php
namespace App\Services\Document;

use App\Exceptions\CustomException;
use App\Interfaces\IDocument;
use App\Repositories\Document\Wdt\WDTProductRepository;
use App\Repositories\Document\Wdt\WDTRepository;
use App\Repositories\Shipping\Difference\OrderShippingDifferenceProductRepository;
use App\Repositories\Shipping\Difference\OrderShippingDifferenceRepository;
use App\Repositories\Shipping\Log\ShippingLogRepository;
use App\Repositories\Client\ClientRepository;
use App\Services\Utils\DocumentLabels;
use App\Services\Utils\TCPDFService;

class OrderShippingDifferenceService implements IDocument
{

    private OrderShippingDifferenceRepository $orderShippingDifferenceRepository;
    private OrderShippingDifferenceProductRepository $orderShippingDifferenceProductRepository;
    private WDTRepository $wdtRepository;
    private ShippingLogRepository $shippingLogRepository;

    public function __construct(
        OrderShippingDifferenceRepository $orderShippingDifferenceRepository,
        OrderShippingDifferenceProductRepository $orderShippingDifferenceProductRepository,
        WDTRepository $wdtRepository,
        ShippingLogRepository $shippingLogRepository,
        private ClientRepository $clientRepository
    ) {
        $this->orderShippingDifferenceRepository = $orderShippingDifferenceRepository;
        $this->orderShippingDifferenceProductRepository = $orderShippingDifferenceProductRepository;
        $this->wdtRepository = $wdtRepository;
        $this->shippingLogRepository = $shippingLogRepository;
    }

    /** List do klienta w jego języku ; {name}, {amount}, {doc} podstawiane. */
    private const LETTER = [
        'fr' => [
            'hello' => 'Bonjour {name},',
            'intro' => ["Nous avons récemment pris connaissance des écarts entre la commande que nous avons reçue et la facture correspondante.",
                "Nous comprenons que des erreurs peuvent survenir, mais nous tenons à régulariser la situation.",
                "Voici un récapitulatif des différences entre la commande et la facture :"],
            'outro' => ["Nous sommes conscients de l'importance de maintenir des transactions transparentes et de la satisfaction de nos clients.",
                "Nous vous remercions de votre compréhension et de votre coopération."],
            'refund' => "Nous vous remboursons le montant suivant : {amount}, pour que le montant payé en acompte soit adéquat avec le montant de la facture {doc}.",
            'pay' => "Nous vous demandons de régler la différence de {amount} avec la mention « Facture : {doc} ».",
            'bye' => 'Cordialement,',
        ],
        'nl' => [
            'hello' => 'Beste {name},',
            'intro' => ["Wij hebben onlangs verschillen vastgesteld tussen de bestelling die wij ontvingen en de bijbehorende factuur.",
                "Wij begrijpen dat er fouten kunnen gebeuren, maar wij willen de situatie graag rechtzetten.",
                "Hieronder vindt u een overzicht van de verschillen tussen de bestelling en de factuur:"],
            'outro' => ["Transparante transacties en de tevredenheid van onze klanten zijn voor ons zeer belangrijk.",
                "Wij danken u voor uw begrip en uw medewerking."],
            'refund' => "Wij betalen u het volgende bedrag terug: {amount}, zodat het betaalde voorschot overeenkomt met het bedrag van factuur {doc}.",
            'pay' => "Wij vragen u het verschil van {amount} te betalen met de vermelding « Factuur: {doc} ».",
            'bye' => 'Met vriendelijke groeten,',
        ],
        'pl' => [
            'hello' => 'Dzień dobry {name},',
            'intro' => ["Stwierdziliśmy różnice między otrzymanym zamówieniem a odpowiadającą mu fakturą.",
                "Rozumiemy, że błędy mogą się zdarzyć, i chcemy uregulować tę sytuację.",
                "Poniżej zestawienie różnic między zamówieniem a fakturą:"],
            'outro' => ["Przejrzystość transakcji i zadowolenie naszych klientów są dla nas bardzo ważne.",
                "Dziękujemy za zrozumienie i współpracę."],
            'refund' => "Zwracamy Państwu kwotę {amount}, aby wpłacona zaliczka odpowiadała kwocie faktury {doc}.",
            'pay' => "Prosimy o dopłatę różnicy {amount} z tytułem przelewu « Faktura: {doc} ».",
            'bye' => 'Z poważaniem,',
        ],
        'en' => [
            'hello' => 'Dear {name},',
            'intro' => ["We have recently noticed differences between the order we received and the corresponding invoice.",
                "We understand that mistakes can happen, and we would like to settle the situation.",
                "Here is a summary of the differences between the order and the invoice:"],
            'outro' => ["Transparent transactions and the satisfaction of our customers matter to us.",
                "Thank you for your understanding and cooperation."],
            'refund' => "We are refunding the following amount: {amount}, so that the prepayment matches the amount of invoice {doc}.",
            'pay' => "Please pay the difference of {amount} with the reference « Invoice: {doc} ».",
            'bye' => 'Kind regards,',
        ],
    ];

    public function renderDocument(TCPDFService $pdf, array $data): TCPDFService
    {
        $wdt = $data['wdtData'];
        $lang = DocumentLabels::lang($data['lang'] ?? 'fr', 'fr');
        $text = self::LETTER[$lang];
        $T = fn(string $key) => DocumentLabels::get($key, $lang);
        $fill = fn(string $t, array $v) => strtr($t, $v);

        $pdf->brandPage($T('doc_difference'), ['ref' => $wdt['doc_num'], 'lang' => $lang, 'company' => DocumentLabels::companyLine($wdt)]);
        $pdf->brandHeader($T('doc_difference'), [
            [$T('doc_invoice'), $wdt['doc_num']],
            [$T('date'), $data['differenceData']['established_date'] ?? $wdt['invoiced_date']],
        ]);
        $pdf->brandParties([
            'label' => $T('consignee'),
            'name' => $wdt['consignee_name'],
            'lines' => [$wdt['consignee_address_1'], $wdt['consignee_address_2'], 'NIP/TVA: ' . $wdt['consignee_nip']],
        ]);

        $pdf->brandText($fill($text['hello'], ['{name}' => $wdt['consignee_name']]), ['style' => 'B', 'after' => 3]);
        foreach ($text['intro'] as $paragraph) $pdf->brandText($paragraph);

        $columns = [
            ['label' => $T('code'), 'w' => 11],
            ['label' => $T('product'), 'w' => 40],
            ['label' => $T('quantity'), 'sub' => $T('ordered'), 'w' => 10, 'align' => 'R'],
            ['label' => $T('price'), 'sub' => $T('ordered'), 'w' => 11, 'align' => 'R'],
            ['label' => $T('quantity'), 'sub' => $T('invoiced'), 'w' => 10, 'align' => 'R'],
            ['label' => $T('price'), 'sub' => $T('invoiced'), 'w' => 11, 'align' => 'R'],
            ['label' => $T('difference'), 'w' => 13, 'align' => 'R'],
        ];
        $summary = self::differenceSummary($data['diffProductData']);
        $cents = fn(int $c) => DocumentLabels::money($c / 100);
        $rows = array_map(fn($r) => [$r['id_product'], $r['product_name'], DocumentLabels::number($r['quantity_ordered']), DocumentLabels::money($r['price_during_ordering']),
            DocumentLabels::number($r['invoiced_quantity']), DocumentLabels::money($r['price_during_invoicing']), $cents($r['difference_cents'])], $summary['rows']);
        $rows[] = ['_total' => ['', $T('total'), '', $cents($summary['ordered_cents']), '', $cents($summary['invoiced_cents']), $cents($summary['difference_cents'])]];
        $pdf->brandTable($columns, $rows);

        foreach ($text['outro'] as $paragraph) $pdf->brandText($paragraph);
        $amount = $cents(abs($summary['difference_cents']));
        $pdf->brandCallout($fill($summary['difference_cents'] < 0 ? $text['refund'] : $text['pay'], ['{amount}' => $amount, '{doc}' => $wdt['doc_num']]));

        $pdf->brandText($text['bye'], ['after' => 1]);
        $pdf->brandText($wdt['consignor_name'], ['style' => 'B']);

        return $pdf;
    }

    /**
     * Wiersze z różnicą (bez pozycji zgodnych) i sumy w groszach : różnica = zafakturowano − zamówiono.
     * @return array{rows: array, ordered_cents: int, invoiced_cents: int, difference_cents: int}
     */
    public static function differenceSummary(array $products): array
    {
        $rows = [];
        $ordered = 0;
        $invoiced = 0;
        $difference = 0;
        foreach ($products as $item) {
            $orderCents = array_key_exists('ordered_total_amount', $item) && $item['ordered_total_amount'] !== null
                ? DifferenceAmountCalculator::toCents($item['ordered_total_amount'])
                : DifferenceAmountCalculator::toCents($item['price_during_ordering']) * (int) $item['quantity_ordered'];
            $invoiceCents = array_key_exists('invoiced_total_amount', $item) && $item['invoiced_total_amount'] !== null
                ? DifferenceAmountCalculator::toCents($item['invoiced_total_amount'])
                : DifferenceAmountCalculator::toCents($item['price_during_invoicing']) * (int) $item['invoiced_quantity'];
            $diff = $invoiceCents - $orderCents;
            $difference += $diff;
            if ($diff === 0) continue;
            $ordered += $orderCents;
            $invoiced += $invoiceCents;
            $rows[] = $item + ['difference_cents' => $diff];
        }
        return ['rows' => $rows, 'ordered_cents' => $ordered, 'invoiced_cents' => $invoiced, 'difference_cents' => $difference];
    }

    public function buildDocumentFromId(TCPDFService $pdf, int $id): array
    {
        $differenceData = $this->orderShippingDifferenceRepository->first(['id' => $id]);
        if (!$differenceData) {
            throw new CustomException("DIFFERENCE_DATA_NOT_FOUND", 'errors', 404);
        }

        $diffProductData = $this->orderShippingDifferenceProductRepository->getAllByDifferenceId($id);

        $log = $this->shippingLogRepository->first(['id_diff' => $id]);
        if (!$log) {
            throw new CustomException("SHIPPING_LOG_NOT_FOUND", 'errors', 404);
        }

        $wdtData = $this->wdtRepository->first(['id' => $log['id_wdt']]);
        if (!$wdtData) {
            throw new CustomException("WDT_DATA_NOT_FOUND", 'errors', 404);
        }


        $this->renderDocument($pdf, [
            'differenceData' => $differenceData,
            'diffProductData' => $diffProductData,
            'wdtData' => $wdtData,
            'lang' => $this->clientRepository->communicationLang((int) $wdtData['id_customer'])
        ]);

        return ['file_string' => $pdf, "filename" => 'ROZNICE_' . $wdtData['doc_num'] . '.pdf'];
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

    public function getProductsByIdDifference($id)
    {
        return $this->orderShippingDifferenceProductRepository->getAllByDifferenceId($id);
    }

    public function updateProduct($id, $idProduct, $data)
    {
        return $this->orderShippingDifferenceProductRepository->update($id, $idProduct, $data);
    }
}
