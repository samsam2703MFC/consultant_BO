<?php
namespace App\Core;

use PDO;

class Database
{
    private PDO $pdo;

    public function __construct() {
        $host = $_ENV['DB_HOST'] ?? getenv('DB_HOST');
        $name = $_ENV['DB_NAME'] ?? getenv('DB_NAME');
        $user = $_ENV['DB_USER'] ?? getenv('DB_USER');
        $pass = $_ENV['DB_PASS'] ?? getenv('DB_PASS');
        $port = $_ENV['DB_PORT'] ?? getenv('DB_PORT');
        // np. tryb MariaDB 10.3 na serwerze MySQL 8 (bez ONLY_FULL_GROUP_BY, NO_ZERO_DATE)
        $sqlMode = $_ENV['DB_SQL_MODE'] ?? getenv('DB_SQL_MODE');

        $dsn = "mysql:host={$host};" . ($port ? "port={$port};" : '') . "dbname={$name};charset=utf8mb4";

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ];
        if ($sqlMode) {
            $options[PDO::MYSQL_ATTR_INIT_COMMAND] = "SET SESSION sql_mode = " . var_export((string) $sqlMode, true);
        }

        $this->pdo = new PDO($dsn, $user, $pass, $options);
    }

    public function getConnection(): PDO {
        return $this->pdo;
    }
}




