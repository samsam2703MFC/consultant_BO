<?php
declare(strict_types=1);

namespace App\Repositories\Workshop;

/**
 * [353] Procedury SOP (makiety 69 i 69 B) : procedury, etapy, zdjęcia (w bazie), przypięcia do stron, wykonania, historia.
 */
final class SopRepository extends WorkshopRepository
{
    protected string $table = 'n_sop';
    protected array $writable = ['code', 'title', 'purpose', 'area', 'performer', 'ppe', 'status', 'version', 'changed', 'review_months', 'review_at',
        'approved_by', 'approved_at', 'created_by', 'updated_by'];

    /** Lista : liczba etapów, przypięcia, ostatnie wykonanie. */
    public function list(): array
    {
        return $this->all("SELECT s.*, (SELECT COUNT(*) FROM n_sop_step t WHERE t.id_sop = s.id) AS steps,
                    (SELECT COUNT(*) FROM n_sop_step t WHERE t.id_sop = s.id AND t.id_photo IS NOT NULL) AS photos,
                    (SELECT GROUP_CONCAT(l.path ORDER BY l.path SEPARATOR '|') FROM n_sop_link l WHERE l.id_sop = s.id) AS links,
                    (SELECT MAX(r.finished_at) FROM n_sop_run r WHERE r.id_sop = s.id) AS last_run,
                    (SELECT COUNT(*) FROM n_sop_run r WHERE r.id_sop = s.id) AS runs
             FROM n_sop s ORDER BY s.status = 'archived', s.code");
    }

    public function byCode(string $code): ?array
    {
        return $this->all('SELECT * FROM n_sop WHERE code = ?', [$code])[0] ?? null;
    }

    public function steps(int $sopId): array
    {
        return $this->all('SELECT * FROM n_sop_step WHERE id_sop = ? ORDER BY sort, id', [$sopId]);
    }

    /** @return list<string> */
    public function links(int $sopId): array
    {
        return array_column($this->all('SELECT path FROM n_sop_link WHERE id_sop = ? ORDER BY path', [$sopId]), 'path');
    }

    /** Etapy i przypięcia zapisane na nowo (jedna transakcja w serwisie). */
    public function replaceSteps(int $sopId, array $steps): void
    {
        $this->db->prepare('DELETE FROM n_sop_step WHERE id_sop = ?')->execute([$sopId]);
        $st = $this->db->prepare('INSERT INTO n_sop_step (id_sop, sort, title, body, tag, minutes, confirm, value_min, value_max, value_unit, id_photo)
                                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        foreach (array_values($steps) as $i => $s) {
            $st->execute([$sopId, $i + 1, $s['title'], $s['body'], $s['tag'], $s['minutes'], $s['confirm'], $s['value_min'], $s['value_max'], $s['value_unit'], $s['photo_id']]);
        }
    }

    public function replaceLinks(int $sopId, array $paths): void
    {
        $this->db->prepare('DELETE FROM n_sop_link WHERE id_sop = ?')->execute([$sopId]);
        $st = $this->db->prepare('INSERT IGNORE INTO n_sop_link (id_sop, path) VALUES (?, ?)');
        foreach ($paths as $p) $st->execute([$sopId, $p]);
    }

    /** Procedury przypięte do strony (obowiązujące i szkice, bez archiwum). */
    public function forPath(string $path): array
    {
        return $this->all("SELECT s.id, s.code, s.title, s.status, s.version FROM n_sop s JOIN n_sop_link l ON l.id_sop = s.id
                           WHERE l.path = ? AND s.status <> 'archived' ORDER BY s.status = 'draft', s.code", [$path]);
    }

    public function insertPhoto(string $mime, string $bytes, ?int $userId): int
    {
        $st = $this->db->prepare('INSERT INTO n_sop_photo (mime, bytes, created_by) VALUES (?, ?, ?)');
        $st->bindValue(1, $mime);
        $st->bindValue(2, $bytes, \PDO::PARAM_LOB);
        $st->bindValue(3, $userId);
        $st->execute();
        return (int) $this->db->lastInsertId();
    }

    public function photo(int $id): ?array
    {
        return $this->all('SELECT mime, bytes FROM n_sop_photo WHERE id = ?', [$id])[0] ?? null;
    }

    public function photoExists(int $id): bool
    {
        return (bool) $this->all('SELECT 1 AS x FROM n_sop_photo WHERE id = ?', [$id]);
    }

    public function event(int $sopId, string $kind, int $version, ?int $userId): void
    {
        $this->db->prepare('INSERT INTO n_sop_event (id_sop, kind, version, by_user) VALUES (?, ?, ?, ?)')->execute([$sopId, $kind, $version, $userId]);
    }

    /** Zatwierdzenia (i utworzenia) dnia : zadanie « SOP dnia ». */
    public function eventsOn(string $day): array
    {
        return $this->all("SELECT e.kind, e.version, e.at, s.code, s.title FROM n_sop_event e JOIN n_sop s ON s.id = e.id_sop
                           WHERE e.at >= ? AND e.at < DATE_ADD(?, INTERVAL 1 DAY) ORDER BY e.at", [$day, $day]);
    }

    public function insertRun(array $r): int
    {
        $this->db->prepare('INSERT INTO n_sop_run (id_sop, version, source_path, id_user, started_at, finished_at, result, steps, id_nc) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$r['id_sop'], $r['version'], $r['source_path'], $r['id_user'], $r['started_at'], $r['finished_at'], $r['result'], $r['steps'], $r['id_nc']]);
        return (int) $this->db->lastInsertId();
    }

    /** Wykonania (ostatnie $limit, opcjonalnie jednej procedury) z nazwą osoby. */
    public function runs(?int $sopId, int $limit = 100): array
    {
        $where = $sopId !== null ? 'WHERE r.id_sop = ' . (int) $sopId : '';
        return $this->all("SELECT r.*, s.code, s.title, TRIM(COALESCE(NULLIF(u.displayed_name, ''), u.login)) AS user_name, n.number AS nc_number
                           FROM n_sop_run r JOIN n_sop s ON s.id = r.id_sop LEFT JOIN user u ON u.id = r.id_user LEFT JOIN n_nc n ON n.id = r.id_nc
                           {$where} ORDER BY r.finished_at DESC, r.id DESC LIMIT " . max(1, min(500, $limit)));
    }

    public function nextCode(string $prefix): string
    {
        $n = 0;
        foreach ($this->all('SELECT code FROM n_sop WHERE code LIKE ?', [$prefix . '-%']) as $r) {
            if (preg_match('/-(\d+)$/', (string) $r['code'], $m)) $n = max($n, (int) $m[1]);
        }
        return sprintf('%s-%03d', $prefix, $n + 1);
    }
}
