<?php
declare(strict_types=1);

namespace App\Repositories\Organization;

use App\Repositories\Workshop\WorkshopRepository;

/** [275] Wejście do ERP przez QR : liaison compte ↔ personne (n_user_employee), postes concernés (n_position.erp_qr), pointages. */
final class ErpEntryRepository extends WorkshopRepository
{
    protected string $table = 'n_user_employee';

    /** Postes du compte avec l'entrée par QR activée (au moins un). */
    public function positionRequiresQr(int $userId): bool
    {
        return (bool) $this->all('SELECT 1 FROM n_user_position up JOIN n_position p ON p.id = up.id_position WHERE up.id_user = ? AND p.erp_qr = 1 AND p.active = 1 LIMIT 1', [$userId]);
    }

    /** Personne de Czas pracy liée au compte (active ou non). */
    public function employeeOf(int $userId): ?array
    {
        return $this->all("SELECT e.id, e.active, TRIM(CONCAT(pe.name, ' ', pe.surname)) AS name FROM n_user_employee ue
                           JOIN n_prod_employee e ON e.id = ue.id_employee JOIN production_employee pe ON pe.id = e.id_production_employee
                           WHERE ue.id_user = ?", [$userId])[0] ?? null;
    }

    public function link(int $userId, ?int $employeeId, ?int $by): void
    {
        $this->db->prepare('DELETE FROM n_user_employee WHERE id_user = ?')->execute([$userId]);
        if ($employeeId === null) return;
        $this->db->prepare('DELETE FROM n_user_employee WHERE id_employee = ?')->execute([$employeeId]);
        $this->db->prepare('INSERT INTO n_user_employee (id_user, id_employee, created_by) VALUES (?, ?, ?)')->execute([$userId, $employeeId, $by]);
    }

    /** Personnes actives (liste de la fiche utilisateur), avec le compte déjà lié. */
    public function employees(): array
    {
        return $this->all("SELECT e.id, TRIM(CONCAT(pe.name, ' ', pe.surname)) AS name, ue.id_user AS linked_user
                           FROM n_prod_employee e JOIN production_employee pe ON pe.id = e.id_production_employee
                           LEFT JOIN n_user_employee ue ON ue.id_employee = e.id
                           WHERE e.active = 1 ORDER BY pe.surname, pe.name");
    }

    public function openClock(int $employeeId): ?array
    {
        return $this->all('SELECT id, clock_in FROM n_prod_clock WHERE id_employee = ? AND clock_out IS NULL ORDER BY clock_in DESC LIMIT 1', [$employeeId])[0] ?? null;
    }

    /** Pointages commencés ce jour-là (minutes travaillées du jour). */
    public function dayClocks(int $employeeId, string $day): array
    {
        return $this->all('SELECT clock_in, clock_out FROM n_prod_clock WHERE id_employee = ? AND clock_in >= ? AND clock_in < ? + INTERVAL 1 DAY ORDER BY clock_in',
            [$employeeId, $day, $day]);
    }

    /** Dernier pointage (entrée ou sortie) depuis l'affichage du QR. */
    public function clockEventSince(int $employeeId, string $since): ?array
    {
        return $this->all('SELECT id, clock_in, clock_out FROM n_prod_clock WHERE id_employee = ? AND (clock_in >= ? OR clock_out >= ?) ORDER BY COALESCE(clock_out, clock_in) DESC LIMIT 1',
            [$employeeId, $since, $since])[0] ?? null;
    }

    /** Postes ERP (bureau) et leur réglage, avec les comptes, leur liaison et le nombre de téléphones appairés. */
    public function positions(): array
    {
        return $this->all("SELECT p.id, p.code, p.name_pl, p.erp_qr, p.sort_order FROM n_position p WHERE p.active = 1 AND p.kind = 'erp' ORDER BY p.sort_order, p.name_pl");
    }

    public function positionUsers(): array
    {
        return $this->all("SELECT up.id_position, u.id, u.displayed_name AS name, ue.id_employee,
                                  (SELECT COUNT(*) FROM n_prod_phone ph WHERE ph.id_employee = ue.id_employee AND ph.revoked_at IS NULL) AS phones
                           FROM n_user_position up JOIN user u ON u.id = up.id_user LEFT JOIN n_user_employee ue ON ue.id_user = u.id ORDER BY u.displayed_name");
    }

    public function setPositionQr(int $positionId, bool $on): bool
    {
        $st = $this->db->prepare("UPDATE n_position SET erp_qr = ? WHERE id = ? AND kind = 'erp'");
        $st->execute([(int) $on, $positionId]);
        return $st->rowCount() > 0 || (bool) $this->all("SELECT 1 FROM n_position WHERE id = ? AND kind = 'erp'", [$positionId]);
    }
}
