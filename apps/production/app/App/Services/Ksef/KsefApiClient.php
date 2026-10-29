<?php

namespace App\Services\Ksef;

use RuntimeException;

final class KsefApiClient
{
    private const BASE_URLS = [
        'test' => 'https://api-test.ksef.mf.gov.pl/v2',
        'production' => 'https://api.ksef.mf.gov.pl/v2',
    ];

    /** Verifies the selected environment without using or exposing the stored token. */
    public function checkAvailability(string $environment): string
    {
        $response = $this->request('GET', $this->baseUrl($environment) . '/security/public-key-certificates');
        if (!is_array($response) || $response === []) {
            throw new RuntimeException('KSeF nie zwrócił certyfikatów publicznych.');
        }

        return 'Połączenie z KSeF (' . $environment . ') działa; pobrano certyfikaty publiczne.';
    }

    public function baseUrl(string $environment): string
    {
        if (!isset(self::BASE_URLS[$environment])) {
            throw new RuntimeException('Nieznane środowisko KSeF.');
        }
        return self::BASE_URLS[$environment];
    }

    /** @return array<mixed> */
    public function get(string $environment, string $path, ?string $token = null): array { return $this->request('GET', $this->baseUrl($environment).$path, null, $token); }
    public function getRaw(string $environment, string $path, ?string $token = null): string
    {
        $url = $this->baseUrl($environment) . $path;
        $handle = curl_init($url);
        curl_setopt_array($handle, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_CONNECTTIMEOUT=>5, CURLOPT_TIMEOUT=>15, CURLOPT_HTTPHEADER=>array_filter(['Accept: application/xml', 'X-Error-Format: problem-details', $token ? 'Authorization: Bearer '.$token : null])]);
        $body = curl_exec($handle); $error = curl_error($handle); $status = (int)curl_getinfo($handle, CURLINFO_HTTP_CODE); curl_close($handle);
        if ($body === false || $error !== '') throw new KsefApiException('Nie można połączyć się z KSeF: ' . ($error ?: 'błąd sieci.'), null, null, parse_url($url, PHP_URL_PATH));
        if ($status < 200 || $status >= 300) throw new KsefApiException('KSeF odrzucił pobranie dokumentu (HTTP ' . $status . ').', $status, null, parse_url($url, PHP_URL_PATH));
        return (string)$body;
    }
    public function post(string $environment, string $path, array $payload, ?string $token = null): array { return $this->request('POST', $this->baseUrl($environment).$path, $payload, $token); }
    private function request(string $method, string $url, ?array $payload = null, ?string $token = null): array
    {
        $responseHeaders = [];
        $handle = curl_init($url);
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => array_filter(['Accept: application/json', 'Content-Type: application/json', 'X-Error-Format: problem-details', $token ? 'Authorization: Bearer '.$token : null]),
            CURLOPT_HEADERFUNCTION => static function ($curl, string $header) use (&$responseHeaders): int {
                $parts = explode(':', $header, 2);
                if (count($parts) === 2) $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                return strlen($header);
            },
        ]);
        if ($payload !== null) curl_setopt($handle, CURLOPT_POSTFIELDS, json_encode($payload));
        $body = curl_exec($handle);
        $error = curl_error($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        curl_close($handle);

        if ($body === false || $error !== '') {
            throw new KsefApiException('Nie można połączyć się z KSeF: ' . ($error ?: 'błąd sieci.'), null, null, parse_url($url, PHP_URL_PATH));
        }
        if ($status < 200 || $status >= 300) {
            throw $this->httpException($status, (string) $body, parse_url($url, PHP_URL_PATH), $responseHeaders);
        }

        // Endpoints such as closing an online session correctly return HTTP 204.
        if ($status === 204 || trim((string) $body) === '') return [];

        $decoded = json_decode((string) $body, true);
        if (!is_array($decoded)) {
            throw new KsefApiException('KSeF zwrócił odpowiedź w nieobsługiwanym formacie.', $status, null, parse_url($url, PHP_URL_PATH));
        }
        return $decoded;
    }

    /** @param array<string,string> $headers */
    private function httpException(int $status, string $body, ?string $path, array $headers): KsefApiException
    {
        $payload = json_decode($body, true);
        $payload = is_array($payload) ? $payload : [];
        $code = $this->extractCode($payload);
        $detail = $this->extractDetail($payload);
        $base = match ($status) {
            400 => 'KSeF odrzucił żądanie.',
            401 => 'Uwierzytelnienie w KSeF nie powiodło się. Sprawdź token i jego środowisko.',
            403 => 'Token nie ma wymaganego uprawnienia do tej operacji w KSeF.',
            404 => 'KSeF nie znalazł wskazanego zasobu lub sesji.',
            409 => 'KSeF odrzucił żądanie z powodu konfliktu danych lub stanu sesji.',
            429 => 'KSeF ograniczył liczbę żądań. Spróbuj ponownie później.',
            default => $status >= 500 ? 'Usługa KSeF jest chwilowo niedostępna.' : 'KSeF odrzucił żądanie.',
        };
        if ($detail !== '') $base .= ' ' . $detail;
        if ($status === 429 && isset($headers['retry-after'])) $base .= ' Ponów za około ' . $headers['retry-after'] . ' s.';
        return new KsefApiException($base, $status, $code, $path, $this->safeDetails($payload, $headers));
    }

    /** @param array<string,mixed> $payload */
    private function extractCode(array $payload): ?string
    {
        foreach (['code', 'errorCode'] as $key) if (isset($payload[$key])) return (string) $payload[$key];
        if (isset($payload['status']['code'])) return (string) $payload['status']['code'];
        if (isset($payload['errors'][0]['code'])) return (string) $payload['errors'][0]['code'];
        if (isset($payload['exception']['exceptionDetailList'][0]['exceptionCode'])) return (string) $payload['exception']['exceptionDetailList'][0]['exceptionCode'];
        return null;
    }
    /** @param array<string,mixed> $payload */
    private function extractDetail(array $payload): string
    {
        foreach (['detail', 'description', 'title', 'message'] as $key) if (!empty($payload[$key]) && is_scalar($payload[$key])) return trim((string) $payload[$key]);
        if (isset($payload['errors']) && is_array($payload['errors'])) {
            $first = reset($payload['errors']);
            if (is_array($first)) {
                $detail = trim((string)($first['description'] ?? $first['message'] ?? $first['detail'] ?? ''));
                $extra = $first['details'] ?? [];
                if (is_array($extra)) {
                    $extra = array_filter(array_map(static fn ($item): string => is_scalar($item) ? trim((string) $item) : '', $extra));
                    if ($extra !== []) $detail .= ($detail !== '' ? ' ' : '') . implode(' ', $extra);
                }
                return $detail;
            }
        }
        if (isset($payload['exception']['exceptionDetailList']) && is_array($payload['exception']['exceptionDetailList'])) {
            $first = reset($payload['exception']['exceptionDetailList']);
            if (is_array($first)) {
                $detail = trim((string) ($first['exceptionDescription'] ?? $first['description'] ?? ''));
                $extra = $first['details'] ?? [];
                if (is_array($extra)) {
                    $extra = array_filter(array_map(static fn ($item): string => is_scalar($item) ? trim((string) $item) : '', $extra));
                    if ($extra !== []) $detail .= ($detail !== '' ? ' ' : '') . implode(' ', $extra);
                }
                return $detail;
            }
        }
        return '';
    }
    /** @param array<string,mixed> $payload @param array<string,string> $headers @return array<string,mixed> */
    private function safeDetails(array $payload, array $headers): array
    {
        $details = ['response' => $payload];
        if (isset($headers['retry-after'])) $details['retryAfter'] = $headers['retry-after'];
        return $details;
    }
}
