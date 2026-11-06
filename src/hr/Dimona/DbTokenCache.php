<?php

declare(strict_types=1);

namespace Hr\Dimona;

use Hr\Support\Crypto;
use Hr\Support\Database;

/**
 * Le jeton gardé en base (hr_dimona_tokens), chiffré, pour que l'API et le cron se le partagent : sans cela,
 * chaque requête redemanderait un jeton au serveur OAuth. Une copie en mémoire évite de relire la base
 * plusieurs fois dans la même requête.
 */
final class DbTokenCache implements TokenCache
{
    /** @var array<int, array{token: string, expires_at: int}> */
    private array $memo = [];

    public function __construct(private Crypto $crypto)
    {
    }

    public function get(int $credentialId): ?array
    {
        if (isset($this->memo[$credentialId])) {
            return $this->memo[$credentialId];
        }
        $r = Database::row('SELECT token_encrypted, expires_at FROM hr_dimona_tokens WHERE credential_id = ?', [$credentialId]);
        if ($r === null) {
            return null;
        }
        $tok = $this->crypto->decrypt((string) $r['token_encrypted'], 'token', (string) $credentialId);
        if ($tok === null) {
            return null;
        }
        return $this->memo[$credentialId] = ['token' => $tok, 'expires_at' => (int) $r['expires_at']];
    }

    public function put(int $credentialId, string $token, int $expiresAt): void
    {
        $this->memo[$credentialId] = ['token' => $token, 'expires_at' => $expiresAt];
        $enc = $this->crypto->encrypt($token, 'token', (string) $credentialId);
        Database::transaction(static function () use ($credentialId, $enc, $expiresAt): void {
            Database::exec('DELETE FROM hr_dimona_tokens WHERE credential_id = ?', [$credentialId]);
            Database::insert('hr_dimona_tokens', ['credential_id' => $credentialId, 'token_encrypted' => $enc,
                'expires_at' => $expiresAt, 'obtained_at' => \Hr\Support\Clock::now()]);
        });
    }

    public function forget(int $credentialId): void
    {
        unset($this->memo[$credentialId]);
        Database::exec('DELETE FROM hr_dimona_tokens WHERE credential_id = ?', [$credentialId]);
    }
}
