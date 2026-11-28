<?php
declare(strict_types=1);

namespace App\Services\Organization\Wizards;

use App\Domain\Params\ParamRules;
use App\Exceptions\CustomException;
use App\Repositories\Organization\LightWizardRepository;
use App\Repositories\Workshop\IntrastatMonthRepository;
use App\Repositories\Workshop\ParamRepository;
use App\Services\Client\Vies\ViesClient;
use App\Services\Organization\TaskWizards;

/**
 * Asystent « Intrastat miesiąca » [398] (co miesiąc ; zakres obowiązków « produkcja » 237 : Kierownik produkcji 6.5).
 * Mois examiné = le mois précédent (comme « HACCP miesiąca »). Ce que l'ERP sait, rien de plus :
 *   - wywóz : lignes des WDT du mois (date de wysyłka, sinon d'établissement ; WDT remplacées exclues) — société = vendeur
 *     du document (consignor_nip), partenaire = VAT UE du destinataire (consignee_nip, sinon NIP / VAT de la fiche client,
 *     règle KSeF de la WDT), valeur = lignes (quantité × prix carton), masse nette = weight_carton_total (kg) sinon masse
 *     produit × PPC × cartons ;
 *   - przywóz : lignes des factures d'achat du mois d'un vendeur au VAT UE hors Pologne (préfixes VIES) — société = celle de
 *     l'ERP (la facture ne porte pas le NIP acheteur), masse = quantité en unité de base du surowiec (g, kg ; sinon masse par
 *     unité saisie ici) ;
 *   - codes CN (8 chiffres), masse par unité, kursy du mois : saisis ici (tables n_intrastat_*), jamais dans l'ERP ;
 *   - seuils, rodzaj transakcji, warunki dostawy, rodzaj transportu : réglages intrastat.* (0 / vide = non défini).
 * L'ERP ne connaît qu'une société (erp_company_data) : les WDT d'un autre vendeur (autre consignor_nip) forment leur propre
 * déclaration ; Samloc et Atelier n'existent pas dans l'ERP et ne sont pas inventés.
 *   1. Przegląd : tableaux par société et par sens (CN × pays × VAT partenaire), signaux, CSV. 2. Do decyzji : une décision
 *   par signal (n_intrastat_review) — Uzupełnione (code CN / masse / kurs saisis ici ou fiche corrigée ; acceptée seulement
 *   quand le signal a disparu), Nie dotyczy (note) ; déclaration : Zgłoszone w PUESC (numéro de référence) ou Poniżej progu —
 *   bez zgłoszenia (note). 3. Zatwierdź : « Zakończ » écrit n_wizard_done — bloqué tant qu'un élément attend.
 * Aucun XML officiel, aucun appel extérieur, aucun envoi ; lecture seule de l'ERP.
 */
final class IntrastatMonthService
{
    public const CODE = 'intrastat_month_reviewed';
    public const PATH = '/intrastat/month';
    public const DIRECTIONS = ['dispatch' => 'Wywóz', 'arrival' => 'Przywóz'];
    public const DECISIONS = ['completed' => 'Uzupełnione', 'not_applicable' => 'Nie dotyczy', 'declared' => 'Zgłoszone w PUESC', 'below_threshold' => 'Poniżej progu — bez zgłoszenia'];
    public const KIND_DECISIONS = ['declaration' => ['declared', 'below_threshold'], 'rate' => ['completed'], 'product' => ['completed', 'not_applicable'],
        'material' => ['completed', 'not_applicable'], 'partner' => ['completed', 'not_applicable'], 'invoice' => ['completed', 'not_applicable']];
    /** Décisions qui demandent une note (au moins 3 caractères). */
    public const NOTE_REQUIRED = ['not_applicable', 'declared', 'below_threshold'];
    public const KINDS = ['declaration' => 'Deklaracja', 'rate' => 'Kurs waluty', 'product' => 'Produkt (wywóz)', 'material' => 'Surowiec (przywóz)',
        'partner' => 'Odbiorca (wywóz)', 'invoice' => 'Faktura zakupu'];
    /** Signaux (ordre = priorité). */
    public const SIGNALS = [
        'rate_missing' => 'brak kursu waluty na miesiąc',
        'cn_missing' => 'brak kodu CN',
        'mass_missing' => 'brak masy netto',
        'vat_missing' => 'odbiorca bez numeru VAT UE z prefiksem kraju',
        'line_unmapped' => 'pozycje faktury bez surowca (brak kodu CN i masy)',
        'partner_unknown' => 'faktura w walucie obcej, sprzedawca bez VAT UE — czy to przywóz z UE ?',
        'threshold_exceeded' => 'suma od początku roku ≥ próg z ustawień',
        'threshold_below' => 'suma od początku roku poniżej progu z ustawień',
        'threshold_not_set' => 'próg nie ustawiony (Ustawienia › Parametry)',
    ];
    private const DECL_SIGNALS = ['threshold_exceeded', 'threshold_below', 'threshold_not_set'];
    public const SETTINGS = ['threshold_dispatch' => 'intrastat.threshold_dispatch_kpln', 'threshold_arrival' => 'intrastat.threshold_arrival_kpln',
        'transaction_nature' => 'intrastat.transaction_nature', 'delivery_terms' => 'intrastat.delivery_terms', 'transport_mode' => 'intrastat.transport_mode'];
    /** Écrans existants (aucun éditeur ici, sauf codes CN, masse par unité et kursy dans les tables de l'assistant). */
    public const CLIENT_LINK = '/clients/%d/edit';
    public const MATERIAL_LINK = '/materials/%d/edit';
    public const WEIGHTS_LINK = '/audits/product-weights';
    public const INVOICES_LINK = '/workshop/supply#tab=invoices';
    public const SETTINGS_LINK = '/other-settings#params';
    /** Préfixe VAT → code pays (seule différence : la Grèce, EL dans le VAT, GR dans les codes pays). */
    private const VAT_COUNTRY = ['EL' => 'GR'];
    private const MASS_UNITS = ['g' => 0.001, 'gram' => 0.001, 'gramy' => 0.001, 'kg' => 1.0, 'kilogram' => 1.0];
    private const DAY_ABBR = [1 => 'pon.', 2 => 'wt.', 3 => 'śr.', 4 => 'czw.', 5 => 'pt.', 6 => 'sob.', 7 => 'niedz.'];

    public function __construct(private IntrastatMonthRepository $repo, private LightWizardRepository $wizards, private ParamRepository $params)
    {
    }

    /** Premier jour du mois examiné : le mois qui précède celui de $today. */
    public static function reviewedMonth(string $today): string
    {
        return date('Y-m-01', strtotime(substr($today, 0, 7) . '-01 -1 month'));
    }

    /** Réglages intrastat.* : seuils en zł (null = non défini, 0 ou invalide), textes (null = vide ou « - »). */
    public function settings(): array
    {
        $out = [];
        foreach (self::SETTINGS as $k => $code) {
            $raw = trim($this->params->get($code, ''));
            if (str_starts_with($k, 'threshold_')) {
                $v = str_replace(',', '.', $raw);
                $ok = is_numeric($v) && ParamRules::rangeError($code, $v) === null && (float) $v > 0;
                $out[$k] = $ok ? round((float) $v * 1000, 2) : null;
            } else {
                $out[$k] = $raw === '' || $raw === '-' ? null : mb_substr($raw, 0, 40);
            }
        }
        return $out;
    }

    /** Règle KSeF de la WDT (KsefWdtInvoiceXmlMapper::splitEuVat) : [pays, numéro] ou null. */
    public static function euVat(?string $vat): ?array
    {
        $v = strtoupper(preg_replace('/[^A-Z0-9]/', '', (string) $vat) ?? '');
        if (preg_match('/^([A-Z]{2})([A-Z0-9]{2,14})$/', $v, $m) !== 1 || $m[1] === 'PL') return null;
        return [self::VAT_COUNTRY[$m[1]] ?? $m[1], $m[1] . $m[2]];
    }

    /** Vendeur d'une facture d'achat : 'eu' (préfixe VIES hors PL), 'pl' (NIP polonais) ou 'unknown'. */
    public static function sellerOrigin(?string $vat): string
    {
        $v = strtoupper(preg_replace('/[\s.\-\/]+/', '', trim((string) $vat)) ?? '');
        if ($v === '') return 'unknown';
        if (preg_match('/^\d{10}$/', $v) === 1 || preg_match('/^PL\d{10}$/', $v) === 1) return 'pl';
        if (preg_match('/^([A-Z]{2})[A-Z0-9]{2,14}$/', $v, $m) === 1 && $m[1] !== 'PL' && in_array($m[1], ViesClient::VIES_COUNTRY_CODES, true)) return 'eu';
        return 'unknown';
    }

    /** Code CN saisi : 8 chiffres (espaces et points ignorés), '' = effacer ; null = format invalide. */
    public static function normalizeCn(string $raw): ?string
    {
        $v = preg_replace('/[\s.]+/', '', $raw) ?? '';
        if ($v === '') return '';
        return preg_match('/^\d{8}$/', $v) === 1 ? $v : null;
    }

    /** Données des trois étapes ($withAll : aussi les éléments du mois sans signal, pour « uzupełnione » et les codes). */
    public function data(?string $today = null, bool $withAll = false): array
    {
        $today ??= date('Y-m-d');
        $from = self::reviewedMonth($today);
        $to = date('Y-m-t', strtotime($from));
        $ym = substr($from, 0, 7);
        $yearStart = substr($from, 0, 4) . '-01-01';
        $settings = $this->settings();
        $codes = $this->repo->codes();
        $rates = $this->repo->rates($yearStart, $from);
        $erp = $this->repo->erpCompany();
        $wdt = $this->repo->wdtLines($yearStart, $to);
        $inv = $this->repo->invoiceLines($yearStart, $to);
        $customers = $this->repo->customers(array_column($wdt ?? [], 'id_customer'));
        $erpKey = $erp ? self::nipKey($erp['nip']) : 'erp';
        $erpName = $erp ? ($erp['name'] ?: 'NIP ' . $erp['nip']) : 'Firma z ERP (brak danych firmy w ustawieniach)';

        // Lignes normalisées de l'année jusqu'au mois examiné ; les signaux ne viennent que des lignes du mois.
        $lines = [];
        foreach ($wdt ?? [] as $w) {
            $company = self::nipKey((string) $w['consignor_nip']) ?: $erpKey;
            $cust = $customers[(int) $w['id_customer']] ?? null;
            $partner = self::euVat($w['consignee_nip']) ?? self::euVat($cust['tax_number'] ?? null);
            $pid = (int) $w['id_product'];
            $qty = (float) $w['quantity'];
            $mass = (float) ($w['weight_total_kg'] ?? 0) > 0 ? (float) $w['weight_total_kg']
                : ((float) ($w['product_weight_g'] ?? 0) > 0 && (int) ($w['product_ppc'] ?? 0) > 0 ? (float) $w['product_weight_g'] * (int) $w['product_ppc'] * $qty / 1000 : null);
            $lines[] = ['dir' => 'dispatch', 'ym' => substr((string) $w['move_date'], 0, 7), 'company' => $company,
                'company_name' => trim((string) $w['consignor_name']) ?: ($company === $erpKey ? $erpName : 'NIP ' . $w['consignor_nip']),
                'doc' => 'wdt:' . (int) $w['id_wdt'], 'doc_label' => (string) $w['doc_num'], 'item' => 'product:' . $pid, 'item_kind' => 'product', 'item_id' => $pid,
                'item_name' => trim((string) ($w['product_name'] ?? '')) ?: (trim((string) $w['line_name']) ?: 'produkt #' . $pid),
                'cn' => $codes['product'][$pid]['cn_code'] ?? null, 'mass_kg' => $mass, 'qty_label' => self::num($qty) . ' kart.',
                'currency' => (string) $w['currency'], 'value_doc' => round($qty * (float) $w['price_carton'], 2),
                'country' => $partner[0] ?? null, 'partner_vat' => $partner[1] ?? null, 'id_customer' => (int) $w['id_customer'],
                'customer' => $cust['name'] ?? (trim((string) $w['consignee_name']) ?: 'klient #' . (int) $w['id_customer']), 'consignee_nip' => trim((string) $w['consignee_nip'])];
        }
        $unknownInv = [];
        foreach ($inv ?? [] as $i) {
            $origin = self::sellerOrigin($i['seller_vat']);
            if ($origin !== 'eu') {
                if ($origin === 'unknown' && $i['currency'] !== 'PLN' && substr((string) $i['issue_date'], 0, 7) === $ym) $unknownInv[(int) $i['id_inv']] = $i;
                continue;
            }
            $mid = $i['id_raw_material'] !== null ? (int) $i['id_raw_material'] : null;
            $base = (float) $i['quantity'] * ((float) ($i['factor'] ?? 0) ?: 1.0);
            $unit = mb_strtolower(trim((string) ($i['base_unit'] ?? '')));
            $per = self::MASS_UNITS[$unit] ?? ($mid !== null ? ($codes['material'][$mid]['unit_mass_kg'] ?? null) : null);
            $vat = strtoupper(preg_replace('/[\s.\-\/]+/', '', (string) $i['seller_vat']) ?? '');
            $lines[] = ['dir' => 'arrival', 'ym' => substr((string) $i['issue_date'], 0, 7), 'company' => $erpKey, 'company_name' => $erpName,
                'doc' => 'inv:' . (int) $i['id_inv'], 'doc_label' => (string) ($i['number'] ?? ('#' . $i['id_inv'])), 'id_inv' => (int) $i['id_inv'],
                'item' => $mid !== null ? 'material:' . $mid : null, 'item_kind' => $mid !== null ? 'material' : null, 'item_id' => $mid,
                'item_name' => trim((string) ($i['material_name'] ?? '')) ?: trim((string) $i['line_name']), 'base_unit' => $unit ?: null,
                'cn' => $mid !== null ? ($codes['material'][$mid]['cn_code'] ?? null) : null, 'mass_kg' => $mid !== null && $per !== null ? $base * $per : null,
                'qty_label' => self::num((float) $i['quantity']) . ' ' . trim((string) $i['unit']), 'currency' => (string) $i['currency'],
                'value_doc' => round($i['net_value'] !== null ? (float) $i['net_value'] : (float) $i['quantity'] * (float) $i['unit_price'], 2),
                'country' => self::VAT_COUNTRY[substr($vat, 0, 2)] ?? substr($vat, 0, 2), 'partner_vat' => $vat, 'seller' => trim((string) $i['seller_name']) ?: $vat];
        }
        foreach ($lines as &$l) {
            $l['rate'] = $l['currency'] === 'PLN' ? 1.0 : ($rates[$l['ym']][$l['currency']] ?? null);
            $l['value_pln'] = $l['rate'] !== null ? round($l['value_doc'] * $l['rate'], 2) : null;
        }
        unset($l);
        $month = array_values(array_filter($lines, fn($l) => $l['ym'] === $ym));

        // Déclarations : une par société et par sens avec des lignes dans le mois.
        $decls = [];
        foreach ($month as $l) {
            $k = 'decl:' . $l['company'] . ':' . $l['dir'];
            $decls[$k] ??= ['key' => $k, 'dir' => $l['dir'], 'dir_label' => self::DIRECTIONS[$l['dir']], 'company' => $l['company'], 'company_name' => $l['company_name'], 'lines' => []];
            $decls[$k]['lines'][] = $l;
        }
        foreach ($decls as $k => &$dc) {
            $dc['rows'] = self::aggregate($dc['lines'], $dc['dir']);
            $dc['docs'] = count(array_unique(array_column($dc['lines'], 'doc')));
            $dc['value_pln'] = round(array_sum(array_filter(array_column($dc['lines'], 'value_pln'), fn($v) => $v !== null)), 2);
            $dc['value_complete'] = !in_array(null, array_column($dc['lines'], 'value_pln'), true);
            $dc['mass_kg'] = round(array_sum(array_filter(array_column($dc['lines'], 'mass_kg'), fn($v) => $v !== null)), 3);
            $dc['mass_complete'] = !in_array(null, array_column($dc['lines'], 'mass_kg'), true);
            $year = array_filter($lines, fn($l) => $l['dir'] === $dc['dir'] && $l['company'] === $dc['company']);
            $dc['year_pln'] = round(array_sum(array_filter(array_column($year, 'value_pln'), fn($v) => $v !== null)), 2);
            $dc['year_complete'] = !in_array(null, array_column($year, 'value_pln'), true);
            $dc['threshold'] = $settings['threshold_' . $dc['dir']];
            unset($dc['lines']);
        }
        unset($dc);

        // Éléments signalés.
        $items = [];
        $add = function (string $key, string $kind, string $title, string $sub, array $extra = []) use (&$items) {
            $items[$key] ??= $extra + ['key' => $key, 'kind' => $kind, 'kind_label' => self::KINDS[$kind], 'title' => $title, 'sub' => $sub, 'signals' => [], 'decls' => [], 'links' => []];
        };
        $sig = function (string $key, string $code, ?string $detail = null) use (&$items) {
            foreach ($items[$key]['signals'] as &$s) if ($s['code'] === $code) { if ($detail !== null && $s['detail'] !== null && !str_contains($s['detail'], $detail)) $s['detail'] .= ' ; ' . $detail; return; }
            unset($s);
            $items[$key]['signals'][] = ['code' => $code, 'label' => self::SIGNALS[$code], 'detail' => $detail];
        };
        foreach ($decls as $k => $dc) {
            $add($k, 'declaration', $dc['dir_label'] . ' — ' . $dc['company_name'], ($dc['company'] !== 'erp' ? 'NIP ' . $dc['company'] . ' · ' : '') . $dc['docs'] . ' ' . self::pl($dc['docs'], 'dokument', 'dokumenty', 'dokumentów'),
                ['dir' => $dc['dir'], 'company' => $dc['company']]);
            $yearTxt = 'od początku roku ' . self::money($dc['year_pln']) . ' zł' . ($dc['year_complete'] ? '' : ' (bez pozycji bez kursu)');
            if ($dc['threshold'] === null) $sig($k, 'threshold_not_set', $yearTxt);
            elseif ($dc['year_pln'] >= $dc['threshold']) $sig($k, 'threshold_exceeded', $yearTxt . ' ≥ ' . self::money($dc['threshold']) . ' zł');
            else $sig($k, 'threshold_below', $yearTxt . ' < ' . self::money($dc['threshold']) . ' zł' . ($dc['year_complete'] ? '' : ' — sprawdź po uzupełnieniu kursów'));
        }
        $products = [];
        foreach ($month as $l) {
            $decl = 'decl:' . $l['company'] . ':' . $l['dir'];
            if ($l['item'] !== null) {
                $id = (int) $l['item_id'];
                $link = $l['item_kind'] === 'product' ? ['href' => self::WEIGHTS_LINK, 'label' => 'Wagi produktów'] : ['href' => sprintf(self::MATERIAL_LINK, $id), 'label' => 'Karta surowca'];
                $add($l['item'], $l['item_kind'], $l['item_name'], '', ['item_kind' => $l['item_kind'], 'item_id' => $id, 'cn_code' => $codes[$l['item_kind']][$id]['cn_code'] ?? null,
                    'unit_mass_kg' => $codes[$l['item_kind']][$id]['unit_mass_kg'] ?? null, 'base_unit' => $l['base_unit'] ?? null,
                    'needs_unit_mass' => $l['item_kind'] === 'material' && !isset(self::MASS_UNITS[(string) ($l['base_unit'] ?? '')]), 'links' => $id > 0 ? [$link] : []]);
                $items[$l['item']]['decls'][$decl] = true;
                $products[$l['item']][$l['doc']] = true;
                if ($l['cn'] === null) $sig($l['item'], 'cn_missing');
                if ($l['mass_kg'] === null) $sig($l['item'], 'mass_missing', $l['item_kind'] === 'product' ? 'brak wagi kartonu w WDT i masy produktu × PPC'
                    : 'jednostka surowca « ' . ($l['base_unit'] ?? '?') . ' » — wpisz masę netto (kg) jednej jednostki');
            }
            // Odbiorcy, factures et devises du mois : toujours des éléments (pour « Uzupełnione » après correction), signalés seulement si besoin.
            if ($l['dir'] === 'dispatch') {
                $pk = 'partner:' . $l['id_customer'];
                $add($pk, 'partner', $l['customer'], 'WDT : NIP odbiorcy « ' . ($l['consignee_nip'] ?: 'pusty') . ' »', ['links' => [['href' => sprintf(self::CLIENT_LINK, $l['id_customer']), 'label' => 'Karta klienta']]]);
                $items[$pk]['decls'][$decl] = true;
                if ($l['partner_vat'] === null) $sig($pk, 'vat_missing', $l['doc_label']);
            }
            if ($l['dir'] === 'arrival') {
                $ik = 'invoice:' . $l['id_inv'];
                $add($ik, 'invoice', $l['doc_label'] . ' — ' . $l['seller'], 'faktura zakupu', ['links' => [['href' => self::INVOICES_LINK, 'label' => 'Faktury zakupowe (przypisz surowiec)']]]);
                $items[$ik]['decls'][$decl] = true;
                if ($l['item'] === null) {
                    $items[$ik]['unmapped'] = ($items[$ik]['unmapped'] ?? 0) + 1;
                    $sig($ik, 'line_unmapped');
                }
            }
            if ($l['currency'] !== 'PLN') {
                $rk = 'rate:' . $l['currency'];
                $add($rk, 'rate', $l['currency'], 'kurs ' . TaskWizards::monthLabel($ym), ['currency' => $l['currency'], 'rate' => $l['rate']]);
                $items[$rk]['decls'][$decl] = true;
                if ($l['rate'] === null) $sig($rk, 'rate_missing');
            }
        }
        foreach ($unknownInv as $id => $i) {
            $ik = 'invoice:' . $id;
            $add($ik, 'invoice', ($i['number'] ?? '#' . $id) . ' — ' . (trim((string) $i['seller_name']) ?: 'sprzedawca bez nazwy'), 'faktura zakupu w ' . $i['currency'] . ' · VAT sprzedawcy « ' . (trim((string) $i['seller_vat']) ?: 'pusty') . ' »',
                ['links' => [['href' => self::INVOICES_LINK, 'label' => 'Faktury zakupowe']]]);
            $sig($ik, 'partner_unknown');
        }
        foreach ($items as $k => &$it) {
            if (isset($products[$k])) $it['sub'] = count($products[$k]) . ' ' . self::pl(count($products[$k]), 'dokument', 'dokumenty', 'dokumentów') . ($it['cn_code'] ? ' · CN ' . $it['cn_code'] : '');
            if (isset($it['unmapped'])) $it['signals'][0]['detail'] = $it['unmapped'] . ' ' . self::pl($it['unmapped'], 'pozycja', 'pozycje', 'pozycji');
            $it['decls'] = array_keys($it['decls']);
            $it['codes'] = $it['kind'] === 'declaration' ? [] : array_column($it['signals'], 'code');
        }
        unset($it);

        // Décisions : celle du mois ; « Nie dotyczy » d'un mois précédent reprise tant que les signaux sont les mêmes.
        $decided = array_flip($this->repo->decidedIn($from));
        $all = $withAll ? $items : [];
        $list = array_values(array_filter($items, fn($i) => $i['signals'] !== [] || isset($decided[$i['key']])));
        $dec = $this->repo->decisions(array_column($list, 'key'));
        foreach ($list as &$it) {
            $mine = null;
            $earlier = null;
            foreach ($dec[$it['key']] ?? [] as $d) {
                if (substr((string) $d['month_start'], 0, 10) === $from) { $mine ??= $d; continue; }
                $earlier ??= $d;
            }
            $covers = fn(?array $d) => $d !== null && array_diff($it['codes'], array_filter(explode(',', (string) $d['flags']))) === [];
            $carried = $mine === null && $earlier !== null && $it['kind'] !== 'declaration' && $earlier['decision'] === 'not_applicable' && $it['codes'] !== [] && $covers($earlier);
            $covered = $mine !== null && ($it['kind'] === 'declaration' || ($mine['decision'] === 'completed' ? $it['codes'] === [] : $covers($mine)));
            $it['decision'] = $mine ? $this->presentDecision($mine) : null;
            $it['earlier'] = $carried ? $this->presentDecision($earlier) : null;
            $it['waiting'] = $it['signals'] !== [] && !$covered && !$carried;
            $it['reason'] = $it['waiting'] && $mine !== null ? ($mine['decision'] === 'completed' ? 'brak wrócił po « Uzupełnione »' : 'nowy sygnał po decyzji') : null;
        }
        unset($it);
        // Ordre : ce qui attend d'abord ; kursy, produits, surowce, odbiorcy, factures, puis les déclarations (décidées en dernier).
        $kRank = ['rate' => 0, 'product' => 1, 'material' => 2, 'partner' => 3, 'invoice' => 4, 'declaration' => 5];
        usort($list, fn($a, $b) => [!$a['waiting'], $kRank[$a['kind']], $a['title'], $a['key']] <=> [!$b['waiting'], $kRank[$b['kind']], $b['title'], $b['key']]);
        // Déclaration bloquée tant qu'un élément de ses lignes attend.
        $waitingBy = [];
        foreach ($list as $i) if ($i['waiting'] && $i['kind'] !== 'declaration') foreach ($i['decls'] as $dk) $waitingBy[$dk] = ($waitingBy[$dk] ?? 0) + 1;
        foreach ($list as &$it) if ($it['kind'] === 'declaration') $it['blocked'] = $waitingBy[$it['key']] ?? 0;
        unset($it);
        foreach ($decls as $k => &$dc) {
            $dc['blocked'] = $waitingBy[$k] ?? 0;
            $di = array_values(array_filter($list, fn($i) => $i['key'] === $k))[0] ?? null;
            $dc['decision'] = $di['decision'] ?? null;
        }
        unset($dc);

        $eff = fn(array $i) => $i['decision'] ?? $i['earlier'];
        $count = fn(callable $f) => count(array_filter($list, $f));
        $dirTot = fn(string $dir) => ['declarations' => count(array_filter($decls, fn($d) => $d['dir'] === $dir)),
            'docs' => array_sum(array_column(array_filter($decls, fn($d) => $d['dir'] === $dir), 'docs')),
            'value_pln' => round(array_sum(array_column(array_filter($decls, fn($d) => $d['dir'] === $dir), 'value_pln')), 2),
            'value_complete' => !in_array(false, array_column(array_filter($decls, fn($d) => $d['dir'] === $dir), 'value_complete'), true)];
        $totals = ['review' => count($list), 'waiting' => $count(fn($i) => $i['waiting']), 'resolved' => $count(fn($i) => !$i['waiting']),
            'earlier' => $count(fn($i) => $i['decision'] === null && $i['earlier'] !== null),
            'by_kind' => array_combine(array_keys(self::KINDS), array_map(fn($k) => $count(fn($i) => $i['kind'] === $k), array_keys(self::KINDS))),
            'dispatch' => $dirTot('dispatch'), 'arrival' => $dirTot('arrival'), 'signals' => $count(fn($i) => $i['kind'] !== 'declaration' && $i['signals'] !== [])];
        foreach (array_keys(self::DECISIONS) as $k) $totals[$k] = $count(fn($i) => ($d = $eff($i)) !== null && $d['code'] === $k);
        $label = TaskWizards::monthLabel($ym);
        $done = $this->wizards->done(self::CODE, substr($today, 0, 7) . '-01', date('Y-m-01', strtotime(substr($today, 0, 7) . '-01 +1 month')));
        $currencies = array_values(array_unique(array_filter(array_column($month, 'currency'), fn($c) => $c !== 'PLN')));
        sort($currencies);
        return ($withAll ? ['all' => $all] : []) + ['today' => $today, 'label' => self::label($today), 'month_start' => $from, 'month_end' => $to, 'month_key' => $ym, 'month_label' => $label,
            'settings' => $settings, 'settings_link' => self::SETTINGS_LINK, 'company' => $erp, 'company_known' => $erp !== null,
            'currencies' => array_map(fn($c) => ['currency' => $c, 'rate' => $rates[$ym][$c] ?? null], $currencies),
            'sources' => $this->sources($wdt, $inv, $erp), 'declarations' => array_values($decls), 'review' => $list, 'totals' => $totals,
            'can_finish' => $totals['waiting'] === 0, 'summary' => self::summary($label, $totals),
            'decisions' => self::DECISIONS, 'kind_decisions' => self::KIND_DECISIONS, 'note_required' => self::NOTE_REQUIRED, 'kind_labels' => self::KINDS, 'signal_labels' => self::SIGNALS,
            'done' => $done ? ['summary' => $done['summary'], 'at' => self::label((string) $done['day']) . ', ' . self::hm((string) $done['done_at']), 'by' => $done['by_login'] ?? null] : null];
    }

    /** Lignes d'une déclaration regroupées par code CN × pays × (VAT partenaire au wywóz). */
    private static function aggregate(array $lines, string $dir): array
    {
        $rows = [];
        foreach ($lines as $l) {
            $k = ($l['cn'] ?? '') . '|' . ($l['country'] ?? '') . '|' . ($dir === 'dispatch' ? ($l['partner_vat'] ?? '') : '');
            $rows[$k] ??= ['cn' => $l['cn'], 'country' => $l['country'], 'partner_vat' => $dir === 'dispatch' ? $l['partner_vat'] : null, 'mass_kg' => 0.0, 'mass_complete' => true,
                'value_pln' => 0.0, 'value_complete' => true, 'docs' => [], 'lines' => 0, 'currencies' => [], 'items' => []];
            $r = &$rows[$k];
            if ($l['mass_kg'] === null) $r['mass_complete'] = false; else $r['mass_kg'] += $l['mass_kg'];
            if ($l['value_pln'] === null) $r['value_complete'] = false; else $r['value_pln'] += $l['value_pln'];
            $r['docs'][$l['doc']] = true;
            $r['lines']++;
            $r['currencies'][$l['currency']] = true;
            $r['items'][$l['item_name']] = true;
            unset($r);
        }
        $out = [];
        foreach ($rows as $r) {
            $remarks = [];
            if ($r['cn'] === null) $remarks[] = 'brak kodu CN';
            if ($r['country'] === null) $remarks[] = 'brak VAT UE odbiorcy';
            if (!$r['mass_complete']) $remarks[] = 'masa niepełna';
            if (!$r['value_complete']) $remarks[] = 'brak kursu';
            $out[] = ['cn' => $r['cn'], 'country' => $r['country'], 'partner_vat' => $r['partner_vat'], 'mass_kg' => round($r['mass_kg'], 3), 'mass_complete' => $r['mass_complete'],
                'value_pln' => round($r['value_pln'], 2), 'value_complete' => $r['value_complete'], 'docs' => count($r['docs']), 'lines' => $r['lines'],
                'currencies' => array_keys($r['currencies']), 'items' => array_slice(array_keys($r['items']), 0, 4), 'items_count' => count($r['items']), 'remarks' => $remarks];
        }
        usort($out, fn($a, $b) => [$a['cn'] === null, $a['cn'], $a['country'], $a['partner_vat']] <=> [$b['cn'] === null, $b['cn'], $b['country'], $b['partner_vat']]);
        return $out;
    }

    /** Fichier CSV d'une déclaration (à recopier dans PUESC ; aucun format officiel généré). */
    public function csv(string $declKey, ?string $today = null): array
    {
        $d = $this->data($today);
        $dc = array_values(array_filter($d['declarations'], fn($x) => $x['key'] === $declKey))[0] ?? null;
        if ($dc === null) throw new CustomException('Brak takiej deklaracji w miesiącu ' . $d['month_label'] . '.', 'errors', 404);
        $s = $d['settings'];
        $num = fn(float $v, int $dec) => number_format($v, $dec, ',', '');
        $cell = fn(?string $v) => '"' . str_replace('"', '""', (string) $v) . '"';
        $out = ["\u{FEFF}" . implode(';', array_map($cell, ['Miesiąc', 'Kierunek', 'NIP firmy', 'Firma', 'Kod CN', 'Kraj partnera', 'Nr VAT partnera', 'Rodzaj transakcji',
            'Warunki dostawy', 'Rodzaj transportu', 'Masa netto (kg)', 'Wartość (PLN)', 'Liczba dokumentów', 'Uwagi']))];
        foreach ($dc['rows'] as $r) {
            $out[] = implode(';', array_map($cell, [$d['month_key'], $dc['dir_label'], $dc['company'] !== 'erp' ? $dc['company'] : '', $dc['company_name'], $r['cn'] ?? '', $r['country'] ?? '',
                $r['partner_vat'] ?? '', $s['transaction_nature'] ?? '', $s['delivery_terms'] ?? '', $s['transport_mode'] ?? '', $num($r['mass_kg'], 3), $num($r['value_pln'], 2),
                (string) $r['docs'], implode(', ', $r['remarks'])]));
        }
        $name = 'INTRASTAT_' . $d['month_key'] . '_' . ($dc['dir'] === 'dispatch' ? 'WYWOZ' : 'PRZYWOZ') . '_' . preg_replace('/[^A-Za-z0-9]/', '', $dc['company']) . '.csv';
        return ['name' => $name, 'content' => implode("\r\n", $out) . "\r\n"];
    }

    /** État des sources : tables présentes et lignes dans le mois, sinon « brak danych ». */
    private function sources(?array $wdt, ?array $inv, ?array $erp): array
    {
        return [
            'wdt' => ['available' => $wdt !== null, 'from' => 'document_wdt', 'note' => $wdt === null ? 'brak danych — brak tabeli WDT : wywóz nieznany' : null],
            'inv' => ['available' => $inv !== null, 'from' => 'n_inv', 'note' => $inv === null ? 'brak danych — brak tabeli faktur zakupowych : przywóz nieznany' : null],
            'company' => ['available' => $erp !== null, 'from' => 'erp_company_data', 'note' => $erp === null ? 'brak danych — brak NIP firmy w ustawieniach ERP : przywóz przypisany « firmie z ERP »' : null],
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
     * Krok 2 : décision pour un élément (une par mois examiné, la dernière compte). « Uzupełnione » n'est acceptée que si le
     * signal a disparu (code CN, masse, kurs saisis ici ; fiche client, facture corrigées dans l'ERP) ; une déclaration est
     * « Zgłoszone w PUESC » (numéro de référence) seulement quand plus rien n'attend dans ses lignes.
     */
    public function saveDecision(array $in, ?int $userId, ?string $today = null): array
    {
        $today ??= date('Y-m-d');
        $key = (string) ($in['key'] ?? '');
        $decision = (string) ($in['decision'] ?? '');
        $note = trim((string) ($in['note'] ?? ''));
        if (!isset(self::DECISIONS[$decision])) throw new CustomException('Wybierz decyzję.', 'errors', 422);
        if (mb_strlen($note) > 500) throw new CustomException('Notatka : najwyżej 500 znaków.', 'errors', 422);
        $d = $this->data($today, true);
        $it = array_values(array_filter($d['review'], fn($x) => $x['key'] === $key))[0] ?? null;
        $seen = array_values(array_intersect(array_keys(self::SIGNALS), array_map('strval', (array) ($in['seen'] ?? []))));
        if ($it === null && $decision === 'completed' && $seen !== [] && isset($d['all'][$key]) && $d['all'][$key]['signals'] === []) $it = $d['all'][$key];
        if ($it === null) throw new CustomException('Tego elementu nie ma na liście miesiąca ' . $d['month_label'] . ' (bez sygnału).', 'errors', 422);
        if (!in_array($decision, self::KIND_DECISIONS[$it['kind']], true)) {
            throw new CustomException('Dla « ' . self::KINDS[$it['kind']] . ' » wybierz : ' . implode(', ', array_map(fn($k) => self::DECISIONS[$k], self::KIND_DECISIONS[$it['kind']])) . '.', 'errors', 422);
        }
        if ($decision === 'declared' && mb_strlen($note) < 3) throw new CustomException('Zgłoszone w PUESC : wpisz numer referencyjny zgłoszenia.', 'errors', 422);
        if ($decision === 'below_threshold' && mb_strlen($note) < 3) throw new CustomException('Poniżej progu : wpisz uzasadnienie (np. suma roku i próg z przepisów).', 'errors', 422);
        if ($decision === 'not_applicable' && mb_strlen($note) < 3) throw new CustomException('Nie dotyczy : wpisz dlaczego.', 'errors', 422);
        if ($decision === 'declared' && $it['blocked'] > 0) {
            throw new CustomException('Najpierw uzupełnij ' . $it['blocked'] . ' ' . self::pl($it['blocked'], 'element', 'elementy', 'elementów') . ' tej deklaracji (kod CN, masa, kurs, VAT odbiorcy).', 'errors', 422);
        }
        if ($decision === 'completed' && $it['codes'] !== []) {
            throw new CustomException('Nadal : ' . implode(', ', array_map(fn($c) => self::SIGNALS[$c], $it['codes'])) . '. Uzupełnij i zapisz ponownie.', 'errors', 422);
        }
        $flags = $it['kind'] === 'declaration' ? array_column($it['signals'], 'code') : ($it['codes'] ?: ($seen ?: ($it['decision']['flags'] ?? [])));
        $this->repo->saveDecision($d['month_start'], $key, $it['kind'], $decision, $note !== '' ? $note : null, implode(',', $flags), $userId);
        return $this->data($today);
    }

    /**
     * Krok 2 : code CN (8 chiffres) d'un produit ou d'un surowiec du mois, et masse netto (kg) d'une unité de base pour un
     * surowiec qui n'est pas en g / kg. Tables de l'assistant seulement. Si l'élément n'a plus de signal, « Uzupełnione » est
     * enregistrée avec les signaux qu'il avait.
     */
    public function saveCode(array $in, ?int $userId, ?string $today = null): array
    {
        $today ??= date('Y-m-d');
        $key = (string) ($in['key'] ?? '');
        $d = $this->data($today, true);
        $it = $d['all'][$key] ?? null;
        if ($it === null || !in_array($it['kind'], ['product', 'material'], true) || (int) $it['item_id'] <= 0) {
            throw new CustomException('Kod CN wpisuje się dla produktu albo surowca z dokumentów miesiąca ' . $d['month_label'] . '.', 'errors', 422);
        }
        $cn = self::normalizeCn((string) ($in['cn_code'] ?? ''));
        if ($cn === null) throw new CustomException('Kod CN : dokładnie 8 cyfr (np. 19059060).', 'errors', 422);
        $mass = $it['unit_mass_kg'];
        if ($it['kind'] === 'material' && array_key_exists('unit_mass_kg', $in)) {
            $raw = str_replace(',', '.', trim((string) $in['unit_mass_kg']));
            if ($raw === '') $mass = null;
            elseif (!is_numeric($raw) || (float) $raw <= 0 || (float) $raw > 100000) throw new CustomException('Masa jednostki : liczba kg większa od 0 (np. 0,001 dla 1 ml wody).', 'errors', 422);
            else $mass = round((float) $raw, 6);
        }
        $before = array_column($it['signals'], 'code');
        $this->repo->saveCode($it['kind'], (int) $it['item_id'], $cn !== '' ? $cn : null, $it['kind'] === 'material' ? $mass : null, $userId);
        return $this->autoComplete($key, $before, $userId, $today);
    }

    /** Krok 2 : kurs (PLN za 1 jednostkę) d'une devise du mois examiné ; « Uzupełnione » enregistrée pour ce kurs. */
    public function saveRate(array $in, ?int $userId, ?string $today = null): array
    {
        $today ??= date('Y-m-d');
        $cur = strtoupper(trim((string) ($in['currency'] ?? '')));
        $raw = str_replace(',', '.', trim((string) ($in['rate'] ?? '')));
        $d = $this->data($today, true);
        if (!in_array($cur, array_column($d['currencies'], 'currency'), true)) throw new CustomException('Waluta « ' . $cur . ' » nie występuje w dokumentach miesiąca ' . $d['month_label'] . '.', 'errors', 422);
        if (!is_numeric($raw) || (float) $raw <= 0 || (float) $raw > 100000) throw new CustomException('Kurs : liczba PLN za 1 ' . $cur . ' większa od 0 (np. 4,2563).', 'errors', 422);
        $before = array_column($d['all']['rate:' . $cur]['signals'] ?? [], 'code');
        $this->repo->saveRate($d['month_start'], $cur, round((float) $raw, 6), $userId);
        return $this->autoComplete('rate:' . $cur, $before, $userId, $today);
    }

    /** Après une saisie : si l'élément signalé n'a plus de signal, « Uzupełnione » avec les signaux qu'il avait. */
    private function autoComplete(string $key, array $before, ?int $userId, string $today): array
    {
        $d = $this->data($today, true);
        $it = $d['all'][$key] ?? null;
        if ($it !== null && $before !== [] && $it['signals'] === []) {
            $this->repo->saveDecision($d['month_start'], $key, $it['kind'], 'completed', null, implode(',', $before), $userId);
            return $this->data($today);
        }
        unset($d['all']);
        return $d;
    }

    /** Krok 3 : « Zakończ » — chaque élément a sa décision ; la tâche du mois se valide seule. */
    public function finish(?int $userId, ?string $today = null): array
    {
        $today ??= date('Y-m-d');
        $d = $this->data($today);
        $w = $d['totals']['waiting'];
        if ($w > 0) throw new CustomException('Najpierw zapisz decyzję dla ' . $w . ' ' . self::pl($w, 'elementu', 'elementów', 'elementów') . ' z kroku 2.', 'errors', 422);
        $this->wizards->markDone(self::CODE, $today, $d['summary'], $userId);
        return ['summary' => $d['summary'], 'data' => $this->data($today)];
    }

    /** « październik 2026 : wywóz 1 deklaracja (12 345,67 zł, 8 dok.) · przywóz 1 deklaracja (2 100,00 zł, 2 dok.) · 5 sygnałów · decyzje : 3 uzupełnione · 1 zgłoszone w PUESC · 1 poniżej progu » */
    public static function summary(string $month, array $t): string
    {
        $parts = [];
        foreach (self::DIRECTIONS as $dir => $l) {
            $x = $t[$dir];
            if ($x['declarations'] === 0) continue;
            $parts[] = mb_strtolower($l) . ' ' . $x['declarations'] . ' ' . self::pl($x['declarations'], 'deklaracja', 'deklaracje', 'deklaracji')
                . ' (' . self::money($x['value_pln']) . ' zł' . ($x['value_complete'] ? '' : ' bez kursu części') . ', ' . $x['docs'] . ' dok.)';
        }
        $s = $month . ' : ' . ($parts ? implode(' · ', $parts) : 'brak wywozu i przywozu UE w ERP');
        if ($t['signals']) $s .= ' · ' . $t['signals'] . ' ' . self::pl($t['signals'], 'sygnał', 'sygnały', 'sygnałów');
        $dec = [];
        if ($t['completed']) $dec[] = $t['completed'] . ' uzupełnione';
        if ($t['not_applicable']) $dec[] = $t['not_applicable'] . ' nie dotyczy';
        if ($t['declared']) $dec[] = $t['declared'] . ' zgłoszone w PUESC';
        if ($t['below_threshold']) $dec[] = $t['below_threshold'] . ' poniżej progu';
        return $dec ? $s . ' · decyzje : ' . implode(' · ', $dec) : $s;
    }

    private static function nipKey(string $nip): string
    {
        return preg_replace('/[^A-Z0-9]/', '', strtoupper($nip)) ?? '';
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

    private static function money(float $v): string
    {
        return number_format($v, 2, ',', "\u{00A0}");
    }

    private static function pl(int $n, string $one, string $few, string $many): string
    {
        return $n === 1 ? $one : (in_array($n % 10, [2, 3, 4], true) && !in_array($n % 100, [12, 13, 14], true) ? $few : $many);
    }
}
