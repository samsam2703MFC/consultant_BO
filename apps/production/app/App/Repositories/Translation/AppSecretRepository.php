<?php
declare(strict_types=1);

namespace App\Repositories\Translation;

use App\Repositories\Workshop\WorkshopRepository;

/** Sekrety aplikacji wpisane w Ustawieniach (n_app_secret), już zaszyfrowane przez wywołującego. */
final class AppSecretRepository extends WorkshopRepository
{
    protected string $table = 'n_app_secret';

    public function get(string $code): ?array
    {
        try {
            return $this->all('SELECT code, secret, hint, updated_at FROM n_app_secret WHERE code = ?', [$code])[0] ?? null;
        } catch (\PDOException) {
            return null; // przed migracją 117
        }
    }

    public function put(string $code, string $secret, ?string $hint, ?int $userId): void
    {
        $this->db->prepare('INSERT INTO n_app_secret (code, secret, hint, updated_by) VALUES (?, ?, ?, ?)
                            ON DUPLICATE KEY UPDATE secret = VALUES(secret), hint = VALUES(hint), updated_by = VALUES(updated_by)')
            ->execute([$code, $secret, $hint, $userId]);
    }

    public function delete(string $code): void
    {
        $this->db->prepare('DELETE FROM n_app_secret WHERE code = ?')->execute([$code]);
    }
}
