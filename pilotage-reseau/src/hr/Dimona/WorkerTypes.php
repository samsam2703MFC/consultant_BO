<?php

declare(strict_types=1);

namespace Hr\Dimona;

/**
 * Les types de travailleur proposés à l'embauche. Le YAML ne les énumère pas (schéma WorkerType, ligne 2394 :
 * une chaîne de 3 caractères au plus) : les codes et leur sens viennent des instructions administratives
 * ONSS (« Dimona : données principales », types de travailleurs), citées dans le YAML. Le module en retient
 * quatre, ceux d'une boulangerie ; les autres se déclarent sur le portail.
 */
final class WorkerTypes
{
    /** @var array<string, array{label: string, help: string, plannedHours: bool}> */
    public const TYPES = [
        'OTH' => [
            'label' => 'Employé, ouvrier (contrat ordinaire)',
            'help' => 'Les autres travailleurs : un contrat de travail ordinaire, à durée déterminée ou non. Un étudiant pour lequel la cotisation de solidarité ne s’applique pas se déclare aussi ainsi.',
            'plannedHours' => false,
        ],
        'STU' => [
            'label' => 'Étudiant (cotisation de solidarité)',
            'help' => 'Un étudiant sous contrat d’occupation d’étudiant, pour lequel la cotisation de solidarité s’applique. Le nombre d’heures planifiées est obligatoire.',
            'plannedHours' => true,
        ],
        'FLX' => [
            'label' => 'Flexi-job',
            'help' => 'Un travailleur flexi-job.',
            'plannedHours' => false,
        ],
        'EXT' => [
            'label' => 'Extra (travailleur occasionnel)',
            'help' => 'Un travailleur occasionnel : horeca, agriculture, horticulture, pompes funèbres ou intérim.',
            'plannedHours' => false,
        ],
    ];

    public static function exists(?string $code): bool
    {
        return isset(self::TYPES[(string) $code]);
    }

    /**
     * Doc fonctionnelle v2 (§ 5.2, p. 19, citant le glossaire Dimona) : le nombre d'heures planifiées est
     * « obligatoire si la déclaration est faite pour le type de travailleur "STU" (student) et concerne une
     * période après le 01/01/2017 (inclus) ».
     */
    public static function requiresPlannedHours(?string $code): bool
    {
        return (bool) (self::TYPES[(string) $code]['plannedHours'] ?? false);
    }

    /** @return list<array{code: string, label: string, help: string, plannedHours: bool}> */
    public static function forScreen(): array
    {
        $out = [];
        foreach (self::TYPES as $code => $t) {
            $out[] = ['code' => $code] + $t;
        }
        return $out;
    }
}
