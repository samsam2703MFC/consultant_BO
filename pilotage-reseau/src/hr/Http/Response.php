<?php

declare(strict_types=1);

namespace Hr\Http;

/** Une réponse JSON, jamais mise en cache (données personnelles). */
final class Response
{
    /** @param array<string, string> $headers */
    public function __construct(public readonly int $status, public readonly mixed $data, public readonly array $headers = [])
    {
    }

    public static function json(mixed $data, int $status = 200): self
    {
        return new self($status, $data);
    }

    /** @param array<string, string> $fields */
    public static function error(string $message, int $status, array $fields = [], ?string $code = null): self
    {
        $d = ['error' => $message];
        if ($fields !== []) {
            $d['fields'] = $fields;
        }
        if ($code !== null) {
            $d['code'] = $code;
        }
        return new self($status, $d);
    }

    public function emit(): void
    {
        http_response_code($this->status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: same-origin');
        foreach ($this->headers as $k => $v) {
            header($k . ': ' . $v);
        }
        echo json_encode($this->data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
