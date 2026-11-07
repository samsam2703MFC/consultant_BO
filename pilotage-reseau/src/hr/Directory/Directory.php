<?php

declare(strict_types=1);

namespace Hr\Directory;

/**
 * Ce que le module lit du réseau sans le posséder : les boutiques, leur personnel, et ce que l'employé a
 * déjà encodé dans son profil (app employés). Une interface : en production l'API du panel et le profil
 * chiffré du cockpit, en test et en local un annuaire fixe.
 */
interface Directory
{
    /** @return array<string, string> id de boutique → nom */
    public function shops(): array;

    /** @return list<array{id: int, givenName: string, familyName: string, name: string, active: bool}> */
    public function employees(string $shopId): array;

    /**
     * Ce que l'employé a déjà rempli lui-même (identité, NISS, naissance, statut), ou null.
     *
     * @return array{givenName: ?string, familyName: ?string, birthDate: ?string, ssin: ?string, status: ?string}|null
     */
    public function profile(int $employeeId): ?array;
}
