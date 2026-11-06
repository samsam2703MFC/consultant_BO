<?php

declare(strict_types=1);

namespace Hr\Dimona;

use Hr\Dimona\Dto\DeclarationRequest;

/**
 * Construit la requête POST /declarations (YAML : DeclarationRequest = DimonaBase + un bloc) depuis la
 * société, la personne et la saisie de l'écran.
 *
 * Blocs obligatoires (doc fonctionnelle v2, § 5.1.1.1, p. 10-11) : pour une Dimona IN, employer, worker et
 * dimonaIn ; pour OUT, UPDATE et CANCEL, leur seul bloc (numéro de période + ce qui change).
 *
 * Les règles métier ci-dessous sont seulement celles qu'une source officielle cite ; le reste, l'ONSS le
 * vérifie et le dit en anomalies, que l'écran affiche.
 */
final class PayloadBuilder
{
    public const TYPES = ['IN', 'OUT', 'UPDATE', 'CANCEL'];

    /**
     * @param array{enterprise_number: string, onss_employer_id?: int|string|null} $employer
     * @param array{start_date: string, start_hour?: ?string, end_date?: ?string, end_hour?: ?string, worker_type: string, joint_commission?: ?string, planned_hours?: int|string|null} $in
     */
    public static function in(array $employer, string $ssin, array $in, string $reference): DeclarationRequest
    {
        $emp = ['enterpriseNumber' => self::enterprise((string) $employer['enterprise_number'])];
        if (!empty($employer['onss_employer_id'])) {
            $emp['employerId'] = (int) $employer['onss_employer_id'];
        }
        $block = [
            'references' => ['declaration' => $reference],
            'startDate' => $in['start_date'],
            'startHour' => self::hour($in['start_hour'] ?? null),
            'endDate' => self::opt($in['end_date'] ?? null),
            'endHour' => self::hour($in['end_hour'] ?? null),
            'plannedHoursNumber' => self::int($in['planned_hours'] ?? null),
            'features' => [
                'workerType' => strtoupper((string) $in['worker_type']),
                'jointCommissionNumber' => JointCommission::forDimona($in['joint_commission'] ?? null),
            ],
        ];
        return DeclarationRequest::fromArray([
            'employer' => $emp,
            'worker' => ['ssin' => preg_replace('/\D/', '', $ssin)],
            'dimonaIn' => $block,
        ]);
    }

    public static function out(int $periodId, string $endDate, string $reference): DeclarationRequest
    {
        return DeclarationRequest::fromArray(['dimonaOut' => ['references' => ['declaration' => $reference], 'periodId' => $periodId, 'endDate' => $endDate]]);
    }

    /** @param array{start_date?: ?string, start_hour?: ?string, end_date?: ?string, end_hour?: ?string, planned_hours?: int|string|null} $changes */
    public static function update(int $periodId, array $changes, string $reference): DeclarationRequest
    {
        return DeclarationRequest::fromArray(['dimonaUpdate' => [
            'references' => ['declaration' => $reference],
            'periodId' => $periodId,
            'startDate' => self::opt($changes['start_date'] ?? null),
            'startHour' => self::hour($changes['start_hour'] ?? null),
            'endDate' => self::opt($changes['end_date'] ?? null),
            'endHour' => self::hour($changes['end_hour'] ?? null),
            'plannedHoursNumber' => self::int($changes['planned_hours'] ?? null),
        ]]);
    }

    public static function cancel(int $periodId, string $reference): DeclarationRequest
    {
        return DeclarationRequest::fromArray(['dimonaCancel' => ['references' => ['declaration' => $reference], 'periodId' => $periodId]]);
    }

    /**
     * Les contrôles métier avant tout envoi. Rend [champ => message], vide si la saisie est recevable.
     *
     * @param array<string, mixed> $input
     * @param array<string, mixed>|null $period la période visée (OUT, UPDATE, CANCEL)
     * @return array<string, string>
     */
    public static function check(string $type, array $input, ?array $period = null): array
    {
        $e = [];
        $date = static fn ($v): bool => is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && checkdate((int) substr($v, 5, 2), (int) substr($v, 8, 2), (int) substr($v, 0, 4));
        $hourOk = static fn ($v): bool => $v === null || $v === '' || (bool) preg_match('/^([01]\d|2[0-3]):?[0-5]\d$/', (string) $v);
        if (!in_array($type, self::TYPES, true)) {
            return ['type' => 'type de déclaration inconnu'];
        }
        if ($type !== 'IN' && $period === null) {
            $e['period_id'] = 'aucune période Dimona connue pour cette personne : déclarez d’abord son entrée, ou importez ses périodes depuis l’ONSS';
        }
        foreach (['start_hour', 'end_hour'] as $h) {
            if (!$hourOk($input[$h] ?? null)) {
                $e[$h] = 'heure attendue au format HH:MM';
            }
        }
        if ($type === 'IN') {
            if (!$date($input['start_date'] ?? null)) {
                $e['start_date'] = 'date d’entrée obligatoire';
            }
            if (!empty($input['end_date']) && !$date($input['end_date'])) {
                $e['end_date'] = 'date illisible';
            } elseif (!empty($input['end_date']) && $date($input['start_date'] ?? null) && $input['end_date'] < $input['start_date']) {
                $e['end_date'] = 'la date de fin précède la date d’entrée';
            }
            if (!WorkerTypes::exists($input['worker_type'] ?? null)) {
                $e['worker_type'] = 'type de travailleur à choisir';
            }
            if (WorkerTypes::requiresPlannedHours($input['worker_type'] ?? null)) {
                $h = $input['planned_hours'] ?? null;
                if (!is_numeric($h) || (int) $h < 1 || (int) $h > 999) {
                    $e['planned_hours'] = 'obligatoire pour un étudiant : entre 1 et 999 heures (doc fonctionnelle v2, p. 19)';
                }
            }
            if (!empty($input['joint_commission']) && !JointCommission::isPlausible((string) $input['joint_commission'])) {
                $e['joint_commission'] = 'commission paritaire attendue sous la forme 118, 119 ou 302.00';
            }
        }
        if ($type === 'OUT') {
            if (!$date($input['end_date'] ?? null)) {
                $e['end_date'] = 'date de sortie obligatoire';
            } elseif ($period !== null && $input['end_date'] < (string) $period['start_date']) {
                $e['end_date'] = 'la sortie précède l’entrée (' . $period['start_date'] . ')';
            }
        }
        if ($type === 'UPDATE') {
            $any = false;
            foreach (['start_date', 'end_date', 'start_hour', 'end_hour', 'planned_hours'] as $k) {
                if (isset($input[$k]) && $input[$k] !== '' && $input[$k] !== null) {
                    $any = true;
                }
            }
            if (!$any) {
                $e['changes'] = 'indiquez au moins une donnée à modifier';
            }
            foreach (['start_date', 'end_date'] as $k) {
                if (!empty($input[$k]) && !$date($input[$k])) {
                    $e[$k] = 'date illisible';
                }
            }
            if (isset($input['planned_hours']) && $input['planned_hours'] !== '' && (!is_numeric($input['planned_hours']) || (int) $input['planned_hours'] < 1 || (int) $input['planned_hours'] > 999)) {
                $e['planned_hours'] = 'entre 1 et 999 heures';
            }
        }
        return $e;
    }

    public static function reference(): string
    {
        // References.declaration : 20 caractères au plus (YAML, schéma References).
        return 'HR-' . strtoupper(bin2hex(random_bytes(6)));
    }

    /** « 0123.456.789 » ou « BE 0123456789 » → « 0123456789 » (YAML : EnterpriseNumber ^[0|1]?\d{9}$). */
    public static function enterprise(string $bce): string
    {
        $d = preg_replace('/\D/', '', $bce) ?? '';
        return strlen($d) === 9 ? '0' . $d : $d;
    }

    /** « 08:30 » ou « 0830 » → « 0830 » (YAML : ^[0-2][0-9][0-5][0-9]$). */
    private static function hour(mixed $h): ?string
    {
        if ($h === null || $h === '') {
            return null;
        }
        return str_replace(':', '', (string) $h);
    }

    private static function opt(mixed $v): ?string
    {
        return $v === null || $v === '' ? null : (string) $v;
    }

    private static function int(mixed $v): ?int
    {
        return $v === null || $v === '' || !is_numeric($v) ? null : (int) $v;
    }
}
