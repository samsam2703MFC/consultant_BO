<?php

declare(strict_types=1);

namespace Hr\Dimona;

/**
 * Un compte REST Chaman, déchiffré pour la durée d'un appel : Client ID, environnement, clé privée.
 * La clé ne sort jamais de cet objet par accident : ni var_dump, ni print_r, ni json_encode ne la montrent.
 */
final class Credential implements \JsonSerializable
{
    public function __construct(
        public readonly int $id,
        public readonly int $employerId,
        public readonly string $env,
        public readonly string $clientId,
        private readonly string $privateKeyPem,
    ) {
    }

    public function privateKeyPem(): string
    {
        return $this->privateKeyPem;
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return ['id' => $this->id, 'employerId' => $this->employerId, 'env' => $this->env, 'clientId' => $this->clientId, 'privateKeyPem' => '[masqué]'];
    }

    public function jsonSerialize(): mixed
    {
        return $this->__debugInfo();
    }
}
