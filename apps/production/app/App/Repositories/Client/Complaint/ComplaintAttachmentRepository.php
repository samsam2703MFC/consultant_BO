<?php
namespace App\Repositories\Client\Complaint;



use App\Core\Helpers\BaseRepository;

/**
 *  shop customer class
 */
class ComplaintAttachmentRepository extends BaseRepository
{

    protected string $table = 'complaint_attachment';
    protected $allowedColumns = [
        'complaint_id',
        'client_attachment_id',
        'object_key'
    ];
    protected $fetchAllowedColumns = [
        'id',
        'complaint_id',
        'client_attachment_id',
        'object_key'
    ];

    public $errors = [];

    public function getByComplaint($id)
    {
        $sql = "SELECT * FROM {$this->table} WHERE complaint_id = :id";
        return $this->query($sql, ['id' => $id]);
    }
}