<?php

declare(strict_types=1);

namespace Hr\Repository;

use Hr\Dimona\Credential;
use Hr\Support\Clock;
use Hr\Support\Config;
use Hr\Support\Crypto;
use Hr\Support\Database;

/**
 * Les comptes REST Chaman des sociétés (un certificat par société, validé par Sam). La clé privée est
 * chiffrée (AES-256-GCM, clé maître), liée par AAD à sa société et à son Client ID ; elle ne sort jamais
 * par l'API : `view()` n'en dit que l'existence.
 */
final class CredentialRepository
{
    /** @return array<string, mixed>|null */
    public static function find(int $id): ?array
    {
        return Database::row('SELECT * FROM hr_dimona_credentials WHERE id = ?', [$id]);
    }

    /** @return array<string, mixed>|null le compte actif d'une société pour un environnement */
    public static function active(int $employerId, string $env): ?array
    {
        return Database::row('SELECT * FROM hr_dimona_credentials WHERE employer_id = ? AND env = ? AND is_active = 1 ORDER BY id DESC LIMIT 1', [$employerId, $env]);
    }

    /** @return list<array<string, mixed>> */
    public static function forEmployer(int $employerId): array
    {
        return Database::rows('SELECT * FROM hr_dimona_credentials WHERE employer_id = ? ORDER BY is_active DESC, id DESC', [$employerId]);
    }

    /**
     * Le compte qui déclare pour la société : celui de l'environnement demandé s'il est ouvert, sinon PROD si
     * elle est ouverte et configurée, sinon SIM.
     *
     * @return array<string, mixed>|null
     */
    public static function forDeclaring(int $employerId, Config $config, ?string $env = null): ?array
    {
        $envs = $config->enabledEnvs();
        if ($env !== null) {
            return in_array($env, $envs, true) ? self::active($employerId, $env) : null;
        }
        foreach (array_reverse($envs) as $e) {
            $c = self::active($employerId, $e);
            if ($c !== null) {
                return $c;
            }
        }
        return null;
    }

    /**
     * @param array{employer_id: int, env: string, client_id: string, private_key_pem: string, certificate_pem: string, subject: string, fingerprint: string, expires_at: int} $v
     */
    public static function create(array $v, Crypto $crypto, string $by): int
    {
        return (int) Database::transaction(static function () use ($v, $crypto, $by): int {
            Database::exec('UPDATE hr_dimona_credentials SET is_active = 0, updated_at = ? WHERE employer_id = ? AND env = ? AND is_active = 1',
                [Clock::sql(), $v['employer_id'], $v['env']]);
            return Database::insert('hr_dimona_credentials', [
                'employer_id' => $v['employer_id'],
                'env' => $v['env'],
                'client_id' => $v['client_id'],
                'private_key_encrypted' => $crypto->encrypt($v['private_key_pem'], 'private_key', self::aad($v['employer_id'], $v['client_id'])),
                'certificate_pem' => $v['certificate_pem'],
                'certificate_subject' => $v['subject'],
                'certificate_fingerprint' => $v['fingerprint'],
                'certificate_expires_at' => $v['expires_at'] > 0 ? Clock::sql($v['expires_at']) : null,
                'is_active' => 1,
                'created_by' => $by,
                'created_at' => Clock::sql(),
                'updated_at' => Clock::sql(),
            ]);
        });
    }

    /** @param array<string, mixed> $row */
    public static function toCredential(array $row, Crypto $crypto): Credential
    {
        $key = $crypto->decrypt((string) $row['private_key_encrypted'], 'private_key', self::aad((int) $row['employer_id'], (string) $row['client_id']));
        if ($key === null) {
            throw new \RuntimeException('clé privée du compte ' . $row['id'] . ' indéchiffrable (clé maître changée ?) : redéposez le certificat');
        }
        return new Credential((int) $row['id'], (int) $row['employer_id'], (string) $row['env'], (string) $row['client_id'], $key);
    }

    public static function saveTest(int $id, bool $ok, string $message): void
    {
        Database::update('hr_dimona_credentials', $id, ['last_test_at' => Clock::sql(), 'last_test_ok' => $ok ? 1 : 0,
            'last_test_message' => mb_substr($message, 0, 400), 'updated_at' => Clock::sql()]);
    }

    /** @return list<array<string, mixed>> les comptes actifs dont le certificat expire dans moins de N jours */
    public static function expiring(int $days): array
    {
        return Database::rows('SELECT * FROM hr_dimona_credentials WHERE is_active = 1 AND certificate_expires_at IS NOT NULL AND certificate_expires_at < ?',
            [Clock::sql(Clock::now() + $days * 86400)]);
    }

    public static function markAlerted(int $id): void
    {
        Database::update('hr_dimona_credentials', $id, ['expiry_alert_at' => Clock::sql()]);
    }

    private static function aad(int $employerId, string $clientId): string
    {
        return 'employer:' . $employerId . '|client:' . $clientId;
    }

    /** @param array<string, mixed> $r @return array<string, mixed> */
    public static function view(array $r, int $alertDays = 60): array
    {
        $exp = Clock::ts($r['certificate_expires_at'] ?? null);
        $days = $exp !== null ? (int) floor(($exp - Clock::now()) / 86400) : null;
        return [
            'id' => (int) $r['id'],
            'societeId' => (int) $r['employer_id'],
            'env' => $r['env'],
            'clientId' => $r['client_id'],
            'isActive' => (bool) $r['is_active'],
            'hasPrivateKey' => (string) $r['private_key_encrypted'] !== '',
            'certificateSubject' => $r['certificate_subject'],
            'certificateFingerprint' => $r['certificate_fingerprint'],
            'certificateExpiresAt' => Clock::iso($r['certificate_expires_at'] ?? null),
            'certificateDaysLeft' => $days,
            'certificateAlert' => $days !== null && $days < $alertDays,
            'lastTest' => $r['last_test_at'] !== null ? ['at' => Clock::iso($r['last_test_at']), 'ok' => (bool) $r['last_test_ok'], 'message' => $r['last_test_message']] : null,
            'createdAt' => Clock::iso($r['created_at'] ?? null),
        ];
    }
}
