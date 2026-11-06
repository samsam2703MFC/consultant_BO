<?php
declare(strict_types=1);

namespace App\Services\Recipe;

use App\Repositories\Product\Recipe\RecipeVersionRepository;
use DateTimeImmutable;

/**
 * Versions de recette « en vigueur depuis ». Une version = composition directe + matières aplaties + coût au moment
 * du changement. prepare() garde l'état d'avant (version de départ, valid_from NULL) ; record() ajoute une version
 * datée quand le contenu a changé (empreinte sur les identifiants et quantités, pas sur les noms). Appelés par
 * FlattenedRecipeService à chaque réécriture de la liste aplatie (recette, półprodukt, kompozycja, prix) et par
 * les formulaires de recette juste avant l'enregistrement (la composition directe y est encore l'ancienne).
 */
final class RecipeVersionService
{
    public const SOURCES = [
        'baseline' => 'Wersja początkowa',
        'create' => 'Nowa receptura',
        'edit' => 'Zmiana receptury',
        'component' => 'Zmiana składnika',
    ];
    private const CHUNK = 200;

    /** Recette modifiée dans cette requête : nom repris dans la note des recettes qui la contiennent. */
    private ?array $changing = null;

    public function __construct(private RecipeVersionRepository $repo) {}

    /** Avant d'enregistrer une recette : version de départ si elle n'en a pas encore, et sa mention chez les parents. */
    public function changing(int $recipeId): void
    {
        $this->prepare([$recipeId]);
        $recipe = $this->repo->recipe($recipeId);
        $this->changing = $recipe ? ['id' => $recipeId, 'name' => (string) $recipe['name']] : null;
    }

    /** Version de départ (valid_from NULL) des recettes déjà aplaties qui n'ont encore aucune version. */
    public function prepare(array $recipeIds, ?int $userId = null): int
    {
        $created = 0;
        foreach (array_chunk(array_values(array_unique(array_map('intval', $recipeIds))), self::CHUNK) as $ids) {
            $latest = $this->repo->latest($ids);
            $missing = array_values(array_filter($ids, fn($id) => !isset($latest[$id])));
            foreach ($this->repo->current($missing) as $id => $state) {
                if (!$state['ingredients']) continue; // recette nouvelle ou vide : sa première version vient de record()
                $this->insert($id, 1, null, 'baseline', null, $state, $userId);
                $created++;
            }
        }
        return $created;
    }

    /** Après réécriture : nouvelle version datée pour chaque recette dont la composition a changé. */
    public function record(array $recipeIds, ?DateTimeImmutable $now = null, ?int $userId = null): int
    {
        $at = ($now ?? new DateTimeImmutable())->format('Y-m-d H:i:s');
        $userId ??= self::sessionUser();
        $created = 0;
        foreach (array_chunk(array_values(array_unique(array_map('intval', $recipeIds))), self::CHUNK) as $ids) {
            $latest = $this->repo->latest($ids);
            foreach ($this->repo->current($ids) as $id => $state) {
                $previous = $latest[$id] ?? null;
                if ($previous && $previous['hash'] === self::hash($state)) continue;
                if (!$previous && !$state['ingredients'] && !$state['materials'] && !$state['components']) continue;
                $source = 'create';
                $note = null;
                if ($previous) {
                    $sameDirect = self::directKey(json_decode($previous['composition'], true) ?: []) === self::directKey(self::composition($state));
                    $source = $sameDirect ? 'component' : 'edit';
                    if ($sameDirect && $this->changing && $this->changing['id'] !== $id) $note = mb_substr('« ' . $this->changing['name'] . ' »', 0, 255);
                }
                $this->insert($id, ($previous['version_no'] ?? 0) + 1, $at, $source, $note, $state, $userId);
                $created++;
            }
        }
        return $created;
    }

    /** Rattrapage (tâche périodique) : version de départ de toutes les recettes qui n'en ont pas. */
    public function backfill(int $limit = 2000): int
    {
        return $this->prepare($this->repo->withoutVersion($limit));
    }

    /**
     * Historique d'une recette : versions (la plus récente d'abord) avec ce qui a changé par rapport à la précédente.
     * @return array{recipe: array, versions: list<array>, current: array}|null
     */
    public function history(int $recipeId): ?array
    {
        $recipe = $this->repo->recipe($recipeId);
        if (!$recipe) return null;
        $rows = array_reverse($this->repo->history($recipeId));
        $current = $this->repo->current([$recipeId])[$recipeId] ?? null;
        $materialIds = [];
        foreach ($rows as $r) foreach (json_decode((string) $r['ingredients'], true) ?: [] as [$m]) $materialIds[] = $m;
        foreach ($current['ingredients'] ?? [] as [$m]) $materialIds[] = $m;
        $names = $this->repo->materialNames($materialIds);

        $versions = [];
        $prev = null;
        foreach ($rows as $r) {
            $composition = json_decode((string) $r['composition'], true) ?: [];
            $ingredients = json_decode((string) $r['ingredients'], true) ?: [];
            $cost = $r['material_cost'] === null ? null : (float) $r['material_cost'];
            $versions[] = [
                'no' => (int) $r['version_no'],
                'valid_from' => $r['valid_from'],
                'source' => (string) $r['source'],
                'source_label' => self::SOURCES[$r['source']] ?? (string) $r['source'],
                'note' => $r['note'],
                'author' => $r['author'] ?: null,
                'cost' => $cost,
                'cost_delta' => $prev && $cost !== null && $prev['cost'] !== null ? $cost - $prev['cost'] : null,
                'composition' => $composition,
                'ingredients' => $ingredients,
                'changes' => $prev ? self::diff($prev['composition'], $composition) : [],
                'flat_changes' => $prev ? self::flatDiff($prev['ingredients'], $ingredients, $names) : [],
            ];
            $prev = end($versions);
        }
        $last = $prev;
        return [
            'recipe' => $recipe,
            'versions' => array_reverse($versions),
            'current' => [
                'composition' => $current ? self::composition($current) : [],
                'ingredients' => $current['ingredients'] ?? [],
                'cost' => $current['cost'] ?? null,
                // l'état actuel n'est pas encore une version (modifié par un chemin non suivi, ou avant le rattrapage)
                'untracked' => $current && (!$last || self::hashOf($last['composition'], $last['ingredients']) !== self::hash($current)),
            ],
            'names' => $names,
        ];
    }

    /** Composition directe telle que gardée dans la version (libellés pour l'affichage). */
    public static function composition(array $state): array
    {
        return [
            'yield' => round((float) $state['recipe']['yield_quantity'], 4),
            'unit' => $state['recipe']['unit'],
            'materials' => array_map(fn($m) => ['id' => $m['id'], 'name' => $m['name'], 'unit' => $m['unit'], 'qty' => round($m['qty'], 4)], $state['materials']),
            'components' => array_map(fn($c) => ['id' => $c['id'], 'name' => $c['name'], 'type' => $c['type'], 'qty' => round($c['qty'], 4)], $state['components']),
            'preparations' => $state['preparations'],
        ];
    }

    /** Différences de composition directe : matières, półprodukty, préparations, rendement. */
    public static function diff(array $before, array $after): array
    {
        $out = [];
        if ((float) ($before['yield'] ?? 0) !== (float) ($after['yield'] ?? 0)) {
            $out[] = ['kind' => 'yield', 'name' => 'Wydajność', 'before' => $before['yield'] ?? null, 'after' => $after['yield'] ?? null, 'unit' => $after['unit'] ?? null];
        }
        foreach (['materials' => 'material', 'components' => 'component'] as $key => $kind) {
            $old = array_column($before[$key] ?? [], null, 'id');
            $new = array_column($after[$key] ?? [], null, 'id');
            foreach ($new as $id => $n) {
                $o = $old[$id] ?? null;
                if (!$o) $out[] = ['kind' => $kind, 'change' => 'added', 'name' => $n['name'], 'before' => null, 'after' => $n['qty'], 'unit' => $n['unit'] ?? null];
                elseif ((float) $o['qty'] !== (float) $n['qty']) $out[] = ['kind' => $kind, 'change' => 'qty', 'name' => $n['name'], 'before' => $o['qty'], 'after' => $n['qty'], 'unit' => $n['unit'] ?? null];
            }
            foreach ($old as $id => $o) {
                if (!isset($new[$id])) $out[] = ['kind' => $kind, 'change' => 'removed', 'name' => $o['name'], 'before' => $o['qty'], 'after' => null, 'unit' => $o['unit'] ?? null];
            }
        }
        $prep = fn(array $c) => array_map(fn($p) => $p['id'] . ':' . $p['seconds'], $c['preparations'] ?? []);
        if ($prep($before) !== $prep($after)) {
            $out[] = ['kind' => 'preparation', 'change' => 'qty', 'name' => 'Etapy przygotowania',
                'before' => implode(', ', array_map(fn($p) => $p['name'], $before['preparations'] ?? [])) ?: '—',
                'after' => implode(', ', array_map(fn($p) => $p['name'], $after['preparations'] ?? [])) ?: '—', 'unit' => null];
        }
        return $out;
    }

    /** Différences de la liste aplatie (ce que la recette consomme vraiment, półprodukty compris). */
    public static function flatDiff(array $before, array $after, array $names): array
    {
        $old = [];
        foreach ($before as [$m, $q]) $old[(int) $m] = (float) $q;
        $new = [];
        foreach ($after as [$m, $q]) $new[(int) $m] = (float) $q;
        $out = [];
        foreach ($new + $old as $m => $_) {
            $o = $old[$m] ?? null;
            $n = $new[$m] ?? null;
            if ($o !== null && $n !== null && abs($o - $n) < 0.00001) continue;
            $out[] = ['name' => $names[$m]['name'] ?? ('#' . $m), 'unit' => $names[$m]['unit'] ?? null, 'before' => $o, 'after' => $n,
                'change' => $o === null ? 'added' : ($n === null ? 'removed' : 'qty')];
        }
        usort($out, fn($a, $b) => strcmp($a['name'], $b['name']));
        return $out;
    }

    private function insert(int $id, int $no, ?string $validFrom, string $source, ?string $note, array $state, ?int $userId): void
    {
        $this->repo->insert([
            'id_recipe' => $id,
            'version_no' => $no,
            'valid_from' => $validFrom,
            'source' => $source,
            'note' => $note,
            'content_hash' => self::hash($state),
            'composition' => json_encode(self::composition($state), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION),
            'ingredients' => json_encode(self::ingredients($state['ingredients']), JSON_PRESERVE_ZERO_FRACTION),
            'material_cost' => round((float) $state['cost'], 4),
            'created_by' => $userId,
        ]);
    }

    private static function ingredients(array $ingredients): array
    {
        return array_map(fn($i) => [(int) $i[0], round((float) $i[1], 4)], $ingredients);
    }

    /** Empreinte : identifiants et quantités seulement (renommer une matière ne crée pas de version). */
    private static function hash(array $state): string
    {
        return self::hashOf(self::composition($state), $state['ingredients']);
    }

    private static function hashOf(array $composition, array $ingredients): string
    {
        return hash('sha256', json_encode([self::directKey($composition), self::ingredients($ingredients)], JSON_PRESERVE_ZERO_FRACTION));
    }

    private static function directKey(array $composition): array
    {
        return [
            round((float) ($composition['yield'] ?? 0), 4),
            array_map(fn($m) => [(int) $m['id'], round((float) $m['qty'], 4)], $composition['materials'] ?? []),
            array_map(fn($c) => [(int) $c['id'], round((float) $c['qty'], 4)], $composition['components'] ?? []),
            array_map(fn($p) => [(int) $p['id'], (int) $p['seconds']], $composition['preparations'] ?? []),
        ];
    }

    private static function sessionUser(): ?int
    {
        $id = function_exists('user') ? user('id') : null;
        return $id ? (int) $id : null;
    }
}
