<?php
declare(strict_types=1);

namespace App\Services\Organization\Wizards;

use App\Exceptions\CustomException;
use App\Repositories\Organization\LightWizardRepository;
use App\Repositories\Workshop\PpwrMonthRepository;
use App\Services\Audit\ProductWeightAuditEvaluator;
use App\Services\Material\Ppwr\FinalProductPpwrSalesPackagingService;
use App\Services\Material\Ppwr\RawMaterialPpwrService;
use App\Services\Organization\TaskWizards;

/**
 * Asystent « PPWR i opakowania » [397] (co miesiąc ; zakres obowiązków « produkcja » 237 : Kierownik produkcji 2.1–2.7 et
 * 2.10). Mois du contrôle = le mois de la tâche (état des données aujourd'hui, comme « Dane klientów ») ; les wysyłki
 * examinées sont celles du mois écoulé ; les zmiany sont celles depuis le dernier « Zakończ » (sinon depuis le début du
 * mois écoulé). « Complet » = les règles des écrans existants, sans en inventer :
 *   - opakowanie utilisé dans la recette d'un produit actif (les autres sont comptés, pas signalés) : braki du profil PPWR
 *     (RawMaterialPpwrService::issues, la règle du Rejestr PPWR) et données BDO absentes (catégorie, typ — la règle de Dane
 *     opakowań BDO) ; zmiana : profil PPWR modifié depuis le dernier contrôle ;
 *   - produit actif : braki du karton sprzedażowy (FinalProductPpwrSalesPackagingService::issueCodes) et masse du produit
 *     (ProductWeightAuditEvaluator : absente, 0, négative, 1 g douteux) ; zmiana : produit créé ou karton modifié ;
 *   - wysyłka du mois écoulé : sans dowód opakowań, « wymaga danych », lignes PPWR incomplètes (Raporty PPWR).
 *   1. Przegląd : chiffres et éléments signalés. 2. Do decyzji : une décision par élément (n_ppwr_month_review) —
 *   Uzupełnione — sprawdzone (acceptée seulement sans braki au moment de l'enregistrement ; pour une simple zmiana :
 *   sprawdzone), Zapytanie do dostawcy wysłane (opakowania, note obligatoire), Nie dotyczy (note obligatoire, reprise les
 *   mois suivants tant que les braki sont les mêmes). 3. Zatwierdź : « Zakończ » écrit n_wizard_done — bloqué tant qu'un
 *   élément attend ; la tâche du mois se valide seule.
 * Lecture seule hors de n_ppwr_month_review et n_wizard_done : aucune donnée PPWR / BDO changée, aucun e-mail envoyé (le
 * texte de la demande au dostawca est préparé pour être copié, l'humain l'envoie lui-même).
 */
final class PpwrMonthService
{
    public const CODE = 'ppwr_month_reviewed';
    public const PATH = '/ppwr/month';
    public const DECISIONS = ['checked' => 'Uzupełnione — sprawdzone', 'supplier_asked' => 'Zapytanie do dostawcy wysłane', 'not_applicable' => 'Nie dotyczy'];
    /** Décisions qui demandent une note (au moins 3 caractères). */
    public const NOTE_REQUIRED = ['supplier_asked', 'not_applicable'];
    /** Décisions possibles par type d'élément. */
    public const KIND_DECISIONS = ['material' => ['checked', 'supplier_asked', 'not_applicable'], 'product' => ['checked', 'not_applicable'], 'shipment' => ['checked', 'not_applicable']];
    public const KINDS = ['material' => 'Opakowanie', 'product' => 'Produkt', 'shipment' => 'Wysyłka'];
    /** Braki et signaux : code => [libellé, écran où compléter (clé de LINKS)]. Ordre = priorité d'affichage. */
    public const SIGNALS = [
        'MISSING_IDENTIFICATION_NUMBER' => ['brak numeru identyfikacyjnego opakowania', 'register'],
        'MISSING_IDENTIFICATION_SOURCE' => ['brak źródła numeru (własny / dostawcy)', 'register'],
        'MISSING_UNIT_WEIGHT' => ['brak masy elementu opakowania', 'register'],
        'MISSING_PACKAGING_LEVEL' => ['brak poziomu opakowania (sprzedażowe, transportowe…)', 'register'],
        'MISSING_PACKAGING_TYPE' => ['brak rodzaju opakowania', 'register'],
        'MISSING_DOMINANT_MATERIAL' => ['brak materiału (karton, papier, tworzywo…)', 'register'],
        'MISSING_RECYCLED_CONTENT' => ['tworzywo : brak oceny recyklatu', 'register'],
        'MISSING_RECYCLABILITY' => ['brak oceny recyklingowalności', 'register'],
        'MISSING_MINIMISATION' => ['brak oceny minimalizacji', 'register'],
        'MISSING_DIMENSIONS' => ['brak wymiarów (opakowanie zbiorcze / transportowe)', 'register'],
        'MISSING_REUSE_CYCLES' => ['brak liczby cykli (wielokrotnego użytku)', 'register'],
        'BDO_MISSING_CATEGORY' => ['BDO : brak kategorii materiału', 'bdo'],
        'BDO_MISSING_USAGE_TYPE' => ['BDO : brak typu (jednorazowe / wielokrotnego użytku)', 'bdo'],
        'NO_RECIPE' => ['brak receptury', 'sales'],
        'NO_PACKAGING' => ['brak opakowania w recepturze', 'sales'],
        'NO_SALES_CARTON' => ['nie wybrano kartonu sprzedażowego', 'sales'],
        'NO_PPC' => ['brak PPC (sztuk w kartonie)', 'sales'],
        'WEIGHT_MISSING' => ['brak poprawnej masy produktu', 'weights'],
        'WEIGHT_SUSPECT' => ['masa produktu 1 g — prawdopodobnie domyślna', 'weights'],
        'SHIPMENT_NO_EVIDENCE' => ['wysyłka bez dowodu opakowań', 'shipments'],
        'SHIPMENT_REQUIRES_DATA' => ['dowód opakowań « wymaga danych »', 'shipments'],
        'SHIPMENT_PPWR_INCOMPLETE' => ['pozycje PPWR dowodu niekompletne', 'shipments'],
        'CHANGED' => ['nowe albo zmienione od ostatniego przeglądu — sprawdź', null],
    ];
    /** Écrans existants où l'on complète (aucun éditeur ici). */
    public const LINKS = [
        'register' => ['/ppwr/register', 'Rejestr PPWR'],
        'bdo' => ['/audits/packaging-data', 'Dane opakowań BDO'],
        'sales' => ['/ppwr/sales-packaging', 'Kartony sprzedażowe PPWR'],
        'weights' => ['/audits/product-weights', 'Wagi produktów'],
        'shipments' => ['/shipments', 'Wysyłki i dokumenty'],
        'reports' => ['/ppwr/reports', 'Raporty PPWR'],
    ];
    private const DAY_ABBR = [1 => 'pon.', 2 => 'wt.', 3 => 'śr.', 4 => 'czw.', 5 => 'pt.', 6 => 'sob.', 7 => 'niedz.'];

    public function __construct(private PpwrMonthRepository $repo, private LightWizardRepository $wizards)
    {
    }

    /** Premier jour du mois du contrôle (clé des décisions et de l'état de la tâche du mois). */
    public static function reviewMonth(string $today): string
    {
        return substr($today, 0, 7) . '-01';
    }

    /** Données des trois étapes ($withAll : aussi tous les éléments, signalés ou non — pour enregistrer « uzupełnione »). */
    public function data(?string $today = null, bool $withAll = false): array
    {
        $today ??= date('Y-m-d');
        $month = self::reviewMonth($today);
        $prev = date('Y-m-01', strtotime($month . ' -1 month'));
        $prevEnd = date('Y-m-d', strtotime($month . ' -1 day'));
        $last = $this->wizards->done(self::CODE, '1970-01-01', $month);
        $since = $last ? substr((string) $last['done_at'], 0, 19) : $prev . ' 00:00:00';

        $materials = $this->repo->packagingMaterials();
        $products = $this->repo->activeProducts();
        $shipments = $this->repo->shipments($prev, $prevEnd);
        $items = [];
        $mStats = ['total' => 0, 'used' => 0, 'incomplete' => 0, 'unused_incomplete' => 0];
        foreach ($materials ?? [] as $m) {
            $it = $this->materialItem($m, $since);
            $mStats['total']++;
            $used = $m['active_products'] === null || (int) $m['active_products'] > 0;
            if ($used) $mStats['used']++;
            if ($it['codes'] !== [] && $used) $mStats['incomplete']++;
            if ($it['codes'] !== [] && !$used) $mStats['unused_incomplete']++;
            if ($used) $items[] = $it;
        }
        $pStats = ['total' => 0, 'incomplete' => 0];
        foreach ($products ?? [] as $p) {
            $it = $this->productItem($p, $since);
            $pStats['total']++;
            if ($it['codes'] !== []) $pStats['incomplete']++;
            $items[] = $it;
        }
        $sStats = ['total' => 0, 'missing' => 0];
        foreach ($shipments ?? [] as $s) {
            $it = $this->shipmentItem($s);
            $sStats['total']++;
            if ($it['codes'] !== []) $sStats['missing']++;
            $items[] = $it;
        }

        // Décisions : celle du mois ; sinon « Nie dotyczy » d'un mois précédent qui couvre les mêmes braki (reprise) ; une
        // wysyłka décidée une fois ne revient pas. L'élément reste à l'écran s'il a une décision du mois, même sans braki.
        $decided = array_flip($this->repo->decidedIn($month));
        $all = $withAll ? array_column($items, null, 'key') : [];
        $items = array_values(array_filter($items, fn($i) => $i['signals'] !== [] || isset($decided[$i['key']])));
        $dec = $this->repo->decisions(array_column($items, 'key'));
        foreach ($items as &$it) {
            $mine = null;
            $earlier = null;
            foreach ($dec[$it['key']] ?? [] as $d) {
                if (substr((string) $d['month_start'], 0, 10) === $month) { $mine ??= $d; continue; }
                $earlier ??= $d;
            }
            $covers = fn(?array $d) => $d !== null && array_diff($it['codes'], array_filter(explode(',', (string) $d['flags']))) === [];
            $carried = $mine === null && $earlier !== null && (($earlier['decision'] === 'not_applicable' && $it['codes'] !== [] && $covers($earlier))
                || ($it['kind'] === 'shipment'));
            $covered = $mine !== null && ($mine['decision'] === 'checked' ? $it['codes'] === [] : $covers($mine));
            $it['decision'] = $mine ? $this->presentDecision($mine) : null;
            $it['earlier'] = $carried ? $this->presentDecision($earlier) : null;
            $it['previous'] = !$carried && $mine === null && $earlier !== null ? $this->presentDecision($earlier) : null;
            $it['waiting'] = $it['signals'] !== [] && !$covered && !$carried;
            $it['reason'] = $it['waiting'] && $mine !== null ? ($mine['decision'] === 'checked' ? 'brak wrócił po « Uzupełnione »' : 'nowy brak po decyzji') : null;
        }
        unset($it);
        $kRank = ['material' => 0, 'product' => 1, 'shipment' => 2];
        usort($items, fn($a, $b) => [$b['waiting'], $kRank[$a['kind']], count($b['codes']), $a['title'], $a['key']] <=> [$a['waiting'], $kRank[$b['kind']], count($a['codes']), $b['title'], $b['key']]);

        $eff = fn(array $i) => $i['decision'] ?? $i['earlier'];
        $count = fn(callable $f) => count(array_filter($items, $f));
        $byCode = [];
        foreach (array_keys(self::SIGNALS) as $c) $byCode[$c] = $count(fn($i) => in_array($c, array_column($i['signals'], 'code'), true));
        $totals = ['review' => count($items), 'waiting' => $count(fn($i) => $i['waiting']), 'resolved' => $count(fn($i) => !$i['waiting']),
            'earlier' => $count(fn($i) => $i['decision'] === null && $i['earlier'] !== null),
            'checked' => $count(fn($i) => ($d = $eff($i)) !== null && $d['code'] === 'checked'),
            'supplier_asked' => $count(fn($i) => ($d = $eff($i)) !== null && $d['code'] === 'supplier_asked'),
            'not_applicable' => $count(fn($i) => ($d = $eff($i)) !== null && $d['code'] === 'not_applicable'),
            'by_kind' => array_combine(array_keys(self::KINDS), array_map(fn($k) => $count(fn($i) => $i['kind'] === $k), array_keys(self::KINDS))),
            'changed' => $byCode['CHANGED'], 'by_code' => $byCode,
            'materials' => $mStats, 'products' => $pStats, 'shipments' => $sStats];
        $label = TaskWizards::monthLabel(substr($month, 0, 7));
        $done = $this->wizards->done(self::CODE, $month, date('Y-m-01', strtotime($month . ' +1 month')));
        return ($withAll ? ['all' => $all] : []) + ['today' => $today, 'label' => self::label($today), 'month_start' => $month, 'month_label' => $label,
            'shipments_from' => $prev, 'shipments_to' => $prevEnd, 'shipments_label' => TaskWizards::monthLabel(substr($prev, 0, 7)),
            'since' => $since, 'since_label' => self::label(substr($since, 0, 10)) . '.' . substr($since, 0, 4) . ($last ? ', ' . self::hm($since) : ''), 'since_review' => $last !== null,
            'sources' => $this->sources($materials, $products, $shipments),
            'review' => $items, 'totals' => $totals, 'can_finish' => $totals['waiting'] === 0, 'summary' => self::summary($label, $totals),
            'decisions' => self::DECISIONS, 'kind_decisions' => self::KIND_DECISIONS, 'note_required' => self::NOTE_REQUIRED, 'kind_labels' => self::KINDS,
            'signal_labels' => array_map(fn($s) => $s[0], self::SIGNALS), 'links' => array_map(fn($l) => ['href' => $l[0], 'label' => $l[1]], self::LINKS),
            'done' => $done ? ['summary' => $done['summary'], 'at' => self::label((string) $done['day']) . ', ' . self::hm((string) $done['done_at']), 'by' => $done['by_login'] ?? null] : null];
    }

    /** Opakowanie : braki PPWR (règle du Rejestr PPWR), BDO, zmiana du profil ; demande au dostawca préparée. */
    private function materialItem(array $m, string $since): array
    {
        $id = (int) $m['id_raw_material'];
        $codes = RawMaterialPpwrService::issues($m);
        if (($m['bdo_material_category'] ?? null) === null || $m['bdo_material_category'] === '') $codes[] = 'BDO_MISSING_CATEGORY';
        if (($m['packaging_usage_type'] ?? null) === null || $m['packaging_usage_type'] === '') $codes[] = 'BDO_MISSING_USAGE_TYPE';
        $changed = $m['profile_updated_at'] !== null && (string) $m['profile_updated_at'] >= $since;
        $signals = array_map(fn($c) => self::sig($c), $codes);
        if ($changed) $signals[] = self::sig('CHANGED', 'profil PPWR zmieniony ' . self::label(substr((string) $m['profile_updated_at'], 0, 10)) . ', ' . self::hm((string) $m['profile_updated_at']));
        $name = trim((string) $m['material_name']) ?: '#' . $id;
        $supplier = trim((string) ($m['supplier_name'] ?? '')) ?: null;
        $email = trim((string) ($m['supplier_email'] ?? '')) ?: null;
        $ask = $codes !== [] ? self::supplierText($name, array_map(fn($c) => self::SIGNALS[$c][0], $codes)) : null;
        $sub = array_filter([$m['packaging_level'] ? 'poziom ' . $m['packaging_level'] : null, $m['dominant_material'] ?: null,
            $m['packaging_unit_weight_g'] !== null ? self::num((float) $m['packaging_unit_weight_g']) . ' g' : null,
            $m['active_products'] !== null ? (int) $m['active_products'] . ' ' . self::pl((int) $m['active_products'], 'produkt aktywny', 'produkty aktywne', 'produktów aktywnych') : null]);
        return ['key' => 'material:' . $id, 'kind' => 'material', 'kind_label' => self::KINDS['material'], 'source_id' => $id, 'title' => $name,
            'sub' => implode(' · ', $sub), 'supplier' => $supplier, 'codes' => $codes, 'signals' => $signals, 'changed' => $changed,
            'ask' => $ask === null ? null : ['subject' => 'Dane opakowania do dokumentacji PPWR : ' . $name, 'text' => $ask, 'email' => $email],
            'links' => self::linksFor($codes, 'register')];
    }

    /** Produit actif : karton sprzedażowy PPWR, masse, création ou karton modifié depuis le dernier contrôle. */
    private function productItem(array $p, string $since): array
    {
        $id = (int) $p['id_final_product'];
        $codes = FinalProductPpwrSalesPackagingService::issueCodes($p);
        $w = ProductWeightAuditEvaluator::evaluate($p['weight'], $p['ppc']);
        if ($w['audit_status'] === ProductWeightAuditEvaluator::ERROR) $codes[] = 'WEIGHT_MISSING';
        elseif ($w['audit_status'] === ProductWeightAuditEvaluator::WARNING) $codes[] = 'WEIGHT_SUSPECT';
        $signals = array_map(fn($c) => self::sig($c, $c === 'WEIGHT_MISSING' ? (string) $w['audit_reason'] : null), $codes);
        $created = $p['created_at'] !== null && (string) $p['created_at'] >= $since;
        $config = $p['config_updated_at'] !== null && (string) $p['config_updated_at'] >= $since;
        if ($created || $config) {
            $signals[] = self::sig('CHANGED', $created ? 'nowy produkt (utworzony ' . self::label(substr((string) $p['created_at'], 0, 10)) . ')'
                : 'karton sprzedażowy zmieniony ' . self::label(substr((string) $p['config_updated_at'], 0, 10)));
        }
        $sub = array_filter([$p['ppc'] ? (int) $p['ppc'] . ' szt. / karton' : null, $w['unit_weight_g'] !== null ? self::num((float) $w['unit_weight_g']) . ' g / szt.' : null,
            $p['verification_status'] ? 'karton : ' . $p['verification_status'] : 'karton nieprzypisany']);
        return ['key' => 'product:' . $id, 'kind' => 'product', 'kind_label' => self::KINDS['product'], 'source_id' => $id,
            'title' => trim((string) $p['product_name']) ?: '#' . $id, 'sub' => implode(' · ', $sub), 'supplier' => null, 'codes' => $codes, 'signals' => $signals,
            'changed' => $created || $config, 'ask' => null, 'links' => self::linksFor($codes, 'sales')];
    }

    /** Wysyłka du mois écoulé : dowód opakowań absent, « wymaga danych » ou lignes PPWR incomplètes. */
    private function shipmentItem(array $s): array
    {
        $id = (int) $s['id'];
        $codes = [];
        if ($s['id_record'] === null) $codes[] = 'SHIPMENT_NO_EVIDENCE';
        else {
            if ($s['record_status'] === 'REQUIRES_DATA') $codes[] = 'SHIPMENT_REQUIRES_DATA';
            if ((int) $s['ppwr_incomplete'] > 0) $codes[] = 'SHIPMENT_PPWR_INCOMPLETE';
        }
        $detail = ['SHIPMENT_PPWR_INCOMPLETE' => (int) $s['ppwr_incomplete'] . ' z ' . (int) $s['items'] . ' pozycji bez kompletu danych PPWR'];
        $date = substr((string) $s['shipment_date'], 0, 10);
        $customer = trim((string) ($s['customer'] ?? '')) ?: 'klient #' . (int) $s['id_customer'];
        return ['key' => 'shipment:' . $id, 'kind' => 'shipment', 'kind_label' => self::KINDS['shipment'], 'source_id' => $id,
            'title' => $customer, 'sub' => 'wysyłka ' . self::label($date) . '.' . substr($date, 0, 4) . ($s['record_status'] ? ' · dowód ' . $s['record_status'] : ''),
            'supplier' => null, 'codes' => $codes, 'signals' => array_map(fn($c) => self::sig($c, $detail[$c] ?? null), $codes), 'changed' => false, 'ask' => null,
            'links' => self::linksFor($codes, 'shipments')];
    }

    /** Écrans où compléter les braki de l'élément (dans l'ordre des braki), sinon l'écran par défaut du type. */
    private static function linksFor(array $codes, string $default): array
    {
        $keys = array_values(array_unique(array_filter(array_map(fn($c) => self::SIGNALS[$c][1], $codes))));
        if ($keys === [] || $keys === ['shipments']) $keys = $default === 'shipments' ? ['shipments', 'reports'] : [$default];
        return array_map(fn($k) => ['href' => self::LINKS[$k][0], 'label' => self::LINKS[$k][1]], $keys);
    }

    /** Texte de la demande au dostawca (à copier ; l'ERP n'envoie rien). */
    public static function supplierText(string $material, array $missing): string
    {
        return "Dzień dobry,\n\nw związku z obowiązkami PPWR (rozporządzenie UE w sprawie opakowań) prosimy o dane opakowania « {$material} » :\n- "
            . implode("\n- ", $missing) . "\n\nW miarę możliwości prosimy o deklarację lub specyfikację techniczną.\n\nZ góry dziękujemy,";
    }

    /** État de chaque source : table présente et au moins une ligne, sinon « brak danych ». */
    private function sources(?array $materials, ?array $products, ?array $shipments): array
    {
        $src = function (?array $rows, string $empty, string $from) {
            $ok = $rows !== null && $rows !== [];
            return ['available' => $ok, 'rows' => $rows === null ? null : count($rows), 'note' => $ok ? null : ($rows === null ? 'brak danych — brak tabeli w bazie' : 'brak danych — ' . $empty), 'from' => $from];
        };
        return [
            'materials' => $src($materials, 'żaden surowiec nie jest oznaczony jako element opakowania', 'raw_material_ppwr_profile'),
            'products' => $src($products, 'brak aktywnych produktów', 'final_product_ppwr_sales_packaging'),
            'shipments' => $src($shipments, 'brak wysyłek w poprzednim miesiącu', 'shipment_packaging_record'),
        ];
    }

    private function presentDecision(array $d): array
    {
        $flags = array_values(array_filter(explode(',', (string) $d['flags'])));
        return ['code' => (string) $d['decision'], 'label' => self::DECISIONS[$d['decision']] ?? (string) $d['decision'], 'note' => $d['note'], 'flags' => $flags,
            'month' => substr((string) $d['month_start'], 0, 7), 'month_label' => TaskWizards::monthLabel(substr((string) $d['month_start'], 0, 7)),
            'by' => $d['decided_by'], 'at' => self::label(substr((string) $d['updated_at'], 0, 10)) . ', ' . self::hm((string) $d['updated_at'])];
    }

    /**
     * Krok 2 : décision pour un élément (une par mois du contrôle, la dernière compte). « Uzupełnione — sprawdzone » n'est
     * acceptée que si l'élément n'a plus aucun brak maintenant (une simple zmiana : sprawdzone). Rien n'est écrit dans les
     * données PPWR / BDO.
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
        // Brak complété dans l'ERP depuis l'affichage : l'élément n'est plus signalé, « uzupełnione » s'enregistre avec les braki vus à l'écran.
        if ($it === null && $decision === 'checked' && $seen !== [] && isset($d['all'][$key]) && $d['all'][$key]['signals'] === []) $it = $d['all'][$key];
        if ($it === null) {
            if (isset($d['all'][$key]) && $decision === 'checked') throw new CustomException('Ten element nie miał braku ani zmiany do sprawdzenia w tym miesiącu.', 'errors', 422);
            throw new CustomException('Tego elementu nie ma na liście (bez braków i bez zmian w tym miesiącu).', 'errors', 422);
        }
        if (!in_array($decision, self::KIND_DECISIONS[$it['kind']], true)) throw new CustomException('Zapytanie do dostawcy dotyczy tylko opakowań.', 'errors', 422);
        if ($decision === 'supplier_asked' && mb_strlen($note) < 3) throw new CustomException('Zapytanie do dostawcy : wpisz, do kogo i o co (np. dostawca, dane, data).', 'errors', 422);
        if ($decision === 'not_applicable' && mb_strlen($note) < 3) throw new CustomException('Nie dotyczy : wpisz dlaczego (np. opakowanie wycofane, produkt bez sprzedaży).', 'errors', 422);
        if ($decision === 'checked' && $it['codes'] !== []) {
            throw new CustomException('Nadal brakuje : ' . implode(', ', array_map(fn($c) => self::SIGNALS[$c][0], $it['codes'])) . '. Uzupełnij dane na ekranie ' . $it['links'][0]['label'] . ' i zapisz ponownie.', 'errors', 422);
        }
        if ($decision !== 'checked' && $it['codes'] === []) throw new CustomException('Ten element nie ma braków — wybierz « Uzupełnione — sprawdzone ».', 'errors', 422);
        $flags = $it['codes'] !== [] ? $it['codes'] : (array_column($it['signals'], 'code') ?: ($seen ?: ($it['decision']['flags'] ?? [])));
        $this->repo->saveDecision($d['month_start'], $key, $it['kind'], $it['source_id'], $decision, $note !== '' ? $note : null, implode(',', $flags), $userId);
        return $this->data($today);
    }

    /** Krok 3 : « Zakończ » — chaque élément signalé a sa décision ; la tâche du mois se valide seule. */
    public function finish(?int $userId, ?string $today = null): array
    {
        $today ??= date('Y-m-d');
        $d = $this->data($today);
        $w = $d['totals']['waiting'];
        if ($w > 0) throw new CustomException('Najpierw zapisz decyzję dla ' . $w . ' ' . self::pl($w, 'elementu', 'elementów', 'elementów') . ' z kroku 2.', 'errors', 422);
        $this->wizards->markDone(self::CODE, $today, $d['summary'], $userId);
        return ['summary' => $d['summary'], 'data' => $this->data($today)];
    }

    /** « listopad 2026 : opakowania 3 z brakami / 12 · produkty 2 z brakami / 30 · 1 zmiana · wysyłki 1 bez dowodu / 18 · decyzje : 4 sprawdzone · 1 zapytanie do dostawcy · 1 nie dotyczy » */
    public static function summary(string $month, array $t): string
    {
        $s = $month . ' : opakowania ' . $t['materials']['incomplete'] . ' z brakami / ' . $t['materials']['used'] . ' · produkty ' . $t['products']['incomplete'] . ' z brakami / ' . $t['products']['total'];
        if ($t['changed']) $s .= ' · ' . $t['changed'] . ' ' . self::pl($t['changed'], 'zmiana', 'zmiany', 'zmian');
        $s .= ' · wysyłki ' . $t['shipments']['missing'] . ' bez kompletu dowodu / ' . $t['shipments']['total'];
        $dec = [];
        if ($t['checked']) $dec[] = $t['checked'] . ' sprawdzone';
        if ($t['supplier_asked']) $dec[] = $t['supplier_asked'] . ' ' . self::pl($t['supplier_asked'], 'zapytanie do dostawcy', 'zapytania do dostawców', 'zapytań do dostawców');
        if ($t['not_applicable']) $dec[] = $t['not_applicable'] . ' nie dotyczy';
        return $dec ? $s . ' · decyzje : ' . implode(' · ', $dec) : $s;
    }

    private static function sig(string $code, ?string $detail = null): array
    {
        return ['code' => $code, 'label' => self::SIGNALS[$code][0], 'detail' => $detail];
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
        return rtrim(rtrim(number_format($v, 3, ',', ''), '0'), ',');
    }

    private static function pl(int $n, string $one, string $few, string $many): string
    {
        return $n === 1 ? $one : (in_array($n % 10, [2, 3, 4], true) && !in_array($n % 100, [12, 13, 14], true) ? $few : $many);
    }
}
