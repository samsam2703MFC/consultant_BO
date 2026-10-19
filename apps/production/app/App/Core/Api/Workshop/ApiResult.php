<?php
declare(strict_types=1);

namespace App\Core\Api\Workshop;

/** Réponse réussie : {"status":"ok","data":…,"meta":…} avec un statut HTTP. */
final class ApiResult
{
    public function __construct(
        public readonly mixed $data,
        public readonly int $status = 200,
        public readonly ?array $meta = null,
    ) {
    }

    public static function created(mixed $data): self
    {
        return new self($data, 201);
    }

    public static function list(array $items, ?int $total = null): self
    {
        return new self($items, 200, ['total' => $total ?? count($items)]);
    }
}
