<?php

declare(strict_types=1);

namespace Hr\Repository;

use Hr\Dimona\Mask;
use Hr\Support\Clock;
use Hr\Support\Crypto;
use Hr\Support\Database;

/**
 * Les personnes déclarées par une société. Le NISS est chiffré (AAD : la société) et retrouvable par son
 * empreinte HMAC, sans être déchiffré ; l'API ne le montre que masqué (••••••.•••.••), et en clair seulement
 * sur demande explicite, écrite au journal.
 */
final class WorkerRepository
{
    /** @return array<string, mixed>|null */
    public static function find(int $id): ?array
    {
        return Database::row('SELECT * FROM hr_dimona_workers WHERE id = ?', [$id]);
    }

    /** @return list<array<string, mixed>> */
    public static function forEmployer(int $employerId): array
    {
        return Database::rows('SELECT * FROM hr_dimona_workers WHERE employer_id = ? ORDER BY family_name, given_name', [$employerId]);
    }

    /** @return array<string, mixed>|null */
    public static function byErp(int $employerId, int $erpEmployeeId): ?array
    {
        return Database::row('SELECT * FROM hr_dimona_workers WHERE employer_id = ? AND erp_employee_id = ?', [$employerId, $erpEmployeeId]);
    }

    /** @return array<string, mixed>|null */
    public static function bySsin(int $employerId, string $ssin, Crypto $crypto): ?array
    {
        return Database::row('SELECT * FROM hr_dimona_workers WHERE employer_id = ? AND ssin_hash = ?', [$employerId, self::hash($ssin, $crypto)]);
    }

    /**
     * Crée ou complète une personne. `ssin` en clair n'est passé que pour être chiffré ici.
     *
     * @param array<string, mixed> $v
     */
    public static function save(?int $id, int $employerId, array $v, Crypto $crypto, string $by): int
    {
        $row = [];
        foreach (['erp_employee_id', 'shop_id', 'given_name', 'family_name', 'birth_date', 'worker_type', 'joint_commission', 'planned_hours'] as $k) {
            if (array_key_exists($k, $v)) {
                $row[$k] = $v[$k] === '' ? null : $v[$k];
            }
        }
        if (!empty($v['ssin'])) {
            $ssin = preg_replace('/\D/', '', (string) $v['ssin']) ?? '';
            $row['ssin_encrypted'] = $crypto->encrypt($ssin, 'ssin', 'employer:' . $employerId);
            $row['ssin_hash'] = self::hash($ssin, $crypto);
        }
        $row['updated_at'] = Clock::sql();
        if ($id !== null) {
            Database::update('hr_dimona_workers', $id, $row);
            return $id;
        }
        $row += ['employer_id' => $employerId, 'created_by' => $by, 'created_at' => Clock::sql()];
        $row['given_name'] ??= '';
        $row['family_name'] ??= '';
        return Database::insert('hr_dimona_workers', $row);
    }

    /** @param array<string, mixed> $row */
    public static function ssin(array $row, Crypto $crypto): ?string
    {
        if (empty($row['ssin_encrypted'])) {
            return null;
        }
        return $crypto->decrypt((string) $row['ssin_encrypted'], 'ssin', 'employer:' . $row['employer_id']);
    }

    public static function hash(string $ssin, Crypto $crypto): string
    {
        return $crypto->fingerprint(preg_replace('/\D/', '', $ssin) ?? '', 'ssin');
    }

    /** @param array<string, mixed> $r @return array<string, mixed> */
    public static function view(array $r): array
    {
        return [
            'id' => (int) $r['id'],
            'societeId' => (int) $r['employer_id'],
            'erpEmployeeId' => $r['erp_employee_id'] !== null ? (int) $r['erp_employee_id'] : null,
            'shopId' => $r['shop_id'],
            'givenName' => $r['given_name'],
            'familyName' => $r['family_name'],
            'name' => trim($r['given_name'] . ' ' . $r['family_name']),
            'ssin' => !empty($r['ssin_encrypted']) ? Mask::SSIN : null,
            'hasSsin' => !empty($r['ssin_encrypted']),
            'birthDate' => $r['birth_date'],
            'workerType' => $r['worker_type'],
            'jointCommission' => $r['joint_commission'],
            'plannedHours' => $r['planned_hours'] !== null ? (int) $r['planned_hours'] : null,
            'updatedAt' => Clock::iso($r['updated_at'] ?? null),
        ];
    }
}
