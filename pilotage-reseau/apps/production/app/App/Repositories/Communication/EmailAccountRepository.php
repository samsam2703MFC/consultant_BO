<?php
namespace App\Repositories\Communication;

use App\Core\Helpers\BaseRepository;

class EmailAccountRepository extends BaseRepository
{
    protected string $table = 'communication_email_account';
    protected $allowedColumns = ['name','from_name','from_email','reply_to_email','smtp_host','smtp_port','smtp_encryption','smtp_username','smtp_secret_encrypted','smtp_secret','imap_host','imap_port','imap_username','imap_secret_encrypted','imap_secret','is_active','last_tested_at','last_synced_at'];

    public function active(): array { return $this->first(['is_active' => 1]); }
    public function find(int $id): array { return $this->first(['id' => $id]); }
    public function saveAccount(array $data, ?int $id): int
    {
        if ($id) { $this->update($id, $data); return $id; }
        return (int)$this->insert($data);
    }
}
