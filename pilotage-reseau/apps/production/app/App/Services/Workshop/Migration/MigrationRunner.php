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
 * [295] Mode cohabitation : les numéros de migrations/cohabitation_skip.txt sont « skipped » (ni appliqués ni
 * enregistrés) tant que COHABITATION=1 — ils passent à la bascule.
 */
final class MigrationRunner
{
    public const VERSION = '1';
    private const TABLE = 'n_schema_migration';
    /** Nom d'avant le préfixe n_ (migration 069) : renommée ici, avant de lire l'état des migrations. */
    private const LEGACY_TABLE = 'tfb_' . 'schema_migration';
    private const LOCK = 'tfb_migrate';

    /** @var array<string,string> numéro => raison (vide hors cohabitation) */
    private array $skip = [];

    public function __construct(private PDO $db, private string $directory, ?bool $cohabitation = null)
    {
        if ($cohabitation ?? \App\Core\Cohabitation::on()) {
            $this->skip = self::skipList(dirname(rtrim($directory, '/')) . '/cohabitation_skip.txt');
        }
    }

    /** @return array<string,string> numéro (3 chiffres) => raison */
    public static function skipList(string $file): array
    {
        $out = [];
        foreach (is_file($file) ? (file($file, FILE_IGNORE_NEW_LINES) ?: []) : [] as $line) {
            if (preg_match('/^\s*(\d{3})\s+(.*)$/', $line, $m)) $out[$m[1]] = trim($m[2]);
        }
        return $out;
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
            $state = $done === null ? (isset($this->skip[$version]) ? 'skipped' : 'pending') : ($done['checksum'] === $checksum ? 'applied' : 'drift');
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
                        $this->exec($statement);
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

    /**
     * [298] Exécute une instruction ; si le serveur refuse la syntaxe (MariaDB 10.3 / MySQL 5.7 : « INSERT … SELECT
     * <valeurs> WHERE NOT EXISTS (…) » sans FROM), la rejoue une fois avec « FROM DUAL ». Le fichier et son empreinte ne
     * changent pas : les bases qui l'ont déjà appliqué ne voient aucune différence.
     */
    private function exec(string $statement): void
    {
        try {
            $this->db->exec($statement);
        } catch (\PDOException $e) {
            $compat = ($e->errorInfo[1] ?? null) === 1064 ? self::withFromDual($statement) : null;
            if ($compat === null) throw $e;
            $this->db->exec($compat);
        }
    }

    /**
     * « SELECT <liste> WHERE … » sans FROM au niveau principal → « SELECT <liste> FROM DUAL WHERE … ». Null si
     * l'instruction n'a pas cette forme (chaînes, commentaires et parenthèses ignorés pour trouver le niveau principal).
     */
    public static function withFromDual(string $sql): ?string
    {
        $len = strlen($sql);
        $depth = 0;
        $quote = null;
        $selectAt = null;
        $hasFrom = false;
        for ($i = 0; $i < $len; $i++) {
            $c = $sql[$i];
            if ($quote !== null) {
                if ($c === '\\') { $i++; continue; }
                if ($c === $quote) {
                    if (($sql[$i + 1] ?? '') === $quote) { $i++; continue; }
                    $quote = null;
                }
                continue;
            }
            if ($c === "'" || $c === '"' || $c === '`') { $quote = $c; continue; }
            if ($c === '(') { $depth++; continue; }
            if ($c === ')') { $depth--; continue; }
            if ($depth !== 0 || !ctype_alpha($c) || ($i > 0 && (ctype_alnum($sql[$i - 1]) || $sql[$i - 1] === '_'))) continue;
            $word = strtoupper(substr($sql, $i, 6));
            if (str_starts_with($word, 'SELECT') && !ctype_alnum($sql[$i + 6] ?? ' ')) { $selectAt = $i; $hasFrom = false; continue; }
            if ($selectAt === null) continue;
            if (str_starts_with($word, 'FROM') && !ctype_alnum($sql[$i + 4] ?? ' ')) { $hasFrom = true; continue; }
            if (str_starts_with($word, 'WHERE') && !ctype_alnum($sql[$i + 5] ?? ' ')) {
                return $hasFrom ? null : substr($sql, 0, $i) . 'FROM DUAL ' . substr($sql, $i);
            }
        }
        return null;
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
        $exists = fn(string $table): bool => (bool) $this->db->query('SHOW TABLES LIKE ' . $this->db->quote($table))->fetchColumn();
        if (!$exists(self::TABLE) && $exists(self::LEGACY_TABLE)) {
            $this->db->exec('RENAME TABLE ' . self::LEGACY_TABLE . ' TO ' . self::TABLE);
        }
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
