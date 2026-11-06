<?php

declare(strict_types=1);

namespace Hr\Http;

/** La requête vue par l'API RH : méthode, chemin après /api/v1/erp/dimona, paramètres, corps JSON, en-têtes. */
final class Request
{
    public const PREFIX = '/api/v1/erp/dimona';

    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $body
     * @param array<string, string> $headers noms en minuscules
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query = [],
        public readonly array $body = [],
        public readonly array $headers = [],
    ) {
    }

    public static function fromGlobals(): self
    {
        $uri = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
        $p = strpos($uri, self::PREFIX);
        $path = $p === false ? '/' : substr($uri, $p + strlen(self::PREFIX));
        $path = '/' . trim($path, '/');
        $headers = [];
        foreach ($_SERVER as $k => $v) {
            if (str_starts_with($k, 'HTTP_')) {
                $headers[strtolower(str_replace('_', '-', substr($k, 5)))] = (string) $v;
            }
        }
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['content-type'] = (string) $_SERVER['CONTENT_TYPE'];
        }
        $raw = (string) file_get_contents('php://input');
        $body = $raw !== '' ? json_decode($raw, true) : [];
        return new self(strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')), $path, $_GET, is_array($body) ? $body : [], $headers);
    }

    public function header(string $name): string
    {
        return $this->headers[strtolower($name)] ?? '';
    }

    public function q(string $key): ?string
    {
        $v = $this->query[$key] ?? null;
        return is_scalar($v) && (string) $v !== '' ? (string) $v : null;
    }

    public function int(string $key): ?int
    {
        $v = $this->q($key);
        return $v !== null && ctype_digit($v) ? (int) $v : null;
    }
}
