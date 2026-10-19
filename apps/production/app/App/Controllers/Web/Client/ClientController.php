<?php

namespace App\Controllers\Web\Client;

use App\Core\Web\BaseController;
use App\Services\Client\ClientSalesSummaryService;
use App\Services\Client\ClientService;
use App\Services\Client\FranchiseNetwork\IntegrationOnboardingTokenService;
use App\Services\Client\Integration\ClientIntegrationService;
use App\Services\Finances\ProfitabilityService;
use App\Services\Utils\CountryService;
use DateInterval;
use DateTime;
use Symfony\Component\HttpFoundation\Request;

class ClientController extends BaseController
{
    private ClientService $clientService;
    private CountryService $countryService;
    private ProfitabilityService $profitabilityService;
    private IntegrationOnboardingTokenService $integrationOnboardingTokenService;
    private ClientIntegrationService $clientIntegrationService;
    private ClientSalesSummaryService $clientSalesSummaryService;

    public function __construct(
        ClientService        $clientService,
        CountryService       $countryService,
        ProfitabilityService $profitabilityService,
        IntegrationOnboardingTokenService $integrationOnboardingTokenService,
        ClientIntegrationService $clientIntegrationService,
        ClientSalesSummaryService $clientSalesSummaryService
    )
    {
        $this->clientService = $clientService;
        $this->countryService = $countryService;
        $this->profitabilityService = $profitabilityService;
        $this->integrationOnboardingTokenService = $integrationOnboardingTokenService;
        $this->clientIntegrationService = $clientIntegrationService;
        $this->clientSalesSummaryService = $clientSalesSummaryService;
    }

    public function overview()
    {
        $type = $_GET['type'] ?? ''; // jeśli nie ma, to pusty string

        if ($type === 'inactive') {
            $data['table_title'] = "Nieaktywni klienci";
            $data['rows'] = $this->clientService->getInactive();
        } else if ($type === 'franchisee') {
            $data['table_title'] = "Franczyzobiorcy";
            $data['rows'] = $this->clientService->getFranchisee();
        } else if ($type === 'active') {
            $data['table_title'] = "Aktywni klienci";
            $data['rows'] = $this->clientService->getActive();
        } else {
            $data['table_title'] = "Wszyscy klienci";
            $data['rows'] = $this->clientService->getAll();
        }

        $this->view("client/client/overview", $data);
    }

    public function newClient()
    {
        $data['countries'] = $this->countryService->getAll();
        $data['rows'] = $this->clientService->getAll();

        if ($_SERVER['REQUEST_METHOD'] == "POST") {
            $this->errors = $this->clientService->validate($_POST);

            if (empty($this->errors)) {
                $this->handleAction(function () {
                    $this->clientService->insert($_POST);
                    header('Location: ' . ROOT . '/clients');
                    exit;
                }, default: false);
            }
        }
        $this->view("client/client/new", $data);
    }

    public function editClient($id)
    {
        $data['row'] = $this->clientService->getById($id);
        $data['countries'] = $this->countryService->getAll();

        if ($_SERVER['REQUEST_METHOD'] == "POST") {
            $newData = $_POST;

            $this->errors = $this->clientService->validate($newData);

            if (empty($this->errors)) {
                $this->handleAction(function () use ($id, $newData, $data) {

                    //generate franchisee code if type is franchisee and code is null
                    if ($data['row']['type'] == 1 && is_null($data['row']['franchisee_code'])) {
                        $newData['franchisee_code'] = $this->clientService->generateFranchiseeCode($data['row']['company']);
                    }

                    $this->clientService->update($id, $newData);
                    header('Location: ' . ROOT . '/clients');
                    exit;
                }, default: false);
            }
        }
        $this->view("client/client/edit", $data);
    }

    public function deleteClient($id)
    {
        $data['row'] = $this->clientService->getById($id);

        if ($_SERVER['REQUEST_METHOD'] == "POST") {
            $this->handleAction(function () use ($id) {
                $this->clientService->delete($id);
                header('Location: ' . ROOT . '/clients');
                exit;
            }, default: false);
        }

        $this->view("client/client/delete", $data);
    }

    public function ajaxUpdateClient($id)
    {
        $request = Request::createFromGlobals();
        $data = $this->getJson($request);

        if (empty($data)) {
            return $this->json(['message' => 'Brak danych wejściowych'], 400);
        }

        $result = $this->clientService->update($id, $data);

        return $this->handleAjaxResponse($result, 'Klient zaktualizowany', 'Nie udało się zaktualizować klienta');
    }

    public function ajaxViesLookup()
    {
        $request = Request::createFromGlobals();
        $data = $this->getJson($request);
        $vat = is_string($data['vat'] ?? null) ? $data['vat'] : '';

        if (trim($vat) === '') {
            return $this->json(['ok' => false, 'message' => 'Wprowadź numer VAT.', 'code' => 422], 422);
        }

        $result = $this->clientService->lookupVies($vat);
        return $this->json($result, $result['code']);
    }

    public function profitability($id)
    {
        $currentDate = new DateTime();
        $lastMonth = $currentDate->sub(new DateInterval('P1M'));
        $data['last_month_year'] = $lastMonth->format('m-Y');

        $data['customer'] = $this->clientService->getById($id);

        $products = $this->profitabilityService->getSoldProductListByCustomerInLastMonth($id);
        $data['products_grouped_by_category'] = [];

        foreach ($products as $product) {
            if (!isset($data['products_grouped_by_category'][$product['id_category']])) {
                $data['products_grouped_by_category'][$product['id_category']]['id'] = $product['id_category'];
                $data['products_grouped_by_category'][$product['id_category']]['name'] = $product['category_name'];
                $data['products_grouped_by_category'][$product['id_category']]['total_earn'] = 0;
                $data['products_grouped_by_category'][$product['id_category']]['total_cost'] = 0;
                $data['products_grouped_by_category'][$product['id_category']]['products'] = [];
            }

            array_push($data['products_grouped_by_category'][$product['id_category']]['products'], $product);

            $data['products_grouped_by_category'][$product['id_category']]['total_earn'] += $product['earned_value_pln'];
            $data['products_grouped_by_category'][$product['id_category']]['total_cost'] += $product['total_raw_cost'];
        }

        $this->view("client/client/profitability", $data);
    }

    public function ajaxGenerateIntegrationInvitationCodeForFranchiseClient($id)
    {
        $resp = $this->integrationOnboardingTokenService->getForClient($id);

        if (!$resp) {
            return $this->json(['message' => 'Brak danych dla tej wartości'], 404);
        }

        return $this->json(['token' => $resp]);
    }

    public function ajaxActivateIntegration($id)
    {
        $request = Request::createFromGlobals();
        $data = $this->getJson($request);

        if (empty($data)) {
            return $this->json(['message' => 'Brak danych wejściowych'], 400);
        }

        $resp = $this->handleAction(function () use ($id, $data) {
            return $this->clientIntegrationService->activateIntegration($id, $data['link']);
        }, default: false);

        return $this->json($resp, $resp['code']);
    }

    public function salesSummary($id)
    {
        $data['customer'] = $this->clientService->getById($id);

        $defaultDateTo   = date('Y-m-d');
        $defaultDateFrom = date('Y-m-d', strtotime('-3 months'));

        $data['date_from'] = $_GET['date_from'] ?? $defaultDateFrom;
        $data['date_to']   = $_GET['date_to']   ?? $defaultDateTo;
        $data['sort']      = $this->clientSalesSummaryService->normalizeSort($_GET['sort'] ?? null);

        $this->view('client/client/sales_summary', $data);
    }

    public function ajaxSalesSummary($id)
    {
        $dateFrom = $_GET['date_from'] ?? date('Y-m-d', strtotime('-3 months'));
        $dateTo   = $_GET['date_to']   ?? date('Y-m-d');
        $sort     = $this->clientSalesSummaryService->normalizeSort($_GET['sort'] ?? null);

        $summary = $this->clientSalesSummaryService->getSummary((int)$id, $dateFrom, $dateTo, $sort);

        return $this->json(['ok' => true, 'data' => $summary]);
    }

    public function exportSalesSummaryPdf($id)
    {
        $dateFrom = $_GET['date_from'] ?? date('Y-m-d', strtotime('-3 months'));
        $dateTo   = $_GET['date_to']   ?? date('Y-m-d');
        $sort     = $this->clientSalesSummaryService->normalizeSort($_GET['sort'] ?? null);

        $export = $this->clientSalesSummaryService->buildPdf((int)$id, $dateFrom, $dateTo, $sort);

        return new \Symfony\Component\HttpFoundation\Response(
            $export['content'],
            200,
            [
                'Content-Type'        => 'application/pdf',
                'Content-Disposition' => 'attachment; filename="' . $export['filename'] . '"',
                'Content-Length'      => strlen($export['content']),
            ]
        );
    }

    public function exportSalesSummaryXls($id)
    {
        $dateFrom = $_GET['date_from'] ?? date('Y-m-d', strtotime('-3 months'));
        $dateTo   = $_GET['date_to']   ?? date('Y-m-d');
        $sort     = $this->clientSalesSummaryService->normalizeSort($_GET['sort'] ?? null);

        $export = $this->clientSalesSummaryService->buildCsv((int)$id, $dateFrom, $dateTo, $sort);

        return new \Symfony\Component\HttpFoundation\Response(
            $export['content'],
            200,
            [
                'Content-Type'        => 'text/csv; charset=UTF-8',
                'Content-Disposition' => 'attachment; filename="' . $export['filename'] . '"',
                'Content-Length'      => strlen($export['content']),
            ]
        );
    }

}

