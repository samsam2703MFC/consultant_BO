<?php
declare(strict_types=1);

namespace App\Repositories\Workshop;

use PDO;

/**
 * Base des dépôts du module atelier. Contrairement à BaseRepository, les erreurs SQL
 * remontent en exception (rien n'est affiché ni avalé), et seules les colonnes déclarées
 * peuvent être écrites.
 */
abstract class WorkshopRepository
{
    protected string $table;
    /** @var list<string> */
    protected array $writable = [];

    public function __construct(protected PDO $db)
    {
    }

    public function find(int $id): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM {$this->table} WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    public function exists(int $id): bool
    {
        $stmt = $this->db->prepare("SELECT 1 FROM {$this->table} WHERE id = ?");
        $stmt->execute([$id]);
        return (bool) $stmt->fetchColumn();
    }

    public function codeTaken(string $code, ?int $exceptId = null): bool
    {
        $stmt = $this->db->prepare("SELECT 1 FROM {$this->table} WHERE code = ? AND id <> ?");
        $stmt->execute([$code, $exceptId ?? 0]);
        return (bool) $stmt->fetchColumn();
    }

    public function insert(array $data): int
    {
        $data = $this->filter($data);
        $columns = array_keys($data);
        $sql = sprintf('INSERT INTO %s (%s) VALUES (%s)', $this->table, implode(', ', $columns),
            implode(', ', array_map(fn($c) => ':' . $c, $columns)));
        $this->db->prepare($sql)->execute($this->bind($data));
        return (int) $this->db->lastInsertId();
    }

    public function update(int $id, array $data): void
    {
        $data = $this->filter($data);
        if (!$data) return;
        $set = implode(', ', array_map(fn($c) => "{$c} = :{$c}", array_keys($data)));
        $data['__id'] = $id;
        $this->db->prepare("UPDATE {$this->table} SET {$set} WHERE id = :__id")->execute($this->bind($data));
    }

    protected function all(string $sql, array $params = []): array
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    protected function filter(array $data): array
    {
        return array_intersect_key($data, array_flip($this->writable));
    }

    private function bind(array $data): array
    {
        return array_map(fn($v) => is_bool($v) ? (int) $v : $v, $data);
    }
}
