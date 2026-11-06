<?php

declare(strict_types=1);

namespace Hr\Support;

/** Une réponse HTTP brute : statut, en-têtes (noms en minuscules), corps. */
final class HttpResponse
{
    /** @param array<string, string> $headers */
    public function __construct(public int $status, public array $headers, public string $body)
    {
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function json(): mixed
    {
        if ($this->body === '') {
            return null;
        }
        return json_decode($this->body, true);
    }
}
