<?php

declare(strict_types=1);

namespace Hr\Dimona;

use Hr\Support\Clock;
use Hr\Support\Config;
use Hr\Support\HttpClient;
use Hr\Support\HttpTransportException;

/**
 * Le jeton d'accès ONSS d'une société (OAuth 2 client_credentials, assertion JWT signée par sa clé privée).
 *
 * Documentation officielle (developer guide REST, « Obtaining an access token from Social Security OAuth
 * server ») : POST x-www-form-urlencoded sur le token endpoint — grant_type=client_credentials,
 * client_assertion_type=urn:ietf:params:oauth:client-assertion-type:jwt-bearer, client_assertion=<JWT> ;
 * `scope` omis (les scopes par défaut du compte Chaman). Le JWT porte jti (unique), iss = sub = Client ID
 * Chaman, aud = l'URL du token endpoint, iat, exp ; il est signé RS256. Le jeton vaut 10 minutes ; on ne le
 * renouvelle que lorsqu'il lui reste moins d'une minute.
 *
 * Ce service ne journalise jamais l'assertion, le jeton ni la clé.
 */
final class DimonaAuthService
{
    public const RENEW_MARGIN = 60;
    private const GRANT_TYPE = 'client_credentials';
    private const ASSERTION_TYPE = 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer';

    public function __construct(private HttpClient $http, private Config $config, private TokenCache $cache)
    {
    }

    /** Le JWT d'assertion (RS256) pour ce compte. */
    public function assertion(Credential $c): string
    {
        $aud = $this->config->tokenUrl($c->env);
        if ($aud === '') {
            throw new DimonaAuthException('URL du serveur de jetons non configurée pour l’environnement ' . $c->env);
        }
        $now = Clock::now();
        return self::sign([
            'jti' => self::uuid4(),
            'iss' => $c->clientId,
            'sub' => $c->clientId,
            'aud' => $aud,
            'iat' => $now,
            'exp' => $now + (int) $this->config->get('assertionTtl'),
        ], $c->privateKeyPem());
    }

    /** @param array<string, mixed> $claims */
    public static function sign(array $claims, string $privateKeyPem): string
    {
        $head = self::b64u((string) json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $body = self::b64u((string) json_encode($claims, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $key = openssl_pkey_get_private($privateKeyPem);
        if ($key === false) {
            throw new DimonaAuthException('clé privée illisible');
        }
        $sig = '';
        if (!openssl_sign($head . '.' . $body, $sig, $key, OPENSSL_ALGO_SHA256)) {
            throw new DimonaAuthException('signature JWT impossible');
        }
        return $head . '.' . $body . '.' . self::b64u($sig);
    }

    /** Un jeton valable encore au moins une minute — du cache, sinon du serveur OAuth. */
    public function token(Credential $c, bool $force = false): string
    {
        if (!$force) {
            $hit = $this->cache->get($c->id);
            if ($hit !== null && $hit['expires_at'] - Clock::now() > self::RENEW_MARGIN) {
                return $hit['token'];
            }
        }
        return $this->fetch($c);
    }

    public function forget(Credential $c): void
    {
        $this->cache->forget($c->id);
    }

    /** @return array{token: string, expires_at: int}|null pour l'écran : quand le jeton en cache expire */
    public function cached(Credential $c): ?array
    {
        return $this->cache->get($c->id);
    }

    private function fetch(Credential $c): string
    {
        $url = $this->config->tokenUrl($c->env);
        $body = http_build_query([
            'grant_type' => self::GRANT_TYPE,
            'client_assertion_type' => self::ASSERTION_TYPE,
            'client_assertion' => $this->assertion($c),
        ], '', '&', PHP_QUERY_RFC1738);
        try {
            $r = $this->http->request('POST', $url, [
                'Content-Type' => 'application/x-www-form-urlencoded; charset=UTF-8',
                'Accept' => 'application/json',
            ], $body, (int) $this->config->get('httpTimeout'));
        } catch (HttpTransportException $e) {
            throw new DimonaAuthException('serveur de jetons injoignable (' . $e->getMessage() . ')');
        }
        $j = $r->json();
        if ($r->status !== 200 || !is_array($j) || empty($j['access_token']) || !is_string($j['access_token'])) {
            $why = '';
            if (is_array($j)) {
                $why = trim((string) ($j['error_description'] ?? $j['error'] ?? $j['message'] ?? ''));
            }
            throw new DimonaAuthException('jeton refusé (HTTP ' . $r->status . ($why !== '' ? ' : ' . mb_substr($why, 0, 200) : '') . ')', $r->status);
        }
        $ttl = isset($j['expires_in']) && is_numeric($j['expires_in']) ? (int) $j['expires_in'] : 600;
        $exp = Clock::now() + max(1, $ttl);
        $this->cache->put($c->id, $j['access_token'], $exp);
        return $j['access_token'];
    }

    public static function uuid4(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }

    public static function b64u(string $s): string
    {
        return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    }

    public static function b64uDecode(string $s): string
    {
        return (string) base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4));
    }
}
