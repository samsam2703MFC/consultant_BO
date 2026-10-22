<?php
declare(strict_types=1);

namespace App\Repositories\Organization;

use App\Repositories\Workshop\WorkshopRepository;

/** Uprawnienia stanowisk do stron ERP (tfb_page_access ; stanowisko 0 = bez stanowiska) i administratorzy (rola prod_admin). */
final class PageAccessRepository extends WorkshopRepository
{
    protected string $table = 'tfb_page_access';

    /** @return array<int, list<string>> stanowisko => klucze stron */
    public function grants(): array
    {
        $out = [];
        foreach ($this->all('SELECT id_position, page_key FROM tfb_page_access ORDER BY id_position, page_key') as $r) {
            $out[(int) $r['id_position']][] = $r['page_key'];
        }
        return $out;
    }

    /** @return list<string> */
    public function positionGrants(int $positionId): array
    {
        return array_column($this->all('SELECT page_key FROM tfb_page_access WHERE id_position = ?', [$positionId]), 'page_key');
    }

    /** Zastępuje strony stanowiska. */
    public function setGrants(int $positionId, array $keys, ?int $userId): void
    {
        $this->db->prepare('DELETE FROM tfb_page_access WHERE id_position = ?')->execute([$positionId]);
        $insert = $this->db->prepare('INSERT INTO tfb_page_access (id_position, page_key, updated_by) VALUES (?, ?, ?)');
        foreach ($keys as $key) $insert->execute([$positionId, $key, $userId]);
    }

    /** Stanowisko użytkownika (0 = bez stanowiska). */
    public function userPosition(int $userId): int
    {
        return (int) ($this->all('SELECT id_position FROM tfb_user_position WHERE id_user = ?', [$userId])[0]['id_position'] ?? 0);
    }

    public function isProdAdmin(int $userId): bool
    {
        return (bool) $this->all("SELECT 1 FROM tfb_prod_user_role WHERE id_user = ? AND role_code = 'prod_admin' AND id_group IS NULL", [$userId]);
    }

    /** Czy ktokolwiek ma rolę administratora (bez tego konto « admin » zostaje administratorem — bez blokady dostępu). */
    public function anyProdAdmin(): bool
    {
        return (bool) $this->all("SELECT 1 FROM tfb_prod_user_role WHERE role_code = 'prod_admin' AND id_group IS NULL LIMIT 1");
    }

    public function login(int $userId): ?string
    {
        return $this->all('SELECT login FROM user WHERE id = ?', [$userId])[0]['login'] ?? null;
    }

    public function positions(): array
    {
        return $this->all('SELECT id, code, name_pl, active FROM tfb_position ORDER BY active DESC, sort_order, name_pl');
    }

    public function positionExists(int $id): bool
    {
        return (bool) $this->all('SELECT 1 FROM tfb_position WHERE id = ?', [$id]);
    }

    /** Użytkownicy ERP ze stanowiskiem i informacją o roli administratora. */
    public function users(): array
    {
        return $this->all(
            "SELECT u.id, COALESCE(NULLIF(TRIM(u.displayed_name), ''), TRIM(CONCAT(u.name, ' ', u.surname)), u.login) AS name, u.login,
                    COALESCE(up.id_position, 0) AS id_position,
                    EXISTS (SELECT 1 FROM tfb_prod_user_role r WHERE r.id_user = u.id AND r.role_code = 'prod_admin' AND r.id_group IS NULL) AS is_admin
             FROM user u LEFT JOIN tfb_user_position up ON up.id_user = u.id
             ORDER BY name"
        );
    }
}
