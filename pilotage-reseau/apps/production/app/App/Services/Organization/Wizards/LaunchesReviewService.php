<?php
declare(strict_types=1);

namespace App\Services\Organization\Wizards;

use App\Domain\Params\ParamRules;
use App\Exceptions\CustomException;
use App\Repositories\Analysis\ProductMarginRepository;
use App\Repositories\Dashboard\ProfitabilityRepository;
use App\Repositories\Organization\LightWizardRepository;
use App\Repositories\Product\LaunchesReviewRepository;
use App\Repositories\Workshop\ManagerRepository;
use App\Repositories\Workshop\ParamRepository;
use App\Services\Dashboard\ProfitabilityService;
use App\Services\Organization\PageAccessService;
use App\Services\Organization\TaskWizards;

/**
 * Asystent « Nowe produkty » [384] (co miesiąc ; obligation 6 du Kierownik produkcji dans la matrice 224 « Nowe produkty
 * i karty produktów »). Contrôle du mois en cours (clé = premier jour du mois du contrôle) sur les lancements des
 * launches_review.months derniers mois, jusqu'à aujourd'hui.
 *   1. Przegląd — lancements (ManagerRepository::launches : ligne n_mgr_launch ou produit créé depuis la date ; date de
 *      lancement = n_mgr_launch.launch_date, sinon DATE(final_product.create_timestamp), comme ManagerService::newProducts) :
 *        - objectifs : n_mgr_launch.target_cost, target_price (€ / carton), min_margin_pct, expected_week_shop (cartons /
 *          magasin / semaine) — saisis dans le module Kierownik produkcji (page réservée à l'administrateur) ;
 *        - réel, avec les services existants, jamais recalculé autrement :
 *            · cartons vendus, prix pratiqué (ventes ÷ cartons) et marge après matières depuis le 1er du mois du lancement :
 *              ProfitabilityService::production (lignes WDT, recette en vigueur et prix des matières à la fin de chaque mois
 *              — la marge de Rentowność et du rapport du Kierownik produkcji, qui la compare à min_margin_pct) ;
 *            · coût matières / carton : matières ÷ cartons vendus (le cost_carton de ManagerService::newProducts) ; sans
 *              vente, coût matières de la fiche produit (final_product.pre_calculated_raw_price_per_carton ÷ cours €, le
 *              foodcost de Marża produktów — ProductMarginRepository::getProductData) ; prix sans vente : prix standard ;
 *            · ventes par magasin et par semaine depuis le lancement : ManagerRepository::productShops (cartons WDT ÷
 *              magasins ayant acheté ÷ semaines, formule de ManagerService::newProducts) ;
 *        - décision déjà prise dans le module (n_mgr_launch.decision keep / fix / stop, decision_note).
 *      Sans aucune ligne n_mgr_launch (ou table absente) : « brak danych » pour les objectifs, et la liste des produits
 *      créés récemment reste proposée, sans objectifs.
 *   2. Do wyjaśnienia — un lancement est signalé s'il est loin de ses objectifs (coût > cible + cost_over_pct %, prix <
 *      cible − price_under_pct %, marge < minimum − margin_gap_pts points, ventes < attendu − volume_under_pct % ou aucune
 *      vente, ces deux derniers après min_weeks semaines) ou sans décision du module. Une décision par lancement et par mois
 *      (n_launch_review) : Zostaje (note), Korekta (note obligatoire, domaine optionnel), Wycofać (note obligatoire). Un
 *      lancement sans écart déjà décidé dans un contrôle précédent n'attend plus ; un lancement loin de ses objectifs revient
 *      chaque mois. L'assistant n'écrit pas n_mgr_launch.decision : l'écran dit où la reporter.
 *   3. Zatwierdź — récapitulatif, « Zakończ » écrit n_wizard_done — bloqué tant qu'un lancement signalé attend.
 * Montants (coûts, prix, marges et leurs écarts) : règle des écrans de marges (droit « margins » : Marża produktów, Marża
 * kategorii — PageAccessService::canSee sur MARGIN_LINK) ; sans ce droit, ils sont retirés des données (aucun écran
 * existant ne montre les écarts de marge en % sans ce droit), seuls restent les signaux (sans chiffre) et les ventes en cartons.
 * Lecture seule hors de n_launch_review et n_wizard_done : aucun produit, recette, prix ni n_mgr_launch changé, aucun e-mail ni SMS.
 */
final class LaunchesReviewService
{
    public const CODE = 'launches_reviewed';
    public const PATH = '/products/launches-review';
    public const DECISIONS = ['keep' => 'Zostaje', 'fix' => 'Korekta', 'stop' => 'Wycofać'];
    /** Décisions qui demandent une note (au moins 3 caractères). */
    public const NOTE_REQUIRED = ['fix', 'stop'];
    /** Korekta : domaine (optionnel). */
    public const FIX_AREAS = ['price' => 'cena', 'recipe' => 'receptura', 'cost' => 'koszt surowców', 'packaging' => 'opakowanie / karton', 'sales' => 'sprzedaż / klienci', 'other' => 'inne'];
    /** Décisions du module Kierownik produkcji (resources/lang/ui/pl/manager.json, decision.*). */
    public const MANAGER_DECISIONS = ['keep' => 'zatrzymać', 'fix' => 'poprawić', 'stop' => 'wycofać'];
    /** Signaux, dans l'ordre de priorité ; GAPS = loin des objectifs. */
    public const SIGNALS = ['margin_below' => 'marża poniżej minimum', 'cost_over' => 'koszt powyżej celu', 'price_under' => 'cena poniżej celu',
        'no_sales' => 'brak sprzedaży', 'volume_below' => 'sprzedaż poniżej oczekiwanej', 'no_targets' => 'brak celów wprowadzenia', 'no_decision' => 'bez decyzji w module'];
    public const GAPS = ['margin_below', 'cost_over', 'price_under', 'no_sales', 'volume_below'];
    /** Signaux qui portent un montant : sans le droit aux montants, le détail chiffré est retiré. */
    private const AMOUNT_SIGNALS = ['margin_below', 'cost_over', 'price_under'];
    /** Écrans existants : marge du produit (droit « margins »), module Kierownik produkcji (administrateur). */
    public const MARGIN_LINK = '/finances/product-margin';
    public const MANAGER_LINK = '/workshop/manager#tab=report';
    public const MANAGER_WHERE = 'Kierownik produkcji › Raport miesięczny › Cele wprowadzenia nowych produktów';
    /** clé => [code n_prod_param, défaut] */
    private const PARAMS = ['months' => ['launches_review.months', 3], 'cost_over' => ['launches_review.cost_over_pct', 10],
        'price_under' => ['launches_review.price_under_pct', 5], 'margin_gap' => ['launches_review.margin_gap_pts', 0],
        'volume_under' => ['launches_review.volume_under_pct', 30], 'min_weeks' => ['launches_review.min_weeks', 4]];

    public function __construct(
        private LaunchesReviewRepository $repo,
        private ManagerRepository $manager,
        private ProfitabilityService $profit,
        private ProfitabilityRepository $profitRepo,
        private ProductMarginRepository $cards,
        private LightWizardRepository $wizards,
        private ParamRepository $params,
        private PageAccessService $access,
    ) {
    }

    /** Réglage launches_review.* (n_prod_param, borné par ParamRules ; valeur invalide → défaut). */
    public function param(string $name): int
    {
        [$code, $default] = self::PARAMS[$name];
        $raw = trim($this->params->get($code, (string) $default));
        $v = ctype_digit($raw) ? (int) $raw : $default;
        [$min, $max] = ParamRules::RANGES[$code];
        return $v < $min || $v > $max ? $default : $v;
    }

    /** Premier jour du mois du contrôle (clé des décisions et de l'état de la tâche du mois). */
    public static function reviewMonth(string $today): string
    {
        return substr($today, 0, 7) . '-01';
    }

    /**
     * Données des trois étapes. $values : montants (coûts, prix, marges) — droit des écrans de marges ; sans lui, aucun
     * montant ni écart chiffré n'est renvoyé.
     */
    public function data(?string $today = null, bool $values = false): array
    {
        $today ??= date('Y-m-d');
        $month = self::reviewMonth($today);
        $p = array_combine(array_keys(self::PARAMS), array_map(fn($k) => $this->param($k), array_keys(self::PARAMS)));
        $since = date('Y-m-d', strtotime($today . ' -' . $p['months'] . ' months'));

        $hasLaunch = $this->repo->hasTable('n_mgr_launch');
        $rows = $hasLaunch ? $this->manager->launches($since) : $this->repo->createdProducts($since);
        $launches = [];
        foreach ($rows as $l) {
            $launch = $l['launch_date'] ?? $l['created'];
            if ($launch === null || $launch < $since || $launch > $today) continue;
            $launches[] = ['launch' => substr((string) $launch, 0, 10)] + $l;
        }
        $hasSales = $this->repo->hasTable('document_wdt') && $this->repo->hasTable('document_wdt_product');
        // « Brak sprzedaży » n'est un signal que si des ventes WDT existent (sinon la source entière est « brak danych »).
        $salesOk = $hasSales && $this->repo->sourceRows('document_wdt') > 0;
        $rateRaw = $this->profitRepo->eurRate();
        $rate = $rateRaw > 0 ? $rateRaw : 1.0;
        // Ventes : une lecture par mois de lancement (marge) et par date de lancement (magasins / semaine).
        $byMonth = [];
        $shops = [];
        if ($hasSales) {
            foreach (array_unique(array_map(fn($l) => substr($l['launch'], 0, 7) . '-01', $launches)) as $m) {
                $byMonth[$m] = [];
                foreach ($this->profit->production($m, $today, $rate)['groups'] as $g) foreach ($g['categories'] as $c) foreach ($c['products'] as $prod) $byMonth[$m][$prod['id']] = $prod;
            }
            foreach (array_unique(array_column($launches, 'launch')) as $d) $shops[$d] = $this->manager->productShops($d, $today);
        }
        $dec = $this->repo->decisions(array_map(fn($l) => (int) $l['product_id'], $launches));

        $items = [];
        foreach ($launches as $l) {
            $id = (int) $l['product_id'];
            $sold = $byMonth[substr($l['launch'], 0, 7) . '-01'][$id] ?? null;
            $items[] = $this->item($l, $sold, $shops[$l['launch']][$id] ?? null, $this->cards->getProductData($id), $rateRaw, $p, $today, $dec[$id] ?? [], $month, $salesOk);
        }
        $rank = array_flip(array_keys(self::SIGNALS));
        $first = fn($i) => $i['signals'] ? $rank[$i['signals'][0]['code']] : 99;
        usort($items, fn($a, $b) => [$b['in_review'], $first($a), $b['launch_date'], $a['name']] <=> [$a['in_review'], $first($b), $a['launch_date'], $b['name']]);
        $review = array_values(array_filter($items, fn($i) => $i['in_review']));

        $sources = $this->sources($hasSales, $since);
        $eff = fn(array $i) => $i['decision'] ?? $i['earlier'];
        $decided = fn(string $k) => count(array_filter($review, fn($i) => ($d = $eff($i)) !== null && $d['code'] === $k));
        $sig = fn(string $k) => count(array_filter($items, fn($i) => in_array($k, array_column($i['signals'], 'code'), true)));
        $totals = ['launches' => count($items), 'with_targets' => count(array_filter($items, fn($i) => $i['has_targets'])),
            'from_launch' => count(array_filter($items, fn($i) => $i['launch_source'] === 'launch')), 'created' => count(array_filter($items, fn($i) => $i['launch_source'] === 'created')),
            'manager_decided' => count(array_filter($items, fn($i) => $i['manager_decision'] !== null)), 'too_early' => count(array_filter($items, fn($i) => $i['too_early'])),
            'off_target' => count(array_filter($items, fn($i) => $i['off_target'])), 'with_sales' => count(array_filter($items, fn($i) => $i['actual']['cartons'] > 0)),
            'review' => count($review), 'waiting' => count(array_filter($review, fn($i) => $i['waiting'])), 'resolved' => count(array_filter($review, fn($i) => !$i['waiting'])),
            'earlier' => count(array_filter($review, fn($i) => $i['decision'] === null && $i['earlier'] !== null)),
            'keep' => $decided('keep'), 'fix' => $decided('fix'), 'stop' => $decided('stop'),
            'by_signal' => array_combine(array_keys(self::SIGNALS), array_map($sig, array_keys(self::SIGNALS)))];
        $done = $this->wizards->done(self::CODE, $month, date('Y-m-01', strtotime($month . ' +1 month')));
        $label = TaskWizards::monthLabel(substr($month, 0, 7));
        return ['today' => $today, 'label' => self::label($today), 'month_start' => $month, 'month_label' => $label, 'since' => $since, 'since_label' => self::label($since),
            'params' => $p, 'values' => $values, 'eur_rate' => $values ? $rateRaw : null, 'sources' => $sources,
            'items' => array_map(fn($i) => $this->present($i, $values), $items), 'review' => array_map(fn($i) => $this->present($i, $values), $review),
            'totals' => $totals, 'can_finish' => $totals['waiting'] === 0, 'summary' => self::summary($label, $p['months'], $totals, $sources),
            'decisions' => self::DECISIONS, 'note_required' => self::NOTE_REQUIRED, 'fix_areas' => self::FIX_AREAS, 'signal_labels' => self::SIGNALS,
            'manager_link' => ['href' => self::MANAGER_LINK, 'label' => self::MANAGER_WHERE], 'manager_where' => self::MANAGER_WHERE,
            'done' => $done ? ['summary' => $done['summary'], 'at' => self::label((string) $done['day']) . ', ' . self::hm((string) $done['done_at']), 'by' => $done['by_login'] ?? null] : null];
    }

    /** Un lancement : objectifs, réel, écarts, signaux, décisions. */
    private function item(array $l, ?array $sold, ?array $shop, array $card, float $rateRaw, array $p, string $today, array $decisions, string $month, bool $salesOk): array
    {
        $id = (int) $l['product_id'];
        $f = fn(string $k) => $l[$k] !== null ? (float) $l[$k] : null;
        $targets = ['cost' => $f('target_cost'), 'price' => $f('target_price'), 'min_margin' => $f('min_margin_pct'), 'expected' => $f('expected_week_shop')];
        $hasTargets = array_filter($targets, fn($v) => $v !== null) !== [];
        $launch = $l['launch'];
        $weeks = (int) floor((strtotime($today) - strtotime($launch)) / 86400 / 7);
        $weeksF = (self::days($launch, $today) + 1) / 7;
        $tooEarly = $weeks < $p['min_weeks'];

        $cartonsSold = $sold ? (float) $sold['cartons'] : 0.0;
        $costSold = $sold && $sold['cartons'] > 0 ? $sold['materials'] / $sold['cartons'] : null;
        $cardCost = (float) ($card['pre_calculated_raw_price_per_carton'] ?? 0);
        $costCard = $cardCost > 0 && $rateRaw > 0 ? $cardCost / $rateRaw : null;
        $listPrice = (float) ($card['standard_price'] ?? 0) > 0 ? (float) $card['standard_price'] : null;
        $priceSold = $sold['price_carton'] ?? null;
        $cost = $costSold ?? $costCard;
        $price = $priceSold ?? $listPrice;
        $margin = $sold['margin_pct'] ?? null;
        $cartons = $shop ? (float) $shop['cartons'] : 0.0;
        $shopsN = $shop ? (int) $shop['shops'] : 0;
        $perShopWeek = $shopsN > 0 && $weeksF > 0 ? $cartons / $shopsN / $weeksF : 0.0;

        $gap = [
            'cost_pct' => $targets['cost'] > 0 && $cost !== null ? ($cost / $targets['cost'] - 1) * 100 : null,
            'price_pct' => $targets['price'] > 0 && $price !== null ? ($price / $targets['price'] - 1) * 100 : null,
            'margin_pts' => $targets['min_margin'] !== null && $margin !== null ? $margin - $targets['min_margin'] : null,
            'volume_pct' => $targets['expected'] > 0 && !$tooEarly ? ($perShopWeek / $targets['expected'] - 1) * 100 : null,
        ];
        $eur = fn(?float $v) => $v === null ? '—' : self::num($v) . ' €';
        $signals = [];
        if ($gap['margin_pts'] !== null && $gap['margin_pts'] < -$p['margin_gap']) {
            $signals[] = self::sig('margin_below', 'marża ' . self::num($margin, 1) . ' % — minimum ' . self::num($targets['min_margin'], 1) . ' % (' . self::num($gap['margin_pts'], 1) . ' pkt)');
        }
        if ($gap['cost_pct'] !== null && $gap['cost_pct'] > $p['cost_over']) {
            $signals[] = self::sig('cost_over', 'koszt surowców ' . $eur($cost) . ' / karton — cel ' . $eur($targets['cost']) . ' (+' . self::num($gap['cost_pct'], 0) . ' %)');
        }
        if ($gap['price_pct'] !== null && $gap['price_pct'] < -$p['price_under']) {
            $signals[] = self::sig('price_under', ($priceSold !== null ? 'cena ' : 'cena standardowa ') . $eur($price) . ' / karton — cel ' . $eur($targets['price']) . ' (' . self::num($gap['price_pct'], 0) . ' %)');
        }
        if (!$tooEarly && $salesOk && $cartons <= 0) $signals[] = self::sig('no_sales', 'brak sprzedaży od wprowadzenia (' . $weeks . ' tyg.)');
        if ($gap['volume_pct'] !== null && $cartons > 0 && $gap['volume_pct'] < -$p['volume_under']) {
            $signals[] = self::sig('volume_below', self::num($perShopWeek, 1) . ' kart. / sklep / tydz. — oczekiwane ' . self::num($targets['expected'], 1) . ' (' . self::num($gap['volume_pct'], 0) . ' %, ' . $shopsN . ' ' . self::pl($shopsN, 'sklep', 'sklepy', 'sklepów') . ')');
        }
        if (!$hasTargets) $signals[] = self::sig('no_targets', $l['launch_date'] === null ? 'produkt utworzony w ERP, bez celów w module Kierownik produkcji' : 'wprowadzenie bez celów (koszt, cena, marża, wolumen)');
        $managerDecision = $l['decision'] !== null && $l['decision'] !== '' ? ['code' => (string) $l['decision'], 'label' => self::MANAGER_DECISIONS[$l['decision']] ?? (string) $l['decision'], 'note' => $l['decision_note']] : null;
        if ($managerDecision === null) $signals[] = self::sig('no_decision', 'brak decyzji w module Kierownik produkcji (n_mgr_launch.decision)');
        $offTarget = array_intersect(array_column($signals, 'code'), self::GAPS) !== [];

        $mine = null;
        $earlier = null;
        foreach ($decisions as $d) {
            if (substr((string) $d['month_start'], 0, 10) === $month) { $mine ??= $d; continue; }
            $earlier ??= $d;
        }
        $inReview = $offTarget || $managerDecision === null;
        return ['key' => 'p:' . $id, 'id_product' => $id, 'name' => (string) $l['name'], 'ppc' => (int) ($l['ppc'] ?? 0), 'active' => (bool) (int) ($l['active'] ?? 1),
            'launch_date' => $launch, 'launch_label' => self::label($launch) . '.' . substr($launch, 0, 4), 'launch_source' => $l['launch_date'] !== null ? 'launch' : 'created',
            'created' => $l['created'] ?? null, 'weeks' => $weeks, 'too_early' => $tooEarly, 'has_targets' => $hasTargets, 'targets' => $targets,
            'actual' => ['cost' => $cost, 'cost_source' => $costSold !== null ? 'sales' : ($costCard !== null ? 'card' : null), 'cost_card' => $costCard,
                'price' => $price, 'price_source' => $priceSold !== null ? 'sales' : ($listPrice !== null ? 'list' : null), 'price_list' => $listPrice,
                'margin' => $margin, 'cartons' => $cartons, 'cartons_margin' => $cartonsSold, 'shops' => $shopsN, 'per_shop_week' => round($perShopWeek, 2)],
            'gap' => $gap, 'signals' => $signals, 'off_target' => $offTarget, 'manager_decision' => $managerDecision, 'in_review' => $inReview,
            'decision' => $mine ? $this->presentDecision($mine) : null, 'earlier' => !$mine && $earlier ? $this->presentDecision($earlier) : null,
            'waiting' => $inReview && $mine === null && ($offTarget || $earlier === null),
            'link' => ['href' => self::MARGIN_LINK . '?product_id=' . $id, 'label' => 'Marża produktu']];
    }

    /** Sans le droit aux montants : coûts, prix, marges, leurs objectifs et écarts retirés ; signaux sans chiffre. */
    private function present(array $i, bool $values): array
    {
        if ($values) return $i;
        $i['targets'] = ['cost' => null, 'price' => null, 'min_margin' => null, 'expected' => $i['targets']['expected']];
        $i['actual'] = ['cost' => null, 'cost_source' => null, 'cost_card' => null, 'price' => null, 'price_source' => null, 'price_list' => null, 'margin' => null] + $i['actual'];
        $i['gap'] = ['cost_pct' => null, 'price_pct' => null, 'margin_pts' => null, 'volume_pct' => $i['gap']['volume_pct']];
        foreach ($i['signals'] as &$s) if (in_array($s['code'], self::AMOUNT_SIGNALS, true)) $s['detail'] = 'kwoty widoczne z prawem « Marże i koszty »';
        unset($s);
        $i['link'] = null;
        return $i;
    }

    /** État des sources : objectifs de lancement (n_mgr_launch), ventes WDT, dates de création. */
    private function sources(bool $hasSales, string $since): array
    {
        $rows = $this->repo->sourceRows('n_mgr_launch');
        $withTargets = $this->repo->sourceRows('n_mgr_launch', LaunchesReviewRepository::HAS_TARGETS);
        $authors = $this->repo->launchAuthors() ?? [];
        $adminOnly = $authors !== [] && array_filter($authors, fn($u) => !$this->access->isAdmin($u)) === [];
        $launchOk = $rows !== null && $withTargets > 0;
        $launchNote = $rows === null ? 'brak danych — brak tabeli n_mgr_launch w bazie'
            : ($rows === 0 ? 'brak danych — nikt nie wpisuje celów wprowadzenia (moduł Kierownik produkcji, strona tylko dla administratora) ; poniżej produkty utworzone ostatnio, bez celów'
                : ($withTargets === 0 ? 'brak danych — wprowadzenia zapisane bez celów (koszt, cena, marża, wolumen)' : null));
        $wdt = $hasSales ? $this->repo->sourceRows('document_wdt') : null;
        return [
            'launch' => ['available' => $launchOk, 'rows' => $rows, 'with_targets' => $withTargets, 'admin_only' => $adminOnly, 'note' => $launchNote, 'from' => 'n_mgr_launch',
                'admin_note' => $adminOnly ? 'cele wpisuje tylko administrator (moduł Kierownik produkcji jest stroną administratora)' : null],
            'sales' => ['available' => $wdt !== null && $wdt > 0, 'rows' => $wdt, 'note' => $wdt === null ? 'brak danych — brak tabel WDT w bazie' : ($wdt === 0 ? 'brak danych — brak dokumentów WDT' : null), 'from' => 'document_wdt'],
            'created' => ['available' => true, 'note' => null, 'from' => 'final_product.create_timestamp', 'since' => $since],
        ];
    }

    private function presentDecision(array $d): array
    {
        return ['code' => (string) $d['decision'], 'label' => self::DECISIONS[$d['decision']] ?? (string) $d['decision'], 'note' => $d['note'],
            'fix_area' => $d['fix_area'], 'fix_area_label' => $d['fix_area'] !== null ? (self::FIX_AREAS[$d['fix_area']] ?? $d['fix_area']) : null,
            'month' => substr((string) $d['month_start'], 0, 7), 'month_label' => TaskWizards::monthLabel(substr((string) $d['month_start'], 0, 7)),
            'flags' => $d['flags'], 'by' => $d['decided_by'], 'at' => self::label(substr((string) $d['updated_at'], 0, 10)) . ', ' . self::hm((string) $d['updated_at'])];
    }

    /** Krok 2 : décision pour un lancement signalé (une par mois du contrôle, la dernière compte). Rien n'est écrit dans n_mgr_launch. */
    public function saveDecision(array $in, ?int $userId, ?string $today = null, bool $values = false): array
    {
        $today ??= date('Y-m-d');
        $productId = (int) ($in['id_product'] ?? 0);
        $decision = (string) ($in['decision'] ?? '');
        $note = trim((string) ($in['note'] ?? ''));
        $area = (string) ($in['fix_area'] ?? '') ?: null;
        if (!isset(self::DECISIONS[$decision])) throw new CustomException('Wybierz decyzję : zostaje, korekta albo wycofać.', 'errors', 422);
        if (mb_strlen($note) > 500) throw new CustomException('Notatka : najwyżej 500 znaków.', 'errors', 422);
        if ($decision === 'fix' && mb_strlen($note) < 3) throw new CustomException('Korekta : wpisz, co zostanie poprawione (cena, receptura, koszt…).', 'errors', 422);
        if ($decision === 'stop' && mb_strlen($note) < 3) throw new CustomException('Wycofać : wpisz powód i termin wycofania.', 'errors', 422);
        if ($decision !== 'fix') $area = null;
        if ($area !== null && !isset(self::FIX_AREAS[$area])) throw new CustomException('Nieznany zakres korekty.', 'errors', 422);
        $d = $this->data($today, true);
        $it = array_values(array_filter($d['review'], fn($x) => $x['id_product'] === $productId))[0] ?? null;
        if ($it === null) throw new CustomException('Tego produktu nie ma na liście do wyjaśnienia (wprowadzenie bez sygnału albo spoza okresu).', 'errors', 422);
        $this->repo->saveDecision($d['month_start'], $productId, $it['launch_date'], $decision, $area, $note !== '' ? $note : null, implode(',', array_column($it['signals'], 'code')), $userId);
        return $this->data($today, $values);
    }

    /** Krok 3 : « Zakończ » — chaque lancement signalé a sa décision ; la tâche du mois se valide seule. */
    public function finish(?int $userId, ?string $today = null, bool $values = false): array
    {
        $today ??= date('Y-m-d');
        $d = $this->data($today, $values);
        $w = $d['totals']['waiting'];
        if ($w > 0) throw new CustomException('Najpierw zapisz decyzję dla ' . $w . ' ' . self::pl($w, 'produktu', 'produktów', 'produktów') . ' z kroku 2.', 'errors', 422);
        $this->wizards->markDone(self::CODE, $today, $d['summary'], $userId);
        return ['summary' => $d['summary'], 'data' => $this->data($today, $values)];
    }

    /** « listopad 2026 : 5 wprowadzeń (3 mies.) · 3 z celami · 2 poza celem · 4 do wyjaśnienia : 2 zostaje · 1 korekta · 1 wycofać » */
    public static function summary(string $month, int $months, array $t, array $sources): string
    {
        $s = $month . ' : ' . $t['launches'] . ' ' . self::pl($t['launches'], 'nowy produkt', 'nowe produkty', 'nowych produktów') . ' (' . $months . ' mies.)';
        $s .= $sources['launch']['available'] ? ' · ' . $t['with_targets'] . ' z celami' : ' · cele : brak danych';
        if ($t['off_target']) $s .= ' · ' . $t['off_target'] . ' poza celem';
        if ($t['review'] > 0) {
            $s .= ' · ' . $t['review'] . ' do wyjaśnienia';
            $dec = [];
            if ($t['keep']) $dec[] = $t['keep'] . ' zostaje';
            if ($t['fix']) $dec[] = $t['fix'] . ' ' . self::pl($t['fix'], 'korekta', 'korekty', 'korekt');
            if ($t['stop']) $dec[] = $t['stop'] . ' wycofać';
            if ($dec) $s .= ' : ' . implode(' · ', $dec);
        }
        return $s;
    }

    private static function sig(string $code, string $detail): array
    {
        return ['code' => $code, 'label' => self::SIGNALS[$code], 'detail' => $detail];
    }

    private const DAY_ABBR = [1 => 'pon.', 2 => 'wt.', 3 => 'śr.', 4 => 'czw.', 5 => 'pt.', 6 => 'sob.', 7 => 'niedz.'];

    public static function label(string $day): string
    {
        $t = strtotime($day);
        return $t === false ? $day : self::DAY_ABBR[(int) date('N', $t)] . ' ' . date('j.m', $t);
    }

    /** Jours calendaires de $from à $to (0 = le même jour). */
    private static function days(string $from, string $to): int
    {
        return (int) round((strtotime($to . ' 12:00:00') - strtotime(substr($from, 0, 10) . ' 12:00:00')) / 86400);
    }

    private static function hm(string $ts): string
    {
        return strlen($ts) >= 16 ? (int) substr($ts, 11, 2) . substr($ts, 13, 3) : '';
    }

    private static function num(float $v, int $d = 2): string
    {
        $s = number_format($v, $d, ',', ' ');
        return $d > 0 ? rtrim(rtrim($s, '0'), ',') : $s;
    }

    private static function pl(int $n, string $one, string $few, string $many): string
    {
        return $n === 1 ? $one : (in_array($n % 10, [2, 3, 4], true) && !in_array($n % 100, [12, 13, 14], true) ? $few : $many);
    }
}
