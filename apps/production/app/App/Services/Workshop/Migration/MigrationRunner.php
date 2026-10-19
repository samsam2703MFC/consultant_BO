<?php
declare(strict_types=1);

namespace App\Services\Workshop\Migration;

use PDO;
use RuntimeException;

/**
 * Applique les migrations du module atelier (migrations/tfb/NNN_nom.sql), dans l'ordre,
 * une seule fois chacune. Chaque fichier appliqué est enregistré avec son empreinte : un
 * fichier modifié après coup bloque la suite (on écrit une nouvelle migration à la place).
 * Un verrou MySQL empêche deux exécutions simultanées.
 */
final class MigrationRunner
{
    public const VERSION = '1';
    private const TABLE = 'tfb_schema_migration';
    private const LOCK = 'tfb_migrate';

    public function __construct(private PDO $db, private string $directory)
    {
    }

    /** @return list<array{version:string,name:string,checksum:string,state:string,applied_at:?string}> */
    public function status(): array
    {
        $this->ensureTable();
        $applied = [];
        foreach ($this->db->query('SELECT version, name, checksum, applied_at FROM ' . self::TABLE) as $row) {
            $applied[$row['version']] = $row;
        }

        $rows = [];
        foreach ($this->files() as $version => $file) {
            $checksum = hash_file('sha256', $file);
            $done = $applied[$version] ?? null;
            $state = $done === null ? 'pending' : ($done['checksum'] === $checksum ? 'applied' : 'drift');
            $rows[] = [
                'version' => $version,
                'name' => basename($file),
                'checksum' => $checksum,
                'state' => $state,
                'applied_at' => $done['applied_at'] ?? null,
            ];
            unset($applied[$version]);
        }
        foreach ($applied as $version => $done) {
            $rows[] = ['version' => (string) $version, 'name' => $done['name'], 'checksum' => $done['checksum'], 'state' => 'missing', 'applied_at' => $done['applied_at']];
        }
        return $rows;
    }

    /**
     * @param callable(string):void|null $log
     * @return list<string> versions appliquées
     */
    public function apply(?callable $log = null): array
    {
        $log ??= static function (string $line): void {};
        $this->ensureTable();

        if ((int) $this->db->query("SELECT GET_LOCK('" . self::LOCK . "', 30)")->fetchColumn() !== 1) {
            throw new RuntimeException('Another migration run holds the lock.');
        }

        try {
            $status = $this->status();
            $blocked = array_filter($status, fn($r) => in_array($r['state'], ['drift', 'missing'], true));
            if ($blocked) {
                $names = implode(', ', array_map(fn($r) => $r['name'] . ' (' . $r['state'] . ')', $blocked));
                throw new RuntimeException("Applied migrations differ from the files: {$names}. Write a new migration instead of editing an applied one.");
            }

            $done = [];
            $files = $this->files();
            foreach ($status as $row) {
                if ($row['state'] !== 'pending') continue;
                $started = microtime(true);
                $sql = (string) file_get_contents($files[$row['version']]);
                foreach (self::splitStatements($sql) as $i => $statement) {
                    try {
                        $this->db->exec($statement);
                    } catch (\PDOException $e) {
                        throw new RuntimeException(sprintf('%s, statement %d: %s', $row['name'], $i + 1, $e->getMessage()), 0, $e);
                    }
                }
                $insert = $this->db->prepare('INSERT INTO ' . self::TABLE . ' (version, name, checksum, duration_ms, runner_version) VALUES (?, ?, ?, ?, ?)');
                $insert->execute([$row['version'], $row['name'], $row['checksum'], (int) round((microtime(true) - $started) * 1000), self::VERSION]);
                $log('applied ' . $row['name']);
                $done[] = $row['version'];
            }
            return $done;
        } finally {
            $this->db->query("SELECT RELEASE_LOCK('" . self::LOCK . "')");
        }
    }

    /** @return array<string,string> version => chemin, triés */
    private function files(): array
    {
        $files = [];
        foreach (glob(rtrim($this->directory, '/') . '/*.sql') ?: [] as $file) {
            if (!preg_match('/^(\d{3})_[a-z0-9_]+\.sql$/', basename($file), $m)) {
                throw new RuntimeException('Invalid migration file name: ' . basename($file) . ' (expected NNN_snake_case.sql)');
            }
            if (isset($files[$m[1]])) {
                throw new RuntimeException('Duplicate migration number ' . $m[1]);
            }
            $files[$m[1]] = $file;
        }
        ksort($files, SORT_STRING);
        return $files;
    }

    private function ensureTable(): void
    {
        $this->db->exec('CREATE TABLE IF NOT EXISTS ' . self::TABLE . " (
            version         CHAR(3)      NOT NULL,
            name            VARCHAR(128) NOT NULL,
            checksum        CHAR(64)     NOT NULL,
            applied_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            duration_ms     INT          NOT NULL DEFAULT 0,
            runner_version  VARCHAR(8)   NOT NULL,
            PRIMARY KEY (version)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    }

    /**
     * Découpe un fichier SQL en instructions (« ; » hors chaînes et hors commentaires).
     * @return list<string>
     */
    public static function splitStatements(string $sql): array
    {
        $statements = [];
        $current = '';
        $quote = null;
        $length = strlen($sql);
        for ($i = 0; $i < $length; $i++) {
            $c = $sql[$i];
            if ($quote !== null) {
                $current .= $c;
                if ($c === '\\' && $i + 1 < $length) {
                    $current .= $sql[++$i];
                } elseif ($c === $quote) {
                    if ($i + 1 < $length && $sql[$i + 1] === $quote) {
                        $current .= $sql[++$i];
                    } else {
                        $quote = null;
                    }
                }
                continue;
            }
            if ($c === "'" || $c === '"' || $c === '`') {
                $quote = $c;
                $current .= $c;
            } elseif ($c === '-' && ($sql[$i + 1] ?? '') === '-' || $c === '#') {
                $end = strpos($sql, "\n", $i);
                $i = $end === false ? $length : $end;
                $current .= "\n";
            } elseif ($c === ';') {
                if (trim($current) !== '') $statements[] = trim($current);
                $current = '';
            } else {
                $current .= $c;
            }
        }
        if (trim($current) !== '') $statements[] = trim($current);
        return $statements;
    }
}
