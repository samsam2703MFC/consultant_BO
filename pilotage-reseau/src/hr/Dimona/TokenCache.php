<?php

declare(strict_types=1);

namespace Hr\Dimona;

/** Où vit le jeton d'accès ONSS d'un compte entre deux appels. */
interface TokenCache
{
    /** @return array{token: string, expires_at: int}|null */
    public function get(int $credentialId): ?array;

    public function put(int $credentialId, string $token, int $expiresAt): void;

    public function forget(int $credentialId): void;
}
