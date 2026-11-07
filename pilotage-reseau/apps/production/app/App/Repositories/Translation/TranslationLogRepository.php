<?php
declare(strict_types=1);

namespace App\Repositories\Translation;

use App\Repositories\Workshop\WorkshopRepository;

/** Zużycie tłumacza DeepL (n_translation_log) : limit dzienny na osobę i licznik miesiąca w Ustawieniach. */
final class TranslationLogRepository extends WorkshopRepository
{
    protected string $table = 'n_translation_log';

    public function add(?int $userId, string $context, int $chars, ?string $source, string $target): void
    {
        $this->db->prepare('INSERT INTO n_translation_log (id_user, context, chars, source_lang, target_lang) VALUES (?, ?, ?, ?, ?)')
            ->execute([$userId, $context, $chars, $source, $target]);
    }

    public function charsToday(int $userId): int
    {
        return (int) ($this->all('SELECT COALESCE(SUM(chars), 0) AS n FROM n_translation_log WHERE id_user = ? AND created_at >= CURDATE()', [$userId])[0]['n'] ?? 0);
    }

    /** Ten miesiąc : znaki i liczba tłumaczeń, według miejsca (Poczta, stopki, Komunikacja). */
    public function month(): array
    {
        $rows = $this->all("SELECT context, COUNT(*) AS n, SUM(chars) AS chars FROM n_translation_log
                            WHERE created_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01') GROUP BY context");
        return ['count' => (int) array_sum(array_column($rows, 'n')), 'chars' => (int) array_sum(array_column($rows, 'chars')),
            'by_context' => array_column(array_map(fn($r) => ['context' => $r['context'], 'chars' => (int) $r['chars']], $rows), 'chars', 'context')];
    }
}
