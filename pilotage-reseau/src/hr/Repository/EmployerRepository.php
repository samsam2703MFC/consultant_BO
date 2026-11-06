<?php

declare(strict_types=1);

namespace Hr\Repository;

use Hr\Auth\Identity;
use Hr\Support\Clock;
use Hr\Support\Database;

/** Les sociétés employeuses (une société = un employeur ONSS) et leurs boutiques. */
final class EmployerRepository
{
    /** @return array<string, mixed>|null */
    public static function find(int $id): ?array
    {
        $r = Database::row('SELECT * FROM hr_dimona_employers WHERE id = ?', [$id]);
        return $r === null ? null : self::withShops($r);
    }

    /** @return array<string, mixed>|null */
    public static function byEnterprise(string $bce): ?array
    {
        $r = Database::row('SELECT * FROM hr_dimona_employers WHERE enterprise_number = ?', [$bce]);
        return $r === null ? null : self::withShops($r);
    }

    /** @return list<array<string, mixed>> les sociétés visibles par cette identité */
    public static function visibleTo(Identity $who): array
    {
        if ($who->seesAll()) {
            $rows = Database::rows('SELECT * FROM hr_dimona_employers ORDER BY name');
        } else {
            $ids = $who->shopIds ?? [];
            if ($ids === []) {
                return [];
            }
            $rows = Database::rows('SELECT DISTINCT e.* FROM hr_dimona_employers e JOIN hr_dimona_employer_shops s ON s.employer_id = e.id
                WHERE s.shop_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY e.name', $ids);
        }
        return array_map([self::class, 'withShops'], $rows);
    }

    public static function canAccess(Identity $who, int $employerId): bool
    {
        if ($who->seesAll()) {
            return self::find($employerId) !== null;
        }
        foreach (self::shops($employerId) as $s) {
            if ($who->hasShop($s)) {
                return true;
            }
        }
        return false;
    }

    /** @return list<string> */
    public static function shops(int $employerId): array
    {
        return array_map(static fn (array $r): string => (string) $r['shop_id'],
            Database::rows('SELECT shop_id FROM hr_dimona_employer_shops WHERE employer_id = ? ORDER BY shop_id', [$employerId]));
    }

    public static function shopOwner(string $shopId): ?int
    {
        $r = Database::row('SELECT employer_id FROM hr_dimona_employer_shops WHERE shop_id = ?', [$shopId]);
        return $r === null ? null : (int) $r['employer_id'];
    }

    /** @param array<string, mixed> $v @param list<string> $shops */
    public static function create(array $v, array $shops, string $by): int
    {
        return (int) Database::transaction(static function () use ($v, $shops, $by): int {
            $id = Database::insert('hr_dimona_employers', [
                'name' => $v['name'], 'enterprise_number' => $v['enterprise_number'],
                'onss_employer_id' => $v['onss_employer_id'] ?? null, 'joint_commission' => $v['joint_commission'] ?? null,
                'created_by' => $by, 'created_at' => Clock::sql(), 'updated_at' => Clock::sql(),
            ]);
            self::setShops($id, $shops);
            return $id;
        });
    }

    /** @param array<string, mixed> $v */
    public static function update(int $id, array $v): void
    {
        $v['updated_at'] = Clock::sql();
        Database::update('hr_dimona_employers', $id, array_intersect_key($v, array_flip(['name', 'enterprise_number', 'onss_employer_id', 'joint_commission', 'updated_at'])));
    }

    /** @param list<string> $shops */
    public static function setShops(int $id, array $shops): void
    {
        Database::exec('DELETE FROM hr_dimona_employer_shops WHERE employer_id = ?', [$id]);
        foreach (array_values(array_unique($shops)) as $s) {
            Database::insert('hr_dimona_employer_shops', ['shop_id' => $s, 'employer_id' => $id, 'created_at' => Clock::sql()]);
        }
    }

    /** @param array<string, mixed> $r @return array<string, mixed> */
    public static function withShops(array $r): array
    {
        $r['id'] = (int) $r['id'];
        $r['onss_employer_id'] = $r['onss_employer_id'] !== null ? (int) $r['onss_employer_id'] : null;
        $r['shops'] = self::shops((int) $r['id']);
        return $r;
    }

    /** @param array<string, mixed> $r @return array<string, mixed> */
    public static function view(array $r): array
    {
        $bce = (string) $r['enterprise_number'];
        return ['id' => $r['id'], 'name' => $r['name'], 'enterpriseNumber' => $bce,
            'enterpriseNumberFormatted' => strlen($bce) === 10 ? substr($bce, 0, 4) . '.' . substr($bce, 4, 3) . '.' . substr($bce, 7) : $bce,
            'onssEmployerId' => $r['onss_employer_id'], 'jointCommission' => $r['joint_commission'], 'shops' => $r['shops'],
            'updatedAt' => Clock::iso($r['updated_at'] ?? null)];
    }
}
