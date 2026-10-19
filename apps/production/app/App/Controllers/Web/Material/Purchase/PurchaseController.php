<?php
namespace App\Controllers\Web\Material\Purchase;



use App\Core\Web\BaseController;
use App\Services\Material\MaterialService;
use App\Services\Material\Purchase\PurchaseItemService;
use App\Services\Material\Purchase\PurchaseService;
use App\Services\Supplier\MaterialSupplierService;
use Symfony\Component\HttpFoundation\Request;

class PurchaseController extends BaseController
{

    public function __construct(
        private MaterialSupplierService $supplierService,
        private MaterialService $materialService,
        private PurchaseService $purchaseService,
        private PurchaseItemService $purchaseItemService

    )
    {
    }

    public function index()
    {
        // --- filtry z GET
        $supplierId = isset($_GET['supplier_id']) && $_GET['supplier_id'] !== ''
            ? (int)$_GET['supplier_id']
            : null;

        $status = isset($_GET['status']) && $_GET['status'] !== ''
            ? strtoupper(trim((string)$_GET['status']))
            : null;

        // whitelist statusów żeby nie wpuszczać śmieci
        $allowedStatuses = ['DRAFT', 'POSTED', 'CANCELED'];
        if ($status !== null && !in_array($status, $allowedStatuses, true)) {
            $status = null;
        }

        $dateFrom = isset($_GET['date_from']) && $_GET['date_from'] !== ''
            ? (string)$_GET['date_from']
            : null;

        $dateTo = isset($_GET['date_to']) && $_GET['date_to'] !== ''
            ? (string)$_GET['date_to']
            : null;

        // prosta walidacja formatu YYYY-MM-DD (MVP)
        $dateRegex = '/^\d{4}-\d{2}-\d{2}$/';
        if ($dateFrom !== null && !preg_match($dateRegex, $dateFrom)) $dateFrom = null;
        if ($dateTo !== null && !preg_match($dateRegex, $dateTo)) $dateTo = null;

        $q = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
        if ($q === '') $q = null;

        $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
        if ($page < 1) $page = 1;

        $perPage = 20; // MVP stałe, możesz później zrobić w configu

        $filters = [
            'supplier_id' => $supplierId,
            'status'      => $status,
            'date_from'   => $dateFrom,
            'date_to'     => $dateTo,
            'q'           => $q,
        ];

        // --- dane do widoku
        $data = [];
        $data['suppliers'] = $this->supplierService->getActive();
        $data['filters']   = $filters;

        // --- pobranie listy + total pod paginację
        $result = $this->purchaseService->search($filters, $page, $perPage);

        $rows  = $result['rows'] ?? [];
        $total = (int)($result['total'] ?? 0);

        $data['rows'] = $rows;

        $hasPrev = $page > 1;
        $hasNext = ($page * $perPage) < $total;

        $data['pagination'] = [
            'page'      => $page,
            'per_page'  => $perPage,
            'total'     => $total,
            'has_prev'  => $hasPrev,
            'has_next'  => $hasNext,
        ];

        $this->view("material/purchase/index", $data);
    }

    public function insert()
    {
        $data['suppliers'] = $this->supplierService->getActive();
        $data['raw_materials'] = $this->materialService->getAll();



        $this->view("material/purchase/insert", $data);
    }

    public function show($id)
    {
        $data['doc'] = $this->purchaseService->getById($id);
        $data['items'] = $this->purchaseItemService->getByDocumentId($id);

        $this->view("material/purchase/show", $data);
    }

    public function ajaxInsert()
    {
        $request = Request::createFromGlobals();
        $data = $this->getJson($request);

        $result = $this->purchaseService->insert($data);

        return $this->json($result, $result ? 200 : 500);
    }
}