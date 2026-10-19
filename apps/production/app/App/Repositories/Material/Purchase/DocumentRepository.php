<?php
namespace App\Repositories\Material\Purchase;

use App\Core\Helpers\BaseRepository;
use PDO;

class DocumentRepository extends BaseRepository
{
    protected string $table = 'purchase_document';

    protected $allowedColumns = [
        'supplier_id',
        'doc_no',
        'doc_date',
        'currency_code',
        'note',
        'status',
        'posted_at',
    ];

    protected $fetchAllowedColumns = [
        'id',
        'supplier_id',
        'doc_no',
        'doc_date',
        'currency_code',
        'note',
        'status',
        'posted_at',
        'created_at'
    ];

    /**
     * Lista dokumentów pod widok index z agregacją pozycji.
     * @return array<int, array>
     */
    public function search(array $filters, int $limit, int $offset): array
    {
        [$whereSql, $params] = $this->buildFiltersWhere($filters);

        // UWAGA: LIMIT/OFFSET w PDO bywa problematyczny do bindowania w MySQL/MariaDB,
        // dlatego wstawiamy je jako inty po castowaniu.
        $limit  = max(1, (int)$limit);
        $offset = max(0, (int)$offset);

        $sql = "
            SELECT
                d.id,
                d.doc_date,
                d.doc_no,
                d.status,
                d.currency_code,
                s.name AS supplier_name,
                COALESCE(x.items_count, 0) AS items_count,
                COALESCE(x.total_net, 0)   AS total_net
            FROM purchase_document d
            LEFT JOIN supplier s
                ON s.id = d.supplier_id
            LEFT JOIN (
                SELECT
                    purchase_document_id,
                    COUNT(*) AS items_count,
                    SUM(line_total_net) AS total_net
                FROM purchase_item
                GROUP BY purchase_document_id
            ) x ON x.purchase_document_id = d.id
            WHERE 1=1
            {$whereSql}
            ORDER BY d.doc_date DESC, d.id DESC
            LIMIT {$limit} OFFSET {$offset};
        ";

        return $this->query($sql, $params);
    }

    /**
     * Total rekordów pod paginację (te same filtry co search()).
     */
    public function count(array $filters): int
    {
        [$whereSql, $params] = $this->buildFiltersWhere($filters);

        $sql = "
            SELECT COUNT(*) AS cnt
            FROM purchase_document d
            WHERE 1=1
            {$whereSql};
        ";

        $res = $this->query($sql, $params);
        return (int)($res[0]['cnt'] ?? 0);
    }

    /**
     * Buduje wspólny WHERE + parametry.
     * Zwraca: [sql, params]
     */
    private function buildFiltersWhere(array $filters): array
    {
        $sql = "";
        $params = [];

        // supplier
        if (!empty($filters['supplier_id'])) {
            $sql .= " AND d.supplier_id = :supplier_id";
            $params['supplier_id'] = (int)$filters['supplier_id'];
        }

        // status
        if (!empty($filters['status'])) {
            $sql .= " AND d.status = :status";
            $params['status'] = (string)$filters['status'];
        }

        // daty (inclusive)
        if (!empty($filters['date_from'])) {
            $sql .= " AND d.doc_date >= :date_from";
            $params['date_from'] = (string)$filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $sql .= " AND d.doc_date <= :date_to";
            $params['date_to'] = (string)$filters['date_to'];
        }

        // search w doc_no / note
        if (!empty($filters['q'])) {
            $sql .= " AND (d.doc_no LIKE :q OR d.note LIKE :q)";
            $params['q'] = '%' . (string)$filters['q'] . '%';
        }

        return [$sql, $params];
    }

    public function getById($id)
    {
        $sql = "SELECT 
                    doc.*, 
                    supp.name as supplier_name
                FROM {$this->table} doc
                INNER JOIN supplier supp
                    ON doc.supplier_id = supp.id
                WHERE doc.id = :id";
        $res = $this->query($sql, ['id' => $id]);
        return count($res) > 0 ? $res[0] : null;
    }
}
