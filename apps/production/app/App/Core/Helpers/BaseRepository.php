<?php
namespace App\Core\Helpers;

use App\Core\Database;
use App\Exceptions\CustomException;
use PDO;
use PDOException;
use Exception;

class BaseRepository {
    protected PDO $db;
    protected string $table; // Nazwa tabeli - każdy model powinien ją określić
    protected $allowedColumns = [];

    public function __construct(PDO $pdo) {
        $this->db = $pdo;
        if (!isset($this->table)) {
            throw new PDOException();
        }
    }

    /**
     * Pobiera wszystkie rekordy z tabeli
     */
    public function findAll(): array {
        $query = "SELECT * FROM {$this->table}";
        try {
            $stmt = $this->db->prepare($query);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            $this->logError($e);
            return [];
        }
    }

    /**
     * Pobiera jeden rekord na podstawie ID
     */
    public function first(array $where = [], array $whereNot = []): array
    {
        $clauses = [];
        $params  = [];

        // BEZPIECZEŃSTWO: upewnij się, że $this->table i nazwy kolumn są z whitelisty,
        // albo otocz nazwę kolumny backtickami: `{$col}` (jeśli to MySQL).
        $fmtCol = static function(string $c): string {
            // minimalne „quotowanie” pod MySQL; dostosuj do swojego DBMS
            return '`' . str_replace('`','``',$c) . '`';
        };

        // = warunki
        $i = 0;
        foreach ($where as $col => $val) {
            $colQ = $fmtCol($col);
            if (is_null($val)) {
                $clauses[] = "{$colQ} IS NULL";
            } elseif (is_array($val) && count($val)) {
                $inPlaceholders = [];
                foreach ($val as $k => $v) {
                    $ph = ":w_{$i}_{$k}";
                    $inPlaceholders[] = $ph;
                    $params[$ph] = $v;
                }
                $clauses[] = "{$colQ} IN (" . implode(',', $inPlaceholders) . ")";
            } else {
                $ph = ":w_{$i}";
                $clauses[] = "{$colQ} = {$ph}";
                $params[$ph] = $val;
            }
            $i++;
        }

        // != warunki
        $j = 0;
        foreach ($whereNot as $col => $val) {
            $colQ = $fmtCol($col);
            if (is_null($val)) {
                $clauses[] = "{$colQ} IS NOT NULL";
            } elseif (is_array($val) && count($val)) {
                $inPlaceholders = [];
                foreach ($val as $k => $v) {
                    $ph = ":n_{$j}_{$k}";
                    $inPlaceholders[] = $ph;
                    $params[$ph] = $v;
                }
                $clauses[] = "{$colQ} NOT IN (" . implode(',', $inPlaceholders) . ")";
            } else {
                $ph = ":n_{$j}";
                $clauses[] = "{$colQ} <> {$ph}";
                $params[$ph] = $val;
            }
            $j++;
        }

        $whereSql = count($clauses) ? (' WHERE ' . implode(' AND ', $clauses)) : '';
        $sql = "SELECT * FROM {$this->table}{$whereSql} LIMIT 1";

        try {
            $stmt = $this->db->prepare($sql);
            if (!$stmt->execute($params)) {
                return [];
            }
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return is_array($row) ? $row : [];
        } catch (PDOException $e) {
            $this->logError($e);
            return [];
        }
    }
//    public function query($sql, $data = [])
//    {
//
//        foreach ($data as $key => $value)
//        {
//            if($value === TRUE) $data[$key] = 1;
//            else if ($value === FALSE) $data[$key] = 0;
//        }
//
//        try{
//            $stmt = $this->db->prepare($sql);
//            $check = $stmt->execute($data);
//        }catch (PDOException $e){
//            echo $e;
//            $this->logError($e);
//            return [];
//        }
//
//        if($check)
//        {
//            $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
//
//            if(is_array($result) && count($result))
//            {
//                return $result;
//            }
//        }
//        return [];
//    }

    public function query($sql, $data = [])
    {
        // Zamiana TRUE/FALSE na 1/0
        foreach ($data as $key => $value) {
            if ($value === true) $data[$key] = 1;
            elseif ($value === false) $data[$key] = 0;
        }

        try {
            $stmt = $this->db->prepare($sql);
            $check = $stmt->execute($data);
        } catch (PDOException $e) {
            echo $e;
            $this->logError($e);
            return false;
        }

        if (!$check) {
            return false;
        }

        // Rozpoznaj typ zapytania po pierwszym słowie
        preg_match('/^\s*(SELECT|INSERT|UPDATE|DELETE)/i', $sql, $matches);
        $queryType = strtoupper($matches[1] ?? '');

        switch ($queryType) {
            case 'SELECT':
                return $stmt->fetchAll(PDO::FETCH_ASSOC);

            case 'INSERT':
                if($this->db->lastInsertId() == 0)
                    return true;
                else
                    return $this->db->lastInsertId();
            case 'UPDATE':
                return $stmt->rowCount();
            case 'DELETE':
                return true; // liczba zmienionych/usuniętych wierszy

            default:
                return true; // fallback dla innych zapytań, np. CREATE, DROP, etc.
        }
    }

    public function executeStatement($sql, $data = [])
    {
        try {
            $stmt = $this->db->prepare($sql);
            return $stmt->execute($data);
        } catch (PDOException $e) {
            echo $e;
            $this->logError($e);
            return false;
        }
    }

    /**
     * Wstawia nowy rekord do bazy
     */
    public function customInsert($query, array $data): int|bool {
        try {
            $stmt = $this->db->prepare($query);
            $stmt->execute($data);
            $resp = $this->db->lastInsertId();

            if($resp == 0)
                return true;
            else
                return $resp;
        } catch (PDOException $e) {
            $this->logError($e);
            throw new CustomException("INSERT_FAILED: " . $e->getMessage(), 'errors', 500);
        }
    }

    public function customUpdate($query, array $data): int|bool {
        try {
            $stmt = $this->db->prepare($query);
            $stmt->execute($data);
            return true;
        } catch (PDOException $e) {
            $this->logError($e);
            throw new CustomException("UPDATE_FAILED: " . $e->getMessage(), 'errors', 500);
        }
    }

    /**
     * Wstawia nowy rekord do bazy
     */
    public function insert(array $data): int|bool {

        foreach ($data as $key => $value)
        {
            if($value === TRUE) $data[$key] = 1;
            else if ($value === FALSE) $data[$key] = 0;
        }

        /**remove unwanted data */
        if(!empty($this->allowedColumns)){
            foreach ($data as $key => $value){
                if(!in_array($key, $this->allowedColumns))
                {
                    unset($data[$key]);
                }
            }
        }
        $keys = array_keys($data);

        $query = "INSERT INTO {$this->table} (".implode(",", $keys).") values (:".implode(",:", $keys).")";

        try {
            $stmt = $this->db->prepare($query);
            $stmt->execute($data);
            $resp = $this->db->lastInsertId();
            if($resp == 0)
                return true;
            else
                return $resp;
        } catch (PDOException $e) {
            $this->logError($e);
            throw new CustomException("INSERT_FAILED: " . $e->getMessage(), 'errors', 500);
        }
    }

    /**
     * Aktualizuje rekord na podstawie ID
     */
    public function update(mixed $id, array $data, $id_column = "id"): bool {
        foreach ($data as $key => $value)
        {
            if($value === TRUE) $data[$key] = 1;
            else if ($value === FALSE) $data[$key] = 0;
        }

        /**remove unwanted data */
        if(!empty($this->allowedColumns)){
            foreach ($data as $key => $value){
                if(!in_array($key, $this->allowedColumns))
                {
                    unset($data[$key]);
                }
            }
        }

        $keys = array_keys($data);
        $query = "UPDATE {$this->table} SET ";

        foreach ($keys as $key){
            $query .= $key . " = :" . $key . ", ";
        }

        $query = trim($query, ", ");
        $query .= " where $id_column = :$id_column ";

        $data[$id_column] = $id;
        try {
            $stmt = $this->db->prepare($query);
            return $stmt->execute($data);
        } catch (PDOException $e) {
            $this->logError($e);
//            throw new CustomException("UPDATE_FAILED: " . $e->getMessage(), 'errors', 500);
            throw new CustomException("UPDATE_FAILED", 'errors', 500);
        }
    }

    /**
     * Usuwa rekord na podstawie ID
     */
    public function delete(int $id, $id_column = 'id'): bool {
        $data[$id_column] = $id;
        $query = "DELETE FROM {$this->table} WHERE $id_column = :$id_column";
        try {
            $stmt = $this->db->prepare($query);
            return $stmt->execute($data);
        } catch (PDOException $e) {
            $this->logError($e);
//            throw new CustomException("DELETE_FAILED: " . $e->getMessage(), 'errors', 500);
            throw new CustomException("DELETE_FAILED", 'errors', 500);
        }
    }


    public function customDelete($query, $data = []): bool {

        foreach ($data as $key => $value)
        {
            if($value === TRUE) $data[$key] = 1;
            else if ($value === FALSE) $data[$key] = 0;
        }

        try {
            $stmt = $this->db->prepare($query);
            return $stmt->execute($data);
        } catch (PDOException $e) {
            $this->logError($e);
//            throw new CustomException("DELETE_FAILED: " . $e->getMessage(), 'errors', 500);
            throw new CustomException("DELETE_FAILED", 'errors', 500);
        }
    }

    /**
     * Logowanie błędów do pliku
     */
    private function logError(PDOException $e): void {
        $date = date("Y-m-d H:i:s");
        error_log("[$date] MYSQL ERROR: {$e->getMessage()}\n", 3, __DIR__ . "/../../Log/mysql_error.log");
    }

    // Helper budujący klauzulę WHERE
    protected function buildFilterClause(array $filters, array $allowedColumns, string $alias = ''): array
    {
        $whereClauses = [];
        $params       = [];

        foreach ($filters as $column => $value) {
            if (! in_array($column, $allowedColumns, true)) {
                continue; // nieznana kolumna
            }
            if ($value === null || $value === '') {
                continue; // pomijamy puste filtry
            }

            $paramName = str_replace('.', '_', $column);
            $colRef    = $alias ? "$alias.$column" : $column;

            if ($column === 'name') {
                // wyszukiwanie częściowe
                $whereClauses[]       = "$colRef LIKE :$paramName";
                $params[$paramName]   = "%{$value}%";
            } else {
                // dokładne porównanie
                $whereClauses[]       = "$colRef = :$paramName";
                $params[$paramName]   = $value;
            }
        }

        // scalanie klauzul
        $whereSql = $whereClauses
            ? implode(' AND ', $whereClauses)
            : '';

        return [$whereSql, $params];
    }
}
