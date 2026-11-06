<?php

declare(strict_types=1);

namespace Hr\Repository;

use Hr\Dimona\StatusMapper;
use Hr\Support\Clock;
use Hr\Support\Database;

/** Les déclarations envoyées (ou tentées) et leur suivi. */
final class DeclarationRepository
{
    /** @return array<string, mixed>|null */
    public static function find(int $id): ?array
    {
        return Database::row('SELECT * FROM hr_dimona_declarations WHERE id = ?', [$id]);
    }

    /** @param array<string, mixed> $v */
    public static function create(array $v): int
    {
        $v += ['attempts' => 0, 'created_at' => Clock::sql(), 'updated_at' => Clock::sql()];
        return Database::insert('hr_dimona_declarations', $v);
    }

    /** @param array<string, mixed> $v */
    public static function update(int $id, array $v): void
    {
        $v['updated_at'] = Clock::sql();
        Database::update('hr_dimona_declarations', $id, $v);
    }

    /**
     * @param list<int> $employerIds
     * @param array{worker_id?: int, employee_id?: int, status?: string} $filters
     * @return list<array<string, mixed>>
     */
    public static function search(array $employerIds, array $filters = [], int $limit = 200): array
    {
        if ($employerIds === []) {
            return [];
        }
        $w = ['employer_id IN (' . implode(',', array_fill(0, count($employerIds), '?')) . ')'];
        $p = $employerIds;
        if (!empty($filters['worker_id'])) {
            $w[] = 'worker_id = ?';
            $p[] = (int) $filters['worker_id'];
        }
        if (!empty($filters['employee_id'])) {
            $w[] = 'erp_employee_id = ?';
            $p[] = (int) $filters['employee_id'];
        }
        if (!empty($filters['status'])) {
            $w[] = 'status = ?';
            $p[] = (string) $filters['status'];
        }
        return Database::rows('SELECT * FROM hr_dimona_declarations WHERE ' . implode(' AND ', $w) . ' ORDER BY id DESC LIMIT ' . max(1, min(500, $limit)), $p);
    }

    /** @return list<array<string, mixed>> les déclarations à relire maintenant */
    public static function due(int $now, int $limit = 25): array
    {
        return Database::rows('SELECT * FROM hr_dimona_declarations WHERE status IN (?, ?) AND next_poll_at IS NOT NULL AND next_poll_at <= ? ORDER BY next_poll_at LIMIT ' . max(1, $limit),
            [StatusMapper::PENDING, StatusMapper::WAITING_SIGEDIS, Clock::sql($now)]);
    }

    public static function nextDueAt(): ?int
    {
        $r = Database::row('SELECT MIN(next_poll_at) AS n FROM hr_dimona_declarations WHERE status IN (?, ?) AND next_poll_at IS NOT NULL',
            [StatusMapper::PENDING, StatusMapper::WAITING_SIGEDIS]);
        return Clock::ts($r['n'] ?? null);
    }

    /** @return array<string, mixed>|null la dernière déclaration d'une personne */
    public static function lastForWorker(int $workerId): ?array
    {
        return Database::row('SELECT * FROM hr_dimona_declarations WHERE worker_id = ? ORDER BY id DESC LIMIT 1', [$workerId]);
    }

    /** @param array<string, mixed> $r @return array<string, mixed> */
    public static function view(array $r): array
    {
        $dec = static fn (?string $s): mixed => $s === null || $s === '' ? null : json_decode($s, true);
        return [
            'id' => (int) $r['id'],
            'societeId' => (int) $r['employer_id'],
            'workerId' => (int) $r['worker_id'],
            'employeeId' => $r['erp_employee_id'] !== null ? (int) $r['erp_employee_id'] : null,
            'env' => $r['env'],
            'type' => $r['type'],
            'status' => $r['status'],
            'resultCode' => $r['result_code'],
            'dimonaDeclarationId' => $r['dimona_declaration_id'] !== null ? (int) $r['dimona_declaration_id'] : null,
            'dimonaPeriodId' => $r['dimona_period_id'] !== null ? (int) $r['dimona_period_id'] : null,
            'requestPayload' => $dec($r['request_payload']),
            'resultPayload' => $dec($r['result_payload'] ?? null),
            'anomalies' => $dec($r['anomalies'] ?? null) ?? [],
            'lastError' => $r['last_error'],
            'attempts' => (int) $r['attempts'],
            'nextPollAt' => Clock::iso($r['next_poll_at'] ?? null),
            'submittedAt' => Clock::iso($r['submitted_at'] ?? null),
            'resolvedAt' => Clock::iso($r['resolved_at'] ?? null),
            'submittedBy' => $r['submitted_by_name'],
            'createdAt' => Clock::iso($r['created_at'] ?? null),
        ];
    }
}
