<?php

namespace App\Services\Ksef;

use RuntimeException;

final class KsefApiException extends RuntimeException
{
    /** @param array<string,mixed> $details */
    public function __construct(
        string $message,
        private readonly ?int $httpStatus = null,
        private readonly ?string $ksefCode = null,
        private readonly ?string $path = null,
        private readonly array $details = [],
    ) {
        parent::__construct($message);
    }

    public function httpStatus(): ?int { return $this->httpStatus; }
    public function ksefCode(): ?string { return $this->ksefCode; }
    public function path(): ?string { return $this->path; }
    /** @return array<string,mixed> */
    public function details(): array { return $this->details; }
}
