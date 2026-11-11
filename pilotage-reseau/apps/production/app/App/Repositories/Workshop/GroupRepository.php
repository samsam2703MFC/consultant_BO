<?php
declare(strict_types=1);

namespace App\Repositories\Workshop;

final class GroupRepository extends WorkshopRepository
{
    protected string $table = 'n_prod_group';
    protected array $writable = ['code', 'name_pl', 'name_fr', 'color', 'sort_order', 'id_section', 'active'];

    public function list(?bool $active): array
    {
        $where = $active === null ? '' : 'WHERE active = ' . (int) $active;
        return $this->all("SELECT * FROM n_prod_group {$where} ORDER BY sort_order, id");
    }

    public function sectionExists(int $id): bool
    {
        return (bool) $this->all('SELECT 1 FROM production_sections WHERE id = ?', [$id]);
    }
}
