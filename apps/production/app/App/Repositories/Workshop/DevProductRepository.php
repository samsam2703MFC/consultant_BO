<?php
declare(strict_types=1);

namespace App\Repositories\Workshop;

/**
 * Nowe produkty : produkty testowe ERP (test_product, statusy, typy, etapy) z ustawieniami rozwoju (n_dev_product :
 * pokaż na tablecie, grupa, etap, data docelowa, receptura potwierdzona, produkt wprowadzony, film, notatki),
 * zdjęciami wzorcowymi (n_dev_product_photo) i testami receptury robionymi na tablecie (n_dev_trial, n_dev_trial_photo).
 */
final class DevProductRepository extends WorkshopRepository
{
    protected string $table = 'n_dev_product';

    private const PRODUCT = "SELECT tp.id, tp.name, tp.id_recipe, tp.id_section, tp.id_status, tp.id_type, tp.create_timestamp,
            st.name AS status_name, ty.name AS type_name, ps.name AS section_name, pr.name AS recipe_name,
            COALESCE(d.on_tablet, 0) AS on_tablet, d.video_url, d.notes, d.updated_at,
            d.id_group, COALESCE(d.stage, CASE tp.id_status WHEN 2 THEN 'launch' WHEN 3 THEN 'launched' ELSE 'idea' END) AS stage, d.stage_changed_at, d.target_date, d.weekly_sales, d.season_quarters, d.recipe_confirmed_at, d.id_final_product,
            g.name_pl AS group_pl, g.name_fr AS group_fr, g.color AS group_color, fp.name AS final_product_name,
            TRIM(CONCAT(COALESCE(ce.name, ''), ' ', COALESCE(ce.surname, ''))) AS recipe_confirmed_name,
            (SELECT COUNT(*) FROM n_dev_product_photo ph WHERE ph.id_test_product = tp.id) AS photo_count,
            (SELECT COUNT(*) FROM n_dev_card dc WHERE dc.id_test_product = tp.id AND dc.card_json IS NOT NULL) AS has_card,
            (SELECT COUNT(*) FROM n_dev_trial tr WHERE tr.id_test_product = tp.id AND tr.status = 'done') AS trials_done,
            (SELECT tr.result FROM n_dev_trial tr WHERE tr.id_test_product = tp.id AND tr.status = 'done' ORDER BY tr.trial_no DESC LIMIT 1) AS last_result,
            (SELECT tr.done_at FROM n_dev_trial tr WHERE tr.id_test_product = tp.id AND tr.status = 'done' ORDER BY tr.trial_no DESC LIMIT 1) AS last_trial_at,
            (SELECT MIN(tr.planned_date) FROM n_dev_trial tr WHERE tr.id_test_product = tp.id AND tr.status = 'planned') AS next_trial_date,
            (SELECT MIN(tr.trial_no) FROM n_dev_trial tr WHERE tr.id_test_product = tp.id AND tr.status = 'planned') AS next_trial_no
        FROM test_product tp
        LEFT JOIN test_product_status st ON st.id = tp.id_status
        LEFT JOIN test_product_type ty ON ty.id = tp.id_type
        LEFT JOIN production_sections ps ON ps.id = tp.id_section
        LEFT JOIN product_recipe pr ON pr.id = tp.id_recipe
        LEFT JOIN n_dev_product d ON d.id_test_product = tp.id
        LEFT JOIN n_prod_group g ON g.id = d.id_group
        LEFT JOIN final_product fp ON fp.id = d.id_final_product
        LEFT JOIN n_prod_employee cne ON cne.id = d.recipe_confirmed_by
        LEFT JOIN production_employee ce ON ce.id = cne.id_production_employee";

    /**
     * Produkty pokazane na tablecie grupy : przypisane tej grupie ; bez grupy — jak dotąd według działu (bez działu =
     * wszystkie tablety). Wprowadzone i wstrzymane już nie.
     */
    public function forGroup(int $groupId): array
    {
        return $this->all(self::PRODUCT . " WHERE d.on_tablet = 1 AND COALESCE(d.stage, 'idea') NOT IN ('launched', 'stopped')
            AND (d.id_group = ? OR (d.id_group IS NULL AND (tp.id_section IS NULL OR tp.id_section = (SELECT pg.id_section FROM n_prod_group pg WHERE pg.id = ?))))
            ORDER BY FIELD(COALESCE(d.stage, 'idea'), 'recipe', 'test2', 'test1', 'idea', 'launch'), tp.name", [$groupId, $groupId]);
    }

    /** Wszystkie produkty rozwijane (produkty testowe rodzaju « Produkt ») do tablicy etapów w biurze. */
    public function pipeline(): array
    {
        return $this->all(self::PRODUCT . ' WHERE tp.id_type = 1 ORDER BY COALESCE(d.stage_changed_at, d.updated_at, tp.create_timestamp) DESC, tp.id DESC');
    }

    /** Ustawienia rozwoju produktu : tylko podane kolumny (wiersz tworzony, gdy go nie ma). */
    public function saveDev(int $id, array $data): void
    {
        if (!$data) return;
        $cols = array_keys($data);
        $this->db->prepare('INSERT INTO n_dev_product (id_test_product, ' . implode(', ', $cols) . ') VALUES (?, ' . implode(', ', array_fill(0, count($cols), '?')) . ')
            ON DUPLICATE KEY UPDATE ' . implode(', ', array_map(fn($c) => "{$c} = VALUES({$c})", $cols)))
            ->execute([$id, ...array_values($data)]);
    }

    /** Produkt testowy (status « Propozycja ») ; kategoria, typ i etykieta z kreatora (opcjonalne). */
    public function createTestProduct(string $name, ?int $sectionId, ?int $categoryId = null, int $typeId = 1, ?int $labelType = null): int
    {
        $this->db->prepare('INSERT INTO test_product (name, id_section, id_category, id_status, id_type, label_type) VALUES (?, ?, ?, 1, ?, ?)')
            ->execute([$name, $sectionId, $categoryId, $typeId, $labelType]);
        return (int) $this->db->lastInsertId();
    }

    public function setTestProductDetails(int $id, ?int $categoryId, int $typeId, ?int $labelType): void
    {
        $this->db->prepare('UPDATE test_product SET id_category = ?, id_type = ?, label_type = ? WHERE id = ?')->execute([$categoryId, $typeId, $labelType, $id]);
    }

    public function renameTestProduct(int $id, string $name): void
    {
        $this->db->prepare('UPDATE test_product SET name = ? WHERE id = ?')->execute([$name, $id]);
    }

    public function setTestProductStatus(int $id, int $status): void
    {
        $this->db->prepare('UPDATE test_product SET id_status = ? WHERE id = ?')->execute([$status, $id]);
    }

    /** @return list<array> testy produktu (z osobą zidentyfikowaną na tablecie, która je potwierdziła), od pierwszego */
    public function trials(int $id): array
    {
        return $this->all("SELECT tr.*, TRIM(CONCAT(COALESCE(e.name, ''), ' ', COALESCE(e.surname, ''))) AS employee, g.name_pl AS group_pl, g.name_fr AS group_fr,
                (SELECT COUNT(*) FROM n_dev_trial_photo p WHERE p.id_trial = tr.id) AS photo_count
            FROM n_dev_trial tr LEFT JOIN n_prod_employee ne ON ne.id = tr.id_employee LEFT JOIN production_employee e ON e.id = ne.id_production_employee
            LEFT JOIN n_prod_group g ON g.id = tr.id_group
            WHERE tr.id_test_product = ? ORDER BY tr.trial_no", [$id]);
    }

    public function trial(int $trialId): ?array
    {
        return $this->all('SELECT * FROM n_dev_trial WHERE id = ?', [$trialId])[0] ?? null;
    }

    public function nextTrialNo(int $id): int
    {
        return (int) ($this->all('SELECT COALESCE(MAX(trial_no), 0) + 1 AS n FROM n_dev_trial WHERE id_test_product = ?', [$id])[0]['n'] ?? 1);
    }

    /** Najbliższy zaplanowany test (najniższy numer). */
    public function plannedTrial(int $id): ?array
    {
        return $this->all("SELECT * FROM n_dev_trial WHERE id_test_product = ? AND status = 'planned' ORDER BY trial_no LIMIT 1", [$id])[0] ?? null;
    }

    public function insertTrial(array $data): int
    {
        $cols = array_keys($data);
        $this->db->prepare('INSERT INTO n_dev_trial (' . implode(', ', $cols) . ') VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')')->execute(array_values($data));
        return (int) $this->db->lastInsertId();
    }

    public function updateTrial(int $trialId, array $data): void
    {
        $cols = array_keys($data);
        $this->db->prepare('UPDATE n_dev_trial SET ' . implode(', ', array_map(fn($c) => "{$c} = ?", $cols)) . ' WHERE id = ?')->execute([...array_values($data), $trialId]);
    }

    public function deletePlannedTrial(int $id, int $trialId): bool
    {
        $st = $this->db->prepare("DELETE FROM n_dev_trial WHERE id = ? AND id_test_product = ? AND status = 'planned'");
        $st->execute([$trialId, $id]);
        return $st->rowCount() > 0;
    }

    public function addTrialPhoto(int $trialId, string $mime, string $image, string $takenAt): int
    {
        $this->db->prepare('INSERT INTO n_dev_trial_photo (id_trial, mime, bytes, image, taken_at) VALUES (?, ?, ?, ?, ?)')->execute([$trialId, $mime, strlen($image), $image, $takenAt]);
        return (int) $this->db->lastInsertId();
    }

    /** @return array<int, list<array>> zdjęcia testów (bez obrazu) według testu */
    public function trialPhotos(array $trialIds, bool $withImage = false): array
    {
        $ids = array_values(array_filter(array_map('intval', $trialIds)));
        if (!$ids) return [];
        $in = implode(',', $ids);
        $out = [];
        foreach ($this->all('SELECT id, id_trial, mime, taken_at' . ($withImage ? ', image' : '') . " FROM n_dev_trial_photo WHERE id_trial IN ({$in}) ORDER BY id") as $r) $out[(int) $r['id_trial']][] = $r;
        return $out;
    }

    public function trialPhoto(int $photoId): ?array
    {
        return $this->all('SELECT id, id_trial, mime, image FROM n_dev_trial_photo WHERE id = ?', [$photoId])[0] ?? null;
    }

    /** Osoba zidentyfikowana na tablecie (n_prod_employee) : imię i nazwisko z karty pracownika. */
    public function employeeName(int $id): ?string
    {
        $name = $this->all("SELECT TRIM(CONCAT(COALESCE(pe.name, ''), ' ', COALESCE(pe.surname, ''))) AS n
            FROM n_prod_employee e JOIN production_employee pe ON pe.id = e.id_production_employee WHERE e.id = ?", [$id])[0]['n'] ?? null;
        return $name !== null && $name !== '' ? $name : null;
    }

    /** @return list<array{id:int, name_pl:string, name_fr:string, color:string, id_section:?int}> aktywne grupy produkcji */
    public function groups(): array
    {
        return $this->all('SELECT id, name_pl, name_fr, color, id_section FROM n_prod_group WHERE active = 1 ORDER BY sort_order, name_pl');
    }

    /** @return list<array{id:int, name:string}> produkty ERP do powiązania przy wprowadzeniu (najnowsze pierwsze) */
    public function finalProducts(): array
    {
        return $this->all('SELECT id, name FROM final_product WHERE active = 1 ORDER BY create_timestamp DESC, name LIMIT 1500');
    }

    public function finalProductExists(int $id): bool
    {
        return (bool) $this->all('SELECT 1 AS x FROM final_product WHERE id = ?', [$id]);
    }

    /** Data wprowadzenia (moduł kierownika produkcji, raport miesięczny) : tylko gdy jej nie ma. */
    public function recordLaunch(int $finalProductId, string $date, ?int $userId): void
    {
        $this->db->prepare('INSERT INTO n_mgr_launch (id_product, launch_date, updated_by) SELECT ?, ?, ? FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM n_mgr_launch WHERE id_product = ?)')
            ->execute([$finalProductId, $date, $userId, $finalProductId]);
    }

    /** Pola test_product bez złączeń (kategoria, etykieta) — kreator produktu z testu. */
    public function testProductRaw(int $id): ?array
    {
        return $this->all('SELECT id, name, id_category, id_section, id_recipe, label_type FROM test_product WHERE id = ?', [$id])[0] ?? null;
    }

    public function product(int $id): ?array
    {
        return $this->all(self::PRODUCT . ' WHERE tp.id = ?', [$id])[0] ?? null;
    }

    /** Etapy rozwoju (dziennik zmian produktu testowego), najnowsze pierwsze. */
    public function stages(int $id): array
    {
        return $this->all('SELECT c.id, s.name AS stage, s.priority, c.description, c.deadline, c.create_timestamp
            FROM test_product_changelog c LEFT JOIN test_product_stage s ON s.id = c.id_stage
            WHERE c.id_test_product = ? ORDER BY c.create_timestamp DESC, c.id DESC', [$id]);
    }

    /** @return array<int, array> produkt => najnowszy etap */
    public function lastStages(array $ids): array
    {
        if (!$ids) return [];
        $in = implode(',', array_map('intval', $ids));
        $out = [];
        foreach ($this->all("SELECT c.id_test_product, s.name AS stage, c.description, c.deadline FROM test_product_changelog c
                             LEFT JOIN test_product_stage s ON s.id = c.id_stage WHERE c.id_test_product IN ({$in})
                             ORDER BY c.create_timestamp DESC, c.id DESC") as $r) {
            $out[(int) $r['id_test_product']] ??= $r;
        }
        return $out;
    }

    /** Zdjęcia (bez danych obrazu). */
    public function photos(int $id): array
    {
        return $this->all('SELECT id, caption, bytes, created_at FROM n_dev_product_photo WHERE id_test_product = ? ORDER BY id', [$id]);
    }

    /** Zdjęcia z obrazem (tablet : data URL). */
    public function photosWithImage(int $id): array
    {
        return $this->all('SELECT id, caption, mime, image FROM n_dev_product_photo WHERE id_test_product = ? ORDER BY id', [$id]);
    }

    public function photo(int $photoId): ?array
    {
        return $this->all('SELECT id, id_test_product, mime, image FROM n_dev_product_photo WHERE id = ?', [$photoId])[0] ?? null;
    }

    public function saveSettings(int $id, bool $onTablet, ?string $videoUrl, ?string $notes, ?int $userId): void
    {
        $this->db->prepare('INSERT INTO n_dev_product (id_test_product, on_tablet, video_url, notes, updated_by) VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE on_tablet = VALUES(on_tablet), video_url = VALUES(video_url), notes = VALUES(notes), updated_by = VALUES(updated_by)')
            ->execute([$id, $onTablet ? 1 : 0, $videoUrl, $notes, $userId]);
    }

    public function addPhoto(int $id, string $mime, string $image, ?string $caption, ?int $userId): int
    {
        $this->db->prepare('INSERT INTO n_dev_product_photo (id_test_product, mime, bytes, image, caption, created_by) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$id, $mime, strlen($image), $image, $caption, $userId]);
        return (int) $this->db->lastInsertId();
    }

    public function deletePhoto(int $id, int $photoId): bool
    {
        $st = $this->db->prepare('DELETE FROM n_dev_product_photo WHERE id = ? AND id_test_product = ?');
        $st->execute([$photoId, $id]);
        return $st->rowCount() > 0;
    }

    public function photoCount(int $id): int
    {
        return (int) ($this->all('SELECT COUNT(*) AS n FROM n_dev_product_photo WHERE id_test_product = ?', [$id])[0]['n'] ?? 0);
    }
}
