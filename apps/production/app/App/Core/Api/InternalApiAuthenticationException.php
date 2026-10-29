<?php
declare(strict_types=1);

namespace App\Core\Api;

final class InternalApiAuthenticationException extends \RuntimeException
{
    public function __construct(string $message, private int $httpStatus)
    {
        parent::__construct($message, $httpStatus);
    }

    public function httpStatus(): int
    {
        return $this->httpStatus;
    }
}
