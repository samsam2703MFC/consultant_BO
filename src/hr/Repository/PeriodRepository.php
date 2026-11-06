<?php

declare(strict_types=1);

namespace Hr\Repository;

use Hr\Support\Clock;
use Hr\Support\Database;

/** Les périodes Dimona connues du module : déclarées ici, ou importées de l'ONSS (POST /periods/search). */
final class PeriodRepository
{
    public const ACTIVE = 'ACTIVE';
    public const CLOSED = 'CLOSED';
    public const CANCELED = 'CANCELED';

    /** @return array<string, mixed>|null la période ouverte la plus récente d'une personne */
    public static function active(int $workerId): ?array
    {
        return Database::row('SELECT * FROM hr_dimona_periods WHERE worker_id = ? AND status = ? ORDER BY start_date DESC, id DESC LIMIT 1', [$workerId, self::ACTIVE]);
    }

    /** @return array<string, mixed>|null */
    public static function find(int $id): ?array
    {
        return Database::row('SELECT * FROM hr_dimona_periods WHERE id = ?', [$id]);
    }

    /** @return array<string, mixed>|null */
    public static function byDimonaId(int $dimonaPeriodId): ?array
    {
        return Database::row('SELECT * FROM hr_dimona_periods WHERE dimona_period_id = ?', [$dimonaPeriodId]);
    }

    /** @return list<array<string, mixed>> */
    public static function forWorker(int $workerId): array
    {
        return Database::rows('SELECT * FROM hr_dimona_periods WHERE worker_id = ? ORDER BY start_date DESC, id DESC', [$workerId]);
    }

    /** @return list<array<string, mixed>> */
    public static function activeForEmployer(int $employerId): array
    {
        return Database::rows('SELECT * FROM hr_dimona_periods WHERE employer_id = ? AND status = ? ORDER BY start_date DESC', [$employerId, self::ACTIVE]);
    }

    /** Crée ou met à jour la période (clé : son numéro ONSS). @param array<string, mixed> $v */
    public static function upsert(array $v): int
    {
        $v['updated_at'] = Clock::sql();
        $cur = self::byDimonaId((int) $v['dimona_period_id']);
        if ($cur !== null) {
            unset($v['created_at']);
            Database::update('hr_dimona_periods', (int) $cur['id'], $v);
            return (int) $cur['id'];
        }
        $v['created_at'] = Clock::sql();
        return Database::insert('hr_dimona_periods', $v);
    }

    /** @param array<string, mixed> $v */
    public static function update(int $id, array $v): void
    {
        $v['updated_at'] = Clock::sql();
        Database::update('hr_dimona_periods', $id, $v);
    }

    /** @param array<string, mixed> $r @return array<string, mixed> */
    public static function view(array $r): array
    {
        return [
            'id' => (int) $r['id'],
            'workerId' => (int) $r['worker_id'],
            'dimonaPeriodId' => (int) $r['dimona_period_id'],
            'startDate' => $r['start_date'],
            'startHour' => $r['start_hour'],
            'endDate' => $r['end_date'],
            'endHour' => $r['end_hour'],
            'workerType' => $r['worker_type'],
            'jointCommission' => $r['joint_commission'],
            'plannedHours' => $r['planned_hours'] !== null ? (int) $r['planned_hours'] : null,
            'status' => $r['status'],
            'source' => $r['source'],
            'updatedAt' => Clock::iso($r['updated_at'] ?? null),
        ];
    }
}
