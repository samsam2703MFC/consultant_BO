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
        $map = fn(array $rows) => array_map(fn($q) => ['id' => (int) $q['id'], 'text' => $fr && (string) $q['text_fr'] !== '' ? (string) $q['text_fr'] : (string) $q['text_pl'],
            'required' => (bool) $q['required']], $rows);
        return ['in' => $map($this->repo->active('in')), 'out' => $map($this->repo->active('out'))];
    }

    /**
     * Réponses envoyées avec le pointage : [{question_id, yes}]. Une question obligatoire non confirmée → 422.
     * @return list<array{id:int, text:string, answer:bool}> réponses à garder
     */
    public function check(string $direction, mixed $answers, bool $fr): array
    {
        $given = [];
        foreach (is_array($answers) ? $answers : [] as $a) {
            if (is_array($a) && isset($a['question_id'])) $given[(int) $a['question_id']] = filter_var($a['yes'] ?? false, FILTER_VALIDATE_BOOLEAN);
        }
        $out = [];
        $missing = [];
        foreach ($this->repo->active($direction) as $q) {
            $yes = $given[(int) $q['id']] ?? false;
            if ($q['required'] && !$yes) $missing[] = $fr && (string) $q['text_fr'] !== '' ? $q['text_fr'] : $q['text_pl'];
            $out[] = ['id' => (int) $q['id'], 'text' => (string) $q['text_pl'], 'answer' => $yes];
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
        $v = (new Validator($body, $partial))->only(['text_pl', 'text_fr', 'direction', 'required', 'active', 'sort_order'])
            ->string('text_pl', 200)->string('text_fr', 200, false, true)->choice('direction', ['in', 'out', 'both'], false)
            ->boolean('required')->boolean('active')->integer('sort_order', false, 0, 100000);
        $v->throwIfInvalid();
        $c = $v->clean();
        foreach (['required', 'active'] as $k) if (array_key_exists($k, $c)) $c[$k] = (int) (bool) $c[$k];
        if (array_key_exists('text_fr', $c) && ($c['text_fr'] === null || trim((string) $c['text_fr']) === '')) $c['text_fr'] = null;
        return $c;
    }

    private static function row(array $q): array
    {
        return ['id' => (int) $q['id'], 'text_pl' => $q['text_pl'], 'text_fr' => $q['text_fr'], 'direction' => $q['direction'],
            'required' => (bool) $q['required'], 'active' => (bool) $q['active'], 'sort_order' => (int) $q['sort_order']];
    }
}
