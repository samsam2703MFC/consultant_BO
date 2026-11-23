<?php
declare(strict_types=1);

namespace App\Services\Organization\Wizards;

use App\Exceptions\CustomException;
use App\Repositories\Organization\LightWizardRepository;
use App\Repositories\Workshop\MaterialsMonthRepository;
use App\Services\Organization\TaskWizards;

/**
 * Asystent « Kartoteka surowców » [401] (co miesiąc ; zakres obowiązków « produkcja » 237 : Kierownik produkcji 1.3
 * « Dodawanie surowców wykorzystywanych w produkcji » et 1.5 « Kategoryzacja surowców i produktów »). Mois du contrôle =
 * le mois de la tâche, état des fiches aujourd'hui. Surowce utilisés dans la recette d'un produit actif (les autres sont
 * comptés, pas signalés) et nowe surowce (créés depuis le dernier « Zakończ » : raw_material.id au-delà du repère
 * n_materials_month_mark ; avant le premier, première cena depuis le début du mois écoulé). « Complet » = la fiche
 * surowca de l'ERP, sans en inventer :
 *   - kategoria (category_material), jednostka bazowa (unit), cena aktualna (price_per_unit > 0), wielkość opakowania
 *     (package_size_in_base_unit > 0 : cena opakowania, zamówienia), dostawca (id_current_supplier, actif) ;
 *   - wartości odżywcze (écran Wartości odżywcze : surowce hors opakowania) : toutes vides ou à 0 = brak.
 * Alergeny : l'ERP ne distingue pas « aucun alergen » de « non saisi » (material_allergen_connection) — compté, jamais
 * signalé. Décisions (n_materials_month_review) : Uzupełnione — sprawdzone (seulement sans braki ; pour un nouveau
 * surowiec sans brak : sprawdzone), Zapytanie do dostawcy wysłane (note ; revient le mois suivant tant que les braki
 * restent), Nie dotyczy (note, reprise tant que les braki sont les mêmes). « Zakończ » écrit n_wizard_done et le repère
 * des nowe surowce ; la tâche du mois se valide seule.
 * Lecture seule hors de n_materials_month_review, n_materials_month_mark et n_wizard_done : aucune fiche changée ici,
 * aucun e-mail envoyé.
 */
final class MaterialsMonthService
{
    public const CODE = 'materials_month_reviewed';
    public const PATH = '/materials/month';
    public const DECISIONS = ['checked' => 'Uzupełnione — sprawdzone', 'supplier_asked' => 'Zapytanie do dostawcy wysłane', 'not_applicable' => 'Nie dotyczy'];
    public const NOTE_REQUIRED = ['supplier_asked', 'not_applicable'];
    /** Braki et signaux : code => [libellé, écran où compléter (clé de LINKS)]. Ordre = priorité d'affichage. */
    public const SIGNALS = [
        'NO_CATEGORY' => ['brak kategorii surowca', 'edit'],
        'NO_UNIT' => ['brak jednostki bazowej', 'edit'],
        'NO_PRICE' => ['brak aktualnej ceny', 'prices'],
        'NO_PACKAGE_SIZE' => ['brak wielkości opakowania (cena opakowania, zamówienia)', 'edit'],
        'NO_SUPPLIER' => ['brak dostawcy', 'edit'],
        'SUPPLIER_INACTIVE' => ['dostawca nieaktywny', 'suppliers'],
        'NO_NUTRITION' => ['brak wartości odżywczych', 'nutrition'],
        'NEW' => ['nowy surowiec od ostatniego przeglądu — sprawdź kartę', null],
    ];
    public const LINKS = [
        'edit' => ['/materials/%d/edit', 'Karta surowca'],
        'prices' => ['/materials/prices', 'Ceny surowców'],
        'suppliers' => ['/material-suppliers', 'Dostawcy surowców'],
        'nutrition' => ['/materials/nutritional-values', 'Wartości odżywcze'],
    ];
    private const DAY_ABBR = [1 => 'pon.', 2 => 'wt.', 3 => 'śr.', 4 => 'czw.', 5 => 'pt.', 6 => 'sob.', 7 => 'niedz.'];

    public function __construct(private MaterialsMonthRepository $repo, private LightWizardRepository $wizards)
    {
    }

    public static function reviewMonth(string $today): string
    {
        return substr($today, 0, 7) . '-01';
    }

    /** Données des trois étapes ($withAll : aussi tous les surowce — pour enregistrer « uzupełnione » d'un surowiec complété). */
    public function data(?string $today = null, bool $withAll = false): array
    {
        $today ??= date('Y-m-d');
        $month = self::reviewMonth($today);
        $prev = date('Y-m-01', strtotime($month . ' -1 month'));
        $materials = $this->repo->materials();
        $ref = $this->repo->references();
        $mark = $this->repo->lastMark($month);
        $newRule = $mark !== null ? 'mark' : ($ref['price_log'] ? 'first_price' : null);

        $items = [];
        $stats = ['total' => 0, 'used' => 0, 'incomplete' => 0, 'unused_incomplete' => 0, 'new' => 0, 'no_allergen' => 0];
        $all = [];
        foreach ($materials ?? [] as $m) {
            $new = $newRule === 'mark' ? (int) $m['id_raw_material'] > (int) $mark['max_material_id']
                : ($newRule === 'first_price' && $m['first_price_at'] !== null && (string) $m['first_price_at'] >= $prev . ' 00:00:00');
            $it = $this->materialItem($m, $ref, $new);
            $used = $m['active_products'] === null || (int) $m['active_products'] > 0;
            $stats['total']++;
            if ($used) $stats['used']++;
            if ($new) $stats['new']++;
            if ($it['codes'] !== [] && $used) $stats['incomplete']++;
            if ($it['codes'] !== [] && !$used) $stats['unused_incomplete']++;
            if ($used && $it['food'] && $m['allergens'] !== null && (int) $m['allergens'] === 0) $stats['no_allergen']++;
            if ($withAll) $all[$it['key']] = $it;
            if ($used || $new) $items[] = $it;
        }

        $decided = array_flip($this->repo->decidedIn($month));
        $items = array_values(array_filter($items, fn($i) => ($i['codes'] !== [] && $i['used']) || $i['new'] || isset($decided[$i['key']])));
        $dec = $this->repo->decisions(array_column($items, 'key'));
        foreach ($items as &$it) {
            $mine = null;
            $earlier = null;
            foreach ($dec[$it['key']] ?? [] as $d) {
                if (substr((string) $d['month_start'], 0, 10) === $month) { $mine ??= $d; continue; }
                $earlier ??= $d;
            }
            $covers = fn(?array $d) => $d !== null && array_diff($it['codes'], array_filter(explode(',', (string) $d['flags']))) === [];
            $carried = $mine === null && $earlier !== null && $earlier['decision'] === 'not_applicable' && $it['codes'] !== [] && !$it['new'] && $covers($earlier);
            $covered = $mine !== null && ($mine['decision'] === 'checked' ? $it['codes'] === [] : $covers($mine));
            $it['decision'] = $mine ? $this->presentDecision($mine) : null;
            $it['earlier'] = $carried ? $this->presentDecision($earlier) : null;
            $it['previous'] = !$carried && $mine === null && $earlier !== null ? $this->presentDecision($earlier) : null;
            $it['waiting'] = $it['signals'] !== [] && !$covered && !$carried;
            $it['reason'] = $it['waiting'] && $mine !== null ? ($mine['decision'] === 'checked' ? 'brak wrócił po « Uzupełnione »' : 'nowy brak po decyzji') : null;
        }
        unset($it);
        usort($items, fn($a, $b) => [$b['waiting'], count($b['codes']), $a['title'], $a['key']] <=> [$a['waiting'], count($a['codes']), $b['title'], $b['key']]);

        $eff = fn(array $i) => $i['decision'] ?? $i['earlier'];
        $count = fn(callable $f) => count(array_filter($items, $f));
        $byCode = [];
        foreach (array_keys(self::SIGNALS) as $c) $byCode[$c] = $count(fn($i) => in_array($c, array_column($i['signals'], 'code'), true));
        $totals = ['review' => count($items), 'waiting' => $count(fn($i) => $i['waiting']), 'resolved' => $count(fn($i) => !$i['waiting']),
            'earlier' => $count(fn($i) => $i['decision'] === null && $i['earlier'] !== null), 'by_code' => $byCode, 'materials' => $stats];
        foreach (array_keys(self::DECISIONS) as $k) $totals[$k] = $count(fn($i) => ($d = $eff($i)) !== null && $d['code'] === $k);
        $label = TaskWizards::monthLabel(substr($month, 0, 7));
        $done = $this->wizards->done(self::CODE, $month, date('Y-m-01', strtotime($month . ' +1 month')));
        $since = $newRule === 'mark' ? substr((string) $mark['marked_at'], 0, 10) : $prev;
        return ($withAll ? ['all' => $all] : []) + ['today' => $today, 'label' => self::label($today), 'month_start' => $month, 'month_label' => $label,
            'new_rule' => $newRule, 'new_since' => $newRule ? $since : null, 'new_since_label' => $newRule ? self::label($since) . '.' . substr($since, 0, 4) : null,
            'source' => ['available' => $materials !== null && $materials !== [], 'rows' => $materials === null ? null : count($materials),
                'note' => $materials === null ? 'brak danych — brak tabeli surowców w bazie' : ($materials === [] ? 'brak danych — brak surowców' : null)],
            'references' => $ref,
            'review' => $items, 'totals' => $totals, 'can_finish' => $totals['waiting'] === 0, 'summary' => self::summary($label, $totals),
            'decisions' => self::DECISIONS, 'note_required' => self::NOTE_REQUIRED, 'signal_labels' => array_map(fn($s) => $s[0], self::SIGNALS),
            'links' => array_map(fn($l) => ['href' => $l[0], 'label' => $l[1]], array_diff_key(self::LINKS, ['edit' => 1])),
            'done' => $done ? ['summary' => $done['summary'], 'at' => self::label((string) $done['day']) . ', ' . self::hm((string) $done['done_at']), 'by' => $done['by_login'] ?? null] : null];
    }

    /** Surowiec : braki de la fiche (seulement ce que la base permet de vérifier) et « nowy ». */
    private function materialItem(array $m, array $ref, bool $new): array
    {
        $id = (int) $m['id_raw_material'];
        $packaging = (int) $m['is_part_of_package'] === 1 || ($m['category_group'] !== null && (int) $m['category_group'] === 1);
        $codes = [];
        if ($ref['category'] && ($m['id_category'] === null || $m['category_name'] === null)) $codes[] = 'NO_CATEGORY';
        if ($ref['unit'] && ($m['id_unit'] === null || $m['unit_name'] === null)) $codes[] = 'NO_UNIT';
        if ($m['price_per_unit'] === null || (float) $m['price_per_unit'] <= 0) $codes[] = 'NO_PRICE';
        if ($m['package_size'] === null || (float) $m['package_size'] <= 0) $codes[] = 'NO_PACKAGE_SIZE';
        if ($ref['supplier'] && ($m['id_supplier'] === null || $m['supplier_name'] === null)) $codes[] = 'NO_SUPPLIER';
        elseif ($m['supplier_active'] !== null && (int) $m['supplier_active'] === 0) $codes[] = 'SUPPLIER_INACTIVE';
        if (!$packaging && $m['nutrition_values'] !== null && (int) $m['nutrition_values'] === 0) $codes[] = 'NO_NUTRITION';
        $signals = array_map(fn($c) => ['code' => $c, 'label' => self::SIGNALS[$c][0], 'detail' => null], $codes);
        if ($new) $signals[] = ['code' => 'NEW', 'label' => self::SIGNALS['NEW'][0], 'detail' => $m['first_price_at'] ? 'pierwsza cena ' . self::label(substr((string) $m['first_price_at'], 0, 10)) : null];
        $unit = $m['unit_name'] ? (string) $m['unit_name'] : null;
        $price = $m['price_per_unit'] !== null && (float) $m['price_per_unit'] > 0 ? (float) $m['price_per_unit'] : null;
        $sub = array_filter([$m['category_name'] ? (string) $m['category_name'] : null, $packaging ? 'opakowanie' : null,
            $price !== null ? self::price($price, $unit) : null, $m['supplier_name'] ? 'dostawca ' . trim((string) $m['supplier_name']) : null,
            $m['active_products'] !== null ? (int) $m['active_products'] . ' ' . self::pl((int) $m['active_products'], 'produkt aktywny', 'produkty aktywne', 'produktów aktywnych') : null]);
        $keys = array_values(array_unique(array_filter(array_map(fn($c) => self::SIGNALS[$c][1], $codes))));
        if ($keys === []) $keys = ['edit'];
        return ['key' => 'material:' . $id, 'source_id' => $id, 'title' => trim((string) $m['material_name']) ?: '#' . $id, 'sub' => implode(' · ', $sub),
            'used' => $m['active_products'] === null || (int) $m['active_products'] > 0, 'new' => $new, 'food' => !$packaging,
            'codes' => $codes, 'signals' => $signals,
            'links' => array_map(fn($k) => ['href' => sprintf(self::LINKS[$k][0], $id), 'label' => self::LINKS[$k][1]], $keys)];
    }

    private function presentDecision(array $d): array
    {
        $flags = array_values(array_filter(explode(',', (string) $d['flags'])));
        return ['code' => (string) $d['decision'], 'label' => self::DECISIONS[$d['decision']] ?? (string) $d['decision'], 'note' => $d['note'], 'flags' => $flags,
            'month' => substr((string) $d['month_start'], 0, 7), 'month_label' => TaskWizards::monthLabel(substr((string) $d['month_start'], 0, 7)),
            'by' => $d['decided_by'], 'at' => self::label(substr((string) $d['updated_at'], 0, 10)) . ', ' . self::hm((string) $d['updated_at'])];
    }

    /**
     * Krok 2 : décision pour un surowiec (une par mois du contrôle, la dernière compte). « Uzupełnione — sprawdzone » n'est
     * acceptée que si la fiche n'a plus aucun brak maintenant (un nouveau surowiec complet : sprawdzone).
     */
    public function saveDecision(array $in, ?int $userId, ?string $today = null): array
    {
        $today ??= date('Y-m-d');
        $key = (string) ($in['key'] ?? '');
        $decision = (string) ($in['decision'] ?? '');
        $note = trim((string) ($in['note'] ?? ''));
        if (!isset(self::DECISIONS[$decision])) throw new CustomException('Wybierz decyzję : uzupełnione — sprawdzone, zapytanie do dostawcy albo nie dotyczy.', 'errors', 422);
        if (mb_strlen($note) > 500) throw new CustomException('Notatka : najwyżej 500 znaków.', 'errors', 422);
        $d = $this->data($today, true);
        $it = array_values(array_filter($d['review'], fn($x) => $x['key'] === $key))[0] ?? null;
        $seen = array_values(array_intersect(array_keys(self::SIGNALS), array_map('strval', (array) ($in['seen'] ?? []))));
        if ($it === null && $decision === 'checked' && $seen !== [] && isset($d['all'][$key]) && $d['all'][$key]['codes'] === []) $it = $d['all'][$key];
        if ($it === null) {
            if (isset($d['all'][$key]) && $d['all'][$key]['used'] && $decision === 'checked') throw new CustomException('Ten surowiec nie miał braku do sprawdzenia w tym miesiącu.', 'errors', 422);
            throw new CustomException('Tego surowca nie ma na liście (nieużywany w aktywnych produktach albo bez braków).', 'errors', 422);
        }
        if ($decision === 'supplier_asked' && mb_strlen($note) < 3) throw new CustomException('Zapytanie do dostawcy : wpisz, do kogo i o co (np. dostawca, cena, wartości odżywcze, data).', 'errors', 422);
        if ($decision === 'not_applicable' && mb_strlen($note) < 3) throw new CustomException('Nie dotyczy : wpisz dlaczego (np. woda bez wartości odżywczych, surowiec wycofywany).', 'errors', 422);
        if ($decision === 'checked' && $it['codes'] !== []) {
            throw new CustomException('Nadal brakuje : ' . implode(', ', array_map(fn($c) => self::SIGNALS[$c][0], $it['codes'])) . '. Uzupełnij kartę na ekranie ' . $it['links'][0]['label'] . ' i zapisz ponownie.', 'errors', 422);
        }
        if ($decision !== 'checked' && $it['codes'] === []) throw new CustomException('Ten surowiec nie ma braków — wybierz « Uzupełnione — sprawdzone ».', 'errors', 422);
        $flags = $it['codes'] !== [] ? $it['codes'] : (array_column($it['signals'], 'code') ?: ($seen ?: ($it['decision']['flags'] ?? [])));
        $this->repo->saveDecision($d['month_start'], $key, $it['source_id'], $decision, $note !== '' ? $note : null, implode(',', $flags), $userId);
        return $this->data($today);
    }

    /** Krok 3 : « Zakończ » — chaque surowiec signalé a sa décision ; repère des nowe surowce ; la tâche du mois se valide seule. */
    public function finish(?int $userId, ?string $today = null): array
    {
        $today ??= date('Y-m-d');
        $d = $this->data($today);
        $w = $d['totals']['waiting'];
        if ($w > 0) throw new CustomException('Najpierw zapisz decyzję dla ' . $w . ' ' . self::pl($w, 'surowca', 'surowców', 'surowców') . ' z kroku 2.', 'errors', 422);
        $this->wizards->markDone(self::CODE, $today, $d['summary'], $userId);
        if ($d['source']['rows'] !== null) $this->repo->saveMark($d['month_start'], $userId);
        return ['summary' => $d['summary'], 'data' => $this->data($today)];
    }

    /** « listopad 2026 : surowce 3 z brakami / 40 używanych · 2 nowe · decyzje : 2 sprawdzone · 1 zapytanie do dostawcy » */
    public static function summary(string $month, array $t): string
    {
        $m = $t['materials'];
        $s = $month . ' : surowce ' . $m['incomplete'] . ' z brakami / ' . $m['used'] . ' używanych';
        if ($m['new']) $s .= ' · ' . $m['new'] . ' ' . self::pl($m['new'], 'nowy', 'nowe', 'nowych');
        $dec = [];
        if ($t['checked']) $dec[] = $t['checked'] . ' sprawdzone';
        if ($t['supplier_asked']) $dec[] = $t['supplier_asked'] . ' ' . self::pl($t['supplier_asked'], 'zapytanie do dostawcy', 'zapytania do dostawców', 'zapytań do dostawców');
        if ($t['not_applicable']) $dec[] = $t['not_applicable'] . ' nie dotyczy';
        return $dec ? $s . ' · decyzje : ' . implode(' · ', $dec) : $s;
    }

    /** Prix lisible : g / ml → au kg / litre (comme Zaopatrzenie), sinon à l'unité. */
    private static function price(float $perUnit, ?string $unit): string
    {
        [$label, $factor] = match ($unit) { 'g' => ['kg', 1000], 'ml' => ['l', 1000], default => [$unit ?: 'j.', 1] };
        return number_format($perUnit * $factor, 2, ',', ' ') . ' zł / ' . $label;
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

    private static function pl(int $n, string $one, string $few, string $many): string
    {
        return $n === 1 ? $one : (in_array($n % 10, [2, 3, 4], true) && !in_array($n % 100, [12, 13, 14], true) ? $few : $many);
    }
}
