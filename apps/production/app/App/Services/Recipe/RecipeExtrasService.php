<?php
declare(strict_types=1);

namespace App\Services\Recipe;

use App\Core\TransactionManager;
use App\Repositories\Product\Recipe\PreparationStepRepository;
use App\Repositories\Workshop\RecipeProducerRepository;
use App\Services\Workshop\Product\CompositionProducerService;

/**
 * Formularz receptury w trzech zakładkach (audyt 2, lot C) : Skład · Przygotowanie · Grupa i kompetencje — od razu przy
 * tworzeniu, nie dopiero na osobnych stronach. Tu : instrukcja krok po kroku (jeden krok w linii = kroki przygotowania
 * tabletu), kompetencje z listy i grupa przygotowująca półprodukt / kompozycję. Skład i czynności zapisują jak dotąd
 * usługi receptur ; ta usługa dopisuje resztę po zapisie, a błąd jednej części trafia na listę « do uzupełnienia ».
 */
final class RecipeExtrasService
{
    public function __construct(
        private PreparationStepRepository $steps,
        private RecipeCompetencyService $competencies,
        private RecipeProducerRepository $producers,
        private CompositionProducerService $compositions,
        private TransactionManager $transactions,
    ) {
    }

    /** Dane zakładek Przygotowanie i Grupa : wartości z POST (po błędzie) mają pierwszeństwo przed zapisanymi. */
    public function formData(?int $recipeId, string $kind, ?array $post = null): array
    {
        $saved = $recipeId ? array_column($this->steps->getStepsByRecipeId($recipeId) ?: [], 'step_description') : [];
        $assigned = $recipeId ? array_map('intval', $this->competencies->getCompetencyIdsByRecipeId($recipeId)) : [];
        $data = [
            'recipe_id' => $recipeId,
            'steps_text' => $post !== null && isset($post['steps_text']) ? (string) $post['steps_text'] : implode("\n", $saved),
            'competency_list' => $this->competencies->getAll() ?: [],
            'competency_ids' => $post !== null && isset($post['competencies_present']) ? array_map('intval', (array) ($post['competency_ids'] ?? [])) : $assigned,
        ];
        // Kompozycje w składzie : grupa robiąca każdej (lista w zakładce Skład, zapis od razu).
        $data['producer_groups'] = $this->producers->groups();
        $data['child_producers'] = $this->producers->groupMap();
        if ($kind !== 'product') {
            $data['producer'] = $recipeId ? ($this->producers->forRecipes([$recipeId])[$recipeId] ?? null) : null;
            $data['producer_new'] = $post['producer_group_id'] ?? '';
        }
        return $data;
    }

    /** @return list<string> problemy (część : komunikat) */
    public function save(int $recipeId, array $input, bool $isNew, ?int $userId): array
    {
        $problems = [];
        if (array_key_exists('steps_text', $input)) {
            $lines = array_values(array_filter(array_map(fn($l) => trim(preg_replace('/^\s*\d+[.)]\s*/', '', $l)), preg_split('/\R/', (string) $input['steps_text'])), fn($l) => $l !== ''));
            $current = array_column($this->steps->getStepsByRecipeId($recipeId) ?: [], 'step_description');
            if ($lines !== array_map('trim', $current)) {
                try {
                    $this->transactions->execute(function () use ($recipeId, $lines) {
                        $this->steps->deleteByRecipe($recipeId);
                        foreach ($lines as $i => $line) {
                            $this->steps->insert(['recipe_id' => $recipeId, 'step_number' => $i + 1, 'step_description' => mb_substr($line, 0, 2000)]);
                        }
                    });
                } catch (\Throwable $e) {
                    $problems[] = 'Instrukcja : ' . $e->getMessage();
                }
            }
        }
        if (isset($input['competencies_present'])) {
            $ids = array_values(array_unique(array_filter(array_map('intval', (array) ($input['competency_ids'] ?? [])))));
            $known = array_map('intval', array_column($this->competencies->getAll() ?: [], 'id'));
            try {
                $this->competencies->syncRecipeCompetencies($recipeId, array_values(array_intersect($ids, $known)));
            } catch (\Throwable $e) {
                $problems[] = 'Kompetencje : ' . $e->getMessage();
            }
        }
        // Grupa : przy tworzeniu z formularza ; w edycji zapisuje ją od razu lista (RecipeProducerController).
        $group = trim((string) ($input['producer_group_id'] ?? ''));
        if ($isNew && $group !== '' && ctype_digit($group)) {
            try {
                if ($this->producers->isComponent($recipeId) && $this->producers->groupExists((int) $group)) $this->compositions->set($recipeId, (int) $group, $userId);
                else $problems[] = 'Grupa : nieznana grupa albo to nie półprodukt / kompozycja.';
            } catch (\Throwable $e) {
                $problems[] = 'Grupa : ' . $e->getMessage();
            }
        }
        return $problems;
    }
}
