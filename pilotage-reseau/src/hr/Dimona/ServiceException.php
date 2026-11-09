<?php

declare(strict_types=1);

namespace Hr\Dimona;

/** Une erreur à montrer à l'écran : un statut HTTP, un message, et les champs en cause. */
final class ServiceException extends \RuntimeException
{
    /** @param array<string, string> $fields */
    public function __construct(string $message, public readonly int $httpStatus = 400, public readonly array $fields = [])
    {
        parent::__construct($message);
    }
}
