<?php

declare(strict_types=1);

namespace Hr\Auth;

use Hr\Http\Request;

/**
 * Une identité fixe : les tests, et le serveur de développement local (php -S) seulement — le contrôleur
 * frontal ne l'utilise que si PHP_SAPI vaut « cli-server » et que HR_DEV_IDENTITY est posée. Jamais en
 * production : Apache ne passe pas par là.
 */
final class FixedIdentityResolver implements IdentityResolver
{
    public function __construct(private ?Identity $identity)
    {
    }

    /** HR_DEV_IDENTITY = {"id":"dev","name":"…","role":"franchisee","shops":["4"]} */
    public static function fromJson(string $json): self
    {
        $j = json_decode($json, true);
        if (!is_array($j)) {
            return new self(null);
        }
        $role = ($j['role'] ?? '') === Identity::FRANCHISOR ? Identity::FRANCHISOR : Identity::FRANCHISEE;
        return new self(new Identity((string) ($j['id'] ?? 'dev'), (string) ($j['name'] ?? 'Développement'), (string) ($j['email'] ?? ''), $role,
            $role === Identity::FRANCHISOR ? null : array_values(array_map('strval', (array) ($j['shops'] ?? []))), 'dev'));
    }

    public function resolve(Request $r): ?Identity
    {
        return $this->identity;
    }

    public function lastSession(): array
    {
        return ['bo' => null, 'csrf' => null];
    }
}
