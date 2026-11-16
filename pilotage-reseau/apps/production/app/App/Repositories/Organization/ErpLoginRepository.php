<?php
declare(strict_types=1);

namespace App\Repositories\Organization;

use App\Repositories\Workshop\WorkshopRepository;

/** [300] Logowanie kodem QR : demandes de connexion (une par navigateur), compte lié à la personne. */
final class ErpLoginRepository extends WorkshopRepository
{
    protected string $table = 'n_erp_login_request';

    public function create(string $nonceHash, string $expiresAt, ?string $userAgent, ?int $forEmployee = null): int
    {
        $this->db->prepare('INSERT INTO n_erp_login_request (nonce_hash, expires_at, user_agent, id_for_employee) VALUES (?, ?, ?, ?)')->execute([$nonceHash, $expiresAt, $userAgent, $forEmployee]);
        return (int) $this->db->lastInsertId();
    }

    public function request(int $id): ?array
    {
        return $this->all('SELECT * FROM n_erp_login_request WHERE id = ?', [$id])[0] ?? null;
    }

    /** Validée sur le téléphone : seulement une demande en cours (ni expirée, ni déjà validée). */
    public function approve(int $id, int $employeeId, int $userId): bool
    {
        $st = $this->db->prepare('UPDATE n_erp_login_request SET id_employee = ?, id_user = ?, approved_at = NOW()
                                  WHERE id = ? AND approved_at IS NULL AND expires_at > NOW()');
        $st->execute([$employeeId, $userId, $id]);
        return $st->rowCount() === 1;
    }

    /** Session ouverte : une seule fois. */
    public function consume(int $id): bool
    {
        $st = $this->db->prepare('UPDATE n_erp_login_request SET used_at = NOW() WHERE id = ? AND approved_at IS NOT NULL AND used_at IS NULL');
        $st->execute([$id]);
        return $st->rowCount() === 1;
    }

    public function purge(): void
    {
        $this->db->exec('DELETE FROM n_erp_login_request WHERE expires_at < NOW() - INTERVAL 1 DAY');
    }

    /** Compte ERP lié à la personne de Czas pracy. */
    public function userOfEmployee(int $employeeId): ?array
    {
        return $this->all('SELECT u.* FROM n_user_employee ue JOIN user u ON u.id = ue.id_user WHERE ue.id_employee = ?', [$employeeId])[0] ?? null;
    }

    /** Personnes actives de Czas pracy avec un compte ERP lié (choix sur la page de connexion). */
    public function linkedPeople(): array
    {
        return $this->all('SELECT ue.id_user, e.id AS employee_id, pe.name, pe.surname FROM n_user_employee ue
                           JOIN n_prod_employee e ON e.id = ue.id_employee AND e.active = 1 JOIN production_employee pe ON pe.id = e.id_production_employee
                           ORDER BY pe.name, pe.surname');
    }

    /** Premier poste du compte (nom de la session). */
    public function positionOf(int $userId): ?array
    {
        return $this->all('SELECT p.code, p.name_pl FROM n_user_position up JOIN n_position p ON p.id = up.id_position WHERE up.id_user = ? ORDER BY p.id LIMIT 1', [$userId])[0] ?? null;
    }

    public function openClock(int $employeeId): ?array
    {
        return $this->all('SELECT id, clock_in FROM n_prod_clock WHERE id_employee = ? AND clock_out IS NULL ORDER BY clock_in DESC LIMIT 1', [$employeeId])[0] ?? null;
    }
}
