<?php
declare(strict_types=1);

namespace App\Repositories\Workshop;

/**
 * Karty technologiczne z filmu [196] : szkic (źródło, nazwa, grupa, prognoza), treść wygenerowana przez asystenta AI
 * (n_dev_card.card_json), klatki filmu (n_dev_card_frame) i prompt w wersjach (n_ai_prompt).
 */
final class DevCardRepository extends WorkshopRepository
{
    protected string $table = 'n_dev_card';
    protected array $writable = ['id_test_product', 'status', 'source_kind', 'source_url', 'source_author', 'source_title', 'source_text', 'source_info',
        'video_url', 'name', 'id_group', 'weekly_sales', 'season_quarters', 'card_json', 'model', 'id_prompt', 'usage_json', 'generated_at', 'generation_ms', 'created_by'];

    private const CARD = 'SELECT c.*, g.name_pl AS group_pl, g.name_fr AS group_fr, g.color AS group_color, tp.name AS product_name,
            COALESCE(d.on_tablet, 0) AS on_tablet, (SELECT COUNT(*) FROM n_dev_card_frame f WHERE f.id_card = c.id) AS frame_count
        FROM n_dev_card c
        LEFT JOIN n_prod_group g ON g.id = c.id_group
        LEFT JOIN test_product tp ON tp.id = c.id_test_product
        LEFT JOIN n_dev_product d ON d.id_test_product = c.id_test_product';

    public function card(int $id): ?array
    {
        return $this->all(self::CARD . ' WHERE c.id = ?', [$id])[0] ?? null;
    }

    public function forProduct(int $productId): ?array
    {
        return $this->all(self::CARD . ' WHERE c.id_test_product = ?', [$productId])[0] ?? null;
    }

    /** Szkice z ostatnich 30 dni (do dokończenia), najnowsze najpierw. */
    public function drafts(int $limit = 8): array
    {
        return $this->all(self::CARD . " WHERE c.status <> 'saved' AND c.created_at >= NOW() - INTERVAL 30 DAY ORDER BY c.updated_at DESC LIMIT " . max(1, $limit));
    }

    public function create(array $data): int
    {
        return $this->insert($data);
    }

    public function save(int $id, array $data): void
    {
        if ($data) $this->update($id, $data);
    }

    /** Usunięte szkice starsze niż 30 dni (klatki kasowane kaskadą). */
    public function purgeOldDrafts(): int
    {
        $st = $this->db->prepare("DELETE FROM n_dev_card WHERE status <> 'saved' AND updated_at < NOW() - INTERVAL 30 DAY");
        $st->execute();
        return $st->rowCount();
    }

    public function deleteCard(int $id): void
    {
        // Klatki jawnie (klucz obcy CASCADE robi to samo ; kopia tabel bez kluczy, np. w testach, też zostaje czysta).
        $this->db->prepare('DELETE FROM n_dev_card_frame WHERE id_card = ?')->execute([$id]);
        $this->db->prepare('DELETE FROM n_dev_card WHERE id = ?')->execute([$id]);
    }

    // --- Klatki ----------------------------------------------------------------------------------------

    public function addFrame(int $cardId, int $position, ?int $atMs, string $kind, string $mime, string $image, ?int $width, ?int $height): int
    {
        $this->db->prepare('INSERT INTO n_dev_card_frame (id_card, position, at_ms, kind, mime, bytes, width, height, image) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$cardId, $position, $atMs, $kind, $mime, strlen($image), $width, $height, $image]);
        return (int) $this->db->lastInsertId();
    }

    /** Klatki bez obrazu (lista w kreatorze), w kolejności filmu. */
    public function frames(int $cardId): array
    {
        return $this->all('SELECT id, position, at_ms, kind, mime, bytes, width, height FROM n_dev_card_frame WHERE id_card = ? ORDER BY position, id', [$cardId]);
    }

    /** Klatki z obrazem (generowanie, .docx, PDF, tablet) ; $ids = tylko te. */
    public function framesWithImage(int $cardId, ?array $ids = null): array
    {
        if ($ids === []) return [];
        $sql = 'SELECT id, position, at_ms, kind, mime, bytes, width, height, image FROM n_dev_card_frame WHERE id_card = ?';
        $params = [$cardId];
        if ($ids !== null) {
            $sql .= ' AND id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
            array_push($params, ...array_map('intval', $ids));
        }
        return $this->all($sql . ' ORDER BY position, id', $params);
    }

    public function frame(int $cardId, int $frameId): ?array
    {
        return $this->all('SELECT id, id_card, mime, bytes, image FROM n_dev_card_frame WHERE id_card = ? AND id = ?', [$cardId, $frameId])[0] ?? null;
    }

    public function deleteFrames(int $cardId, ?string $kind = null): void
    {
        if ($kind === null) {
            $this->db->prepare('DELETE FROM n_dev_card_frame WHERE id_card = ?')->execute([$cardId]);
            return;
        }
        $this->db->prepare('DELETE FROM n_dev_card_frame WHERE id_card = ? AND kind = ?')->execute([$cardId, $kind]);
    }

    public function deleteFrame(int $cardId, int $frameId): void
    {
        $this->db->prepare('DELETE FROM n_dev_card_frame WHERE id_card = ? AND id = ?')->execute([$cardId, $frameId]);
    }

    public function frameBytes(int $cardId): int
    {
        return (int) ($this->all('SELECT COALESCE(SUM(bytes), 0) AS b FROM n_dev_card_frame WHERE id_card = ?', [$cardId])[0]['b'] ?? 0);
    }

    // --- Produkt w rozwoju ----------------------------------------------------------------------------

    public function groups(): array
    {
        return $this->all('SELECT id, name_pl, name_fr, color, id_section FROM n_prod_group WHERE active = 1 ORDER BY sort_order, name_pl');
    }

    /** Numer kolejny karty w nazwie pliku (NN_Nazwa.docx) : kolejność kart zapisanych. */
    public function fileNo(int $cardId): int
    {
        return (int) ($this->all("SELECT COUNT(*) AS n FROM n_dev_card WHERE status = 'saved' AND id <= ?", [$cardId])[0]['n'] ?? 0);
    }

    // --- Prompt ----------------------------------------------------------------------------------------

    public function prompt(string $code): ?array
    {
        try {
            return $this->all('SELECT id, code, body, created_by, created_at FROM n_ai_prompt WHERE code = ? ORDER BY id DESC LIMIT 1', [$code])[0] ?? null;
        } catch (\PDOException) {
            return null; // przed migracją 122
        }
    }

    public function promptVersions(string $code, int $limit = 10): array
    {
        return $this->all('SELECT p.id, p.created_at, TRIM(CONCAT(COALESCE(u.name, \'\'), \' \', COALESCE(u.surname, \'\'))) AS author, CHAR_LENGTH(p.body) AS chars
            FROM n_ai_prompt p LEFT JOIN user u ON u.id = p.created_by WHERE p.code = ? ORDER BY p.id DESC LIMIT ' . max(1, $limit), [$code]);
    }

    public function addPrompt(string $code, string $body, ?int $userId): int
    {
        $this->db->prepare('INSERT INTO n_ai_prompt (code, body, created_by) VALUES (?, ?, ?)')->execute([$code, $body, $userId]);
        return (int) $this->db->lastInsertId();
    }
}
