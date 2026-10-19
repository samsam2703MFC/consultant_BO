<?php

namespace App\Controllers\Web\Product\Price;

use App\Core\Web\BaseController;
use App\Services\Client\ClientService;
use App\Services\Finances\ClientProductPriceService;
use App\Services\Product\Price\ClientPriceListService;
use App\Services\Product\Price\GeneralPriceListPdfService;
use App\Services\Product\ProductService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class PriceListController extends BaseController
{
    private ClientPriceListService $clientPriceListService;
    private ClientProductPriceService $clientProductPriceService;
    private CLientService $clientService;
    private ProductService $productService;
    private GeneralPriceListPdfService $generalPriceListPdfService;

    public function __construct(
        ClientPriceListService     $clientPriceListService,
        ClientService              $clientService,
        ClientProductPriceService  $clientProductPriceService,
        ProductService             $productService,
        GeneralPriceListPdfService $generalPriceListPdfService
    )
    {
        $this->clientPriceListService     = $clientPriceListService;
        $this->clientService              = $clientService;
        $this->clientProductPriceService  = $clientProductPriceService;
        $this->productService             = $productService;
        $this->generalPriceListPdfService = $generalPriceListPdfService;
    }

    public function clientOverview()
    {
        $data['rows'] = $this->clientService->getActive();

        $this->view("product/price/client_overview", $data);
    }

    public function clientPriceList($id)
    {
        $data['id_customer'] = $id;
        $data['rows'] = $this->clientProductPriceService->selectProductPriceArrByCustomer($id);
        $data['client'] = $this->clientService->getById($id);

        $this->view("product/price/client_price_list", $data);
    }

    public function generalPriceList()
    {
        $data['rows'] = $this->productService->getAll();

        $this->view("product/price/general_price_list", $data);

    }

    public function raiseAllForClientsPricePanel($idClient)
    {
        $data['id_customer'] = $idClient;

        if ($_SERVER['REQUEST_METHOD'] == "POST") {
            $this->handleAction(function () use ($idClient) {
                $this->clientProductPriceService->increatePriceByPercentage($idClient, $_POST['percentage']);
                header('Location: ' . ROOT . "/clients/{$idClient}/price-list");
                exit;
            }, default: false);
        }

        $this->view("product/price/groupRaising", $data);
    }

    public function ajaxGetPriceListByClientId($id)
    {
        $resp = $this->handleAction(function () use ($id) {
            return $this->clientPriceListService->generateDocumentPDF($id);
        }, default: false);

        if (!$resp || !is_array($resp)) {
            return $this->json(['message' => 'Wystąpił błąd przy generowaniu listy produkcyjnej'], 500);
        }

        $pdfData = $resp['file_string'];
        $filename = htmlspecialchars($resp['filename']);

        return new Response(
            $pdfData,
            200,
            [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => "attachment; filename={$filename}",
                'Content-Length' => strlen($pdfData),
            ]
        );
    }

    public function ajaxUpdateProductPriceForClient($idClient, $idProduct)
    {
        $request = Request::createFromGlobals();
        $data = $this->getJson($request);

        if (empty($data)) {
            return $this->json(['message' => 'Brak danych wejściowych'], 400);
        }

        $success = $this->handleAction(function () use ($idClient, $idProduct, $data) {
            $this->clientProductPriceService->updateForClient($idClient, $idProduct, $data);
            return true;
        }, default: false);

        return $this->handleAjaxResponse($success, 'Aktualizacja powiodła się', 'Nie udało się zaktualizować');
    }

    public function ajaxGetProductPriceByClientId($idClient, $idProduct)
    {
        $price = $this->handleAction(function () use ($idClient, $idProduct) {
            return $this->clientProductPriceService->getByClientAndProduct($idClient, $idProduct);
        }, default: false);

        if ($price === false) {
            return $this->json(['message' => 'Nie udało się pobrać ceny'], 500);
        }

        return $this->json($price);
    }


    public function ajaxGetPriceListByClientCSV($id)
    {
        $respDoc = $this->handleAction(function () use ($id) {
            return $this->clientProductPriceService->getByClientAndProductCSV($id);
        }, default: false);

        if (!$respDoc) {
            return $this->json($respDoc, $respDoc['code']);
        }

        $pdfData = $respDoc['file_string'];
        $filename = htmlspecialchars($respDoc['filename']);

        return new Response(
            $pdfData,
            200,
            [
                'Content-Type' => 'text/csv',
                'Content-Disposition' => "attachment; filename={$filename}",
                'Content-Length' => strlen($pdfData),
            ]
        );
    }

    /**
     * AJAX endpoint: Get price list in JSON format
     * GET /ajax/clients/{id}/price-list/json
     */
    public function ajaxGetPriceListByClientJSON($id)
    {
        try {
            $jsonData = $this->handleAction(function () use ($id) {
                return $this->clientProductPriceService->getByClientAndProductJSON($id);
            }, default: false);

            if ($jsonData === false) {
                return $this->json(['error' => 'Nie udało się pobrać cennika'], 500);
            }

            // Nazwa pliku
            $client = $jsonData['client'];
            $safeCompany = preg_replace('/[^a-zA-Z0-9_-]/', '_', $client['company']);
            $filename = "price_list_{$safeCompany}_" . date('Ymd_His') . ".json";

            // Zwróć JSON z nagłówkami do pobrania
            return new Response(
                json_encode($jsonData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
                200,
                [
                    'Content-Type' => 'application/json',
                    'Content-Disposition' => "attachment; filename={$filename}",
                ]
            );
        } catch (\Exception $e) {
            return $this->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * AJAX endpoint: Eksport cennika ogólnego do PDF
     * GET /ajax/products/price-list/pdf?include_inactive=0&include_zero_price=0
     */
    public function ajaxExportGeneralPriceListPDF()
    {
        $request = Request::createFromGlobals();

        $includeInactive  = (bool)(int)($request->query->get('include_inactive',  0));
        $includeZeroPrice = (bool)(int)($request->query->get('include_zero_price', 0));

        try {
            $products = $this->productService->getFiltered($includeInactive, $includeZeroPrice);
            $result   = $this->generalPriceListPdfService->generate($products);

            return new Response(
                $result['file_string'],
                200,
                [
                    'Content-Type'        => 'application/pdf',
                    'Content-Disposition' => 'attachment; filename=' . $result['filename'],
                    'Content-Length'      => strlen($result['file_string']),
                    'Cache-Control'       => 'no-cache',
                ]
            );

        } catch (\Exception $e) {
            return new Response(
                json_encode(['message' => $e->getMessage()]),
                500,
                ['Content-Type' => 'application/json']
            );
        }
    }

    /**
     * AJAX endpoint: Eksport cennika ogólnego do JSON
     * GET /ajax/products/price-list/json?include_inactive=0&include_zero_price=0
     *
     * Format zgodny z formatem importu (bez sekcji "client"):
     * {
     *   "meta": { "generated_at": "...", "currency": "EUR", "products_count": N },
     *   "products": [
     *     { "sku": 1001, "product_name": "...", "gross_price": 0.00, "net_price": 0.00,
     *       "pack_quantity_in_base_unit": 8, "is_available": 1 },
     *     ...
     *   ]
     * }
     */
    public function ajaxExportGeneralPriceListJSON()
    {
        $request = Request::createFromGlobals();

        $includeInactive  = (bool)(int)($request->query->get('include_inactive',  0));
        $includeZeroPrice = (bool)(int)($request->query->get('include_zero_price', 0));

        try {
            $products = $this->productService->getFiltered($includeInactive, $includeZeroPrice);

            $productList = [];
            foreach ($products as $product) {
                $price = round((float)($product['standard_price'] ?? 0), 2);
                $productList[] = [
                    'sku'                        => (int)$product['id'],
                    'product_name'               => $product['name'] ?? '',
                    'gross_price'                => $price,
                    'net_price'                  => $price,
                    'pack_quantity_in_base_unit' => (int)($product['ppc'] ?? 1),
                    'is_available'               => (int)($product['active'] ?? 1),
                ];
            }

            $payload = [
                'meta'     => [
                    'generated_at'   => date('Y-m-d H:i:s'),
                    'currency'       => 'EUR',
                    'products_count' => count($productList),
                ],
                'products' => $productList,
            ];

            $filename = 'cennik_ogolny_' . date('Y-m-d') . '.json';

            return new Response(
                json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
                200,
                [
                    'Content-Type'        => 'application/json; charset=UTF-8',
                    'Content-Disposition' => "attachment; filename={$filename}",
                    'Cache-Control'       => 'no-cache',
                ]
            );

        } catch (\Exception $e) {
            return new Response(
                json_encode(['message' => $e->getMessage()]),
                500,
                ['Content-Type' => 'application/json']
            );
        }
    }

    /**
     * AJAX endpoint: Eksport cennika ogólnego do CSV
     * GET /ajax/products/price-list/csv?include_inactive=0&include_zero_price=0
     */
    public function ajaxExportGeneralPriceListCSV()
    {
        $request = Request::createFromGlobals();

        $includeInactive  = (bool)(int)($request->query->get('include_inactive',  0));
        $includeZeroPrice = (bool)(int)($request->query->get('include_zero_price', 0));

        try {
            $products = $this->productService->getFiltered($includeInactive, $includeZeroPrice);

            // Buduj CSV w pamięci
            $handle = fopen('php://temp', 'r+');

            // BOM UTF-8 dla poprawnego otwarcia w Excelu
            fputs($handle, "\xEF\xBB\xBF");

            // Nagłówek
            fputcsv($handle, ['ID', 'Nazwa produktu', 'Kategoria', 'Sekcja', 'Aktywny', 'Cena standardowa (EUR)'], ';');

            foreach ($products as $product) {
                fputcsv($handle, [
                    $product['id'],
                    $product['name'],
                    $product['category_name'] ?? '',
                    $product['section_name']  ?? '',
                    (int)($product['active'] ?? 1) ? 'Tak' : 'Nie',
                    number_format((float)($product['standard_price'] ?? 0), 2, '.', ''),
                ], ';');
            }

            rewind($handle);
            $csvContent = stream_get_contents($handle);
            fclose($handle);

            $filename = 'cennik_ogolny_' . date('Y-m-d') . '.csv';

            return new Response(
                $csvContent,
                200,
                [
                    'Content-Type'        => 'text/csv; charset=UTF-8',
                    'Content-Disposition' => "attachment; filename={$filename}",
                    'Content-Length'      => strlen($csvContent),
                    'Cache-Control'       => 'no-cache',
                ]
            );

        } catch (\Exception $e) {
            return new Response(
                json_encode(['message' => $e->getMessage()]),
                500,
                ['Content-Type' => 'application/json']
            );
        }
    }

    public function ajaxChangeAvailabilityStatusForClient($idClient, $idProduct)
    {

        $request = Request::createFromGlobals();
        $data = $this->getJson($request);

        if (empty($data)) {
            return $this->json(['message' => 'Brak danych wejściowych'], 400);
        }

        $success = $this->handleAction(function () use ($idClient, $idProduct, $data) {
            $this->clientProductPriceService->updateAvailable($idClient, $idProduct, $data);
            return true;
        }, default: false);

        return $this->handleAjaxResponse($success, 'Aktualizacja powiodła się', 'Nie udało się zaktualizować');
    }
}