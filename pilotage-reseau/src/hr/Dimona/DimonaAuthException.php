<?php

declare(strict_types=1);

namespace Hr\Dimona;

/** Le serveur OAuth de la sécurité sociale a refusé le compte (ou n'a pas répondu). Message sans secret. */
final class DimonaAuthException extends \RuntimeException
{
    public function __construct(string $message, public readonly int $httpStatus = 0)
    {
        parent::__construct($message);
    }
}
