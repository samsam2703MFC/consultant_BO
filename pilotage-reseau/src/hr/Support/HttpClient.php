<?php

declare(strict_types=1);

namespace Hr\Support;

/** Le client HTTP du module : une interface, pour que les tests remplacent le réseau. */
interface HttpClient
{
    /** @param array<string, string> $headers */
    public function request(string $method, string $url, array $headers = [], ?string $body = null, int $timeout = 20): HttpResponse;
}
