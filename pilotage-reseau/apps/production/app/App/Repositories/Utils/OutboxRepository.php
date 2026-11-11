<?php
namespace App\Repositories\Utils;
use App\Core\Helpers\BaseRepository;
/**
 * User class
 */
class OutboxRepository extends BaseRepository
{
    protected string $table = 'outbox';
    protected $allowedColumns = [
        'type',
        'payload_json',
        'status',
        'dedup_key',
        'attempts',
        'last_error',
        'locked_at',
        'locked_by',
    ];


}