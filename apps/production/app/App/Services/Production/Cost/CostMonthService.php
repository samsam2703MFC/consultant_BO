<?php
namespace App\Services\Production\Cost;

use App\Core\TransactionManager;
use App\Exceptions\CustomException;
use App\Repositories\Employee\ProductionEmployeeRepository;
use App\Repositories\Production\Cost\CostMonthRepository;
use App\Repositories\Production\Cost\CostMonthEmployeeRepository;
use App\Repositories\Production\Cost\CostMonthFixedCostRepository;
use App\Repositories\Production\Cost\CostMonthSectionRepository;
use App\Repositories\Production\Cost\CostMediaTypeRepository;
use App\Repositories\Production\Cost\CostMediaMonthRepository;
use App\Repositories\Production\Cost\CostMediaSectionAllocationRepository;
use App\Repositories\Production\Cost\CostLogisticsTypeRepository;
use App\Repositories\Production\Cost\CostMonthLogisticsRepository;

class CostMonthService
{
    public function __construct(
        private TransactionManager $transactionManager,
        private CostMonthRepository $monthRepo,
        private CostMonthEmployeeRepository $costEmployeeRepo,
        private CostMonthFixedCostRepository $fixedRepo,
        private CostMonthSectionRepository $sectionRepo,
        private CostMonthEmployeeRepository $monthEmployeeRepo,
        private ProductionEmployeeRepository $employeeRepo,
        private CostMediaTypeRepository $mediaTypeRepo,
        private CostMediaMonthRepository $mediaMonthRepo,
        private CostMediaSectionAllocationRepository $mediaAllocationRepo,
        private CostLogisticsTypeRepository $logisticsTypeRepo,
        private CostMonthLogisticsRepository $logisticsRepo,
    ) {}

    public const FIXED_COST_CATEGORIES = [
        'RENT' => 'Czynsz / Najem',
        'UTILITIES' => 'Media (prąd/gaz/woda)',
        'TELECOM' => 'Internet / Telefon',
        'WASTE' => 'Odpady / Utylizacja',
        'LOGISTICS' => 'Logistyka / Transport / Paliwo',
        'OFFICE' => 'Koszty biurowe',
        'CLEANING' => 'Sprzątanie / Higiena',
        'MAINTENANCE' => 'Serwis / Naprawy / Utrzymanie',
        'IT' => 'IT / Licencje / Subskrypcje',
        'INSURANCE' => 'Ubezpieczenia',
        'FINANCE_LEGAL' => 'Księgowość / Prawne / Bank',
        'MARKETING' => 'Marketing / Reklama',
        'HR_TRAINING' => 'HR / Szkolenia',
        'TAX_FEES' => 'Opłaty / Podatki / Zezwolenia',
        'OTHER' => 'Inne',
    ];

    public function getOrCreate(int $year, int $month): array
    {
        $year = (int)$year;
        $month = (int)$month;

        if ($year < 2000 || $year > 2100) {
            throw new CustomException("INVALID_YEAR", "error", 400);
        }
        if ($month < 1 || $month > 12) {
            throw new CustomException("INVALID_MONTH", "error", 400);
        }

        $existing = $this->monthRepo->getByYearMonth($year, $month);
        if ($existing) return $existing;

        $id = $this->monthRepo->insert([
            'year' => $year,
            'month' => $month,
            'status' => 'OPEN'
        ]);

        if (!$id) {
            throw new CustomException("FAILED_CREATE_MONTH", "error", 500);
        }

        $created = $this->monthRepo->getById((int)$id);
        return $created ?: ['id'=>(int)$id,'year'=>$year,'month'=>$month,'status'=>'OPEN'];
    }

    public function getPrevYearMonth(int $year, int $month): array
    {
        $month--;
        if ($month < 1) {
            $month = 12;
            $year--;
        }
        return [$year,$month];
    }

    public function copyFromPrevious(int $monthId): bool
    {
        $month = $this->monthRepo->getById($monthId);
        if (!$month) throw new CustomException("MONTH_NOT_FOUND", "error", 404);

        [$py, $pm] = $this->getPrevYearMonth((int)$month['year'], (int)$month['month']);
        $prev = $this->monthRepo->getByYearMonth($py, $pm);
        if (!$prev) {
            throw new CustomException("PREVIOUS_MONTH_NOT_FOUND", "error", 404);
        }

        return $this->transactionManager->execute(function () use ($monthId, $prev) {
            // czyścimy i kopiujemy (MVP)
            $this->costEmployeeRepo->deleteByMonthId($monthId);
            $this->fixedRepo->deleteByMonthId($monthId);
            $this->logisticsRepo->deleteByMonthId($monthId);
            $this->mediaMonthRepo->deleteByMonthId($monthId);
            $this->mediaAllocationRepo->deleteByMonthId($monthId);

            $ok1 = $this->costEmployeeRepo->copyFromMonth((int)$prev['id'], $monthId);
            $ok2 = $this->fixedRepo->copyFromMonth((int)$prev['id'], $monthId);
            $ok3 = $this->logisticsRepo->copyFromMonth((int)$prev['id'], $monthId);
            $ok4 = $this->mediaMonthRepo->copyFromMonth((int)$prev['id'], $monthId);
            $ok5 = $this->mediaAllocationRepo->copyFromMonth((int)$prev['id'], $monthId);

            return ($ok1 && $ok2 && $ok3 && $ok4 && $ok5);
        });
    }

    /**
     * Manual override cartons per section
     */
    public function setManualCartons(int $monthId, int $sectionId, int $cartonsQty): bool
    {
        $m = $this->monthRepo->getById($monthId);
        if (!$m) throw new CustomException("MONTH_NOT_FOUND", "error", 404);
        if (($m['status'] ?? 'OPEN') === 'LOCKED') throw new CustomException("MONTH_LOCKED", "error", 409);

        return $this->sectionRepo->updateCartonsManual($monthId, $sectionId, $cartonsQty);
    }

    /**
     * Zmienia źródło (source) dla danego działu bez zmiany ilości kartonów.
     * Dozwolone wartości: MANUAL, AUTO, WDT.
     */
    public function setCartonSource(int $monthId, int $sectionId, string $source): bool
    {
        $m = $this->monthRepo->getById($monthId);
        if (!$m) throw new CustomException("MONTH_NOT_FOUND", "error", 404);
        if (($m['status'] ?? 'OPEN') === 'LOCKED') throw new CustomException("MONTH_LOCKED", "error", 409);

        $allowed = ['MANUAL', 'AUTO', 'WDT'];
        $source  = strtoupper(trim($source));
        if (!in_array($source, $allowed, true)) {
            throw new CustomException("INVALID_SOURCE", "error", 400);
        }

        // Pobierz aktualną ilość — nie zmieniamy qty, tylko source
        $existing = $this->sectionRepo->getOne($monthId, $sectionId);
        $qty = (int)($existing['cartons_qty'] ?? 0);

        return $this->sectionRepo->upsert($monthId, $sectionId, $qty, $source);
    }

    /**
     * Sync AUTO cartons from orders - does not overwrite MANUAL entries.
     */
    public function syncAutoCartons(int $monthId, array $autoRows): bool
    {
        $m = $this->monthRepo->getById($monthId);
        if (!$m) throw new CustomException("MONTH_NOT_FOUND", "error", 404);
        if (($m['status'] ?? 'OPEN') === 'LOCKED') throw new CustomException("MONTH_LOCKED", "error", 409);

        $existing = $this->sectionRepo->getByMonthId($monthId);
        $existingMap = [];
        foreach ($existing as $row) {
            $existingMap[(int)$row['section_id']] = $row;
        }

        foreach ($autoRows as $r) {
            $sid = (int)$r['section_id'];
            $qty = (int)$r['cartons_qty'];

            if (isset($existingMap[$sid]) && ($existingMap[$sid]['source'] ?? 'AUTO') === 'MANUAL') {
                continue; // nie ruszamy MANUAL
            }
            $this->sectionRepo->upsert($monthId, $sid, $qty, 'AUTO');
        }

        return true;
    }

    public function insertEmployee($data)
    {
        return $this->costEmployeeRepo->insert($data);
    }

    public function ensureDefaultEmployees(int $monthId): void
    {
        $master = $this->employeeRepo->getAllWithSection();
        $this->monthEmployeeRepo->insertFromMasterIfMissing($monthId, $master);
    }

    public function deleteEmployee($monthId, int $id): bool
    {
        return $this->monthEmployeeRepo->excludeById($monthId, $id);
    }

    /**
     * Nadpisuje koszt miesięczny pracownika w snapshocie danego miesiąca.
     */
    public function updateEmployeeCost(int $monthId, int $employeeRefId, float $monthlyCost): bool
    {
        $m = $this->monthRepo->getById($monthId);
        if (!$m) throw new \RuntimeException('MONTH_NOT_FOUND');
        if (($m['status'] ?? 'OPEN') === 'LOCKED') throw new \RuntimeException('MONTH_LOCKED');
        if ($monthlyCost < 0) throw new \RuntimeException('INVALID_COST');

        return $this->monthEmployeeRepo->updateMonthlyCostByRef($monthId, $employeeRefId, $monthlyCost);
    }

    public function includeEmployeeFromMaster(int $monthId, int $employeeId, ?float $monthlyCost = null): int
    {
        // 1) pobierz pracownika z master
        $emp = $this->employeeRepo->getById($employeeId);
        if (!$emp) return 0;

        $cost = $monthlyCost !== null ? (float)$monthlyCost : (float)$emp['cost'];

        // 2) jeśli jest już w snapshot -> ustaw included=1 + update koszt/sekcja
        $existing = $this->monthEmployeeRepo->findByMonthAndRef($monthId, $employeeId);
        if ($existing) {
            $this->monthEmployeeRepo->reincludeAndUpdate((int)$existing['id'], (int)$emp['id_section'], $cost);
            return (int)$existing['id'];
        }

        // 3) jeśli nie ma -> insert snapshot
        return (int)$this->monthEmployeeRepo->insert([
            'month_id' => $monthId,
            'employee_ref_id' => $employeeId,
            'section_id' => (int)$emp['id_section'],
            'name' => (string)$emp['name'],
            'surname' => (string)$emp['surname'],
            'monthly_cost' => $cost,
            'is_included' => 1,
        ]);
    }

    public function insertMonthFixedCost($data)
    {
        $cat = strtoupper((string)($data['category'] ?? 'OTHER'));
        if (!isset(self::FIXED_COST_CATEGORIES[$cat])) {
            $cat = 'OTHER';
        }
        $data['category'] = $cat;

        return $this->fixedRepo->insert($data);
    }

    public function deleteMonthFixedCost($id)
    {
        return $this->fixedRepo->delete($id);
    }

    public function getFixedCostCategories(): array
    {
        return self::FIXED_COST_CATEGORIES;
    }

    public function getById(int $monthId): array
    {
        return $this->monthRepo->getById($monthId) ?: [];
    }

    public function listRecentMonths(int $limit = 24): array
    {
        return $this->monthRepo->listLast($limit);
    }

    public function getMediaTypes(): array
    {
        return $this->mediaTypeRepo->getActive();
    }

    public function getAllMediaTypes(): array
    {
        return $this->mediaTypeRepo->getAll();
    }

    public function insertMediaType(array $data)
    {
        return $this->mediaTypeRepo->insert($data);
    }

    public function updateMediaType(int $id, array $data): bool
    {
        return (bool)$this->mediaTypeRepo->update($id, $data);
    }

    public function deactivateMediaType(int $id): bool
    {
        return (bool)$this->mediaTypeRepo->update($id, ['is_active' => 0]);
    }


    public function getMediaAllocationsByPeriod(int $year, int $month): array
    {
        $m = $this->monthRepo->getByYearMonth($year, $month);
        if (!$m) return [];
        return $this->mediaAllocationRepo->getByMonthId((int)$m['id']);
    }

    public function getMediaAllocationsByMonthId(int $monthId): array
    {
        return $this->mediaAllocationRepo->getByMonthId($monthId);
    }

    public function insertMediaMonthCost(array $data)
    {
        $monthId = (int)($data['cost_month_id'] ?? 0);
        $mediaTypeId = (int)($data['media_type_id'] ?? 0);
        $amount = (float)($data['amount'] ?? 0);
        $note = isset($data['note']) ? (string)$data['note'] : null;

        if ($monthId <= 0 || $mediaTypeId <= 0) {
            return 0;
        }

        return $this->mediaMonthRepo->upsertByMonthIdAndType($monthId, $mediaTypeId, $amount, $note);
    }

    public function upsertMediaAllocation(int $monthId, int $mediaTypeId, int $sectionId, float $percent, ?string $note = null): array
    {
        $percent = (float)$percent;
        if ($percent < 0 || $percent > 100) {
            return ['id' => 0, 'error' => 'ALLOCATION_OUT_OF_RANGE'];
        }

        // Sprawdź czy suma nie przekracza 100%
        $existing = $this->mediaAllocationRepo->findByKey($monthId, $mediaTypeId, $sectionId);
        $excludeId = !empty($existing) ? (int)$existing['id'] : null;
        $sumOther = $this->mediaAllocationRepo->getSumByMonthAndMediaType($monthId, $mediaTypeId, $excludeId);
        $total = $sumOther + $percent;
        
        if ($total > 100.01) { // tolerancja 0.01 na zaokrąglenia
            return [
                'id' => 0, 
                'error' => 'ALLOCATION_SUM_EXCEEDED',
                'current_sum' => $sumOther,
                'max_allowed' => max(0, 100 - $sumOther)
            ];
        }

        $ok = $this->mediaAllocationRepo->upsert($monthId, $mediaTypeId, $sectionId, $percent, $note);
        if (!$ok) return ['id' => 0, 'error' => 'ALLOCATION_SAVE_FAILED'];
        $row = $this->mediaAllocationRepo->findByKey($monthId, $mediaTypeId, $sectionId);
        return ['id' => (int)($row['id'] ?? 0), 'error' => null];
    }

    public function deleteMediaAllocation(int $id): bool
    {
        return (bool)$this->mediaAllocationRepo->delete($id);
    }

    public function deleteMediaCost($monthId, $id)
    {
        return $this->mediaMonthRepo->deleteByMonthIdAndId($monthId, $id);
    }

    // -------------------------------------------------------------------------
    // Logistyka
    // -------------------------------------------------------------------------

    public function getLogisticsTypes(): array
    {
        return $this->logisticsTypeRepo->getActive();
    }

    public function getLogisticsCosts(int $monthId): array
    {
        return $this->logisticsRepo->getByMonthId($monthId);
    }

    public function insertLogisticsCost(int $monthId, int $logisticsTypeId, float $amount, ?string $note): int
    {
        $id = $this->logisticsRepo->insert([
            'month_id'          => $monthId,
            'logistics_type_id' => $logisticsTypeId,
            'amount'            => $amount,
            'note'              => $note,
        ]);
        return (int)$id;
    }

    public function deleteLogisticsCost(int $id): bool
    {
        return (bool)$this->logisticsRepo->delete($id);
    }

    public function getLogisticsTotalByMonthId(int $monthId): float
    {
        return $this->logisticsRepo->getTotalByMonthId($monthId);
    }

    // -------------------------------------------------------------------------
    // Zamykanie / otwieranie miesiąca
    // -------------------------------------------------------------------------

    /**
     * Locks a cost month (status → LOCKED).
     * Returns ['ok' => bool, 'warnings' => string[]]
     */
    public function lockMonth(int $monthId): array
    {
        $m = $this->monthRepo->getById($monthId);
        if (!$m) {
            throw new CustomException('MONTH_NOT_FOUND', 'error', 404);
        }
        if (($m['status'] ?? '') === 'LOCKED') {
            return ['ok' => false, 'warnings' => ['Miesiąc jest już zamknięty.']];
        }

        $warnings = [];

        // Sprawdź kompletność – czy są kartony w sekcjach
        $sections = $this->sectionRepo->getByMonthId($monthId);
        $totalCartons = array_sum(array_column($sections, 'cartons_qty'));
        if ($totalCartons <= 0) {
            $warnings[] = 'Brak kartonów w sekcjach – dane o wolumenie mogą być niekompletne.';
        }

        // Czy są pracownicy
        $employees = $this->costEmployeeRepo->getIncludedByMonth($monthId);
        if (empty($employees)) {
            $warnings[] = 'Brak pracowników przypisanych do miesiąca.';
        }

        $ok = $this->monthRepo->lockMonth($monthId);
        return ['ok' => $ok, 'warnings' => $warnings];
    }

    /**
     * Unlocks a cost month (status → OPEN).
     */
    public function unlockMonth(int $monthId): bool
    {
        $m = $this->monthRepo->getById($monthId);
        if (!$m) {
            throw new CustomException('MONTH_NOT_FOUND', 'error', 404);
        }
        return (bool)$this->monthRepo->customUpdate(
            "UPDATE cost_month SET status = 'OPEN' WHERE id = :id",
            ['id' => $monthId]
        );
    }
}
