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

    /** Change le PIN de caisse de la personne connectée, et d'elle seule : l'identifiant vient de son jeton. */
    public function changerPin(string $pin): array
    {
        return $this->employeeRepository->changerPin((int) GlobalRegistry::get('user')->getId(), $pin);
    }

}