<?php
declare(strict_types=1);

namespace App\Repositories\Workshop;

final class ParamRepository extends WorkshopRepository
{
    protected string $table = 'tfb_prod_param';

    private ?array $cache = null;

    public function list(): array
    {
        return $this->all('SELECT * FROM tfb_prod_param ORDER BY code');
    }

    public function findByCode(string $code): ?array
    {
        $rows = $this->all('SELECT * FROM tfb_prod_param WHERE code = ?', [$code]);
        return $rows[0] ?? null;
    }

    /** Valeur brute d'un paramètre, ou $default s'il n'existe pas (table absente comprise). */
    public function get(string $code, string $default): string
    {
        if ($this->cache === null) {
            try {
                $this->cache = array_column($this->all('SELECT code, value FROM tfb_prod_param'), 'value', 'code');
            } catch (\PDOException) {
                $this->cache = [];
            }
        }
        return (string) ($this->cache[$code] ?? $default);
    }

    public function setValue(string $code, string $value, ?int $userId): void
    {
        $this->db->prepare('UPDATE tfb_prod_param SET value = ?, updated_by = ? WHERE code = ?')->execute([$value, $userId, $code]);
        $this->cache = null;
    }
}
