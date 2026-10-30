<?php
namespace App\Repositories\Organization;

use App\Repositories\Workshop\WorkshopRepository;

/** Postes, tâches récurrentes par poste (avec catégorie), poste de chaque utilisateur de l'ERP, occurrences. */
final class PositionRepository extends WorkshopRepository
{
    protected string $table = 'n_position';
    protected array $writable = ['code', 'name_pl', 'name_fr', 'description', 'kind', 'id_group', 'id_reports_to', 'sort_order', 'active'];
    /** Pola karty stanowiska (n_position_sheet), każde w wersji _pl i _fr. */
    public const SHEET_FIELDS = ['mission', 'responsibilities', 'skills', 'requirements', 'conditions', 'indicators', 'safety', 'relations'];
    private const TASK_COLUMNS = ['id_position', 'id_category', 'title_pl', 'title_fr', 'frequency', 'due_weekday', 'minutes', 'weekdays', 'link_path', 'form_code', 'auto_code', 'sort_order', 'active'];

    public function positions(): array
    {
        return $this->all(
            "SELECT p.*, (SELECT COUNT(*) FROM n_position_task t WHERE t.id_position = p.id AND t.active = 1) AS task_count,
                    (SELECT COUNT(*) FROM n_user_position up WHERE up.id_position = p.id) AS user_count,
                    (SELECT COUNT(*) FROM n_prod_employee e WHERE e.id_position = p.id) AS employee_count,
                    COALESCE((SELECT IF(s.validated_at IS NULL, 'draft', 'validated') FROM n_position_sheet s WHERE s.id_position = p.id), 'empty') AS sheet_state
             FROM n_position p ORDER BY p.active DESC, p.kind = 'atelier', p.sort_order, p.name_pl"
        );
    }

    public function tasks(?int $positionId = null, bool $activeOnly = false): array
    {
        $where = [];
        $params = [];
        if ($positionId !== null) { $where[] = 't.id_position = ?'; $params[] = $positionId; }
        if ($activeOnly) $where[] = 't.active = 1';
        return $this->all(
            "SELECT t.*, c.code AS category_code, c.name_pl AS category_name, c.name_fr AS category_name_fr, c.icon AS category_icon, c.sort_order AS category_sort
             FROM n_position_task t LEFT JOIN n_task_category c ON c.id = t.id_category" . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
            . " ORDER BY t.id_position, FIELD(t.frequency, 'daily', 'weekly', 'monthly', 'quarterly'), t.sort_order, t.id",
            $params
        );
    }

    public function task(int $id): ?array
    {
        return $this->all('SELECT t.*, c.name_pl AS category_name FROM n_position_task t LEFT JOIN n_task_category c ON c.id = t.id_category WHERE t.id = ?', [$id])[0] ?? null;
    }

    /** Kategorie zadań (ceny, marże, upload, produkcja, zebrania…), z liczbą aktywnych zadań. */
    public function categories(): array
    {
        return $this->all('SELECT c.*, (SELECT COUNT(*) FROM n_position_task t WHERE t.id_category = c.id AND t.active = 1) AS task_count
                           FROM n_task_category c ORDER BY c.active DESC, c.sort_order, c.name_pl');
    }

    public function category(int $id): ?array
    {
        return $this->all('SELECT * FROM n_task_category WHERE id = ?', [$id])[0] ?? null;
    }

    public function categoryCodeTaken(string $code, ?int $exceptId): bool
    {
        return (bool) $this->all('SELECT 1 FROM n_task_category WHERE code = ? AND id <> ?', [$code, $exceptId ?? 0]);
    }

    public function saveCategory(?int $id, array $data): int
    {
        $data = self::ints(array_intersect_key($data, array_flip(['code', 'name_pl', 'name_fr', 'icon', 'sort_order', 'active'])));
        if ($id === null) {
            $columns = array_keys($data);
            $this->db->prepare(sprintf('INSERT INTO n_task_category (%s) VALUES (%s)', implode(', ', $columns), implode(', ', array_fill(0, count($columns), '?'))))
                ->execute(array_values($data));
            return (int) $this->db->lastInsertId();
        }
        if ($data) {
            $set = implode(', ', array_map(fn($c) => "{$c} = ?", array_keys($data)));
            $this->db->prepare("UPDATE n_task_category SET {$set} WHERE id = ?")->execute([...array_values($data), $id]);
        }
        return $id;
    }

    public function insertTask(array $data): int
    {
        $data = self::ints(array_intersect_key($data, array_flip(self::TASK_COLUMNS)));
        $columns = array_keys($data);
        $this->db->prepare(sprintf('INSERT INTO n_position_task (%s) VALUES (%s)', implode(', ', $columns), implode(', ', array_fill(0, count($columns), '?'))))
            ->execute(array_values($data));
        return (int) $this->db->lastInsertId();
    }

    public function updateTask(int $id, array $data): void
    {
        $data = self::ints(array_intersect_key($data, array_flip(self::TASK_COLUMNS)));
        if (!$data) return;
        $set = implode(', ', array_map(fn($c) => "{$c} = ?", array_keys($data)));
        $this->db->prepare("UPDATE n_position_task SET {$set} WHERE id = ?")->execute([...array_values($data), $id]);
    }

    /** Booléens en 0 / 1 (PDO lie false comme chaîne vide, refusée par MySQL strict). */
    private static function ints(array $data): array
    {
        return array_map(fn($v) => is_bool($v) ? (int) $v : $v, $data);
    }

    /** Utilisateurs de l'ERP avec leur poste. */
    public function users(): array
    {
        return $this->all(
            'SELECT u.id, u.displayed_name, u.name, u.surname, u.email, u.login, up.id_position, up.since, p.name_pl AS position_name
             FROM user u LEFT JOIN n_user_position up ON up.id_user = u.id LEFT JOIN n_position p ON p.id = up.id_position
             ORDER BY u.displayed_name, u.login'
        );
    }

    public function user(int $id): ?array
    {
        return $this->all(
            'SELECT u.id, u.displayed_name, u.name, u.surname, u.email, u.phone, u.login, up.id_position, up.since,
                    p.code AS position_code, p.name_pl AS position_name, p.name_fr AS position_name_fr, p.description AS position_description
             FROM user u LEFT JOIN n_user_position up ON up.id_user = u.id LEFT JOIN n_position p ON p.id = up.id_position
             WHERE u.id = ?',
            [$id]
        )[0] ?? null;
    }

    /** Role modułu produkcji (dostęp do API pracowni : planowanie, tablety, kierownik produkcji). */
    public function moduleRoles(): array
    {
        return $this->all("SELECT code, name_pl FROM n_prod_role ORDER BY FIELD(code, 'prod_admin', 'direction', 'prod_manager', 'team_lead', 'operator'), code");
    }

    /** @return list<string> role modułu użytkownika bez grupy */
    public function userModuleRoles(int $userId): array
    {
        return array_column($this->all('SELECT role_code FROM n_prod_user_role WHERE id_user = ? AND id_group IS NULL ORDER BY role_code', [$userId]), 'role_code');
    }

    /** Jedna rola modułu bez grupy (null = bez dostępu do modułu) ; role w grupach zostają. */
    public function setModuleRole(int $userId, ?string $role): void
    {
        $this->db->prepare('DELETE FROM n_prod_user_role WHERE id_user = ? AND id_group IS NULL')->execute([$userId]);
        if ($role !== null) $this->db->prepare('INSERT INTO n_prod_user_role (id_user, role_code) VALUES (?, ?)')->execute([$userId, $role]);
    }

    public function assign(int $userId, ?int $positionId, ?string $since): void
    {
        if ($positionId === null) {
            $this->db->prepare('DELETE FROM n_user_position WHERE id_user = ?')->execute([$userId]);
            return;
        }
        $this->db->prepare('INSERT INTO n_user_position (id_user, id_position, since) VALUES (?, ?, ?)
                            ON DUPLICATE KEY UPDATE id_position = VALUES(id_position), since = VALUES(since)')
            ->execute([$userId, $positionId, $since]);
    }

    public function userExists(int $id): bool
    {
        return (bool) $this->all('SELECT 1 FROM user WHERE id = ?', [$id]);
    }

    /** Crée l'occurrence d'une période si elle n'existe pas encore. */
    public function ensureOccurrence(int $taskId, string $periodKey, string $dueDate): void
    {
        $this->db->prepare('INSERT IGNORE INTO n_task_occurrence (id_task, period_key, due_date) VALUES (?, ?, ?)')->execute([$taskId, $periodKey, $dueDate]);
    }

    /** Occurrences des tâches données : période courante (clés) et toutes celles restées ouvertes avant aujourd'hui. */
    public function occurrences(array $taskIds, array $currentKeys, string $today): array
    {
        if (!$taskIds) return [];
        $in = implode(', ', array_fill(0, count($taskIds), '?'));
        $keys = $currentKeys ?: [''];
        $inKeys = implode(', ', array_fill(0, count($keys), '?'));
        return $this->all(
            "SELECT o.*, u.displayed_name AS done_by_name, f.id AS form_id, f.status AS form_status
             FROM n_task_occurrence o LEFT JOIN user u ON u.id = o.done_by LEFT JOIN n_task_form f ON f.id_occurrence = o.id
             WHERE o.id_task IN ({$in}) AND (o.period_key IN ({$inKeys}) OR (o.status = 'open' AND o.due_date < ?))
             ORDER BY o.due_date, o.id",
            [...$taskIds, ...$keys, $today]
        );
    }

    /**
     * Occurrences du tableau de la semaine : échéance dans la semaine [from, to], périodes en cours (mois, trimestre)
     * et toutes celles restées ouvertes avant la semaine.
     */
    public function weekOccurrences(array $taskIds, array $currentKeys, string $from, string $to): array
    {
        if (!$taskIds) return [];
        $in = implode(', ', array_fill(0, count($taskIds), '?'));
        $keys = $currentKeys ?: [''];
        $inKeys = implode(', ', array_fill(0, count($keys), '?'));
        return $this->all(
            "SELECT o.*, u.displayed_name AS done_by_name, f.id AS form_id, f.status AS form_status
             FROM n_task_occurrence o LEFT JOIN user u ON u.id = o.done_by LEFT JOIN n_task_form f ON f.id_occurrence = o.id
             WHERE o.id_task IN ({$in}) AND (o.due_date BETWEEN ? AND ? OR o.period_key IN ({$inKeys}) OR (o.status = 'open' AND o.due_date < ?))
             ORDER BY o.due_date, o.id",
            [...$taskIds, $from, $to, ...$keys, $from]
        );
    }

    /** Zadanie zaliczone samo (kreator zakończony) : bez osoby, z notatką. */
    public function autoDone(int $occurrenceId, string $note): void
    {
        $this->db->prepare("UPDATE n_task_occurrence SET status = 'done', done_by = NULL, done_at = ?, note = ? WHERE id = ? AND status = 'open'")
            ->execute([date('Y-m-d H:i:s'), $note, $occurrenceId]);
    }

    /**
     * Warunek zaliczenia automatycznego w okresie [from, to) : pozycje faktur sprawdzone (coś sprawdzono w okresie
     * i nic nie czeka), koszty przeliczone, nowe ceny zaplanowane, zapytania ofertowe wysłane.
     */
    public function autoCondition(string $code, string $from, string $to): bool
    {
        $sql = match ($code) {
            'inv_checked' => "SELECT EXISTS (SELECT 1 FROM n_inv_line WHERE checked_at >= ? AND checked_at < ?)
                AND NOT EXISTS (SELECT 1 FROM n_inv_line l JOIN n_inv i ON i.id = l.id_inv WHERE i.kind = 'goods' AND i.status = 'open' AND l.check_status IS NULL) AS ok",
            'costs_recalc' => 'SELECT EXISTS (SELECT 1 FROM n_margin_recalc_run WHERE run_at >= ? AND run_at < ?) AS ok',
            'prices_planned' => "SELECT EXISTS (SELECT 1 FROM n_price_publication WHERE created_at >= ? AND created_at < ? AND status <> 'cancelled') AS ok",
            'buy_sent' => 'SELECT EXISTS (SELECT 1 FROM n_buy_round WHERE sent_at >= ? AND sent_at < ?) AS ok',
            default => null,
        };
        return $sql !== null && (bool) ($this->all($sql, [$from, $to])[0]['ok'] ?? false);
    }

    /** Czas aktywny (s) według ścieżki strony dla użytkowników od dnia (rejestrator aktywności). @return array<string, int> */
    public function activeSecondsByPath(array $userIds, string $since): array
    {
        if (!$userIds) return [];
        $in = implode(', ', array_fill(0, count($userIds), '?'));
        $out = [];
        foreach ($this->all("SELECT path, SUM(active_s) AS s FROM n_activity_view WHERE id_user IN ({$in}) AND started_at >= ? GROUP BY path", [...$userIds, $since]) as $r) {
            $out[$r['path']] = (int) $r['s'];
        }
        return $out;
    }

    /** Otwarte wystąpienia zadania od dnia (bez rozpoczętego protokołu) — po zmianie dni w planie powstaną od nowa. */
    public function dropOpenOccurrences(int $taskId, string $fromDay): void
    {
        $this->db->prepare("DELETE o FROM n_task_occurrence o LEFT JOIN n_task_form f ON f.id_occurrence = o.id
                            WHERE o.id_task = ? AND o.status = 'open' AND o.due_date >= ? AND f.id IS NULL")->execute([$taskId, $fromDay]);
    }

    public function beginTransaction(): void
    {
        $this->db->beginTransaction();
    }

    public function commit(): void
    {
        $this->db->commit();
    }

    public function rollBack(): void
    {
        if ($this->db->inTransaction()) $this->db->rollBack();
    }

    /** Formularz okresu zakończony (zadanie z protokołem). */
    public function formFinal(int $occurrenceId): bool
    {
        return (bool) $this->all("SELECT 1 FROM n_task_form WHERE id_occurrence = ? AND status = 'final'", [$occurrenceId]);
    }

    public function occurrence(int $id): ?array
    {
        return $this->all('SELECT * FROM n_task_occurrence WHERE id = ?', [$id])[0] ?? null;
    }

    public function setOccurrence(int $id, string $status, ?int $userId, ?string $note): void
    {
        $this->db->prepare('UPDATE n_task_occurrence SET status = ?, done_by = ?, done_at = ?, note = ? WHERE id = ?')
            ->execute([$status, $status === 'open' ? null : $userId, $status === 'open' ? null : date('Y-m-d H:i:s'), $note, $id]);
    }

    /** Dernières occurrences traitées des tâches d'un poste (historique de la fiche). */
    public function history(int $positionId, int $limit = 30): array
    {
        return $this->all(
            "SELECT o.*, t.title_pl, t.frequency, u.displayed_name AS done_by_name, f.id AS form_id
             FROM n_task_occurrence o JOIN n_position_task t ON t.id = o.id_task LEFT JOIN user u ON u.id = o.done_by
             LEFT JOIN n_task_form f ON f.id_occurrence = o.id AND f.status = 'final'
             WHERE t.id_position = ? AND o.status <> 'open'
             ORDER BY o.done_at DESC, o.id DESC LIMIT " . max(1, min(200, $limit)),
            [$positionId]
        );
    }

    /** Karta stanowiska (null, gdy jeszcze nie wypełniona). */
    public function sheet(int $positionId): ?array
    {
        return $this->all(
            'SELECT s.*, vu.displayed_name AS validated_by_name, uu.displayed_name AS updated_by_name
             FROM n_position_sheet s LEFT JOIN user vu ON vu.id = s.validated_by LEFT JOIN user uu ON uu.id = s.updated_by
             WHERE s.id_position = ?',
            [$positionId]
        )[0] ?? null;
    }

    /** Zapis treści karty : nowa wersja, zatwierdzenie do ponowienia. */
    public function saveSheet(int $positionId, array $texts, ?int $userId): void
    {
        $columns = array_keys($texts);
        $this->db->prepare(sprintf(
            'INSERT INTO n_position_sheet (id_position, %s, updated_by) VALUES (?, %s, ?)
             ON DUPLICATE KEY UPDATE %s, updated_by = VALUES(updated_by), version = version + 1, validated_at = NULL, validated_by = NULL',
            implode(', ', $columns), implode(', ', array_fill(0, count($columns), '?')),
            implode(', ', array_map(fn($c) => "{$c} = VALUES({$c})", $columns))
        ))->execute([$positionId, ...array_values($texts), $userId]);
    }

    public function validateSheet(int $positionId, ?int $userId): void
    {
        $this->db->prepare('UPDATE n_position_sheet SET validated_at = NOW(), validated_by = ? WHERE id_position = ?')->execute([$userId, $positionId]);
    }

    /** Pracownicy produkcji (z profilem lub bez) i ich stanowisko. */
    public function employees(): array
    {
        return $this->all(
            'SELECT pe.id AS production_employee_id, pe.name, pe.surname, e.id AS profile_id, e.id_position, e.id_primary_group,
                    COALESCE(e.active, 1) AS active
             FROM production_employee pe LEFT JOIN n_prod_employee e ON e.id_production_employee = pe.id
             ORDER BY pe.surname, pe.name'
        );
    }

    /** Stanowisko pracowników produkcji : podani pracownicy dostają stanowisko, pozostali je tracą. */
    public function setEmployees(int $positionId, array $productionEmployeeIds, ?int $groupId): void
    {
        $this->db->prepare('UPDATE n_prod_employee SET id_position = NULL WHERE id_position = ?')->execute([$positionId]);
        $find = $this->db->prepare('SELECT id FROM n_prod_employee WHERE id_production_employee = ?');
        $create = $this->db->prepare('INSERT INTO n_prod_employee (id_production_employee, id_primary_group) VALUES (?, ?)');
        $set = $this->db->prepare('UPDATE n_prod_employee SET id_position = ? WHERE id = ?');
        foreach ($productionEmployeeIds as $peId) {
            $find->execute([$peId]);
            $profile = $find->fetchColumn();
            if ($profile === false) {
                $create->execute([$peId, $groupId]);
                $profile = $this->db->lastInsertId();
            }
            $set->execute([$positionId, (int) $profile]);
        }
    }

    public function productionEmployeeExists(int $id): bool
    {
        return (bool) $this->all('SELECT 1 FROM production_employee WHERE id = ?', [$id]);
    }

    /** @return list<array{id:int,code:string,name_pl:string,name_fr:string}> grupy produkcji */
    public function idByCode(string $code): ?int
    {
        $id = $this->all('SELECT id FROM n_position WHERE code = ?', [$code])[0]['id'] ?? null;
        return $id === null ? null : (int) $id;
    }

    public function groups(): array
    {
        return $this->all('SELECT id, code, name_pl, name_fr FROM n_prod_group ORDER BY sort_order, id');
    }

    public function groupExists(int $id): bool
    {
        return (bool) $this->all('SELECT 1 FROM n_prod_group WHERE id = ?', [$id]);
    }

    /** @return list<string> klucze stron ERP przyznanych stanowisku */
    public function pageGrants(int $positionId): array
    {
        return array_column($this->all('SELECT page_key FROM n_page_access WHERE id_position = ? ORDER BY page_key', [$positionId]), 'page_key');
    }
}
