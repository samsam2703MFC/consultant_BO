<?php
declare(strict_types=1);

namespace App\Core\Api\Workshop;

/**
 * Jetons JWT signés HS256 (RFC 7519). Seul l'algorithme HS256 est accepté : un jeton
 * annonçant un autre algorithme (dont « none ») est refusé.
 */
final class Jwt
{
    private const LEEWAY_S = 30;

    public function __construct(private string $secret)
    {
        if (strlen($secret) < 32) {
            throw new \InvalidArgumentException('JWT secret must be at least 32 bytes.');
        }
    }

    public function encode(array $claims): string
    {
        $header = self::b64(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
        $payload = self::b64(json_encode($claims, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $signature = self::b64(hash_hmac('sha256', $header . '.' . $payload, $this->secret, true));
        return $header . '.' . $payload . '.' . $signature;
    }

    /**
     * @return array<string,mixed> les claims d'un jeton valide
     * @throws JwtException si le jeton est mal formé, mal signé, expiré ou pas encore valable
     */
    public function decode(string $token, string $audience, ?int $now = null): array
    {
        $now ??= time();
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            throw new JwtException('malformed');
        }
        [$header64, $payload64, $signature64] = $parts;

        $header = json_decode(self::unb64($header64), true);
        if (!is_array($header) || ($header['alg'] ?? null) !== 'HS256') {
            throw new JwtException('algorithm');
        }
        $expected = hash_hmac('sha256', $header64 . '.' . $payload64, $this->secret, true);
        if (!hash_equals($expected, self::unb64($signature64))) {
            throw new JwtException('signature');
        }

        $claims = json_decode(self::unb64($payload64), true);
        if (!is_array($claims)) {
            throw new JwtException('malformed');
        }
        if (!isset($claims['exp']) || !is_int($claims['exp']) || $claims['exp'] + self::LEEWAY_S < $now) {
            throw new JwtException('expired');
        }
        if (isset($claims['nbf']) && is_int($claims['nbf']) && $claims['nbf'] - self::LEEWAY_S > $now) {
            throw new JwtException('not_yet_valid');
        }
        if (($claims['aud'] ?? null) !== $audience) {
            throw new JwtException('audience');
        }
        return $claims;
    }

    private static function b64(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function unb64(string $data): string
    {
        $decoded = base64_decode(strtr($data, '-_', '+/') . str_repeat('=', (4 - strlen($data) % 4) % 4), true);
        return $decoded === false ? '' : $decoded;
    }
}
