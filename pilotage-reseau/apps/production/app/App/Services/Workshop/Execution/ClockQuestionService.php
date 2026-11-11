<?php
declare(strict_types=1);

namespace App\Services\Workshop\Execution;

use App\Core\Api\Workshop\ApiContext;
use App\Core\Api\Workshop\ApiException;
use App\Core\Api\Workshop\Validator;
use App\Repositories\Workshop\ClockQuestionRepository;

/**
 * Pointage : questions à confirmer avant « OK, zatwierdzam » (tablette fixe au bureau, QR scanné au téléphone,
 * ou tablette du groupe). Paramétrables par le responsable ; une question obligatoire non cochée bloque le pointage.
 * Les réponses sont gardées avec le pointage (texte de la question figé).
 * [263] Genre « yes_no » : réponse Tak / Nie (les deux valables ; obligatoire = il faut choisir), information (i) ;
 * « Tak » demande ce qui est pris (note obligatoire), par exemple la marchandise reprise à la sortie.
 */
final class ClockQuestionService
{
    public function __construct(private ClockQuestionRepository $repo)
    {
    }

    public function list(): array
    {
        return array_map(fn($q) => self::row($q), $this->repo->list());
    }

    public function create(array $body, ApiContext $context): array
    {
        $c = $this->validate($body, false);
        $id = $this->repo->insert($c + ['sort_order' => $c['sort_order'] ?? $this->repo->nextSort(), 'updated_by' => $context->userId]);
        return self::row($this->repo->find($id));
    }

    public function update(int $id, array $body, ApiContext $context): array
    {
        if ($this->repo->find($id) === null) throw ApiException::notFound('clock_question', $id);
        $this->repo->update($id, $this->validate($body, true) + ['updated_by' => $context->userId]);
        return self::row($this->repo->find($id));
    }

    /** Les réponses déjà données gardent le texte de la question. */
    public function delete(int $id): array
    {
        if ($this->repo->find($id) === null) throw ApiException::notFound('clock_question', $id);
        $this->repo->delete($id);
        return ['id' => $id, 'deleted' => true];
    }

    /** Tablette / téléphone : questions de l'entrée et de la sortie, dans la langue de l'écran (polonais sans traduction). */
    public function forKiosk(bool $fr): array
    {
        $pick = fn(array $q, string $field) => $fr && trim((string) ($q[$field . '_fr'] ?? '')) !== '' ? (string) $q[$field . '_fr'] : (string) ($q[$field . '_pl'] ?? '');
        $map = fn(array $rows) => array_map(fn($q) => ['id' => (int) $q['id'], 'text' => $pick($q, 'text'),
            'required' => (bool) $q['required'], 'kind' => (string) ($q['kind'] ?? 'confirm'), 'info' => $pick($q, 'info') !== '' ? $pick($q, 'info') : null], $rows);
        return ['in' => $map($this->repo->active('in')), 'out' => $map($this->repo->active('out'))];
    }

    /**
     * Réponses envoyées avec le pointage : [{question_id, yes, note?}]. Une question obligatoire non confirmée (confirm)
     * ou sans réponse (yes_no), ou « Tak » sans préciser quoi (yes_no) → 422.
     * @return list<array{id:int, text:string, answer:bool, note:?string}> réponses à garder
     */
    public function check(string $direction, mixed $answers, bool $fr): array
    {
        $given = [];
        foreach (is_array($answers) ? $answers : [] as $a) {
            if (!is_array($a) || !isset($a['question_id'])) continue;
            $note = isset($a['note']) && is_scalar($a['note']) ? trim((string) $a['note']) : '';
            $given[(int) $a['question_id']] = ['yes' => filter_var($a['yes'] ?? false, FILTER_VALIDATE_BOOLEAN), 'note' => $note === '' ? null : mb_substr($note, 0, 200)];
        }
        $out = [];
        $missing = [];
        foreach ($this->repo->active($direction) as $q) {
            $text = $fr && (string) $q['text_fr'] !== '' ? $q['text_fr'] : $q['text_pl'];
            $g = $given[(int) $q['id']] ?? null;
            $yes = $g['yes'] ?? false;
            if (($q['kind'] ?? 'confirm') === 'yes_no') {
                if ($q['required'] && $g === null) $missing[] = $text;
                elseif ($yes && $g['note'] === null) $missing[] = $text . ' → ' . ($fr ? 'quoi ?' : 'co ?');
                if ($g !== null) $out[] = ['id' => (int) $q['id'], 'text' => (string) $q['text_pl'], 'answer' => $yes, 'note' => $yes ? $g['note'] : null];
                continue;
            }
            if ($q['required'] && !$yes) $missing[] = $text;
            $out[] = ['id' => (int) $q['id'], 'text' => (string) $q['text_pl'], 'answer' => $yes, 'note' => null];
        }
        if ($missing) throw new ApiException('CLOCK_QUESTIONS_REQUIRED', 422, [], ['questions' => implode(' · ', $missing)]);
        return $out;
    }

    public function save(int $clockId, string $direction, array $answers): void
    {
        if ($answers) $this->repo->saveAnswers($clockId, $direction, $answers);
    }

    private function validate(array $body, bool $partial): array
    {
        $v = (new Validator($body, $partial))->only(['text_pl', 'text_fr', 'direction', 'kind', 'info_pl', 'info_fr', 'required', 'active', 'sort_order'])
            ->string('text_pl', 200)->string('text_fr', 200, false, true)->choice('direction', ['in', 'out', 'both'], false)
            ->choice('kind', ['confirm', 'yes_no'], false)->string('info_pl', 1000, false, true)->string('info_fr', 1000, false, true)
            ->boolean('required')->boolean('active')->integer('sort_order', false, 0, 100000);
        $v->throwIfInvalid();
        $c = $v->clean();
        foreach (['required', 'active'] as $k) if (array_key_exists($k, $c)) $c[$k] = (int) (bool) $c[$k];
        foreach (['text_fr', 'info_pl', 'info_fr'] as $k) if (array_key_exists($k, $c) && ($c[$k] === null || trim((string) $c[$k]) === '')) $c[$k] = null;
        return $c;
    }

    private static function row(array $q): array
    {
        return ['id' => (int) $q['id'], 'text_pl' => $q['text_pl'], 'text_fr' => $q['text_fr'], 'direction' => $q['direction'],
            'kind' => (string) ($q['kind'] ?? 'confirm'), 'info_pl' => $q['info_pl'] ?? null, 'info_fr' => $q['info_fr'] ?? null,
            'required' => (bool) $q['required'], 'active' => (bool) $q['active'], 'sort_order' => (int) $q['sort_order']];
    }
}
