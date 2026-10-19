<?php
namespace App\Repositories\Employee;

use App\Core\Helpers\BaseRepository;

/**
 * Production sections model class
 */
class ProductionEmployeeRepository extends BaseRepository
{

    protected string $table = 'production_employee';

    protected $allowedColumns = [
        "name",
        "surname",
        "cost",
        "id_section"
    ];


    public function getAllWithSection()
    {
        $sql = "SELECT 
        p_emp.id,
        p_emp.name,
        p_emp.surname,
        p_emp.cost,
        p_emp.id_section,
        p_section.name as section_name
        FROM $this->table p_emp
        INNER JOIN production_sections p_section
            ON p_emp.id_section = p_section.id
        ORDER BY surname, p_section.name";
        return $this->query($sql);
    }

    public function getById(int $id)
    {
        $sql = "SELECT e.*, s.name AS section_name
            FROM production_employee e
            LEFT JOIN production_sections s ON s.id = e.id_section
            WHERE e.id = :id
            LIMIT 1";
        $res = $this->query($sql, ['id'=>$id]);
        return count($res) > 0 ? $res[0] : null;
    }

    public function getCostBySection($id_section)
    {
        $sql = "SELECT IFNULL(SUM(cost),0) as total_cost FROM production_employee WHERE id_section = :id_section";
        $res = $this->query($sql, ['id_section' => $id_section]);
        if (!$res) return 0;

        return $res[0]['total_cost'];
    }

}