<?php
namespace App\Controllers\Web\Material\Supplier;

use App\Core\Route;
use App\Core\Web\BaseController;
use App\Services\Supplier\MaterialSupplierService;

class MaterialSupplierController extends BaseController
{

    private MaterialSupplierService $supplierService;

    public function __construct(
        MaterialSupplierService $supplierService
    )
    {
        $this->supplierService = $supplierService;
    }

    #[Route('GET', '/benchmarks/competitors')]
    public function overview()
    {
        $data['rows'] = $this->supplierService->getActive();

        $this->view("supplier/material/overview", $data);
    }

    /** Nowy dostawca : po zapisie karta dostawcy (nie lista). */
    public function newSupplier()
    {
        if ($_SERVER['REQUEST_METHOD'] == "POST") {
            $this->errors = $this->supplierService->validate($_POST);

            if (empty($this->errors)) {
                $this->handleAction(function () {
                    $id = (int) $this->supplierService->insert($_POST);
                    header('Location: ' . ROOT . '/material-suppliers/' . $id . '/edit?created=1');
                    exit;
                }, default: false);
            }
        }
        $this->view("supplier/material/new");
    }

    /** POST /ajax/material-suppliers : nowy dostawca z karty surowca (okno), zwraca id i nazwę do listy. */
    public function ajaxCreate()
    {
        $data = $this->getJson(\Symfony\Component\HttpFoundation\Request::createFromGlobals());
        $errors = $this->supplierService->validate($data);
        if ($errors) return $this->json(['ok' => false, 'errors' => $errors, 'message' => implode(' ', $errors)], 422);
        $id = (int) $this->supplierService->insert($data + ['is_active' => 1]);
        if ($id <= 0) return $this->json(['ok' => false, 'message' => 'Nie udało się zapisać dostawcy.'], 500);
        return $this->json(['ok' => true, 'id' => $id, 'name' => trim((string) $data['name'])]);
    }

    public function editSupplier($id)
    {
        $data['row'] = $this->supplierService->getById($id);

        if ($_SERVER['REQUEST_METHOD'] == "POST") {
            $this->errors = $this->supplierService->validate($_POST);

            if (empty($this->errors)) {
                $this->handleAction(function () use ($id) {
                    $this->supplierService->update($id, $_POST);
                    header('Location: ' . ROOT . '/material-suppliers/' . (int) $id . '/edit?saved=1');
                    exit;
                }, default: false);
            }
        }
        $this->view("supplier/material/edit", $data);
    }

    public function deleteSupplier($id)
    {
        $data['row'] = $this->supplierService->getById($id);

        if ($_SERVER['REQUEST_METHOD'] == "POST") {
            $this->handleAction(function () use ($id) {
                $this->supplierService->delete($id);
                header('Location: ' . ROOT . '/material-suppliers');
                exit;
            }, default: false);
        }

        $this->view("supplier/material/delete", $data);
    }


}