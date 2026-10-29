<?php

namespace App\Repositories\Complaint;
use App\Core\Helpers\BaseRepository;

/**
 * ShipmentLogModel class
 */
class ComplaintCompensationMethodRepository extends BaseRepository
{

    protected string $table = 'complaint_compensation_method';

    protected $allowedColumns = [
        'context',
    ];


}