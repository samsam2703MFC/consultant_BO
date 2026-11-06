<?php

declare(strict_types=1);

namespace Hr\Dimona;

use Hr\Support\HttpResponse;

/**
 * L'API Dimona a répondu par une erreur (problem+json, YAML : schémas Problem et InputValidationProblem).
 * `issues` reprend les champs refusés tels que l'ONSS les nomme.
 */
final class DimonaApiException extends \RuntimeException
{
    /** @param list<array{in?: string, name?: string, detail?: string, title?: string}> $issues */
    public function __construct(string $message, public readonly int $httpStatus, public readonly array $issues = [])
    {
        parent::__construct($message);
    }

    public static function fromResponse(HttpResponse $r, string $context): self
    {
        $j = $r->json();
        $msg = '';
        $issues = [];
        if (is_array($j)) {
            foreach (['detail', 'title', 'message', 'code'] as $k) {
                if (!empty($j[$k]) && is_string($j[$k])) {
                    $msg = trim($j[$k]);
                    break;
                }
            }
            foreach ((array) ($j['issues'] ?? []) as $i) {
                if (is_array($i)) {
                    $issues[] = array_intersect_key($i, array_flip(['in', 'name', 'detail', 'title', 'type']));
                }
            }
        }
        if ($msg === '') {
            $msg = 'réponse HTTP ' . $r->status;
        }
        return new self($context . ' : ' . mb_substr($msg, 0, 300), $r->status, $issues);
    }
}
