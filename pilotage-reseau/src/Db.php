<?php
declare(strict_types=1);

/** Connexion PDO unique du cockpit. */
final class Db
{
    private static ?PDO $pdo = null;

    private static ?array $cfg = null;

    /** Configuration applicative (config/config.php, sinon l'exemple). */
    public static function config(): array
    {
        if (self::$cfg === null) {
            $file = __DIR__ . '/../config/config.php';
            self::$cfg = is_file($file) ? require $file : require __DIR__ . '/../config/config.example.php';
        }
        return self::$cfg;
    }

    public static function pdo(): PDO
    {
        if (self::$pdo !== null) {
            return self::$pdo;
        }
        $cfg = self::config();
        $db = $cfg['db'];
        $dsn = "mysql:host={$db['host']};port={$db['port']};dbname={$db['name']};charset={$db['charset']}";
        self::$pdo = new PDO($dsn, $db['user'], $db['password'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        return self::$pdo;
    }

    /** SELECT → toutes les lignes. */
    public static function rows(string $sql, array $params = []): array
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        return $st->fetchAll();
    }

    /** SELECT → première ligne ou null. */
    public static function row(string $sql, array $params = []): ?array
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        $r = $st->fetch();
        return $r === false ? null : $r;
    }

    /** INSERT / UPDATE / DELETE. */
    public static function exec(string $sql, array $params = []): int
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        return $st->rowCount();
    }
}
