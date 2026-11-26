<?php
declare(strict_types=1);

namespace App\Repositories\Finances;

use App\Repositories\Workshop\WorkshopRepository;

/**
 * [438] Kursy średnie NBP (tabela A) : n_fx_rate (kurs waluty z dnia publikacji) et n_fx_rate_day (jours déjà demandés à
 * NBP, avec ou sans table). N'écrit que ces deux tables.
 */
final class FxRateRepository extends WorkshopRepository
{
    protected string $table = 'n_fx_rate';

    /** @return array<string, array{has_table: bool, table_no: ?string}> jour → état, du $from au $to inclus */
    public function days(string $from, string $to): array
    {
        $out = [];
        foreach ($this->all('SELECT day, has_table, table_no FROM n_fx_rate_day WHERE day >= ? AND day <= ?', [$from, $to]) as $r) {
            $out[substr((string) $r['day'], 0, 10)] = ['has_table' => (bool) $r['has_table'], 'table_no' => $r['table_no'] !== null ? (string) $r['table_no'] : null];
        }
        return $out;
    }

    /** @return array<string, array{mid: float, table_no: string}> jour → kurs de la devise, du $from au $to inclus */
    public function rates(string $currency, string $from, string $to): array
    {
        $out = [];
        foreach ($this->all('SELECT rate_date, mid, table_no FROM n_fx_rate WHERE currency = ? AND rate_date >= ? AND rate_date <= ? ORDER BY rate_date', [$currency, $from, $to]) as $r) {
            $out[substr((string) $r['rate_date'], 0, 10)] = ['mid' => (float) $r['mid'], 'table_no' => (string) $r['table_no']];
        }
        return $out;
    }

    public function saveRate(string $currency, string $day, float $mid, string $tableNo): void
    {
        $this->db->prepare('INSERT INTO n_fx_rate (currency, rate_date, mid, table_no, fetched_at) VALUES (?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE mid = VALUES(mid), table_no = VALUES(table_no), fetched_at = VALUES(fetched_at)')
            ->execute([$currency, $day, $mid, $tableNo]);
    }

    /** Jour demandé : une table trouvée n'est jamais effacée par une demande sans table. */
    public function markDay(string $day, bool $hasTable, ?string $tableNo): void
    {
        $this->db->prepare('INSERT INTO n_fx_rate_day (day, has_table, table_no, checked_at) VALUES (?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE has_table = GREATEST(has_table, VALUES(has_table)), table_no = COALESCE(VALUES(table_no), table_no), checked_at = VALUES(checked_at)')
            ->execute([$day, $hasTable ? 1 : 0, $tableNo]);
    }

    /** Dernière demande à NBP (null = jamais). */
    public function lastCheck(): ?string
    {
        $v = $this->all('SELECT MAX(checked_at) AS t FROM n_fx_rate_day')[0]['t'] ?? null;
        return $v === null ? null : (string) $v;
    }

    /** Dernières tables de la devise jusqu'au $to inclus. @return list<array{date: string, mid: float, table_no: string}> */
    public function latest(string $currency, string $to, int $limit): array
    {
        return array_map(fn($r) => ['date' => substr((string) $r['rate_date'], 0, 10), 'mid' => (float) $r['mid'], 'table_no' => (string) $r['table_no']],
            $this->all('SELECT rate_date, mid, table_no FROM n_fx_rate WHERE currency = ? AND rate_date <= ? ORDER BY rate_date DESC LIMIT ' . max(1, min(31, $limit)), [$currency, $to]));
    }
}
