<?php
namespace App\Repositories\Communication;

use App\Core\Helpers\BaseRepository;

class EmailAccountRepository extends BaseRepository
{
    protected string $table = 'communication_email_account';
    protected $allowedColumns = ['name','purpose','from_name','from_email','reply_to_email','smtp_host','smtp_port','smtp_encryption','smtp_username','smtp_secret_encrypted','smtp_secret','imap_host','imap_port','imap_username','imap_secret_encrypted','imap_secret','is_active','last_tested_at','last_synced_at'];

    /** Compte par défaut : celui de la finance, sinon le premier compte actif. */
    public function active(): array { return $this->forPurpose('finance') ?: $this->first(['is_active' => 1]); }
    /** [341] Compte actif d'un usage (finance, logistics, production) ; [] s'il n'y en a pas. */
    public function forPurpose(string $purpose): array
    {
        try { return $this->first(['is_active' => 1, 'purpose' => $purpose]); }
        catch (\Throwable) { return []; } // colonne purpose absente (migration 204 pas encore passée)
    }
    /** @return list<array> tous les comptes actifs (réception IMAP de chaque adresse) */
    public function allActive(): array { return $this->query("SELECT * FROM {$this->table} WHERE is_active = 1 ORDER BY id") ?: []; }
    public function find(int $id): array { return $this->first(['id' => $id]); }
    public function saveAccount(array $data, ?int $id): int
    {
        if ($id) { $this->update($id, $data); return $id; }
        return (int)$this->insert($data);
    }
}
