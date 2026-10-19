<?php
namespace App\Core;

use App\Exceptions\CustomException;
use PDO;
use Throwable;

class TransactionManager
{
    private PDO $db;
    private int $depth = 0;   // poziom zagnieżdżenia

    public function __construct(PDO $db) // ✅ PDO wstrzykiwane przez DI
    {
        $this->db = $db;
    }

    public function execute(callable $operation)
    {
        $this->start();          // BEGIN lub SAVEPOINT

        try {
            $result = $operation();

            $this->commit();     // COMMIT lub RELEASE SAVEPOINT
            return $result;

        } catch (Throwable $e) { // łapiemy wszystko — CustomException i inne
            $this->rollBack();   // ROLLBACK lub ROLLBACK TO SAVEPOINT
            throw $e;
        }
    }

    /* ---------- prywatne metody pomocnicze ---------- */

    private function start(): void
    {
        if ($this->depth === 0) {
            $this->db->beginTransaction();
        } else {
            $this->db->exec("SAVEPOINT sp{$this->depth}");
        }
        $this->depth++;
    }

    private function commit(): void
    {
        $this->depth--;
        if ($this->depth === 0) {
            $this->db->commit();
        } else {
            $this->db->exec("RELEASE SAVEPOINT sp{$this->depth}");
        }
    }

    private function rollBack(): void
    {
        $this->depth--;
        if ($this->depth === 0) {
            $this->db->rollBack();
        } else {
            $this->db->exec("ROLLBACK TO SAVEPOINT sp{$this->depth}");
        }
    }
}