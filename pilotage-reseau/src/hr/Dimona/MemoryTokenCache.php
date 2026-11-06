<?php

declare(strict_types=1);

namespace Hr\Dimona;

/** Cache en mémoire (tests, script de vérification). */
final class MemoryTokenCache implements TokenCache
{
    /** @var array<int, array{token: string, expires_at: int}> */
    private array $items = [];

    public function get(int $credentialId): ?array
    {
        return $this->items[$credentialId] ?? null;
    }

    public function put(int $credentialId, string $token, int $expiresAt): void
    {
        $this->items[$credentialId] = ['token' => $token, 'expires_at' => $expiresAt];
    }

    public function forget(int $credentialId): void
    {
        unset($this->items[$credentialId]);
    }
}
