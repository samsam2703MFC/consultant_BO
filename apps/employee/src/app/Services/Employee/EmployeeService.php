<?php
namespace App\Employee\app\Services\Employee;



use App\Employee\app\Repositories\Employee\EmployeeRepository;
use App\Employee\core\Support\GlobalRegistry;

class EmployeeService {


    public function __construct(private EmployeeRepository $employeeRepository) {
    }

    public function getMe($include = [])
    {
        return $this->employeeRepository->getById(GlobalRegistry::get('user')->getId(), $include);
    }

}