<?php
namespace App\Employee\app\Services\Schedule;



use App\Employee\app\Repositories\Schedule\ScheduleRepository;
use App\Employee\core\Support\GlobalRegistry;

class ScheduleService {


    public function __construct(private ScheduleRepository $scheduleRepository) {
    }

    public function getForMe($dateFrom = null, $dateTo = null)
    {
        return $this->scheduleRepository->getByEmployee(GlobalRegistry::get('user')->getId(), $dateFrom, $dateTo);
    }

}