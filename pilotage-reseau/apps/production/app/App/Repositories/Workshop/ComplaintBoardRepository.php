<?php
declare(strict_types=1);

namespace App\Repositories\Workshop;

/**
 * Tablet grupy : reklamacje klientów przyjęte przez biuro (complaint.acceptance_status = ACCEPTED, typ PRODUCT) na produkty
 * grupy — grupa produktu jak w Stanie magazynu : grupa główna karty produktu, inaczej pierwsza aktywna grupa działu produktu.
 * Tłumaczenia komentarzy na język tabletu (n_complaint_translation).
 */
final class ComplaintBoardRepository extends WorkshopRepository
{
    protected string $table = 'complaint';

    private const FROM = "FROM complaint c
        JOIN final_product fp ON fp.id = c.id_product
        LEFT JOIN customer cu ON cu.id = c.id_customer
        LEFT JOIN n_prod_item i ON i.id_final_product = fp.id AND i.item_type = 'product'
        LEFT JOIN n_prod_product_settings s ON s.id_item = i.id
        LEFT JOIN (SELECT id_section, MIN(id) AS id FROM n_prod_group WHERE active = 1 AND id_section IS NOT NULL GROUP BY id_section) dg ON dg.id_section = fp.id_section
        WHERE c.acceptance_status = 'ACCEPTED' AND c.complaint_type = 'PRODUCT' AND COALESCE(s.id_primary_group, dg.id) = ?";

    /** @return list<array> reklamacje przyjęte grupy wpisane od dnia (najnowsze najpierw) */
    public function accepted(int $groupId, string $since, ?int $limit = null): array
    {
        return $this->all("SELECT c.id, c.complaint_reason AS reason, c.compensation_method, c.product_quantity, c.accepted_quantity, c.client_comment,
                c.production_response, c.acceptance_timestamp AS received_at, c.reported_at, fp.id AS product_id, fp.name AS product, cu.company AS customer,
                (SELECT COUNT(*) FROM complaint_attachment a WHERE a.complaint_id = c.id) AS photos
            " . self::FROM . " AND c.acceptance_timestamp >= ?
            ORDER BY c.acceptance_timestamp DESC, c.id DESC" . ($limit !== null ? ' LIMIT ' . max(1, $limit) : ''), [$groupId, $since . ' 00:00:00']);
    }

    /** Reklamacja przyjęta tej grupy (zdjęcia) albo null. */
    public function acceptedOne(int $groupId, int $complaintId): ?array
    {
        return $this->all('SELECT c.id ' . self::FROM . ' AND c.id = ?', [$groupId, $complaintId])[0] ?? null;
    }

    /** @return list<array{id:int}> zdjęcia reklamacji */
    public function attachments(int $complaintId): array
    {
        return $this->all('SELECT id FROM complaint_attachment WHERE complaint_id = ? ORDER BY id', [$complaintId]);
    }

    /** @return array<int, array> reklamacja → tłumaczenie w języku */
    public function translations(array $complaintIds, string $lang): array
    {
        $ids = array_values(array_unique(array_map('intval', $complaintIds)));
        if (!$ids) return [];
        $out = [];
        foreach ($this->all('SELECT * FROM n_complaint_translation WHERE lang = ? AND id_complaint IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', [$lang, ...$ids]) as $r) {
            $out[(int) $r['id_complaint']] = $r;
        }
        return $out;
    }

    public function saveTranslation(int $complaintId, string $lang, string $hash, ?string $sourceLang, ?string $comment, ?string $response): void
    {
        $this->db->prepare('INSERT INTO n_complaint_translation (id_complaint, lang, source_hash, source_lang, comment_text, response_text) VALUES (?, ?, ?, ?, ?, ?)
                            ON DUPLICATE KEY UPDATE source_hash = VALUES(source_hash), source_lang = VALUES(source_lang), comment_text = VALUES(comment_text),
                                response_text = VALUES(response_text), translated_at = CURRENT_TIMESTAMP')
            ->execute([$complaintId, $lang, $hash, $sourceLang, $comment, $response]);
    }

    public function groupName(int $groupId, string $lang): ?string
    {
        $r = $this->all('SELECT name_pl, name_fr FROM n_prod_group WHERE id = ?', [$groupId])[0] ?? null;
        return $r === null ? null : ($lang === 'fr' && $r['name_fr'] ? $r['name_fr'] : $r['name_pl']);
    }
}
