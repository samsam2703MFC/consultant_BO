<?php

declare(strict_types=1);

namespace Hr\Support;

use PDO;

/**
 * La connexion du module RH. En production c'est celle du cockpit (Db::pdo(), base du panel), injectée par
 * le contrôleur frontal ; en test, une base SQLite en mémoire. Le SQL du module s'écrit donc dans le
 * sous-ensemble commun à MySQL et SQLite : pas d'ON DUPLICATE KEY, pas de fonctions propres à l'un.
 */
final class Database
{
    private static ?PDO $pdo = null;

    public static function set(PDO $pdo): void
    {
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        self::$pdo = $pdo;
    }

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            throw new \RuntimeException('base du module RH non branchée');
        }
        return self::$pdo;
    }

    public static function driver(): string
    {
        return (string) self::pdo()->getAttribute(PDO::ATTR_DRIVER_NAME);
    }

    /** @return list<array<string, mixed>> */
    public static function rows(string $sql, array $params = []): array
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        return $st->fetchAll();
    }

    /** @return array<string, mixed>|null */
    public static function row(string $sql, array $params = []): ?array
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        $r = $st->fetch();
        return $r === false ? null : $r;
    }

    public static function exec(string $sql, array $params = []): int
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        return $st->rowCount();
    }

    /** INSERT d'une ligne, rend l'identifiant créé. @param array<string, mixed> $values */
    public static function insert(string $table, array $values): int
    {
        $cols = array_keys($values);
        $sql = 'INSERT INTO ' . $table . ' (' . implode(', ', $cols) . ') VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')';
        self::exec($sql, array_values($values));
        return (int) self::pdo()->lastInsertId();
    }

    /** UPDATE par identifiant. @param array<string, mixed> $values */
    public static function update(string $table, int $id, array $values, string $key = 'id'): int
    {
        if ($values === []) {
            return 0;
        }
        $set = implode(', ', array_map(static fn (string $c): string => $c . ' = ?', array_keys($values)));
        return self::exec('UPDATE ' . $table . ' SET ' . $set . ' WHERE ' . $key . ' = ?', [...array_values($values), $id]);
    }

    /** @param callable(): mixed $fn */
    public static function transaction(callable $fn): mixed
    {
        $pdo = self::pdo();
        if ($pdo->inTransaction()) {
            return $fn();
        }
        $pdo->beginTransaction();
        try {
            $r = $fn();
            $pdo->commit();
            return $r;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
}
