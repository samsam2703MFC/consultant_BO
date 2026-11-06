<?php

declare(strict_types=1);

namespace Hr\Support;

/**
 * Les tables du module RH (préfixe hr_dimona_, demande de Sam du 01/11/2026), décrites une seule fois et
 * traduites pour MySQL (production) et SQLite (tests). `ensure()` les crée si elles manquent, au premier appel
 * de l'API, comme l'auto-installation du cockpit ; sql/hr_dimona.sql en est la version MySQL lisible
 * (php bin/hr_dimona_schema.php > sql/hr_dimona.sql), vérifiée identique par les tests.
 *
 * Aucune donnée sensible en clair : la clé privée, le NISS, le jeton d'accès et la requête complète sont
 * chiffrés (Hr\Support\Crypto) ; les copies lisibles (request_payload, result_payload) ont le NISS masqué.
 */
final class Schema
{
    public const VERSION = 1;

    /**
     * Colonnes : [nom, type MySQL, type SQLite]. Types communs : 'id' (clé auto), 'ts' (DATETIME UTC).
     *
     * @return array<string, array{columns: list<array{0: string, 1: string, 2?: string}>, primary?: string, unique?: list<list<string>>, index?: list<list<string>>}>
     */
    public static function tables(): array
    {
        return [
            'hr_dimona_meta' => [
                'columns' => [['k', 'VARCHAR(64) NOT NULL'], ['v', 'TEXT NULL']],
                'primary' => 'k',
            ],
            // La société employeuse (un employeur ONSS) ; elle peut exploiter plusieurs boutiques.
            'hr_dimona_employers' => [
                'columns' => [
                    ['id', 'id'],
                    ['name', 'VARCHAR(160) NOT NULL'],
                    ['enterprise_number', 'CHAR(10) NOT NULL'],
                    ['onss_employer_id', 'BIGINT NULL'],
                    ['joint_commission', 'VARCHAR(10) NULL'],
                    ['created_by', 'VARCHAR(64) NULL'],
                    ['created_at', 'ts'],
                    ['updated_at', 'ts'],
                ],
                'unique' => [['enterprise_number']],
            ],
            // Les boutiques (id du panel) d'une société : une boutique n'appartient qu'à une société.
            'hr_dimona_employer_shops' => [
                'columns' => [['shop_id', 'VARCHAR(20) NOT NULL'], ['employer_id', 'BIGINT NOT NULL'], ['created_at', 'ts']],
                'primary' => 'shop_id',
                'index' => [['employer_id']],
            ],
            // Le compte REST Chaman de la société : Client ID + certificat ; la clé privée chiffrée.
            'hr_dimona_credentials' => [
                'columns' => [
                    ['id', 'id'],
                    ['employer_id', 'BIGINT NOT NULL'],
                    ['env', 'VARCHAR(4) NOT NULL'],
                    ['client_id', 'VARCHAR(128) NOT NULL'],
                    ['private_key_encrypted', 'TEXT NOT NULL'],
                    ['certificate_pem', 'TEXT NOT NULL'],
                    ['certificate_subject', 'VARCHAR(255) NULL'],
                    ['certificate_fingerprint', 'CHAR(64) NULL'],
                    ['certificate_expires_at', 'DATETIME NULL'],
                    ['is_active', 'TINYINT NOT NULL DEFAULT 1', 'INTEGER NOT NULL DEFAULT 1'],
                    ['last_test_at', 'DATETIME NULL'],
                    ['last_test_ok', 'TINYINT NULL', 'INTEGER NULL'],
                    ['last_test_message', 'VARCHAR(400) NULL'],
                    ['expiry_alert_at', 'DATETIME NULL'],
                    ['created_by', 'VARCHAR(64) NULL'],
                    ['created_at', 'ts'],
                    ['updated_at', 'ts'],
                ],
                'index' => [['employer_id', 'env', 'is_active']],
            ],
            // Le jeton d'accès ONSS (10 min) gardé entre deux requêtes, chiffré.
            'hr_dimona_tokens' => [
                'columns' => [
                    ['credential_id', 'BIGINT NOT NULL'],
                    ['token_encrypted', 'TEXT NOT NULL'],
                    ['expires_at', 'BIGINT NOT NULL'],
                    ['obtained_at', 'BIGINT NOT NULL'],
                ],
                'primary' => 'credential_id',
            ],
            // La personne déclarée : liée à l'employé du panel quand il existe, sinon saisie à l'embauche.
            'hr_dimona_workers' => [
                'columns' => [
                    ['id', 'id'],
                    ['employer_id', 'BIGINT NOT NULL'],
                    ['erp_employee_id', 'BIGINT NULL'],
                    ['shop_id', 'VARCHAR(20) NULL'],
                    ['given_name', 'VARCHAR(48) NOT NULL'],
                    ['family_name', 'VARCHAR(128) NOT NULL'],
                    ['ssin_encrypted', 'TEXT NULL'],
                    ['ssin_hash', 'CHAR(64) NULL'],
                    ['birth_date', 'DATE NULL'],
                    ['worker_type', 'VARCHAR(3) NULL'],
                    ['joint_commission', 'VARCHAR(10) NULL'],
                    ['planned_hours', 'INT NULL', 'INTEGER NULL'],
                    ['created_by', 'VARCHAR(64) NULL'],
                    ['created_at', 'ts'],
                    ['updated_at', 'ts'],
                ],
                'unique' => [['employer_id', 'ssin_hash'], ['employer_id', 'erp_employee_id']],
                'index' => [['shop_id']],
            ],
            'hr_dimona_declarations' => [
                'columns' => [
                    ['id', 'id'],
                    ['employer_id', 'BIGINT NOT NULL'],
                    ['worker_id', 'BIGINT NOT NULL'],
                    ['erp_employee_id', 'BIGINT NULL'],
                    ['credential_id', 'BIGINT NULL'],
                    ['env', 'VARCHAR(4) NOT NULL'],
                    ['type', 'VARCHAR(6) NOT NULL'],
                    ['request_payload', 'MEDIUMTEXT NOT NULL', 'TEXT NOT NULL'],
                    ['request_encrypted', 'MEDIUMTEXT NULL', 'TEXT NULL'],
                    ['dimona_declaration_id', 'BIGINT NULL'],
                    ['dimona_period_id', 'BIGINT NULL'],
                    ['status', 'VARCHAR(16) NOT NULL'],
                    ['result_code', 'CHAR(1) NULL'],
                    ['result_payload', 'MEDIUMTEXT NULL', 'TEXT NULL'],
                    ['anomalies', 'MEDIUMTEXT NULL', 'TEXT NULL'],
                    ['http_status', 'INT NULL', 'INTEGER NULL'],
                    ['last_error', 'VARCHAR(500) NULL'],
                    ['attempts', 'INT NOT NULL DEFAULT 0', 'INTEGER NOT NULL DEFAULT 0'],
                    ['next_poll_at', 'DATETIME NULL'],
                    ['submitted_at', 'DATETIME NULL'],
                    ['resolved_at', 'DATETIME NULL'],
                    ['submitted_by_user_id', 'VARCHAR(64) NULL'],
                    ['submitted_by_name', 'VARCHAR(120) NULL'],
                    ['created_at', 'ts'],
                    ['updated_at', 'ts'],
                ],
                'unique' => [['dimona_declaration_id']],
                'index' => [['status', 'next_poll_at'], ['employer_id'], ['worker_id']],
            ],
            // La période Dimona en cours (ou close) de chaque personne : ce qu'il faut clôturer, modifier, annuler.
            'hr_dimona_periods' => [
                'columns' => [
                    ['id', 'id'],
                    ['employer_id', 'BIGINT NOT NULL'],
                    ['worker_id', 'BIGINT NOT NULL'],
                    ['dimona_period_id', 'BIGINT NOT NULL'],
                    ['start_date', 'DATE NOT NULL'],
                    ['start_hour', 'CHAR(4) NULL'],
                    ['end_date', 'DATE NULL'],
                    ['end_hour', 'CHAR(4) NULL'],
                    ['worker_type', 'VARCHAR(3) NULL'],
                    ['joint_commission', 'VARCHAR(3) NULL'],
                    ['planned_hours', 'INT NULL', 'INTEGER NULL'],
                    ['status', 'VARCHAR(10) NOT NULL'],
                    ['source', 'VARCHAR(10) NOT NULL'],
                    ['last_declaration_id', 'BIGINT NULL'],
                    ['created_at', 'ts'],
                    ['updated_at', 'ts'],
                ],
                'unique' => [['dimona_period_id']],
                'index' => [['worker_id', 'status'], ['employer_id']],
            ],
            // Qui a fait quoi, quand — sans jamais une donnée sensible dans `detail`.
            'hr_dimona_audit' => [
                'columns' => [
                    ['id', 'id'],
                    ['at', 'ts'],
                    ['user_id', 'VARCHAR(64) NULL'],
                    ['user_name', 'VARCHAR(120) NULL'],
                    ['role', 'VARCHAR(16) NULL'],
                    ['action', 'VARCHAR(48) NOT NULL'],
                    ['employer_id', 'BIGINT NULL'],
                    ['target_type', 'VARCHAR(24) NULL'],
                    ['target_id', 'VARCHAR(64) NULL'],
                    ['detail', 'VARCHAR(500) NULL'],
                    ['ip', 'VARCHAR(45) NULL'],
                ],
                'index' => [['employer_id', 'at'], ['action']],
            ],
        ];
    }

    /** @return list<string> */
    public static function statements(string $driver): array
    {
        $out = [];
        foreach (self::tables() as $name => $t) {
            $cols = [];
            foreach ($t['columns'] as $c) {
                $cols[] = '  ' . $c[0] . ' ' . self::type($driver, $c);
            }
            if (isset($t['primary'])) {
                $cols[] = '  PRIMARY KEY (' . $t['primary'] . ')';
            }
            foreach ($t['unique'] ?? [] as $u) {
                $cols[] = $driver === 'mysql'
                    ? '  UNIQUE KEY ux_' . $name . '_' . implode('_', $u) . ' (' . implode(', ', $u) . ')'
                    : '  UNIQUE (' . implode(', ', $u) . ')';
            }
            if ($driver === 'mysql') {
                foreach ($t['index'] ?? [] as $i) {
                    $cols[] = '  KEY ix_' . $name . '_' . implode('_', $i) . ' (' . implode(', ', $i) . ')';
                }
            }
            $tail = $driver === 'mysql' ? ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : ')';
            $out[] = 'CREATE TABLE IF NOT EXISTS ' . $name . " (\n" . implode(",\n", $cols) . "\n" . $tail;
            if ($driver !== 'mysql') {
                foreach ($t['index'] ?? [] as $i) {
                    $out[] = 'CREATE INDEX IF NOT EXISTS ix_' . $name . '_' . implode('_', $i) . ' ON ' . $name . ' (' . implode(', ', $i) . ')';
                }
            }
        }
        return $out;
    }

    private static function type(string $driver, array $c): string
    {
        if ($c[1] === 'id') {
            return $driver === 'mysql' ? 'BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        }
        if ($c[1] === 'ts') {
            return 'DATETIME NULL';
        }
        return $driver === 'mysql' ? $c[1] : ($c[2] ?? $c[1]);
    }

    public static function mysqlScript(): string
    {
        return "-- Module RH / Dimona — tables hr_dimona_* (version " . self::VERSION . ").\n"
            . "-- Généré par : php bin/hr_dimona_schema.php > sql/hr_dimona.sql — ne pas modifier à la main.\n"
            . "-- Créées aussi automatiquement au premier appel de l'API (Hr\\Support\\Schema::ensure).\n\n"
            . implode(";\n\n", self::statements('mysql')) . ";\n";
    }

    /** Crée ce qui manque, une fois par version (la version est gardée dans hr_dimona_meta). */
    public static function ensure(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $driver = Database::driver();
        try {
            $v = Database::row('SELECT v FROM hr_dimona_meta WHERE k = ?', ['schema_version']);
            if ($v !== null && (int) $v['v'] >= self::VERSION) {
                $done = true;
                return;
            }
        } catch (\PDOException $e) {
            // la table n'existe pas encore : on crée tout
        }
        foreach (self::statements($driver) as $sql) {
            Database::pdo()->exec($sql);
        }
        Database::exec('DELETE FROM hr_dimona_meta WHERE k = ?', ['schema_version']);
        Database::insert('hr_dimona_meta', ['k' => 'schema_version', 'v' => (string) self::VERSION]);
        $done = true;
    }
}
