<?php

namespace App\Services\Client\Vies;

use InvalidArgumentException;

/** Client for the VIESAPI.eu parsed EU VAT lookup service. */
final class ViesClient
{
    private const API_HOST = 'viesapi.eu';
    private const API_BASE_PATH = '/api';


    private const VIES_COUNTRY_CODES = [
        'AT', 'BE', 'BG', 'CY', 'CZ', 'DE', 'DK', 'EE', 'EL', 'ES', 'FI', 'FR',
        'HR', 'HU', 'IE', 'IT', 'LT', 'LU', 'LV', 'MT', 'NL', 'PL', 'PT', 'RO',
        'SE', 'SI', 'SK', 'XI',
    ];

    /** @return array{vat:string,country_code:string,vat_number:string} */
    public static function normalizeVat(string $vat): array
    {
        $value = strtoupper(trim($vat));
        $value = preg_replace('/[\s.\-\/]+/', '', $value) ?? '';
        if (preg_match('/^\d{10}$/', $value) === 1) $value = 'PL' . $value;

        if (preg_match('/^(?<country>[A-Z]{2})(?<number>[A-Z0-9]{2,14})$/', $value, $matches) !== 1
            || !in_array($matches['country'], self::VIES_COUNTRY_CODES, true)) {
            throw new InvalidArgumentException('Podaj VAT w formacie PL1234567890 lub z prefiksem kraju UE.');
        }

        return ['vat' => $value, 'country_code' => $matches['country'], 'vat_number' => $matches['number']];
    }

    /** @return array{vat:string,country_code:string,valid:bool,company:?string,address:?string,address_fields:?array{fac_street:string,fac_street_num:string,fac_zip:string,fac_town:string},consultation_number:?string,query_uid:?string} */
    public function lookup(string $vat): array
    {
        [$keyId, $key] = self::credentials();
        if ($keyId === '' || $key === '') {
            throw new ViesUnavailableException('Brak konfiguracji klucza VIESAPI.eu. Uzupełnij VIESAPI_KEY_ID i VIESAPI_KEY w pliku .env.');
        }

        $normalized = self::normalizeVat($vat);
        $path = self::API_BASE_PATH . '/get/vies/parsed/euvat/' . rawurlencode($normalized['vat']);
        $response = $this->request($path);

        // Parsed address data is a separate, paid VIESAPI.eu feature. Keep the
        // lookup usable on plans that only include the standard VIES endpoint.
        if ($this->apiErrorCode($response['payload']) === 26) {
            $path = self::API_BASE_PATH . '/get/vies/euvat/' . rawurlencode($normalized['vat']);
            $response = $this->request($path);
        }

        $payload = $response['payload'];
        if ($response['status'] < 200 || $response['status'] >= 300 || isset($payload['error'])) {
            $errorCode = $this->apiErrorCode($payload);
            if (in_array($errorCode, [22, 204, 205], true)) {
                throw new InvalidArgumentException('Numer VAT jest nieprawidłowy.');
            }
            throw new ViesUnavailableException('VIESAPI.eu nie wykonał zapytania. Sprawdź klucz API i spróbuj ponownie później.');
        }

        return self::mapResponse($payload, $normalized);
    }

    /** VIESAPI.eu credentials (API keys tab), read from .env. @return array{0:string,1:string} */
    private static function credentials(): array
    {
        return [
            (string) ($_ENV['VIESAPI_KEY_ID'] ?? getenv('VIESAPI_KEY_ID') ?: ''),
            (string) ($_ENV['VIESAPI_KEY'] ?? getenv('VIESAPI_KEY') ?: ''),
        ];
    }

    /** @return array{status:int,payload:array<string,mixed>} */
    private function request(string $path): array
    {
        [$keyId, $key] = self::credentials();
        $timestamp = (string) time();
        $nonce = bin2hex(random_bytes(6));
        $macInput = implode("\n", [$timestamp, $nonce, 'GET', $path, self::API_HOST, '443', '']) . "\n";
        $mac = base64_encode(hash_hmac('sha256', $macInput, $key, true));
        $authorization = sprintf('MAC id="%s", ts="%s", nonce="%s", mac="%s"', $keyId, $timestamp, $nonce, $mac);

        $handle = curl_init('https://' . self::API_HOST . $path);
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_HTTPGET => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: ' . $authorization,
                'Accept: application/json',
                'User-Agent: SLFood-VIESAPI/1.0 PHP/' . PHP_VERSION,
            ],
        ]);

        $body = curl_exec($handle);
        $errorNo = curl_errno($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        curl_close($handle);

        if ($body === false || $errorNo !== 0) {
            throw new ViesUnavailableException('Nie można połączyć się z VIESAPI.eu. Spróbuj ponownie później.');
        }

        $payload = json_decode((string) $body, true);
        if (!is_array($payload)) {
            throw new ViesUnavailableException('VIESAPI.eu zwrócił nieprawidłową odpowiedź. Spróbuj ponownie później.');
        }

        return ['status' => $status, 'payload' => $payload];
    }

    /** @param array<string,mixed> $payload */
    private function apiErrorCode(array $payload): int
    {
        return (int) ($payload['error']['code'] ?? $payload['code'] ?? 0);
    }

    /**
     * @param array<string, mixed> $payload
     * @param array{vat:string,country_code:string,vat_number:string} $normalized
     * @return array{vat:string,country_code:string,valid:bool,company:?string,address:?string,address_fields:?array{fac_street:string,fac_street_num:string,fac_zip:string,fac_town:string},consultation_number:?string,query_uid:?string}
     */
    public static function mapResponse(array $payload, array $normalized): array
    {
        $vies = is_array($payload['vies'] ?? null) ? $payload['vies'] : $payload;
        $valid = filter_var($vies['valid'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $countryCode = strtoupper((string) ($vies['countryCode'] ?? $normalized['country_code']));
        $vatNumber = strtoupper((string) ($vies['vatNumber'] ?? $normalized['vat_number']));

        return [
            'vat' => $countryCode . $vatNumber,
            'country_code' => $countryCode,
            'valid' => $valid,
            'company' => $valid ? self::nullableValue($vies['traderName'] ?? null) : null,
            'address' => $valid ? self::nullableValue($vies['traderAddress'] ?? null) : null,
            'address_fields' => $valid
                ? (self::mapAddressComponents($vies['traderAddressComponents'] ?? null)
                    ?? self::parseAddress((string) ($vies['traderAddress'] ?? '')))
                : null,
            'consultation_number' => self::nullableValue($vies['id'] ?? null),
            'query_uid' => self::nullableValue($vies['uid'] ?? null),
        ];
    }

    /** @return array{fac_street:string,fac_street_num:string,fac_zip:string,fac_town:string}|null */
    public static function mapAddressComponents(mixed $components): ?array
    {
        if (!is_array($components)) return null;

        $street = self::nullableValue($components['street'] ?? null);
        $streetNumber = self::nullableValue($components['streetNumber'] ?? null);
        $houseNumber = self::nullableValue($components['houseNumber'] ?? null);
        $zip = self::nullableValue($components['postalCode'] ?? null);
        $town = self::nullableValue($components['city'] ?? null);
        if ($street === null || $streetNumber === null || $zip === null || $town === null) return null;

        return [
            'fac_street' => $street,
            'fac_street_num' => $streetNumber . ($houseNumber !== null ? '/' . $houseNumber : ''),
            'fac_zip' => $zip,
            'fac_town' => $town,
        ];
    }

    /**
     * Fallback for plans without getVIESDataParsed. It only accepts a common
     * two-line address where both the building number and postal code are clear.
     *
     * @return array{fac_street:string,fac_street_num:string,fac_zip:string,fac_town:string}|null
     */
    public static function parseAddress(string $address): ?array
    {
        $lines = array_values(array_filter(array_map(
            static fn(string $line): string => trim($line),
            preg_split('/\R/u', trim($address)) ?: []
        ), static fn(string $line): bool => $line !== ''));

        if (count($lines) !== 2) {
            return null;
        }

        if (preg_match('/^(?<street>.+?)\s+(?<number>\d+[[:alpha:]]?(?:[\/-]\d+[[:alpha:]]?)?)$/u', $lines[0], $street) !== 1) {
            return null;
        }

        if (preg_match('/^(?<zip>[[:alnum:]][[:alnum:] -]{1,11})\s+(?<town>[^\d].+)$/u', $lines[1], $town) !== 1) {
            return null;
        }

        return [
            'fac_street' => trim($street['street']),
            'fac_street_num' => trim($street['number']),
            'fac_zip' => trim($town['zip']),
            'fac_town' => trim($town['town']),
        ];
    }

    private static function nullableValue(mixed $value): ?string
    {
        if (!is_scalar($value)) return null;
        $value = trim((string) $value);
        return $value === '' || $value === '---' ? null : $value;
    }
}
