<?php
declare(strict_types=1);

namespace App\Repositories\Finances;

use App\Repositories\Workshop\WorkshopRepository;

/** Dane pomocnicze strony « Klienci : wolumen i marża ». */
final class ClientVolumeRepository extends WorkshopRepository
{
    /** Miesiące kosztowe (kalkulator kosztów) roku. */
    public function costMonthIds(int $year): array
    {
        return array_map('intval', array_column($this->all('SELECT id FROM cost_month WHERE year = ? ORDER BY month', [$year]), 'id'));
    }

    /** id klienta => nazwa firmy. */
    public function clientNames(): array
    {
        return array_column($this->all('SELECT id, company FROM customer'), 'company', 'id');
    }
}
