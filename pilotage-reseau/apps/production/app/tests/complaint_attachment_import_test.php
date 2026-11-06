<?php

declare(strict_types=1);

require_once __DIR__ . '/../App/Exceptions/CustomException.php';
require_once __DIR__ . '/../App/Core/Helpers/BaseRepository.php';
require_once __DIR__ . '/../App/Repositories/Client/Complaint/ComplaintAttachmentRepository.php';
require_once __DIR__ . '/../App/Services/Client/Complaint/ComplaintService.php';

use App\Exceptions\CustomException;
use App\Repositories\Client\Complaint\ComplaintAttachmentRepository;
use App\Services\Client\Complaint\ComplaintService;

final class InMemoryComplaintAttachmentRepository extends ComplaintAttachmentRepository
{
    public array $rows = [];

    public function __construct()
    {
    }

    public function first(array $where = [], array $whereNot = []): array
    {
        foreach ($this->rows as $row) {
            if (
                $row['complaint_id'] === $where['complaint_id']
                && $row['client_attachment_id'] === $where['client_attachment_id']
            ) {
                return $row;
            }
        }

        return [];
    }

    public function insert(array $data): int|bool
    {
        $this->rows[] = $data;
        return count($this->rows);
    }
}

function fail(string $message): never
{
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
}

$repository = new InMemoryComplaintAttachmentRepository();
$serviceReflection = new ReflectionClass(ComplaintService::class);
$service = $serviceReflection->newInstanceWithoutConstructor();
$property = $serviceReflection->getProperty('complaintAttachmentRepository');
$property->setValue($service, $repository);
$method = $serviceReflection->getMethod('syncCollectorAttachments');

$attachments = [
    ['id' => 10, 'object_key' => 'complaints/shop/a.jpg'],
    ['id' => 11, 'object_key' => 'complaints/shop/b.jpg'],
];

$method->invoke($service, 42, $attachments);
if (count($repository->rows) !== 2) {
    fail('Pierwszy import powinien zapisać oba załączniki.');
}

$method->invoke($service, 42, $attachments);
if (count($repository->rows) !== 2) {
    fail('Ponowny import nie może duplikować załączników.');
}

try {
    $method->invoke($service, 42, [['id' => 10, 'object_key' => 'complaints/shop/other.jpg']]);
    fail('Niezgodny załącznik powinien przerwać import.');
} catch (ReflectionException $exception) {
    fail($exception->getMessage());
} catch (Throwable $exception) {
    if (!$exception instanceof CustomException || $exception->getMessage() !== 'COMPLAINT_ATTACHMENT_MISMATCH') {
        fail('Niezgodny załącznik zwrócił niewłaściwy błąd.');
    }
}

echo "PASS: complaint attachment import idempotency\n";
