<?php
namespace App\Services\Material\Purchase;

use App\Core\TransactionManager;
use App\Exceptions\CustomException;
use App\Repositories\Material\Purchase\DocumentItemRepository;
use App\Repositories\Material\Purchase\DocumentRepository;

class PurchaseService
{
    public function __construct(
        private TransactionManager $transactionManager,
        private DocumentRepository $documentRepository,
        private DocumentItemRepository $documentItemRepository
    ) {}

    /**
     * @return array{rows: array<int, array>, total: int}
     */
    public function search(array $filters, int $page = 1, int $perPage = 20): array
    {
        $page = max(1, (int)$page);
        $perPage = max(1, min(200, (int)$perPage)); // MVP limit na paginację
        $offset = ($page - 1) * $perPage;

        // normalizacja filtrów (żeby repo miało zawsze te same klucze)
        $filters = [
            'supplier_id' => isset($filters['supplier_id']) && $filters['supplier_id'] !== '' ? (int)$filters['supplier_id'] : null,
            'status'      => isset($filters['status']) && $filters['status'] !== '' ? (string)$filters['status'] : null,
            'date_from'   => isset($filters['date_from']) && $filters['date_from'] !== '' ? (string)$filters['date_from'] : null,
            'date_to'     => isset($filters['date_to']) && $filters['date_to'] !== '' ? (string)$filters['date_to'] : null,
            'q'           => isset($filters['q']) && trim((string)$filters['q']) !== '' ? trim((string)$filters['q']) : null,
        ];

        $rows  = $this->documentRepository->search($filters, $perPage, $offset);
        $total = $this->documentRepository->count($filters);

        return [
            'rows'  => $rows,
            'total' => (int)$total
        ];
    }

    public function insert($data)
    {
        if (empty($data['products'])) {
            throw new CustomException("MISSING_PRODUCTS", "error", 400);
        }

        return $this->transactionManager->execute(function () use ($data) {
            $docId = $this->documentRepository->insert([
                'supplier_id'    => $data['supplier_id'],
                'doc_no'         => $data['doc_no'],
                'doc_date'       => $data['doc_date'],
                'currency_code'  => $data['currency_code'],
                'note'           => $data['note'],
                'status'         => $data['status']
            ]);

            if (!$docId) {
                throw new CustomException("FAILED_INSERT_DOCUMENT", "error", 500);
            }

            foreach ($data['products'] as $product) {
                $resInsertItem = $this->documentItemRepository->insert([
                    'purchase_document_id' => $docId,
                    'raw_material_id'      => $product['raw_material_id'],
                    'qty_base'             => $product['qty_base'],
                    'unit_price_net'       => $product['unit_price_net'],
                    'line_total_net'       => $product['line_total_net'],
                    'package_size_base'    => $product['package_size_base'],
                    'package_price_net'    => $product['package_price_net'],
                    'vat_rate'             => $product['vat_rate'],
                ]);

                if (!$resInsertItem) {
                    throw new CustomException("FAILED_INSERT_PRODUCT", "error", 500);
                }
            }

            return $docId;
        });
    }

    public function getById($id)
    {
        return $this->documentRepository->getById($id);
    }
}
