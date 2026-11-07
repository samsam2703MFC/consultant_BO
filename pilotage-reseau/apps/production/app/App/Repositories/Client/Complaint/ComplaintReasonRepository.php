<?php

namespace App\Repositories\Client\Complaint;
use App\Core\Helpers\BaseRepository;

/**
 * ShipmentLogModel class
 */
class ComplaintReasonRepository extends BaseRepository
{

    protected string $table = 'complaint_reason';

    protected $allowedColumns = [
        'context',
    ];


}