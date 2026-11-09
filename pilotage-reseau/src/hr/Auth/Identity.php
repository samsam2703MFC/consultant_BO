<?php

declare(strict_types=1);

namespace Hr\Auth;

/**
 * Qui appelle l'API RH. Identité de la console franchisé (comptes bo_users du dépôt WebShop) :
 *   - franchisé (rôle « franchise ») : ses boutiques (bo_user_shops), donc les sociétés qui les exploitent ;
 *     il paramètre et déclare ;
 *   - franchiseur (rôle « siege ») : tout le réseau, en lecture seule.
 */
final class Identity
{
    public const FRANCHISEE = 'franchisee';
    public const FRANCHISOR = 'franchisor';
    public const SYSTEM = 'system';

    /** @param list<string>|null $shopIds null = tout le réseau */
    public function __construct(
        public readonly string $userId,
        public readonly string $name,
        public readonly string $email,
        public readonly string $role,
        public readonly ?array $shopIds,
        public readonly string $source,
    ) {
    }

    public static function system(): self
    {
        return new self('system', 'Relecture automatique', '', self::SYSTEM, null, 'cron');
    }

    public function canWrite(): bool
    {
        return $this->role === self::FRANCHISEE || $this->role === self::SYSTEM;
    }

    public function seesAll(): bool
    {
        return $this->shopIds === null;
    }

    public function hasShop(string $shopId): bool
    {
        return $this->shopIds === null || in_array($shopId, $this->shopIds, true);
    }

    /** @return array<string, mixed> */
    public function view(): array
    {
        return ['id' => $this->userId, 'name' => $this->name, 'email' => $this->email, 'role' => $this->role,
            'shops' => $this->shopIds, 'canWrite' => $this->canWrite() && $this->role !== self::SYSTEM, 'source' => $this->source];
    }
}
