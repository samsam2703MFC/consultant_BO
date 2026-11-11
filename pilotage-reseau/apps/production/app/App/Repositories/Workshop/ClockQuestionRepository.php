<?php
declare(strict_types=1);

namespace App\Repositories\Workshop;

/** Questions confirmées au pointage (n_clock_question) et réponses données (n_clock_answer). */
final class ClockQuestionRepository extends WorkshopRepository
{
    protected string $table = 'n_clock_question';
    protected array $writable = ['text_pl', 'text_fr', 'direction', 'kind', 'info_pl', 'info_fr', 'required', 'active', 'sort_order', 'updated_by'];

    public function list(): array
    {
        return $this->all('SELECT * FROM n_clock_question ORDER BY sort_order, id');
    }

    /** Questions actives pour l'entrée ou la sortie. */
    public function active(string $direction): array
    {
        return $this->all("SELECT * FROM n_clock_question WHERE active = 1 AND direction IN (?, 'both') ORDER BY sort_order, id", [$direction]);
    }

    public function delete(int $id): void
    {
        $this->db->prepare('DELETE FROM n_clock_question WHERE id = ?')->execute([$id]);
    }

    public function nextSort(): int
    {
        return (int) ($this->all('SELECT COALESCE(MAX(sort_order), 0) + 10 AS n FROM n_clock_question')[0]['n'] ?? 10);
    }

    /** @param list<array{id:int, text:string, answer:bool, note?:?string}> $answers */
    public function saveAnswers(int $clockId, string $direction, array $answers): void
    {
        $stmt = $this->db->prepare('INSERT INTO n_clock_answer (id_clock, direction, id_question, question, answer, note) VALUES (?, ?, ?, ?, ?, ?)
                                    ON DUPLICATE KEY UPDATE answer = VALUES(answer), note = VALUES(note)');
        foreach ($answers as $a) $stmt->execute([$clockId, $direction, $a['id'], mb_substr($a['text'], 0, 200), (int) $a['answer'], $a['note'] ?? null]);
    }

    /** @return array<int, list<array>> pointage => réponses */
    public function answersFor(array $clockIds): array
    {
        $ids = array_values(array_filter(array_map('intval', $clockIds)));
        if (!$ids) return [];
        $out = [];
        foreach ($this->all('SELECT a.*, COALESCE(q.kind, \'confirm\') AS kind FROM n_clock_answer a LEFT JOIN n_clock_question q ON q.id = a.id_question
                             WHERE a.id_clock IN (' . implode(',', $ids) . ') ORDER BY a.id') as $r) $out[(int) $r['id_clock']][] = $r;
        return $out;
    }
}
