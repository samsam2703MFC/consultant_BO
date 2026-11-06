<?php
declare(strict_types=1);

namespace App\Core\Api\Workshop;

/**
 * Qui appelle et ce qu'il a le droit de faire, pour une requête. Refus par défaut :
 * une permission n'existe que si un rôle de l'utilisateur la donne, globalement ou
 * pour un groupe de production précis (rôle limité à un groupe).
 */
final class ApiContext
{
    /**
     * @param list<array{code:string,group_id:?int}> $roles
     * @param array<string,true> $global permissions valables partout
     * @param array<string,array<int,true>> $byGroup permission => groupes autorisés
     * $deviceId / $employeeId : appel d'une tablette (P7), personne identifiée par badge + PIN.
     */
    public function __construct(
        public readonly string $requestId,
        public readonly Translator $translator,
        public readonly ?int $userId = null,
        public readonly array $roles = [],
        private array $global = [],
        private array $byGroup = [],
        public readonly ?int $deviceId = null,
        public readonly ?int $employeeId = null,
        public readonly ?int $deviceGroupId = null,
    ) {
    }

    public function isAuthenticated(): bool
    {
        return $this->userId !== null;
    }

    /** Permission accordée partout, ou au moins pour un groupe. */
    public function canSomewhere(string $permission): bool
    {
        return isset($this->global[$permission]) || !empty($this->byGroup[$permission]);
    }

    public function can(string $permission): bool
    {
        return isset($this->global[$permission]);
    }

    public function canInGroup(string $permission, ?int $groupId): bool
    {
        return isset($this->global[$permission]) || ($groupId !== null && isset($this->byGroup[$permission][$groupId]));
    }

    /** @return list<string> */
    public function permissions(): array
    {
        $all = array_keys($this->global);
        foreach ($this->byGroup as $permission => $groups) {
            if ($groups) $all[] = $permission;
        }
        $all = array_values(array_unique($all));
        sort($all);
        return $all;
    }

    public function require(string $permission): void
    {
        if (!$this->can($permission)) {
            throw new ApiException('FORBIDDEN', 403, [], ['permission' => $permission]);
        }
    }
}
