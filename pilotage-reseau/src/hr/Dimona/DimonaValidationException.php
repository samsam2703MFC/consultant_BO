<?php

declare(strict_types=1);

namespace Hr\Dimona;

/** La déclaration ne respecte pas le YAML (ou une règle métier citée) : rien n'est parti vers l'ONSS. */
final class DimonaValidationException extends \RuntimeException
{
    /** @param list<array{path: string, rule: string, message: string}> $errors */
    public function __construct(public readonly array $errors)
    {
        parent::__construct('déclaration invalide : ' . implode(' ; ', array_map(static fn (array $e): string => $e['path'] . ' ' . $e['message'], $errors)));
    }
}
