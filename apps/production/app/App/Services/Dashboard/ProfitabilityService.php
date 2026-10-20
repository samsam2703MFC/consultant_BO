<?php
namespace App\Services\Dashboard;

use App\Exceptions\CustomException;
use App\Repositories\Dashboard\ProfitabilityRepository;
use App\Repositories\Workshop\ParamRepository;
use DateTimeImmutable;

/**
 * Rentabilité de la production, page d'accueil de l'ERP.
 *
 * Par groupe de produits (dział produkcyjny) : ventes WDT, coût du personnel, ratio personnel /
 * ventes face à l'objectif, CA manquant pour l'atteindre (en € et en % des ventes) et marge après
 * matières, personnel et coûts du groupe. Pour l'entreprise : même ratio avec les services
 * communs, et résultat de production après tous les coûts, avec le CA qu'il faut pour l'objectif.
 *
 * Mois en cours : le ratio compare les ventes au coût du personnel au prorata des jours écoulés ;
 * le CA nécessaire porte sur le mois entier, donc « brakuje » = ce qu'il reste à vendre d'ici la
 * fin du mois. Un mois sans coûts saisis reprend le dernier mois de coûts connu (signalé).
 *
 * Matières et emballages (« achats du mois ») : cartons vendus × recette aplatie (ingrédients et
 * emballages d'un carton), chaque matière au prix et chez le fournisseur en vigueur à la fin du mois
 * (historique des prix ; à défaut prix actuel). Produit sans recette : coût matières de sa fiche.
 * Détail par catégorie de matière et par fournisseur.
 */
final class ProfitabilityService
{
    /** Mois de la série mensuelle (ventes par personne) ; la tendance en reprend les 6 derniers. */
    public const MONTHLY_SERIES = 18;

    private ?array $targets = null;
    private ?array $costs = null;
    /** @var array{materials:array<int,array>, ingredients:array<int,list<array>>, history:array<int,list<array>>}|null */
    private ?array $bom = null;
    /** @var array<string,array> valorisation matières par mois 'YYYY-MM' */
    private array $materialRuns = [];

    public function __construct(private ProfitabilityRepository $repo, private ParamRepository $params)
    {
    }

    /** Choix de période proposés : mois en cours, 11 mois précédents, 3 derniers mois, année. */
    public function periodOptions(?DateTimeImmutable $today = null): array
    {
        $today ??= new DateTimeImmutable('today');
        $options = ['month' => 'Bieżący miesiąc (' . self::monthLabel($today->format('Y-m')) . ')'];
        for ($i = 1; $i <= 11; $i++) {
            $ym = $today->modify('first day of this month')->modify("-{$i} months")->format('Y-m');
            $options[$ym] = self::monthLabel($ym);
        }
        $options['3m'] = 'Ostatnie 3 pełne miesiące';
        $options['ytd'] = 'Od początku roku ' . $today->format('Y');
        return $options;
    }

    public function overview(string $period = 'month', ?DateTimeImmutable $today = null): array
    {
        $today ??= new DateTimeImmutable('today');
        $spec = $this->period($period, $today);
        $rate = $this->repo->eurRate();
        // Une lecture des ventes pour la période, la série mensuelle (18 mois) et le tableau des périodes.
        $start = $today->modify('first day of this month')->modify('-' . (self::MONTHLY_SERIES - 1) . ' months')->format('Y-m-d');
        $all = $this->sales(min($start, $this->windowStart($today), $spec['from']), $today->format('Y-m-d'), $rate);
        $sales = array_values(array_filter($all, fn($r) => isset($spec['months'][$r['ym']])));
        $open = $spec['current'] ? $this->repo->openOrders($today->format('Y-m-d'), $spec['end']) : [];

        $data = $this->compute($spec, $sales, $open, $rate);
        $data['purchases'] = $this->purchases($spec['from'], $spec['to'], $rate);
        $monthly = $this->monthly($today, $rate, self::MONTHLY_SERIES, $all);
        $data['trend'] = array_slice($monthly, -6);
        $data['per_person'] = $this->perPerson($monthly);
        $data['options'] = $this->periodOptions($today);
        $data['period_table'] = $this->periodTable($today, $rate, $all);
        $data['last_sale'] = $this->repo->lastSaleDate();
        return $data;
    }

    /** Ratio personnel / ventes et résultat des 6 derniers mois (mois en cours compris). */
    public function trend(DateTimeImmutable $today, float $rate, ?array $sales = null): array
    {
        return $this->monthly($today, $rate, 6, $sales);
    }

    /**
     * Série des $count derniers mois (mois en cours compris, au prorata) : ventes, personnel / ventes,
     * résultat et ventes par personne (entreprise, production, chaque groupe).
     */
    public function monthly(DateTimeImmutable $today, float $rate, int $count, ?array $sales = null): array
    {
        $first = $today->modify('first day of this month')->modify('-' . ($count - 1) . ' months');
        $sales ??= $this->sales($first->format('Y-m-d'), $today->format('Y-m-d'), $rate);
        $out = [];
        for ($i = 0; $i < $count; $i++) {
            $ym = $first->modify("+{$i} months")->format('Y-m');
            $spec = $this->period($ym === $today->format('Y-m') ? 'month' : $ym, $today);
            $fraction = array_sum($spec['months']);
            $rows = array_values(array_filter($sales, fn($r) => $r['ym'] === $ym));
            $m = $this->compute($spec, $rows, [], $rate);
            $t = $m['totals'];
            $out[] = [
                'ym' => $ym,
                'label' => self::monthLabel($ym, true),
                'current' => $spec['current'],
                'revenue' => $t['revenue'],
                'staff_pct' => $t['staff_pct'],
                'margin_pct' => $t['margin_pct'],
                'estimated' => $m['estimated_months'] !== [],
                'estimated_from' => isset($m['estimated_months'][$ym]) ? self::monthLabel($m['estimated_months'][$ym]) : null,
                'people' => $t['people'],
                'people_production' => $t['people_production'],
                'person_months' => ($t['people'] ?? 0) * $fraction,
                'person_months_production' => ($t['people_production'] ?? 0) * $fraction,
                'revenue_production' => array_sum(array_column($m['groups'], 'revenue')),
                'revenue_per_person' => $t['revenue_per_person'],
                'revenue_per_person_production' => $t['revenue_per_person_production'],
                'groups' => array_column(array_map(fn($g) => ['id' => $g['id'], 'name' => $g['name'], 'revenue' => $g['revenue'],
                    'people' => $g['people'], 'person_months' => ($g['people'] ?? 0) * $fraction,
                    'revenue_per_person' => $g['revenue_per_person']], $m['groups']), null, 'id'),
            ];
        }
        return $out;
    }

    /**
     * Ventes par personne et par mois sur la série mensuelle (du plus récent au plus ancien) : groupes
     * classés par ventes, moyenne de la période (ventes ÷ mois-personnes) et meilleur mois de chaque
     * groupe (mois en cours exclu : tempo).
     */
    public function perPerson(array $monthly): array
    {
        $groups = [];
        foreach ($monthly as $m) {
            foreach ($m['groups'] as $id => $g) {
                $groups[$id] ??= ['id' => $id, 'name' => $g['name'], 'revenue' => 0.0, 'person_months' => 0.0, 'best' => null];
                $groups[$id]['revenue'] += $g['revenue'];
                $groups[$id]['person_months'] += $g['person_months'];
                if (!$m['current'] && $g['revenue_per_person'] !== null && $g['revenue_per_person'] > ($groups[$id]['best'] ?? 0)) {
                    $groups[$id]['best'] = $g['revenue_per_person'];
                }
            }
        }
        usort($groups, fn($a, $b) => $b['revenue'] <=> $a['revenue']);
        foreach ($groups as &$g) $g['average'] = $g['person_months'] > 0 ? $g['revenue'] / $g['person_months'] : null;
        unset($g);
        $sum = fn(string $key) => array_sum(array_column($monthly, $key));
        return [
            'months' => array_reverse($monthly),
            'groups' => $groups,
            'count' => count($monthly),
            'real' => count(array_filter($monthly, fn($m) => !$m['estimated'])),
            'average' => $sum('person_months') > 0 ? $sum('revenue') / $sum('person_months') : null,
            'average_production' => $sum('person_months_production') > 0 ? $sum('revenue_production') / $sum('person_months_production') : null,
        ];
    }

    /**
     * Liste des périodes du sélecteur, en tableau : ventes, personnel / ventes face à l'objectif et
     * résultat de chaque période (mois en cours au prorata des jours, comme la page).
     */
    public function periodTable(DateTimeImmutable $today, float $rate, ?array $sales = null): array
    {
        $sales ??= $this->sales($this->windowStart($today), $today->format('Y-m-d'), $rate);
        $rows = [];
        foreach ($this->periodOptions($today) as $key => $label) {
            $spec = $this->period($key, $today);
            $m = $this->compute($spec, array_values(array_filter($sales, fn($r) => isset($spec['months'][$r['ym']]))), [], $rate);
            $t = $m['totals'];
            $rows[] = [
                'key' => $key, 'label' => $label, 'current' => $spec['current'],
                'revenue' => $t['revenue'], 'staff_pct' => $t['staff_pct'], 'staff_target' => $t['staff_target'], 'staff_ok' => $t['staff_ok'],
                'margin_pct' => $t['margin_pct'], 'margin_ok' => $t['margin_ok'], 'estimated' => $m['estimated_months'] !== [],
            ];
        }
        return $rows;
    }

    /** Ventes par mois et groupe, matières valorisées par les recettes aux prix du mois (voir materialRun). */
    public function sales(string $from, string $to, float $rate): array
    {
        $rows = $this->repo->sales($from, $to, $rate);
        $run = $this->materialRun($from, $to);
        foreach ($rows as &$r) {
            $key = $r['ym'] . '|' . (int) $r['id_section'];
            if (isset($run['by_key'][$key])) $r['materials_pln'] = $run['by_key'][$key];
        }
        unset($r);
        return $rows;
    }

    /**
     * Achats du mois (ou de la période) : matières et emballages des cartons vendus, par catégorie et par
     * fournisseur (EUR), avec le détail des matières. Même total que « Surowce i opakowania ».
     */
    public function purchases(string $from, string $to, float $rate): array
    {
        $rate = $rate > 0 ? $rate : 1.0;
        $run = $this->materialRun($from, $to);
        $byMaterial = [];
        foreach ($run['materials'] as $lines) {
            foreach ($lines as $id => $l) {
                $byMaterial[$id] ??= ['qty' => 0.0, 'pln' => 0.0, 'supplier' => $l['supplier']];
                $byMaterial[$id]['qty'] += $l['qty'];
                $byMaterial[$id]['pln'] += $l['pln'];
                $byMaterial[$id]['supplier'] = $l['supplier'];
            }
        }
        $catalog = $this->bom['materials'];
        $total = array_sum(array_column($byMaterial, 'pln')) + array_sum(array_column($run['no_recipe'], 'pln'));
        $groups = ['categories' => [], 'suppliers' => []];
        foreach ($byMaterial as $id => $l) {
            $m = $catalog[$id];
            $line = ['name' => $m['name'], 'qty' => $l['qty'], 'unit' => $m['unit'], 'amount' => $l['pln'] / $rate, 'supplier' => $l['supplier'], 'category' => $m['category']];
            foreach (['categories' => $m['category'] ?? 'Bez kategorii', 'suppliers' => $l['supplier'] ?? 'Bez dostawcy'] as $view => $name) {
                $groups[$view][$name] ??= ['name' => $name, 'amount' => 0.0, 'items' => []];
                $groups[$view][$name]['amount'] += $line['amount'];
                $groups[$view][$name]['items'][] = $line;
            }
        }
        $noRecipe = array_sum(array_column($run['no_recipe'], 'pln')) / $rate;
        if ($noRecipe > 0.005) {
            $items = [];
            foreach ($run['no_recipe'] as $p) $items[] = ['name' => $p['product'], 'qty' => $p['cartons'], 'unit' => 'kart.', 'amount' => $p['pln'] / $rate, 'supplier' => null, 'category' => null];
            foreach (['categories', 'suppliers'] as $view) {
                $groups[$view]['__none'] = ['name' => 'Produkty bez receptury (koszt z karty produktu)', 'amount' => $noRecipe, 'items' => $items, 'no_recipe' => true];
            }
        }
        $totalEur = $total / $rate;
        foreach ($groups as &$list) {
            // Pozycje poniżej 0,50 € (np. ślad surowca w recepturze) nie zaśmiecają listy.
            $list = array_filter($list, fn($g) => $g['amount'] >= 0.5);
            foreach ($list as &$g) {
                usort($g['items'], fn($a, $b) => $b['amount'] <=> $a['amount']);
                $g['pct'] = $totalEur > 0 ? $g['amount'] / $totalEur * 100 : null;
            }
            unset($g);
            usort($list, fn($a, $b) => [!empty($a['no_recipe']), -$a['amount']] <=> [!empty($b['no_recipe']), -$b['amount']]);
        }
        unset($list);
        $uncategorised = 0.0;
        foreach ($groups['categories'] as $g) if ($g['name'] === 'Bez kategorii') $uncategorised = $g['amount'];
        return [
            'total' => $totalEur,
            'categories' => $groups['categories'],
            'suppliers' => $groups['suppliers'],
            'uncategorised' => $uncategorised,
            'no_recipe' => $noRecipe,
            'no_recipe_products' => count($run['no_recipe']),
            'card_total' => $run['card_total'] / $rate,
        ];
    }

    /**
     * Valorisation des cartons vendus sur [from, to] (mois entiers) : par mois et groupe (PLN), par mois et
     * matière (quantité, montant, fournisseur), produits sans recette (coût de la fiche produit). Chaque mois
     * est calculé une fois par requête (période, tendance et tableau des périodes se recouvrent).
     */
    private function materialRun(string $from, string $to): array
    {
        $months = [];
        for ($m = new DateTimeImmutable(substr($from, 0, 7) . '-01'); $m->format('Y-m') <= substr($to, 0, 7); $m = $m->modify('+1 month')) $months[] = $m->format('Y-m');
        $missing = array_values(array_diff($months, array_keys($this->materialRuns)));
        if ($missing) $this->computeMonths($missing);
        $run = ['by_key' => [], 'materials' => [], 'no_recipe' => [], 'card_total' => 0.0];
        foreach ($months as $ym) {
            $m = $this->materialRuns[$ym];
            $run['by_key'] += $m['by_key'];
            $run['materials'][$ym] = $m['materials'];
            foreach ($m['no_recipe'] as $id => $p) {
                $run['no_recipe'][$id] ??= ['product' => $p['product'], 'cartons' => 0.0, 'pln' => 0.0];
                $run['no_recipe'][$id]['cartons'] += $p['cartons'];
                $run['no_recipe'][$id]['pln'] += $p['pln'];
            }
            $run['card_total'] += $m['card_total'];
        }
        return $run;
    }

    /** @param list<string> $months mois 'YYYY-MM' à valoriser (une seule lecture des ventes). */
    private function computeMonths(array $months): void
    {
        sort($months);
        $sold = $this->repo->soldProducts($months[0] . '-01', (new DateTimeImmutable(end($months) . '-01'))->format('Y-m-t'));
        $this->loadBom(array_column($sold, 'id_recipe'));
        foreach ($months as $ym) $this->materialRuns[$ym] = ['by_key' => [], 'materials' => [], 'no_recipe' => [], 'card_total' => 0.0];
        $prices = [];
        foreach ($sold as $p) {
            if (!isset($this->materialRuns[$p['ym']])) continue;
            $run = &$this->materialRuns[$p['ym']];
            $cartons = (float) $p['cartons'];
            $groupKey = $p['ym'] . '|' . (int) $p['id_section'];
            $run['card_total'] += $cartons * (float) $p['card_cost'];
            $ingredients = $p['id_recipe'] !== null ? ($this->bom['ingredients'][(int) $p['id_recipe']] ?? []) : [];
            if (!$ingredients) {
                $pln = $cartons * (float) $p['card_cost'];
                $run['by_key'][$groupKey] = ($run['by_key'][$groupKey] ?? 0.0) + $pln;
                $run['no_recipe'][(int) $p['product_id']] ??= ['product' => $p['product'], 'cartons' => 0.0, 'pln' => 0.0];
                $run['no_recipe'][(int) $p['product_id']]['cartons'] += $cartons;
                $run['no_recipe'][(int) $p['product_id']]['pln'] += $pln;
                unset($run);
                continue;
            }
            $end = (new DateTimeImmutable($p['ym'] . '-01'))->format('Y-m-t') . ' 23:59:59';
            foreach ($ingredients as [$materialId, $qty]) {
                if (!isset($this->bom['materials'][$materialId])) continue;
                $prices[$p['ym']][$materialId] ??= $this->priceAt($materialId, $end);
                [$price, $supplier] = $prices[$p['ym']][$materialId];
                $used = $cartons * $qty;
                $pln = $used * $price;
                $run['by_key'][$groupKey] = ($run['by_key'][$groupKey] ?? 0.0) + $pln;
                $run['materials'][$materialId] ??= ['qty' => 0.0, 'pln' => 0.0, 'supplier' => $supplier];
                $run['materials'][$materialId]['qty'] += $used;
                $run['materials'][$materialId]['pln'] += $pln;
            }
            unset($run);
        }
    }

    /** Catalogue des matières, recettes aplaties des produits vendus et historique des prix (lus une fois). */
    private function loadBom(array $recipeIds): void
    {
        if ($this->bom === null) {
            $materials = [];
            foreach ($this->repo->materialCatalog() as $m) $materials[(int) $m['id']] = $m;
            $history = [];
            foreach ($this->repo->priceHistory() as $h) $history[(int) $h['material_id']][] = [$h['changed_at'], (float) $h['unit_price'], $h['supplier']];
            $this->bom = ['materials' => $materials, 'ingredients' => [], 'history' => $history];
        }
        $missing = array_diff(array_unique(array_map('intval', array_filter($recipeIds))), array_keys($this->bom['ingredients']));
        if (!$missing) return;
        foreach ($missing as $id) $this->bom['ingredients'][$id] = [];
        foreach ($this->repo->recipeIngredients($missing) as $r) {
            $this->bom['ingredients'][(int) $r['recipe_id']][] = [(int) $r['material_id'], (float) $r['quantity']];
        }
    }

    /** Prix unitaire (PLN) et fournisseur d'une matière à une date : dernier changement de prix avant, sinon actuels. */
    private function priceAt(int $materialId, string $at): array
    {
        $m = $this->bom['materials'][$materialId];
        $price = (float) $m['price_per_unit'];
        $supplier = $m['supplier'];
        $found = false;
        foreach ($this->bom['history'][$materialId] ?? [] as [$changedAt, $unitPrice, $historySupplier]) {
            if ($changedAt > $at) break;
            $price = $unitPrice;
            $supplier = $historySupplier ?? $supplier;
            $found = true;
        }
        return [$found ? $price : (float) $m['price_per_unit'], $supplier];
    }

    /** Début de la fenêtre des 12 mois glissants (ou du 1er janvier si plus tôt). */
    private function windowStart(DateTimeImmutable $today): string
    {
        return min($today->modify('first day of this month')->modify('-11 months')->format('Y-m-d'), $today->format('Y-01-01'));
    }

    /** Objectifs d'un groupe ; une valeur vide revient au paramètre général. */
    public function saveTarget(int $sectionId, array $input, ?int $userId): void
    {
        if (!$this->repo->sectionExists($sectionId)) {
            throw new CustomException('Nie znaleziono działu.', 'errors', 404);
        }
        $data = ['updated_by' => $userId];
        $messages = [
            'staff_pct_target' => 'Cel kosztu personelu: od 0 do 100 %.',
            'margin_pct_target' => 'Cel marży: od -100 do 100 %.',
            'bonus_pct' => 'Pula premii: od 0 do 100 % sprzedaży.',
        ];
        foreach (['staff_pct_target' => [0, 100], 'margin_pct_target' => [-100, 100], 'bonus_pct' => [-0.001, 100]] as $field => [$min, $max]) {
            if (!array_key_exists($field, $input)) continue;
            $raw = trim(str_replace(',', '.', (string) $input[$field]));
            if ($raw === '') { $data[$field] = null; continue; }
            if (!is_numeric($raw) || (float) $raw <= $min || (float) $raw >= $max) {
                throw new CustomException($messages[$field], 'errors', 422);
            }
            $data[$field] = round((float) $raw, 2);
        }
        if (array_key_exists('is_shared', $input)) $data['is_shared'] = (int) (bool) $input['is_shared'];
        $this->repo->saveTarget($sectionId, $data);
    }

    /** Objectifs généraux (paramètres). */
    public function saveGeneralTargets(array $input, ?int $userId): void
    {
        $limits = [
            'profit.staff_target_pct' => [0, 100],
            'profit.total_staff_target_pct' => [0, 100],
            'profit.section_margin_target_pct' => [-100, 100],
            'profit.margin_target_pct' => [-100, 100],
        ];
        $values = [];
        foreach ($limits as $code => [$min, $max]) {
            if (!array_key_exists($code, $input)) continue;
            $raw = trim(str_replace(',', '.', (string) $input[$code]));
            if (!is_numeric($raw) || (float) $raw <= $min || (float) $raw >= $max) {
                throw new CustomException('Nieprawidłowa wartość celu (' . $code . ').', 'errors', 422);
            }
            $values[$code] = (string) round((float) $raw, 2);
        }
        foreach ($values as $code => $value) {
            $this->params->setValue($code, $value, $userId);
        }
        $this->targets = null;
    }

    public function generalTargets(): array
    {
        return $this->targets ??= [
            'profit.staff_target_pct' => $this->param('profit.staff_target_pct', 25, true),
            'profit.total_staff_target_pct' => $this->param('profit.total_staff_target_pct', 35, true),
            'profit.section_margin_target_pct' => $this->param('profit.section_margin_target_pct', 30, false),
            'profit.margin_target_pct' => $this->param('profit.margin_target_pct', 10, false),
        ];
    }

    /**
     * @param array{months:array<string,float>, from:string, to:string, end:string, current:bool, label:string, elapsed:float} $spec
     */
    public function compute(array $spec, array $salesRows, array $openRows, float $rate): array
    {
        $warnings = [];
        if ($rate <= 0) {
            $warnings[] = 'Brak kursu EUR w ustawieniach firmy — koszty w PLN nie są przeliczone.';
            $rate = 1.0;
        }
        $this->costs ??= [$this->repo->costMonths(), $this->repo->staff(), $this->repo->media(), $this->repo->fixedCosts(), $this->repo->sections(), $this->repo->headcount()];
        [$costMonths, $staff, $media, $fixed, $sectionRows, $headcount] = $this->costs;
        $targets = $this->generalTargets();

        // Coûts du personnel et du groupe, au prorata (pace) et sur les mois entiers (full).
        $sections = [];
        foreach ($sectionRows as $s) {
            $sections[(int) $s['id']] = $s + ['staff' => 0.0, 'staff_full' => 0.0, 'overhead' => 0.0, 'overhead_full' => 0.0,
                'revenue' => 0.0, 'materials' => 0.0, 'cartons' => 0, 'open' => 0.0, 'person_months' => 0.0];
        }
        $fixedPace = 0.0;
        $fixedFull = 0.0;
        $estimated = [];
        $monthsCounted = array_sum($spec['months']);
        foreach ($spec['months'] as $ym => $fraction) {
            [$monthId, $source] = $this->costMonthFor($ym, $costMonths);
            if ($monthId === null) {
                $warnings[] = 'Brak miesięcznych kosztów (personel, media, koszty stałe) — uzupełnij je w kalkulatorze kosztów.';
                continue;
            }
            if ($source !== $ym) $estimated[$ym] = $source;
            foreach ($sections as $id => &$s) {
                $staffCost = ($staff[$monthId][$id] ?? 0.0) / $rate;
                $mediaCost = $media[$monthId][$id] ?? 0.0;
                if ($mediaCost == 0.0) {
                    $mediaCost = (float) $s['water_cost'] + (float) $s['power_supply_cost'] + (float) $s['fuel_cost'] + (float) $s['other_cost'];
                }
                $overhead = ((float) $s['depreciation'] + $mediaCost) / $rate;
                $s['staff_full'] += $staffCost;
                $s['staff'] += $staffCost * $fraction;
                // Osobomiesiące : osoby opłacone w miesiącu × część miesiąca (bieżący : dni, które minęły).
                $s['person_months'] += ($headcount[$monthId][$id] ?? 0) * $fraction;
                $s['overhead_full'] += $overhead;
                $s['overhead'] += $overhead * $fraction;
            }
            unset($s);
            $fixedFull += ($fixed[$monthId] ?? 0.0) / $rate;
            $fixedPace += ($fixed[$monthId] ?? 0.0) / $rate * $fraction;
        }
        if ($estimated) {
            $bySource = [];
            foreach ($estimated as $ym => $source) $bySource[$source][] = self::monthLabel($ym);
            $parts = [];
            foreach ($bySource as $source => $months) $parts[] = implode(', ', $months) . ' → przyjęto ' . self::monthLabel($source);
            $warnings[] = 'Brak wprowadzonych kosztów miesięcznych: ' . implode('; ', $parts) . '.';
        }

        $unassigned = ['revenue' => 0.0, 'materials' => 0.0, 'cartons' => 0];
        foreach ($salesRows as $r) {
            $id = (int) $r['id_section'];
            $target = isset($sections[$id]) ? 'section' : 'none';
            $revenue = (float) $r['revenue_eur'];
            $materials = (float) $r['materials_pln'] / $rate;
            if ($target === 'section') {
                $sections[$id]['revenue'] += $revenue;
                $sections[$id]['materials'] += $materials;
                $sections[$id]['cartons'] += (int) $r['cartons'];
            } else {
                $unassigned['revenue'] += $revenue;
                $unassigned['materials'] += $materials;
                $unassigned['cartons'] += (int) $r['cartons'];
            }
        }
        foreach ($openRows as $r) {
            if (isset($sections[(int) $r['id_section']])) $sections[(int) $r['id_section']]['open'] += (float) $r['value_eur'];
        }

        $groups = [];
        $shared = ['names' => [], 'staff' => 0.0, 'staff_full' => 0.0, 'overhead' => 0.0, 'overhead_full' => 0.0, 'revenue' => 0.0, 'materials' => 0.0, 'person_months' => 0.0];
        foreach ($sections as $id => $s) {
            if ($s['is_shared']) {
                $shared['names'][] = $s['name'];
                foreach (['staff', 'staff_full', 'overhead', 'overhead_full', 'revenue', 'materials', 'person_months'] as $k) $shared[$k] += $s[$k];
                continue;
            }
            if ($s['revenue'] == 0.0 && $s['staff_full'] == 0.0) continue;
            $groups[] = $this->group($id, $s, $targets) + [
                'people' => $monthsCounted > 0 ? $s['person_months'] / $monthsCounted : null,
                'revenue_per_person' => $s['person_months'] > 0 ? $s['revenue'] / $s['person_months'] : null,
            ];
        }
        usort($groups, fn($a, $b) => $b['revenue'] <=> $a['revenue']);

        $revenue = array_sum(array_column($sections, 'revenue')) + $unassigned['revenue'];
        $materials = array_sum(array_column($sections, 'materials')) + $unassigned['materials'];
        $staffPace = array_sum(array_column($sections, 'staff'));
        $staffFull = array_sum(array_column($sections, 'staff_full'));
        $overheadPace = array_sum(array_column($sections, 'overhead'));
        $overheadFull = array_sum(array_column($sections, 'overhead_full'));
        $open = array_sum(array_column($sections, 'open'));

        $personMonths = array_sum(array_column($sections, 'person_months'));
        $productionPersonMonths = $personMonths - $shared['person_months'];
        $shared['people'] = $monthsCounted > 0 ? $shared['person_months'] / $monthsCounted : null;

        $staffTarget = $targets['profit.total_staff_target_pct'];
        $staffNeeded = $staffFull / ($staffTarget / 100);
        $staffMissing = max(0.0, $staffNeeded - $revenue);
        $result = $revenue - $materials - $staffPace - $overheadPace - $fixedPace;

        // CA pour l'objectif de résultat : R × (1 − matières/CA − objectif) = coûts fixes du mois.
        $marginTarget = $targets['profit.margin_target_pct'];
        $variableRatio = $revenue > 0 ? $materials / $revenue : null;
        $fixedCosts = $staffFull + $overheadFull + $fixedFull;
        $denominator = $variableRatio === null ? null : 1 - $variableRatio - $marginTarget / 100;
        $marginNeeded = $denominator !== null && $denominator > 0 ? $fixedCosts / $denominator : null;

        $totals = [
            'revenue' => $revenue,
            'materials' => $materials,
            'materials_pct' => $revenue > 0 ? $materials / $revenue * 100 : null,
            'staff' => $staffPace,
            'staff_full' => $staffFull,
            'staff_pct' => $revenue > 0 ? $staffPace / $revenue * 100 : null,
            'staff_target' => $staffTarget,
            'staff_needed' => $staffNeeded,
            'staff_missing' => $staffMissing,
            'staff_missing_pct' => $revenue > 0 ? $staffMissing / $revenue * 100 : null,
            'staff_ok' => $revenue > 0 && $staffMissing <= 0.005,
            'overhead' => $overheadPace,
            'fixed' => $fixedPace,
            'result' => $result,
            'margin_pct' => $revenue > 0 ? $result / $revenue * 100 : null,
            'margin_target' => $marginTarget,
            'margin_needed' => $marginNeeded,
            'margin_missing' => $marginNeeded === null ? null : max(0.0, $marginNeeded - $revenue),
            'margin_missing_pct' => $marginNeeded !== null && $revenue > 0 ? max(0.0, $marginNeeded - $revenue) / $revenue * 100 : null,
            'margin_reachable' => $revenue <= 0 || $marginNeeded !== null,
            'margin_ok' => $revenue > 0 && $marginNeeded !== null && $marginNeeded <= $revenue,
            'open' => $open,
            'projection' => $spec['current'] && $spec['elapsed'] > 0 ? $revenue / $spec['elapsed'] : null,
            'cartons' => array_sum(array_column($sections, 'cartons')) + $unassigned['cartons'],
            // Sprzedaż na osobę i miesiąc : cała firma (z działami wspólnymi) i sama produkcja.
            'people' => $monthsCounted > 0 ? $personMonths / $monthsCounted : null,
            'people_production' => $monthsCounted > 0 ? $productionPersonMonths / $monthsCounted : null,
            'revenue_per_person' => $personMonths > 0 ? $revenue / $personMonths : null,
            'revenue_per_person_production' => $productionPersonMonths > 0 ? $revenue / $productionPersonMonths : null,
        ];

        return [
            'period' => $spec,
            'rate' => $rate,
            'groups' => $groups,
            'shared' => $shared,
            'unassigned' => $unassigned,
            'totals' => $totals,
            'targets' => $targets,
            'warnings' => array_values(array_unique($warnings)),
            'estimated_months' => $estimated,
            'sections' => $sections,
        ];
    }

    private function group(int $id, array $s, array $targets): array
    {
        $staffTarget = $s['staff_pct_target'] !== null ? (float) $s['staff_pct_target'] : $targets['profit.staff_target_pct'];
        $marginTarget = $s['margin_pct_target'] !== null ? (float) $s['margin_pct_target'] : $targets['profit.section_margin_target_pct'];
        $revenue = $s['revenue'];
        $needed = $s['staff_full'] / ($staffTarget / 100);
        $missing = max(0.0, $needed - $revenue);
        $contribution = $revenue - $s['materials'] - $s['staff'] - $s['overhead'];
        return [
            'id' => $id,
            'name' => $s['name'],
            'revenue' => $revenue,
            'cartons' => $s['cartons'],
            'materials' => $s['materials'],
            'staff' => $s['staff'],
            'staff_full' => $s['staff_full'],
            'staff_pct' => $revenue > 0 ? $s['staff'] / $revenue * 100 : null,
            'staff_target' => $staffTarget,
            'staff_target_custom' => $s['staff_pct_target'] !== null,
            'needed' => $needed,
            'missing' => $missing,
            'missing_pct' => $revenue > 0 ? $missing / $revenue * 100 : null,
            'missing_after_open' => max(0.0, $missing - $s['open']),
            'open' => $s['open'],
            'progress_pct' => $needed > 0 ? min(100.0, $revenue / $needed * 100) : 100.0,
            'ok' => $revenue > 0 && $missing <= 0.005,
            'overhead' => $s['overhead'],
            'contribution' => $contribution,
            'margin_pct' => $revenue > 0 ? $contribution / $revenue * 100 : null,
            'margin_target' => $marginTarget,
            'margin_target_custom' => $s['margin_pct_target'] !== null,
        ];
    }

    /**
     * Période → mois concernés avec la part écoulée de chacun.
     * @return array{key:string, months:array<string,float>, from:string, to:string, end:string, current:bool, label:string, elapsed:float}
     */
    public function period(string $period, DateTimeImmutable $today): array
    {
        $currentYm = $today->format('Y-m');
        $monthFraction = (int) $today->format('j') / (int) $today->format('t');
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period) && $period < $currentYm) {
            $first = new DateTimeImmutable($period . '-01');
            return ['key' => $period, 'months' => [$period => 1.0], 'from' => $first->format('Y-m-d'), 'to' => $first->format('Y-m-t'),
                'end' => $first->format('Y-m-t'), 'current' => false, 'label' => self::monthLabel($period), 'elapsed' => 1.0];
        }
        if ($period === '3m') {
            $first = $today->modify('first day of this month')->modify('-3 months');
            $months = [];
            for ($i = 0; $i < 3; $i++) $months[$first->modify("+{$i} months")->format('Y-m')] = 1.0;
            $last = $today->modify('first day of this month')->modify('-1 day');
            return ['key' => '3m', 'months' => $months, 'from' => $first->format('Y-m-d'), 'to' => $last->format('Y-m-d'), 'end' => $last->format('Y-m-d'),
                'current' => false, 'label' => self::monthLabel(array_key_first($months)) . ' – ' . self::monthLabel(array_key_last($months)), 'elapsed' => 1.0];
        }
        if ($period === 'ytd') {
            $months = [];
            $daysTotal = 0;
            $daysElapsed = 0;
            for ($m = 1; $m <= (int) $today->format('n'); $m++) {
                $ym = sprintf('%s-%02d', $today->format('Y'), $m);
                $days = (int) (new DateTimeImmutable($ym . '-01'))->format('t');
                $months[$ym] = $ym === $currentYm ? $monthFraction : 1.0;
                $daysTotal += $days;
                $daysElapsed += $ym === $currentYm ? (int) $today->format('j') : $days;
            }
            return ['key' => 'ytd', 'months' => $months, 'from' => $today->format('Y-01-01'), 'to' => $today->format('Y-m-d'), 'end' => $today->format('Y-m-t'),
                'current' => true, 'label' => 'Od początku roku ' . $today->format('Y'), 'elapsed' => $daysElapsed / $daysTotal];
        }
        return ['key' => 'month', 'months' => [$currentYm => $monthFraction], 'from' => $today->format('Y-m-01'), 'to' => $today->format('Y-m-d'),
            'end' => $today->format('Y-m-t'), 'current' => true, 'label' => self::monthLabel($currentYm), 'elapsed' => $monthFraction];
    }

    /** Mois de coûts à utiliser : le même, sinon le dernier avant, sinon le premier connu. */
    private function costMonthFor(string $ym, array $costMonths): array
    {
        if (isset($costMonths[$ym])) return [$costMonths[$ym], $ym];
        $before = array_filter($costMonths, fn($key) => $key < $ym, ARRAY_FILTER_USE_KEY);
        if ($before) {
            $key = array_key_last($before);
            return [$before[$key], $key];
        }
        if ($costMonths) {
            $key = array_key_first($costMonths);
            return [$costMonths[$key], $key];
        }
        return [null, null];
    }

    /** Valeur numérique d'un paramètre ; un ratio de personnel doit rester > 0 (on divise par lui). */
    private function param(string $code, float $default, bool $positive): float
    {
        $value = $this->params->get($code, (string) $default);
        if (!is_numeric($value) || ($positive && (float) $value <= 0)) return $default;
        return (float) $value;
    }

    public static function monthLabel(string $ym, bool $short = false): string
    {
        static $names = ['styczeń', 'luty', 'marzec', 'kwiecień', 'maj', 'czerwiec', 'lipiec', 'sierpień', 'wrzesień', 'październik', 'listopad', 'grudzień'];
        static $shortNames = ['sty', 'lut', 'mar', 'kwi', 'maj', 'cze', 'lip', 'sie', 'wrz', 'paź', 'lis', 'gru'];
        [$y, $m] = array_map('intval', explode('-', $ym));
        return ($short ? $shortNames[$m - 1] : $names[$m - 1]) . ' ' . $y;
    }
}
