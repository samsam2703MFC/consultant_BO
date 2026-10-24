<?php
namespace App\Controllers\Web\Client\FranchiseNetwork;

use App\Core\Web\BaseController;
use App\Services\Client\ClientService;
use App\Services\Client\FranchiseNetwork\FranchiseNetworkService;
use Symfony\Component\HttpFoundation\Request;

class FranchiseNetworkController extends BaseController
{
    private FranchiseNetworkService $franchiseNetworkService;
    private ClientService $clientService;

    public function __construct(
        FranchiseNetworkService $franchiseNetworkService,
        ClientService $clientService
    )
    {
        $this->franchiseNetworkService = $franchiseNetworkService;
        $this->clientService = $clientService;
    }

    public function newNetwork()
    {
        if ($_SERVER['REQUEST_METHOD'] == "POST") {
            $this->errors = $this->franchiseNetworkService->validate($_POST);

            if (empty($this->errors)) {
                $this->handleAction(function () {
                    $this->franchiseNetworkService->insert($_POST);
                    header('Location: ' . ROOT . '/client-franchise-networks');
                    exit;
                }, default: false);
            }
        }
        $this->view("client/franchise_network/new", []);
    }

    public function editNetwork($id)
    {
        $data['row'] = $this->franchiseNetworkService->getById($id);

        if ($_SERVER['REQUEST_METHOD'] == "POST") {
            $newData = $_POST;

            $this->errors = $this->franchiseNetworkService->validate($newData);

            if (empty($this->errors)) {
                $this->handleAction(function () use ($id, $newData) {
                    $this->franchiseNetworkService->update($id, $newData);
                    header('Location: ' . ROOT . '/client-franchise-networks');
                    exit;
                }, default: false);
            }
        }
        $this->view("client/franchise_network/edit", $data);
    }

    public function deleteNetwork($id)
    {
        $data['row'] = $this->franchiseNetworkService->getById($id);

        if ($_SERVER['REQUEST_METHOD'] == "POST") {
            $this->handleAction(function () use ($id) {
                $this->franchiseNetworkService->delete($id);
                header('Location: ' . ROOT . '/client-franchise-networks');
                exit;
            }, default: false);
        }

        $this->view("client/franchise_network/delete", $data);
    }

    public function overview()
    {
        $data['networks'] = $this->franchiseNetworkService->getAll();
        $this->view("client/franchise_network/overview", $data);
    }

    public function overviewFranchises($id)
    {
        $data['network'] = $this->franchiseNetworkService->getById($id);
        $data['franchises'] = $this->franchiseNetworkService->getFranchises($id);
        $data['clients'] = $this->clientService->getAll();

        $this->view("client/franchise_network/franchises", $data);
    }

    public function ajaxAssignClientToNetwork($idNetwork)
    {
        $request = Request::createFromGlobals();
        $data = $this->getJson($request);

        if (empty($data)) {
            return $this->json(['message' => 'Brak danych wejściowych'], 400);
        }

        $result = $this->clientService->update($data['client_id'], ['franchise_network_id' => $idNetwork]);

        return $this->handleAjaxResponse($result, 'Zamówienie zaktualizowane', 'Nie udało się zaktualizować zamówienia');

    }

    public function ajaxDeatachClient($idNetwork, $idClient)
    {

        $result = $this->clientService->update($idClient, ['franchise_network_id' => null]);
        return $this->handleAjaxResponse($result, 'Zamówienie zaktualizowane', 'Nie udało się zaktualizować zamówienia');

    }
}