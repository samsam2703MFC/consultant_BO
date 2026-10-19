<?php
namespace App\Controllers\Web\Production\Cost;

use App\Core\Web\BaseController;
use App\Services\Employee\ProductionEmployeeService;
use App\Services\Production\Cost\CostMonthService;
use App\Services\Production\Cost\CostCalculatorService;
use Symfony\Component\HttpFoundation\Request;

class CostCalculatorController extends BaseController
{
    public function __construct(
        private CostMonthService $monthService,
        private CostCalculatorService $calcService,
        private ProductionEmployeeService $employeeService
    ) {}

    public function index()
    {
        $nowY = (int)date('Y');
        $nowM = (int)date('m');

        $data = [];
        $data['current'] = ['year' => $nowY, 'month' => $nowM];
        $data['months']  = $this->monthService->listRecentMonths(24);

        $this->view("production/cost_calculator/index", $data);
    }

    public function show($ym)
    {
        // ym = "YYYY-MM"
        $ym = (string)$ym;
        if (!preg_match('/^\d{4}-\d{2}$/', $ym)) {
            header('Location: ' . ROOT . '/cost-calculator');
            exit;
        }

        $year = (int)substr($ym, 0, 4);
        $month = (int)substr($ym, 5, 2);

        $monthRow = $this->monthService->getOrCreate($year, $month);
        $this->monthService->ensureDefaultEmployees((int)$monthRow['id']);

        $bundle = $this->calcService->getMonthBundle((int)$monthRow['id']);
        $results = $this->calcService->calculateResults((int)$monthRow['id']);

        $data = $bundle;
        $data['results'] = $results;
        $data['fixed_cost_categories'] = $this->monthService->getFixedCostCategories();

// master lista do selecta (NIE nadpisuj $data['employees'])
        $data['employees_master'] = $this->employeeService->getAllWithSection();

        $this->view("production/cost_calculator/show", $data);
    }

    public function ajaxAddLogisticsCost($monthId)
    {
        $request = Request::createFromGlobals();
        $data    = $this->getJson($request);

        $monthId = (int)$monthId;
        $m = $this->monthService->getById($monthId);
        if (empty($m)) {
            return $this->json(['message' => 'MONTH_NOT_FOUND'], 404);
        }
        if (($m['status'] ?? 'OPEN') === 'LOCKED') {
            return $this->json(['message' => 'MONTH_LOCKED'], 409);
        }

        $logisticsTypeId = (int)($data['logistics_type_id'] ?? 0);
        if ($logisticsTypeId <= 0) {
            return $this->json(['message' => 'Missing logistics_type_id'], 400);
        }

        $amount = (float)($data['amount'] ?? 0);
        $note   = isset($data['note']) ? (string)$data['note'] : null;

        $id = $this->monthService->insertLogisticsCost($monthId, $logisticsTypeId, $amount, $note);
        return $this->json(['success' => (bool)$id, 'id' => $id], $id ? 200 : 500);
    }

    public function ajaxDeleteLogisticsCost($monthId, $id)
    {
        $monthId = (int)$monthId;
        $id      = (int)$id;

        $m = $this->monthService->getById($monthId);
        if (empty($m)) {
            return $this->json(['message' => 'MONTH_NOT_FOUND'], 404);
        }
        if (($m['status'] ?? 'OPEN') === 'LOCKED') {
            return $this->json(['message' => 'MONTH_LOCKED'], 409);
        }

        $ok = $this->monthService->deleteLogisticsCost($id);
        return $this->json(['success' => (bool)$ok], $ok ? 200 : 500);
    }

    public function ajaxCopyPrev($monthId)
    {
        $result = $this->monthService->copyFromPrevious((int)$monthId);
        return $this->json(['success' => (bool)$result], $result ? 200 : 500);
    }

    public function ajaxSyncCartons($monthId)
    {
        $monthId = (int)$monthId;
        $bundle = $this->calcService->getMonthBundle($monthId);
        $m = $bundle['month'];

        $auto = $this->calcService->getAutoCartonsFromOrders((int)$m['year'], (int)$m['month']);
        $ok = $this->monthService->syncAutoCartons($monthId, $auto);

        return $this->json(['success' => (bool)$ok, 'auto' => $auto], $ok ? 200 : 500);
    }

    public function ajaxSetManualCartons($monthId)
    {
        $request = Request::createFromGlobals();
        $data = $this->getJson($request);

        $sectionId = (int)($data['section_id'] ?? 0);
        $qty = (int)($data['cartons_qty'] ?? 0);

        $ok = $this->monthService->setManualCartons((int)$monthId, $sectionId, $qty);
        return $this->json(['success'=>(bool)$ok], $ok ? 200 : 500);
    }

    public function ajaxSetCartonSource($monthId)
    {
        $request   = Request::createFromGlobals();
        $data      = $this->getJson($request);
        $monthId   = (int)$monthId;
        $sectionId = (int)($data['section_id'] ?? 0);
        $source    = (string)($data['source'] ?? '');

        if ($sectionId <= 0 || $source === '') {
            return $this->json(['message' => 'Brak wymaganych parametrów (section_id, source).'], 400);
        }

        try {
            $ok = $this->monthService->setCartonSource($monthId, $sectionId, $source);
            return $this->json(['success' => (bool)$ok, 'source' => strtoupper($source)], $ok ? 200 : 500);
        } catch (\Throwable $e) {
            $code = $e->getMessage() === 'MONTH_LOCKED' ? 409 : 400;
            return $this->json(['message' => $e->getMessage()], $code);
        }
    }

    public function ajaxAddEmployee($monthId)
    {
        $request = Request::createFromGlobals();
        $data = $this->getJson($request);

        $employeeId = (int)($data['employee_id'] ?? 0);
        if ($employeeId <= 0) {
            return $this->json(['message' => 'Missing employee_id'], 400);
        }

        $monthlyCost = isset($data['monthly_cost']) ? (float)$data['monthly_cost'] : null;

        $id = $this->monthService->includeEmployeeFromMaster((int)$monthId, $employeeId, $monthlyCost);

        return $this->json(['success' => (bool)$id, 'id' => $id], $id ? 200 : 500);
    }

    public function ajaxDeleteEmployee($monthId, $id)
    {
        $ok = $this->monthService->deleteEmployee($monthId, (int)$id);
        return $this->json(['success'=>(bool)$ok], $ok ? 200 : 500);
    }

    public function ajaxUpdateEmployeeCost($monthId, $id)
    {
        $request  = Request::createFromGlobals();
        $data     = $this->getJson($request);
        $monthId  = (int)$monthId;
        $empRefId = (int)$id;

        if (!isset($data['monthly_cost'])) {
            return $this->json(['message' => 'Missing monthly_cost'], 400);
        }

        $cost = (float)$data['monthly_cost'];

        try {
            $ok = $this->monthService->updateEmployeeCost($monthId, $empRefId, $cost);
            return $this->json(['success' => (bool)$ok, 'monthly_cost' => $cost], $ok ? 200 : 500);
        } catch (\RuntimeException $e) {
            $code = match($e->getMessage()) {
                'MONTH_LOCKED'  => 409,
                'INVALID_COST'  => 422,
                default         => 404,
            };
            return $this->json(['message' => $e->getMessage()], $code);
        }
    }

    public function ajaxAddFixedCost($monthId)
    {
        $request = Request::createFromGlobals();
        $data = $this->getJson($request);

        if (empty($data['name'])) {
            return $this->json(['message' => 'Missing name'], 400);
        }


        $ym = $data['service_month'] ?? null;
        if ($ym !== null && !preg_match('/^\d{4}-\d{2}$/', (string)$ym)) $ym = null;

        $id = $this->monthService->insertMonthFixedCost([
            'month_id' => (int)$monthId,
            'name' => (string)$data['name'],
            'amount' => (float)($data['amount'] ?? 0),
            'category' => (string)($data['category'] ?? 'GENERAL'),
            'service_month' => $ym,
            'posting_month' => $ym,
        ]);

        return $this->json(['success' => (bool)$id, 'id' => $id], $id ? 200 : 500);
    }

    public function ajaxDeleteFixedCost($monthId, $id)
    {
        $ok = $this->monthService->deleteMonthFixedCost($id);

        return $this->json(['success'=>(bool)$ok], $ok ? 200 : 500);
    }

    public function ajaxGetResults($monthId)
    {
        $res = $this->calcService->calculateResults((int)$monthId);
        return $this->json($res);
    }

    public function ajaxAddMediaCost($monthId)
    {
        $request = Request::createFromGlobals();
        $data = $this->getJson($request);

        $monthId = (int)$monthId;
        $m = $this->monthService->getById($monthId);
        if (empty($m)) {
            return $this->json(['message' => 'MONTH_NOT_FOUND'], 404);
        }
        if (($m['status'] ?? 'OPEN') === 'LOCKED') {
            return $this->json(['message' => 'MONTH_LOCKED'], 409);
        }

        $mediaTypeId = (int)($data['media_type_id'] ?? 0);
        if ($mediaTypeId <= 0) {
            return $this->json(['message' => 'Missing media_type_id'], 400);
        }

        $amount = (float)($data['amount'] ?? 0);
        $note = isset($data['note']) ? (string)$data['note'] : null;

        $id = $this->monthService->insertMediaMonthCost([
            'cost_month_id' => $monthId,
            'media_type_id' => $mediaTypeId,
            'amount' => $amount,
            'note' => $note,
        ]);

        return $this->json(['success' => (bool)$id, 'id' => (int)$id], $id ? 200 : 500);
    }

    public function ajaxUpsertMediaAllocation($monthId)
    {
        $request = Request::createFromGlobals();
        $data = $this->getJson($request);

        $monthId = (int)$monthId;
        $m = $this->monthService->getById($monthId);
        if (empty($m)) {
            return $this->json(['message' => 'MONTH_NOT_FOUND'], 404);
        }
        if (($m['status'] ?? 'OPEN') === 'LOCKED') {
            return $this->json(['message' => 'MONTH_LOCKED'], 409);
        }

        $mediaTypeId = (int)($data['media_type_id'] ?? 0);
        $sectionId = (int)($data['section_id'] ?? 0);
        $percent = (float)($data['allocation_percent'] ?? 0);
        $note = isset($data['note']) ? (string)$data['note'] : null;

        if ($mediaTypeId <= 0 || $sectionId <= 0) {
            return $this->json(['message' => 'Missing media_type_id or section_id'], 400);
        }

        $result = $this->monthService->upsertMediaAllocation(
            $monthId,
            $mediaTypeId,
            $sectionId,
            $percent,
            $note
        );

        if (!empty($result['error'])) {
            $status = 422;
            $message = 'ALLOCATION_INVALID';
            
            if ($result['error'] === 'ALLOCATION_OUT_OF_RANGE') {
                $message = 'ALLOCATION_OUT_OF_RANGE';
            } elseif ($result['error'] === 'ALLOCATION_SUM_EXCEEDED') {
                $message = 'ALLOCATION_SUM_EXCEEDED';
                $status = 409;
                // Dodaj info o aktualnej sumie i max dozwolonej wartości
                return $this->json([
                    'message' => $message,
                    'current_sum' => $result['current_sum'] ?? 0,
                    'max_allowed' => $result['max_allowed'] ?? 0
                ], $status);
            }
            
            return $this->json(['message' => $message], $status);
        }

        $id = (int)($result['id'] ?? 0);
        return $this->json(['success' => (bool)$id, 'id' => $id], $id ? 200 : 500);
    }

    public function ajaxDeleteMediaAllocation($monthId, $id)
    {
        $monthId = (int)$monthId;
        $m = $this->monthService->getById($monthId);
        if (empty($m)) {
            return $this->json(['message' => 'MONTH_NOT_FOUND'], 404);
        }
        if (($m['status'] ?? 'OPEN') === 'LOCKED') {
            return $this->json(['message' => 'MONTH_LOCKED'], 409);
        }

        $ok = $this->monthService->deleteMediaAllocation((int)$id);
        return $this->json(['success' => (bool)$ok], $ok ? 200 : 500);
    }

    public function ajaxListMediaTypes()
    {
        $types = $this->monthService->getAllMediaTypes();
        return $this->json(['rows' => $types]);
    }

    public function ajaxAddMediaType()
    {
        $request = Request::createFromGlobals();
        $data = $this->getJson($request);

        $code = trim((string)($data['code'] ?? ''));
        $name = trim((string)($data['name'] ?? ''));
        if ($code === '' || $name === '') {
            return $this->json(['message' => 'Missing code or name'], 400);
        }

        $unit = isset($data['unit']) ? trim((string)$data['unit']) : null;
        $isActive = isset($data['is_active']) ? (int)(bool)$data['is_active'] : 1;

        $id = $this->monthService->insertMediaType([
            'code' => $code,
            'name' => $name,
            'unit' => $unit,
            'is_active' => $isActive,
        ]);

        return $this->json(['success' => (bool)$id, 'id' => $id], $id ? 200 : 500);
    }

    public function ajaxUpdateMediaType($id)
    {
        $request = Request::createFromGlobals();
        $data = $this->getJson($request);

        $id = (int)$id;
        if ($id <= 0) {
            return $this->json(['message' => 'Invalid id'], 400);
        }

        $payload = [];
        if (isset($data['code'])) $payload['code'] = trim((string)$data['code']);
        if (isset($data['name'])) $payload['name'] = trim((string)$data['name']);
        if (array_key_exists('unit', $data)) $payload['unit'] = trim((string)$data['unit']);
        if (isset($data['is_active'])) $payload['is_active'] = (int)(bool)$data['is_active'];

        if (empty($payload)) {
            return $this->json(['message' => 'No fields to update'], 400);
        }

        $ok = $this->monthService->updateMediaType($id, $payload);
        return $this->json(['success' => (bool)$ok], $ok ? 200 : 500);
    }

    public function ajaxDeleteMediaType($id)
    {
        $id = (int)$id;
        if ($id <= 0) {
            return $this->json(['message' => 'Invalid id'], 400);
        }

        $ok = $this->monthService->deactivateMediaType($id);
        return $this->json(['success' => (bool)$ok], $ok ? 200 : 500);
    }

    public function ajaxDeleteMediaCost($monthId, $id)
    {
        $id = (int)$id;
        if ($id <= 0) {
            return $this->json(['message' => 'Invalid id'], 400);
        }

        $ok = $this->monthService->deleteMediaCost($monthId, $id);
        return $this->json(['success' => (bool)$ok], $ok ? 200 : 500);
    }

    public function ajaxLockMonth($monthId)
    {
        // ...existing code...
        $monthId = (int)$monthId;
        try {
            $result = $this->monthService->lockMonth($monthId);
            return $this->json([
                'success'  => (bool)$result['ok'],
                'warnings' => $result['warnings'] ?? [],
            ], $result['ok'] ? 200 : 409);
        } catch (\Throwable $e) {
            return $this->json(['message' => $e->getMessage()], 500);
        }
    }

    public function ajaxUnlockMonth($monthId)
    {
        // ...existing code...
        $monthId = (int)$monthId;
        try {
            $ok = $this->monthService->unlockMonth($monthId);
            return $this->json(['success' => (bool)$ok], $ok ? 200 : 500);
        } catch (\Throwable $e) {
            return $this->json(['message' => $e->getMessage()], 500);
        }
    }

    /**
     * Podgląd kartonów per dział z WDT dla wybranego zakresu dat.
     * Nie zapisuje — zwraca dane do podglądu w UI.
     */
    public function ajaxPreviewWdtCartons($monthId)
    {
        $request  = Request::createFromGlobals();
        $data     = $this->getJson($request);
        $monthId  = (int)$monthId;

        $dateFrom = trim((string)($data['date_from'] ?? ''));
        $dateTo   = trim((string)($data['date_to']   ?? ''));

        if (!$dateFrom || !$dateTo) {
            return $this->json(['message' => 'Podaj zakres dat (date_from, date_to).'], 400);
        }

        $rows = $this->calcService->previewWdtCartons($dateFrom, $dateTo);

        return $this->json([
            'success'   => true,
            'date_from' => $dateFrom,
            'date_to'   => $dateTo,
            'rows'      => $rows,
            'total'     => array_sum(array_column($rows, 'cartons_qty')),
        ]);
    }

    /**
     * Zapisuje kartony z WDT do cost_month_section dla danego miesiąca.
     * Nie nadpisuje wpisów MANUAL.
     */
    public function ajaxSyncCartonsFromWdt($monthId)
    {
        $request = Request::createFromGlobals();
        $data    = $this->getJson($request);
        $monthId = (int)$monthId;

        $m = $this->monthService->getById($monthId);
        if (empty($m)) {
            return $this->json(['message' => 'MONTH_NOT_FOUND'], 404);
        }
        if (($m['status'] ?? 'OPEN') === 'LOCKED') {
            return $this->json(['message' => 'MONTH_LOCKED'], 409);
        }

        $dateFrom = trim((string)($data['date_from'] ?? ''));
        $dateTo   = trim((string)($data['date_to']   ?? ''));

        if (!$dateFrom || !$dateTo) {
            return $this->json(['message' => 'Podaj zakres dat (date_from, date_to).'], 400);
        }

        $result = $this->calcService->syncCartonsFromWdt($monthId, $dateFrom, $dateTo);

        if (isset($result['error'])) {
            return $this->json(['message' => $result['error']], 409);
        }

        return $this->json([
            'success'          => true,
            'saved'            => $result['saved'],
            'skipped_manual'   => $result['skipped_manual'],
            'rows'             => $result['rows'],
        ]);
    }
}
