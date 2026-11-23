<?php
declare(strict_types=1);

namespace App\Services\Organization\Wizards;

use App\Domain\Product\ProductLogistics;
use App\Exceptions\CustomException;
use App\Repositories\Organization\LightWizardRepository;
use App\Repositories\Workshop\RecipeUnitCheckRepository;
use App\Repositories\Workshop\RecipesMonthRepository;
use App\Services\Audit\ProductWeightAuditEvaluator;
use App\Services\Organization\TaskWizards;

/**
 * Asystent « Receptury i dane produktów » [400] (co miesiąc ; zakres obowiązków « produkcja » 237 : Kierownik produkcji
 * 1.2 « Tworzenie i aktualizowanie receptur » et 1.6 « Aktualizacja danych produktowych oraz kontrola poprawności »).
 * Mois du contrôle = le mois de la tâche, état des données aujourd'hui (comme « PPWR i opakowania »). Produits actifs
 * seulement ; « complet » = les règles des écrans existants, sans en inventer :
 *   - receptura : absente (la liste « Produkty bez receptury » et son PDF), introuvable, sans składnik, coût du carton
 *     nul, surowce de la recette sans prix (coût sous-estimé), « na 1 sztukę » encore à décider (Receptura produktu =
 *     1 karton [201], RecipeUnitCheckRepository::candidates) ;
 *   - dane produktu : masse (ProductWeightAuditEvaluator : absente, 1 g douteux), sztuk w kartonie et palette
 *     (ProductLogistics::errors avec les champs exigés d'un nouveau produit), przydatność (ProductLogistics::SHELF_LIFE),
 *     kategoria et dział produkcyjny (exigés par le formulaire produit — ProductService::validationErrors).
 *   1. Przegląd : chiffres et produits signalés. 2. Do decyzji : une décision par produit (n_recipes_month_review) —
 *   Uzupełnione — sprawdzone (acceptée seulement sans braki au moment de l'enregistrement), Przekazane do uzupełnienia
 *   (note : komu, do kiedy ; revient le mois suivant tant que les braki restent), Nie dotyczy (note, reprise les mois
 *   suivants tant que les braki sont les mêmes). 3. Zatwierdź : « Zakończ » écrit n_wizard_done — bloqué tant qu'un
 *   produit attend ; la tâche du mois se valide seule.
 * Lecture seule hors de n_recipes_month_review et n_wizard_done : aucune recette ni fiche produit changée ici (liens vers la
 * Karta produktu, la recette, Wagi produktów, la vérification « 1 karton »).
 */
final class RecipesMonthService
{
    public const CODE = 'recipes_month_reviewed';
    public const PATH = '/recipes/month';
    public const DECISIONS = ['checked' => 'Uzupełnione — sprawdzone', 'assigned' => 'Przekazane do uzupełnienia', 'not_applicable' => 'Nie dotyczy'];
    /** Décisions qui demandent une note (au moins 3 caractères). */
    public const NOTE_REQUIRED = ['assigned', 'not_applicable'];
    /** Braki : code => [libellé, écran où compléter (clé de LINKS)]. Ordre = priorité d'affichage. */
    public const SIGNALS = [
        'NO_RECIPE' => ['brak receptury', 'card_recipe'],
        'RECIPE_NOT_FOUND' => ['receptura przypisana, ale nie istnieje', 'card_recipe'],
        'RECIPE_EMPTY' => ['receptura bez składników', 'recipe'],
        'RECIPE_NO_COST' => ['brak kosztu surowców kartonu (0 zł)', 'recipe'],
        'RECIPE_MATERIAL_NO_PRICE' => ['surowce receptury bez ceny — koszt zaniżony', 'materials'],
        'RECIPE_PER_PIECE' => ['receptura wygląda na 1 sztukę, nie na karton', 'unit_check'],
        'WEIGHT_MISSING' => ['brak poprawnej masy produktu (g / szt.)', 'weights'],
        'WEIGHT_SUSPECT' => ['masa 1 g — prawdopodobnie domyślna', 'weights'],
        'PPC_MISSING' => ['brak liczby sztuk w kartonie', 'card'],
        'PALLET_MISSING' => ['brak danych palety (kartony na warstwę, warstwy, kartony na paletę)', 'card_pallet'],
        'PALLET_OVER' => ['kartonów na paletę więcej niż warstwy × kartony na warstwę', 'card_pallet'],
        'SHELF_LIFE_MISSING' => ['brak przydatności do spożycia (dni)', 'card'],
        'NO_CATEGORY' => ['brak kategorii produktu', 'card'],
        'NO_SECTION' => ['brak działu produkcyjnego', 'card'],
    ];
    /** Écrans existants où l'on complète (%d = produit, %r = receptura ; aucun éditeur ici). */
    public const LINKS = [
        'card' => ['/workshop/products#/product/%d', 'Karta produktu'],
        'card_pallet' => ['/workshop/products#/product/%d?step=3', 'Karta produktu › Paleta'],
        'card_recipe' => ['/workshop/products#/product/%d?step=4', 'Karta produktu › Receptura'],
        'recipe' => ['/recipes/%r/edit', 'Edycja receptury'],
        'materials' => ['/materials/month', 'Kartoteka surowców (asystent)'],
        'unit_check' => ['/products/new-with-recipe', 'Receptury do sprawdzenia (1 karton)'],
        'weights' => ['/audits/product-weights', 'Wagi produktów'],
    ];
    /** Liste des produits sans recette (PDF existant de l'écran Receptury). */
    public const NO_RECIPE_PDF = '/ajax/recipes/products-without-recipe/pdf';
    private const PALLET_FIELDS = ['cpl', 'lpp', 'cpp'];
    private const DAY_ABBR = [1 => 'pon.', 2 => 'wt.', 3 => 'śr.', 4 => 'czw.', 5 => 'pt.', 6 => 'sob.', 7 => 'niedz.'];

    public function __construct(private RecipesMonthRepository $repo, private RecipeUnitCheckRepository $unitCheck, private LightWizardRepository $wizards)
    {
    }

    /** Premier jour du mois du contrôle (clé des décisions et de l'état de la tâche du mois). */
    public static function reviewMonth(string $today): string
    {
        return substr($today, 0, 7) . '-01';
    }

    /** Données des trois étapes ($withAll : aussi tous les produits, signalés ou non — pour enregistrer « uzupełnione »). */
    public function data(?string $today = null, bool $withAll = false): array
    {
        $today ??= date('Y-m-d');
        $month = self::reviewMonth($today);
        $products = $this->repo->activeProducts();
        $perPiece = [];
        if ($products && $this->repo->unitCheckAvailable()) foreach ($this->unitCheck->candidates() as $c) $perPiece[(int) $c['id']] = $c;

        $items = [];
        $stats = ['total' => 0, 'no_recipe' => 0, 'recipe' => 0, 'data' => 0, 'incomplete' => 0];
        foreach ($products ?? [] as $p) {
            $it = $this->productItem($p, $perPiece[(int) $p['id_final_product']] ?? null);
            $stats['total']++;
            if (in_array('NO_RECIPE', $it['codes'], true)) $stats['no_recipe']++;
            if (array_intersect($it['codes'], ['RECIPE_NOT_FOUND', 'RECIPE_EMPTY', 'RECIPE_NO_COST', 'RECIPE_MATERIAL_NO_PRICE', 'RECIPE_PER_PIECE'])) $stats['recipe']++;
            if (array_diff($it['codes'], ['NO_RECIPE', 'RECIPE_NOT_FOUND', 'RECIPE_EMPTY', 'RECIPE_NO_COST', 'RECIPE_MATERIAL_NO_PRICE', 'RECIPE_PER_PIECE'])) $stats['data']++;
            if ($it['codes'] !== []) $stats['incomplete']++;
            $items[] = $it;
        }

        // Décisions : celle du mois ; sinon « Nie dotyczy » d'un mois précédent qui couvre les mêmes braki (reprise).
        // Un produit reste à l'écran s'il a une décision du mois, même complété depuis.
        $decided = array_flip($this->repo->decidedIn($month));
        $all = $withAll ? array_column($items, null, 'key') : [];
        $items = array_values(array_filter($items, fn($i) => $i['codes'] !== [] || isset($decided[$i['key']])));
        $dec = $this->repo->decisions(array_column($items, 'key'));
        foreach ($items as &$it) {
            $mine = null;
            $earlier = null;
            foreach ($dec[$it['key']] ?? [] as $d) {
                if (substr((string) $d['month_start'], 0, 10) === $month) { $mine ??= $d; continue; }
                $earlier ??= $d;
            }
            $covers = fn(?array $d) => $d !== null && array_diff($it['codes'], array_filter(explode(',', (string) $d['flags']))) === [];
            $carried = $mine === null && $earlier !== null && $earlier['decision'] === 'not_applicable' && $it['codes'] !== [] && $covers($earlier);
            $covered = $mine !== null && ($mine['decision'] === 'checked' ? $it['codes'] === [] : $covers($mine));
            $it['decision'] = $mine ? $this->presentDecision($mine) : null;
            $it['earlier'] = $carried ? $this->presentDecision($earlier) : null;
            $it['previous'] = !$carried && $mine === null && $earlier !== null ? $this->presentDecision($earlier) : null;
            $it['waiting'] = $it['codes'] !== [] && !$covered && !$carried;
            $it['reason'] = $it['waiting'] && $mine !== null ? ($mine['decision'] === 'checked' ? 'brak wrócił po « Uzupełnione »' : 'nowy brak po decyzji') : null;
        }
        unset($it);
        usort($items, fn($a, $b) => [$b['waiting'], count($b['codes']), $a['title'], $a['key']] <=> [$a['waiting'], count($a['codes']), $b['title'], $b['key']]);

        $eff = fn(array $i) => $i['decision'] ?? $i['earlier'];
        $count = fn(callable $f) => count(array_filter($items, $f));
        $byCode = [];
        foreach (array_keys(self::SIGNALS) as $c) $byCode[$c] = $count(fn($i) => in_array($c, $i['codes'], true));
        $totals = ['review' => count($items), 'waiting' => $count(fn($i) => $i['waiting']), 'resolved' => $count(fn($i) => !$i['waiting']),
            'earlier' => $count(fn($i) => $i['decision'] === null && $i['earlier'] !== null), 'by_code' => $byCode, 'products' => $stats];
        foreach (array_keys(self::DECISIONS) as $k) $totals[$k] = $count(fn($i) => ($d = $eff($i)) !== null && $d['code'] === $k);
        $label = TaskWizards::monthLabel(substr($month, 0, 7));
        $done = $this->wizards->done(self::CODE, $month, date('Y-m-01', strtotime($month . ' +1 month')));
        return ($withAll ? ['all' => $all] : []) + ['today' => $today, 'label' => self::label($today), 'month_start' => $month, 'month_label' => $label,
            'source' => ['available' => $products !== null && $products !== [], 'rows' => $products === null ? null : count($products),
                'note' => $products === null ? 'brak danych — brak tabeli produktów w bazie' : ($products === [] ? 'brak danych — brak aktywnych produktów' : null),
                'unit_check' => $products !== null && $this->repo->unitCheckAvailable()],
            'review' => $items, 'totals' => $totals, 'can_finish' => $totals['waiting'] === 0, 'summary' => self::summary($label, $totals),
            'decisions' => self::DECISIONS, 'note_required' => self::NOTE_REQUIRED, 'signal_labels' => array_map(fn($s) => $s[0], self::SIGNALS),
            'pdf' => ['href' => self::NO_RECIPE_PDF, 'label' => 'PDF : produkty bez receptury'],
            'done' => $done ? ['summary' => $done['summary'], 'at' => self::label((string) $done['day']) . ', ' . self::hm((string) $done['done_at']), 'by' => $done['by_login'] ?? null] : null];
    }

    /** Produit actif : braki de la recette et des données, avec les règles des écrans existants. */
    private function productItem(array $p, ?array $perPiece): array
    {
        $id = (int) $p['id_final_product'];
        $recipeId = $p['id_recipe'] !== null && (int) $p['id_recipe'] > 0 ? (int) $p['id_recipe'] : null;
        $codes = [];
        $detail = [];
        if ($recipeId === null) $codes[] = 'NO_RECIPE';
        elseif ($p['recipe_exists'] !== null && (int) $p['recipe_exists'] === 0) $codes[] = 'RECIPE_NOT_FOUND';
        else {
            if ($p['recipe_lines'] !== null && (int) $p['recipe_lines'] === 0) $codes[] = 'RECIPE_EMPTY';
            if ($p['card_cost'] === null || (float) $p['card_cost'] <= 0) $codes[] = 'RECIPE_NO_COST';
            if ($p['no_price_materials'] !== null && trim((string) $p['no_price_materials']) !== '') {
                $names = explode("\n", (string) $p['no_price_materials']);
                $codes[] = 'RECIPE_MATERIAL_NO_PRICE';
                $detail['RECIPE_MATERIAL_NO_PRICE'] = implode(', ', array_slice($names, 0, 4)) . (count($names) > 4 ? ' i ' . (count($names) - 4) . ' inne' : '');
            }
            if ($perPiece !== null) {
                $codes[] = 'RECIPE_PER_PIECE';
                $detail['RECIPE_PER_PIECE'] = 'masa składników ' . self::num((float) $perPiece['mass']) . ' g wobec kartonu ' . self::num((float) $perPiece['weight'] * (int) $perPiece['ppc']) . ' g';
            }
        }
        $w = ProductWeightAuditEvaluator::evaluate($p['weight'], $p['ppc']);
        if ($w['audit_status'] === ProductWeightAuditEvaluator::ERROR) { $codes[] = 'WEIGHT_MISSING'; $detail['WEIGHT_MISSING'] = (string) $w['audit_reason']; }
        elseif ($w['audit_status'] === ProductWeightAuditEvaluator::WARNING) $codes[] = 'WEIGHT_SUSPECT';
        // Logistique : la règle du formulaire « nouveau produit » (champs exigés > 0, palette ≤ warstwy × kartony na warstwę).
        $values = [];
        foreach (['ppc', 'cpl', 'lpp', 'cpp'] as $f) $values[$f] = $p[$f] === null ? '' : (string) $p[$f];
        $err = ProductLogistics::errors($values, [], ['ppc', 'cpl', 'lpp', 'cpp']);
        if (isset($err['ppc'])) $codes[] = 'PPC_MISSING';
        $palletMissing = array_values(array_filter(self::PALLET_FIELDS, fn($f) => isset($err[$f]) && $err[$f][0] !== 'PALLET_OVER_LAYERS'));
        if ($palletMissing) { $codes[] = 'PALLET_MISSING'; $detail['PALLET_MISSING'] = implode(', ', array_map(fn($f) => mb_strtolower(ProductLogistics::LABELS[$f]), $palletMissing)); }
        $over = ProductLogistics::palletMax((int) $p['cpl'], (int) $p['lpp'], (int) $p['cpp']);
        if (!$palletMissing && $over !== null) { $codes[] = 'PALLET_OVER'; $detail['PALLET_OVER'] = (int) $p['cpp'] . ' > ' . $over; }
        [$minDays, $maxDays] = ProductLogistics::SHELF_LIFE;
        $shelf = $p['shelf_life'] === null ? null : (int) $p['shelf_life'];
        if ($shelf === null || $shelf < $minDays || $shelf > $maxDays) $codes[] = 'SHELF_LIFE_MISSING';
        if ($p['id_category'] === null || (int) $p['id_category'] <= 0 || $p['category_name'] === null) $codes[] = 'NO_CATEGORY';
        if ($p['id_section'] === null || (int) $p['id_section'] <= 0 || $p['section_name'] === null) $codes[] = 'NO_SECTION';
        // Base partielle : catégorie / dział sans table → pas de signal (rien à comparer).
        if ($p['category_name'] === null && $p['id_category'] !== null && (int) $p['id_category'] > 0 && !$this->repo->hasTable('category_product')) $codes = array_values(array_diff($codes, ['NO_CATEGORY']));
        if ($p['section_name'] === null && $p['id_section'] !== null && (int) $p['id_section'] > 0 && !$this->repo->hasTable('production_sections')) $codes = array_values(array_diff($codes, ['NO_SECTION']));

        $sub = array_filter([$p['category_name'] ? (string) $p['category_name'] : null, $p['section_name'] ? (string) $p['section_name'] : null,
            $p['ppc'] ? (int) $p['ppc'] . ' szt. / karton' : null, $w['unit_weight_g'] !== null ? self::num((float) $w['unit_weight_g']) . ' g / szt.' : null,
            $p['card_cost'] !== null && (float) $p['card_cost'] > 0 ? self::money((float) $p['card_cost']) . ' / karton' : null]);
        return ['key' => 'product:' . $id, 'source_id' => $id, 'id_recipe' => $recipeId, 'title' => trim((string) $p['product_name']) ?: '#' . $id, 'sub' => implode(' · ', $sub),
            'codes' => $codes, 'signals' => array_map(fn($c) => ['code' => $c, 'label' => self::SIGNALS[$c][0], 'detail' => $detail[$c] ?? null], $codes),
            'links' => self::linksFor($codes, $id, $recipeId && !in_array('RECIPE_NOT_FOUND', $codes, true) ? $recipeId : null)];
    }

    /** Écrans où compléter les braki du produit (dans l'ordre des braki), sinon la Karta produktu. */
    private static function linksFor(array $codes, int $productId, ?int $recipeId): array
    {
        $keys = array_values(array_unique(array_map(fn($c) => self::SIGNALS[$c][1], $codes)));
        if ($recipeId === null) $keys = array_values(array_filter($keys, fn($k) => $k !== 'recipe'));
        if (in_array('recipe', $keys, true) && !in_array('card_recipe', $keys, true)) $keys[] = 'card_recipe';
        if ($keys === []) $keys = ['card'];
        return array_map(fn($k) => ['href' => str_replace(['%d', '%r'], [(string) $productId, (string) $recipeId], self::LINKS[$k][0]), 'label' => self::LINKS[$k][1]], $keys);
    }

    private function presentDecision(array $d): array
    {
        $flags = array_values(array_filter(explode(',', (string) $d['flags'])));
        return ['code' => (string) $d['decision'], 'label' => self::DECISIONS[$d['decision']] ?? (string) $d['decision'], 'note' => $d['note'], 'flags' => $flags,
            'month' => substr((string) $d['month_start'], 0, 7), 'month_label' => TaskWizards::monthLabel(substr((string) $d['month_start'], 0, 7)),
            'by' => $d['decided_by'], 'at' => self::label(substr((string) $d['updated_at'], 0, 10)) . ', ' . self::hm((string) $d['updated_at'])];
    }

    /**
     * Krok 2 : décision pour un produit (une par mois du contrôle, la dernière compte). « Uzupełnione — sprawdzone » n'est
     * acceptée que si le produit n'a plus aucun brak maintenant. Rien n'est écrit dans les recettes ni les fiches produits.
     */
    public function saveDecision(array $in, ?int $userId, ?string $today = null): array
    {
        $today ??= date('Y-m-d');
        $key = (string) ($in['key'] ?? '');
        $decision = (string) ($in['decision'] ?? '');
        $note = trim((string) ($in['note'] ?? ''));
        if (!isset(self::DECISIONS[$decision])) throw new CustomException('Wybierz decyzję : uzupełnione — sprawdzone, przekazane do uzupełnienia albo nie dotyczy.', 'errors', 422);
        if (mb_strlen($note) > 500) throw new CustomException('Notatka : najwyżej 500 znaków.', 'errors', 422);
        $d = $this->data($today, true);
        $it = array_values(array_filter($d['review'], fn($x) => $x['key'] === $key))[0] ?? null;
        $seen = array_values(array_intersect(array_keys(self::SIGNALS), array_map('strval', (array) ($in['seen'] ?? []))));
        // Brak complété dans l'ERP depuis l'affichage : le produit n'est plus signalé, « uzupełnione » s'enregistre avec les braki vus à l'écran.
        if ($it === null && $decision === 'checked' && $seen !== [] && isset($d['all'][$key]) && $d['all'][$key]['codes'] === []) $it = $d['all'][$key];
        if ($it === null) {
            if (isset($d['all'][$key]) && $decision === 'checked') throw new CustomException('Ten produkt nie miał braków w tym miesiącu.', 'errors', 422);
            throw new CustomException('Tego produktu nie ma na liście (nieaktywny albo bez braków w tym miesiącu).', 'errors', 422);
        }
        if ($decision === 'assigned' && mb_strlen($note) < 3) throw new CustomException('Przekazane do uzupełnienia : wpisz komu i do kiedy (np. technolog, receptura do 10.12).', 'errors', 422);
        if ($decision === 'not_applicable' && mb_strlen($note) < 3) throw new CustomException('Nie dotyczy : wpisz dlaczego (np. produkt wycofywany, sprzedaż sezonowa).', 'errors', 422);
        if ($decision === 'checked' && $it['codes'] !== []) {
            throw new CustomException('Nadal brakuje : ' . implode(', ', array_map(fn($c) => self::SIGNALS[$c][0], $it['codes'])) . '. Uzupełnij dane na ekranie ' . $it['links'][0]['label'] . ' i zapisz ponownie.', 'errors', 422);
        }
        if ($decision !== 'checked' && $it['codes'] === []) throw new CustomException('Ten produkt nie ma już braków — wybierz « Uzupełnione — sprawdzone ».', 'errors', 422);
        $flags = $it['codes'] !== [] ? $it['codes'] : ($seen ?: ($it['decision']['flags'] ?? []));
        $this->repo->saveDecision($d['month_start'], $key, $it['source_id'], $decision, $note !== '' ? $note : null, implode(',', $flags), $userId);
        return $this->data($today);
    }

    /** Krok 3 : « Zakończ » — chaque produit signalé a sa décision ; la tâche du mois se valide seule. */
    public function finish(?int $userId, ?string $today = null): array
    {
        $today ??= date('Y-m-d');
        $d = $this->data($today);
        $w = $d['totals']['waiting'];
        if ($w > 0) throw new CustomException('Najpierw zapisz decyzję dla ' . $w . ' ' . self::pl($w, 'produktu', 'produktów', 'produktów') . ' z kroku 2.', 'errors', 422);
        $this->wizards->markDone(self::CODE, $today, $d['summary'], $userId);
        return ['summary' => $d['summary'], 'data' => $this->data($today)];
    }

    /** « listopad 2026 : produkty 4 z brakami / 30 · bez receptury 1 · receptury 2 · dane 3 · decyzje : 2 sprawdzone · 1 przekazane · 1 nie dotyczy » */
    public static function summary(string $month, array $t): string
    {
        $p = $t['products'];
        $s = $month . ' : produkty ' . $p['incomplete'] . ' z brakami / ' . $p['total'] . ' · bez receptury ' . $p['no_recipe'] . ' · receptury do poprawy ' . $p['recipe'] . ' · dane ' . $p['data'];
        $dec = [];
        if ($t['checked']) $dec[] = $t['checked'] . ' sprawdzone';
        if ($t['assigned']) $dec[] = $t['assigned'] . ' przekazane';
        if ($t['not_applicable']) $dec[] = $t['not_applicable'] . ' nie dotyczy';
        return $dec ? $s . ' · decyzje : ' . implode(' · ', $dec) : $s;
    }

    public static function label(string $day): string
    {
        $t = strtotime($day);
        return $t === false ? $day : self::DAY_ABBR[(int) date('N', $t)] . ' ' . date('j.m', $t);
    }

    private static function hm(string $ts): string
    {
        return strlen($ts) >= 16 ? (int) substr($ts, 11, 2) . substr($ts, 13, 3) : '';
    }

    private static function num(float $v): string
    {
        return rtrim(rtrim(number_format($v, 3, ',', ' '), '0'), ',');
    }

    private static function money(float $v): string
    {
        return number_format($v, 2, ',', ' ') . ' zł';
    }

    private static function pl(int $n, string $one, string $few, string $many): string
    {
        return $n === 1 ? $one : (in_array($n % 10, [2, 3, 4], true) && !in_array($n % 100, [12, 13, 14], true) ? $few : $many);
    }
}
