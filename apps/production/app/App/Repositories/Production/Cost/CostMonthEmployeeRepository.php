<?php
namespace App\Repositories\Production\Cost;

use App\Core\Helpers\BaseRepository;

class CostMonthEmployeeRepository extends BaseRepository
{
    protected string $table = 'cost_month_employee';

    protected $allowedColumns = [
        'month_id','section_id','name','surname','monthly_cost','employee_ref_id'
    ];

    public function getByMonthId(int $monthId): array
    {
        $sql = "
            SELECT e.*, ps.name AS section_name
            FROM {$this->table} e
            INNER JOIN production_sections ps ON ps.id = e.section_id
            WHERE e.month_id = :mid
            ORDER BY ps.id ASC, e.surname ASC, e.name ASC
        ";
        return $this->query($sql, ['mid'=>$monthId]) ?: [];
    }

    public function deleteByMonthId(int $monthId): bool
    {
        $sql = "DELETE FROM {$this->table} WHERE month_id = :mid";
        return (bool)$this->executeStatement($sql, ['mid'=>$monthId]);
    }

    public function copyFromMonth(int $fromMonthId, int $toMonthId): bool
    {
        $sql = "
            INSERT INTO {$this->table} (month_id, section_id, name, surname, monthly_cost, employee_ref_id)
            SELECT :toMid, section_id, name, surname, monthly_cost, employee_ref_id
            FROM {$this->table}
            WHERE month_id = :fromMid
        ";
        return (bool)$this->executeStatement($sql, ['toMid'=>$toMonthId,'fromMid'=>$fromMonthId]);
    }




    public function getIncludedByMonth(int $monthId): array
    {
        $sql = "
            SELECT
                cme.id,
                cme.month_id,
                cme.employee_ref_id,
                cme.section_id,
                ps.name AS section_name,
                cme.name,
                cme.surname,
                cme.monthly_cost
            FROM cost_month_employee cme
            LEFT JOIN production_sections ps ON ps.id = cme.section_id
            WHERE cme.month_id = :mid AND cme.is_included = 1
            ORDER BY ps.name ASC, cme.surname ASC, cme.name ASC
        ";

        return $this->query($sql, ['mid' => $monthId]);
    }

    public function insertFromMasterIfMissing(int $monthId, array $masterEmployees): int
    {
        if (empty($masterEmployees)) return 0;

        // znajdź już istniejące refy w miesiącu
        $existing = $this->query(
            "SELECT employee_ref_id FROM cost_month_employee WHERE month_id = :mid AND employee_ref_id IS NOT NULL",
            ['mid' => $monthId]
        );

        $existsMap = [];
        foreach ($existing as $r) {
            $existsMap[(int)$r['employee_ref_id']] = true;
        }

        $toInsert = [];
        foreach ($masterEmployees as $e) {
            $refId = (int)$e['id'];
            if ($refId <= 0) continue;
            if (isset($existsMap[$refId])) continue;

            $toInsert[] = [
                'month_id' => $monthId,
                'employee_ref_id' => $refId,
                'section_id' => (int)$e['id_section'],
                'name' => (string)$e['name'],
                'surname' => (string)$e['surname'],
                'monthly_cost' => (float)$e['cost'],
                'is_included' => 1
            ];
        }

        if (empty($toInsert)) return 0;

        // MVP: batch insert
        $values = [];
        $params = [];
        $i = 0;

        foreach ($toInsert as $row) {
            $values[] = "(:m{$i}, :ref{$i}, :sec{$i}, :n{$i}, :s{$i}, :c{$i}, 1)";
            $params["m{$i}"] = $row['month_id'];
            $params["ref{$i}"] = $row['employee_ref_id'];
            $params["sec{$i}"] = $row['section_id'];
            $params["n{$i}"] = $row['name'];
            $params["s{$i}"] = $row['surname'];
            $params["c{$i}"] = $row['monthly_cost'];
            $i++;
        }

        $sql = "
            INSERT INTO cost_month_employee
                (month_id, employee_ref_id, section_id, name, surname, monthly_cost, is_included)
            VALUES " . implode(",", $values);

        return (int)$this->query($sql, $params); // execute() u Ciebie zwraca ilość? jeśli nie, możesz zwrócić count($toInsert)
    }

    public function excludeById($monthId, int $id): bool
    {
        $sql = "UPDATE cost_month_employee SET is_included = 0 WHERE employee_ref_id = :id AND month_id = :month_id";
        return $this->query($sql, [
            'id' => $id,
            'month_id' => $monthId
        ]);
    }

    public function findByMonthAndRef(int $monthId, int $employeeRefId)
    {
        $sql = "SELECT * FROM cost_month_employee
            WHERE month_id = :m AND employee_ref_id = :e
            LIMIT 1";
        $res = $this->query($sql, ['m'=>$monthId, 'e'=>$employeeRefId]) ?: null;
        return count($res) > 0 ? $res[0] : null;
    }

    public function reincludeAndUpdate(int $id, int $sectionId, float $monthlyCost): bool
    {
        $sql = "UPDATE cost_month_employee
            SET is_included = 1,
                section_id = :sid,
                monthly_cost = :c
            WHERE id = :id";
        return (bool)$this->query($sql, ['sid'=>$sectionId, 'c'=>$monthlyCost, 'id'=>$id]);
    }

    /**
     * Nadpisuje monthly_cost dla konkretnego wpisu (po employee_ref_id + month_id).
     */
    public function updateMonthlyCostByRef(int $monthId, int $employeeRefId, float $monthlyCost): bool
    {
        $sql = "UPDATE {$this->table}
                SET monthly_cost = :cost
                WHERE month_id = :mid AND employee_ref_id = :ref";
        return (bool)$this->executeStatement($sql, [
            'cost' => $monthlyCost,
            'mid'  => $monthId,
            'ref'  => $employeeRefId,
        ]);
    }
}
