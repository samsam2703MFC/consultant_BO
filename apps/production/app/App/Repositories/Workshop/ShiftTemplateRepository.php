<?php
declare(strict_types=1);

namespace App\Repositories\Workshop;

final class ShiftTemplateRepository extends WorkshopRepository
{
    protected string $table = 'tfb_prod_shift_template';
    protected array $writable = ['code', 'name_pl', 'name_fr', 'start_time', 'end_time', 'active_days', 'premium_pct', 'is_night', 'sort_order', 'active'];

    public function list(?bool $active): array
    {
        $where = $active === null ? '' : 'WHERE active = ' . (int) $active;
        return $this->all("SELECT * FROM tfb_prod_shift_template {$where} ORDER BY sort_order, start_time, id");
    }
}
