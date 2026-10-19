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

        $dsn = "mysql:host={$host};dbname={$name};charset=utf8mb4";

        $this->pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }

    public function getConnection(): PDO {
        return $this->pdo;
    }
}




