<?php
declare(strict_types=1);

namespace App\Services\Organization\Wizards;

use App\Domain\Params\ParamRules;
use App\Exceptions\CustomException;
use App\Repositories\Client\CustomersDataCheckRepository;
use App\Repositories\Organization\LightWizardRepository;
use App\Repositories\Workshop\ParamRepository;
use App\Services\Client\ClientService;
use App\Services\Logistics\Sms\SmsGateway;
use App\Services\Organization\TaskWizards;
use App\Services\Utils\DocumentLabels;

/**
 * Asystent « Dane klientów » [385] (Administracja — co miesiąc ; obligation ADMINISTRACJA 4 « Dane klientów i cenniki »
 * de la matrice 224). Contrôle du mois en cours (clé = premier jour du mois du contrôle).
 *
 * Client actif = au moins une commande non archivée dans les customers_data.active_months derniers mois
 * (customer_order.create_order_date). Choix : ce sont les commandes qui produisent les documents et les envois (proforma,
 * WZ, CMR, WDT et KSeF, avis de livraison, SMS de commande, rappels de paiement) — une fiche incomplète d'un client qui ne
 * commande plus ne casse rien, alors que customer.active = 1 seul garde aussi les clients dormants (liste par défaut de
 * Klienci [325]) ; un client désactivé qui commande encore reste contrôlé (signalé « nieaktywny »).
 *
 *   1. Przegląd — chaque client actif, problème par problème, avec ce qu'il casse dans l'ERP (PROBLEMS) :
 *        - e-mail (email_head, email_salesman, email_accountant — ClientService::EMAIL_FIELDS) : aucun adresse ; ni e-mail
 *          principal ni commercial (l'avis de livraison prend email_head sinon email_salesman — DeliveryNoticeService ; les
 *          proformas, factures et rappels email_accountant sinon email_head sinon email_salesman — ProformaEmailContextService,
 *          PaymentReminderContextService) ; adresse invalide (ClientService::invalidEmails, la validation de la fiche, sans
 *          connexion réseau) ; dernier e-mail (communication_message, statut définitif) envoyé à une adresse encore sur la
 *          fiche en erreur, sans envoi réussi plus récent à la même adresse ;
 *        - téléphone (phone_head, phone_alt) : vides, ou illisibles pour la passerelle (SmsGateway::e164, la règle de
 *          SmsGateway::send : « Nieczytelny numer telefonu ») — SMS de commande, avis SMS, rappels SMS ;
 *        - langue (communication_lang) hors de DocumentLabels::LANGUAGES : textes en langue par défaut (fr) ;
 *        - délai de paiement (payment_termin) vide : Płynność i gotówka prend liquidity.client_terms_days, facture sans terme ;
 *        - NIP / VAT (tax_number → consignee_nip des documents, ShippingService) vide ; client livré en WDT (document_wdt de la
 *          période, ou pays de la fiche hors Pologne) dont le numéro n'est pas un VAT UE à préfixe pays autre que PL — même
 *          règle que KsefWdtInvoiceXmlMapper::splitEuVat ;
 *        - adresse (fac_street, fac_street_num, fac_zip, fac_town, id_country — champs exigés par ClientService::validate,
 *          consignee_address_1 / 2 des WZ, CMR, WDT ; adresse du chauffeur sur la tablette) ;
 *        - cennik (final_product_customer_price) : aucune ligne, ou produits commandés sur la période sans prix > 0 — le WDT
 *          les prend au prix standard (WDTProductRepository::selectForStandardShipping : IFNULL(fpcp.price, standard_price)) ;
 *          un client intégré (franchisee_uid) n'est pas contrôlé : son cennik vient de la boutique par le réseau
 *          (ExternalPriceListService), que l'assistant n'appelle pas.
 *      Chaque contrôle vérifie que sa table et ses colonnes existent (information_schema) ; sinon « brak danych », pas de signal.
 *   2. Do wyjaśnienia — une décision par client signalé et par mois (n_customers_data_check) : « Poprawione » (acceptée
 *      seulement si la fiche n'a plus AUCUN problème au moment de l'enregistrement), « Brak danych od klienta » (note
 *      obligatoire ; le client revient chaque mois), « Nie dotyczy » (note obligatoire ; reprise les mois suivants tant que
 *      les problèmes sont les mêmes). Une décision dont les codes ne couvrent plus les problèmes actuels (nouveau problème, ou
 *      problème revenu après « Poprawione ») ne compte plus : le client attend de nouveau.
 *   3. Zatwierdź — récapitulatif (clients complets / avec manques, évolution vs le mois précédent lue dans le résumé de son
 *      n_wizard_done), « Zakończ » écrit n_wizard_done — bloqué tant qu'un client signalé attend.
 * Données clients : seulement ce que la fiche client affiche (nom, ville, adresses e-mail et téléphones fautifs) ; rien
 * n'est copié dans n_customers_data_check (id du client, codes des problèmes, décision, note). La fiche n'est JAMAIS
 * modifiée ici (liens vers /clients/{id}/edit et /clients/{id}/price-list), aucun e-mail ni SMS envoyé.
 */
final class CustomersDataCheckService
{
    public const CODE = 'customers_data_checked';
    public const PATH = '/clients/data-check';
    public const DECISIONS = ['fixed' => 'Poprawione', 'no_data' => 'Brak danych od klienta', 'not_applicable' => 'Nie dotyczy'];
    /** Décisions qui demandent une note (au moins 3 caractères). */
    public const NOTE_REQUIRED = ['no_data', 'not_applicable'];
    /** code => [libellé, ce que cela casse dans l'ERP, champ de la fiche (ancre), écran où corriger : card | price_list] */
    public const PROBLEMS = [
        'email_missing' => ['brak e-maila', 'powiadomienia o dostawie, proformy, faktury i przypomnienia płatności nie wychodzą e-mailem', 'email_head', 'card'],
        'email_notice_missing' => ['brak e-maila głównego i handlowca', 'powiadomienie o dostawie bez e-maila (idzie na e-mail główny, inaczej handlowca) ; proformy i faktury idą tylko na e-mail księgowości', 'email_head', 'card'],
        'email_invalid' => ['nieprawidłowy e-mail', 'wysyłka na błędny adres się nie uda ; karty nie da się zapisać, dopóki adres jest błędny', 'email_head', 'card'],
        'email_failed' => ['e-mail nie doszedł', 'kolejne proformy, powiadomienia i przypomnienia na ten adres mogą nie dojść — sprawdź adres z klientem', 'email_head', 'card'],
        'phone_missing' => ['brak telefonu', 'SMS o zamówieniu, SMS z powiadomieniem o dostawie i przypomnienia płatności SMS są niemożliwe', 'phone_head', 'card'],
        'phone_invalid' => ['nieczytelny telefon', 'bramka SMS odrzuci numer (« Nieczytelny numer telefonu ») : SMS o zamówieniu, powiadomienia i przypomnienia nie wyjdą', 'phone_head', 'card'],
        'lang_missing' => ['brak języka komunikacji', 'dokumenty, e-maile i SMS w języku domyślnym (francuski), nie w języku klienta', 'communication_lang', 'card'],
        'payment_missing' => ['brak terminu płatności', 'Płynność i gotówka liczy termin domyślny z Ustawień, faktura bez terminu płatności', 'payment_termin', 'card'],
        'tax_missing' => ['brak NIP / VAT', 'proforma, faktura, WZ, CMR i WDT bez NIP odbiorcy ; KSeF odrzuci WDT (« WDT nie zawiera wymaganego pola: consignee_nip »)', 'tax_number', 'card'],
        'vat_eu_invalid' => ['VAT UE bez prefiksu kraju', 'KSeF odrzuci fakturę WDT (« WDT musi zawierać VAT UE nabywcy z prefiksem kraju, np. BE0123456789 »)', 'tax_number', 'card'],
        'address_missing' => ['niepełny adres', 'WZ, CMR i WDT z niepełnym adresem odbiorcy, kierowca bez adresu na tablecie', 'fac_street', 'card'],
        'price_list_missing' => ['brak cennika klienta', 'zamówienia i WDT po cenie standardowej, nie po cenie klienta', null, 'price_list'],
        'price_missing' => ['produkt zamówiony bez ceny klienta', 'te pozycje na WDT po cenie standardowej, nie po cenie klienta', null, 'price_list'],
    ];
    /** Contrôle => colonnes de customer (ou table) nécessaires ; une absente = « brak danych » pour ce contrôle. */
    private const CHECK_COLUMNS = ['email' => ['email_head', 'email_salesman', 'email_accountant'], 'phone' => ['phone_head', 'phone_alt'], 'lang' => ['communication_lang'],
        'payment' => ['payment_termin'], 'tax' => ['tax_number'], 'address' => ['fac_street', 'fac_street_num', 'fac_zip', 'fac_town', 'id_country']];
    public const CHECK_LABELS = ['email' => 'e-mail', 'phone' => 'telefon', 'lang' => 'język komunikacji', 'payment' => 'termin płatności', 'tax' => 'NIP / VAT',
        'address' => 'adres', 'price' => 'cennik klienta', 'mail_failed' => 'e-maile z błędem', 'wdt' => 'klienci WDT'];
    /** Écrans existants : fiche client (Klienci › Edytuj) et cennik du client — droit « clients ». */
    public const CARD_LINK = '/clients/%d/edit';
    public const PRICE_LINK = '/clients/%d/price-list';
    private const ADDRESS_PARTS = ['fac_street' => 'ulica', 'fac_street_num' => 'numer', 'fac_zip' => 'kod pocztowy', 'fac_town' => 'miejscowość', 'id_country' => 'kraj'];
    private const MAIL_TYPES = ['proforma' => 'proforma', 'payment_reminder' => 'przypomnienie płatności', 'payment_reminder_final' => 'przypomnienie płatności',
        'delivery_details' => 'powiadomienie o dostawie', 'accounting_document' => 'dokument', 'price_change_notice' => 'nowe ceny'];
    /** clé => [code n_prod_param, défaut] */
    private const PARAMS = ['months' => ['customers_data.active_months', 3]];
    private const DAY_ABBR = [1 => 'pon.', 2 => 'wt.', 3 => 'śr.', 4 => 'czw.', 5 => 'pt.', 6 => 'sob.', 7 => 'niedz.'];

    public function __construct(private CustomersDataCheckRepository $repo, private LightWizardRepository $wizards, private ParamRepository $params, private SmsGateway $sms)
    {
    }

    /** Réglage customers_data.* (n_prod_param, borné par ParamRules ; valeur invalide → défaut). */
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
     * Exactement la règle de KsefWdtInvoiceXmlMapper::splitEuVat : caractères hors [A-Z0-9] retirés AVANT la mise en
     * majuscules (une minuscule est donc perdue, comme dans le mapper), préfixe de 2 lettres autre que PL, puis 2 à 14 caractères.
     */
    public static function euVat(string $vat): bool
    {
        $vat = strtoupper(preg_replace('/[^A-Z0-9]/', '', $vat) ?? '');
        return preg_match('/^([A-Z]{2})([A-Z0-9]{2,14})$/', $vat, $m) === 1 && $m[1] !== 'PL';
    }

    /** Données des trois étapes. */
    public function data(?string $today = null): array
    {
        $today ??= date('Y-m-d');
        $month = self::reviewMonth($today);
        $months = $this->param('months');
        $since = date('Y-m-d', strtotime($today . ' -' . $months . ' months'));

        $active = $this->repo->activeCustomers($since . ' 00:00:00');
        $ids = array_values(array_unique(array_merge(array_keys($active), $this->repo->decidedIn($month))));
        $cards = $this->repo->cards($ids);
        $cols = $this->repo->columns('customer');
        $checks = [];
        foreach (self::CHECK_COLUMNS as $k => $need) $checks[$k] = array_values(array_diff($need, $cols));
        $countries = $this->repo->countries();
        $wdt = $this->repo->wdtCustomers($since);
        $priceRows = $this->repo->priceListRows($ids);
        $noPrice = $priceRows === null ? [] : $this->repo->orderedWithoutPrice($ids, $since . ' 00:00:00');
        $mails = $this->repo->mails($ids, $since . ' 00:00:00');
        $decisions = $this->repo->decisions($ids);
        $ctx = ['checks' => $checks, 'countries' => $countries, 'wdt' => array_flip($wdt ?? []), 'price_rows' => $priceRows, 'no_price' => $noPrice, 'mails' => $mails,
            'integrated_col' => in_array('franchisee_uid', $cols, true)];

        $items = [];
        foreach ($ids as $id) {
            if (!isset($cards[$id])) continue;
            $items[] = $this->item($cards[$id], $active[$id] ?? null, $ctx, $decisions[$id] ?? [], $month);
        }
        usort($items, fn($a, $b) => [$b['waiting'], $b['in_review'], count($b['problems']), $a['name']] <=> [$a['waiting'], $a['in_review'], count($a['problems']), $b['name']]);
        $review = array_values(array_filter($items, fn($i) => $i['in_review']));
        $activeItems = array_values(array_filter($items, fn($i) => $i['is_active']));

        $eff = fn(array $i) => $i['decision'] ?? $i['earlier'];
        $decided = fn(string $k) => count(array_filter($review, fn($i) => !$i['waiting'] && ($d = $eff($i)) !== null && $d['code'] === $k));
        $incomplete = count(array_filter($activeItems, fn($i) => $i['problems'] !== []));
        $byCode = [];
        foreach (array_keys(self::PROBLEMS) as $c) $byCode[$c] = count(array_filter($activeItems, fn($i) => in_array($c, $i['codes'], true)));
        $totals = ['active' => count($activeItems), 'complete' => count($activeItems) - $incomplete, 'incomplete' => $incomplete,
            'inactive_flag' => count(array_filter($activeItems, fn($i) => !$i['card_active'])), 'integrated' => count(array_filter($activeItems, fn($i) => $i['integrated'])),
            'review' => count($review), 'waiting' => count(array_filter($review, fn($i) => $i['waiting'])), 'resolved' => count(array_filter($review, fn($i) => !$i['waiting'])),
            'earlier' => count(array_filter($review, fn($i) => !$i['waiting'] && $i['decision'] === null && $i['earlier'] !== null)),
            'fixed' => $decided('fixed'), 'no_data' => $decided('no_data'), 'not_applicable' => $decided('not_applicable'), 'by_code' => $byCode];
        $label = TaskWizards::monthLabel(substr($month, 0, 7));
        $prevMonth = date('Y-m-01', strtotime($month . ' -1 month'));
        $prevDone = $this->wizards->done(self::CODE, $prevMonth, $month);
        $previous = $prevDone ? self::parseSummary((string) $prevDone['summary']) + ['month_label' => TaskWizards::monthLabel(substr($prevMonth, 0, 7)), 'summary' => $prevDone['summary']] : null;
        if ($previous !== null && $previous['incomplete'] !== null) $previous['delta'] = $incomplete - $previous['incomplete'];
        $done = $this->wizards->done(self::CODE, $month, date('Y-m-01', strtotime($month . ' +1 month')));

        return ['today' => $today, 'label' => self::label($today), 'month_start' => $month, 'month_label' => $label, 'since' => $since, 'since_label' => self::label($since) . '.' . substr($since, 0, 4),
            'params' => ['months' => $months], 'sources' => $this->sources($active, $checks, $wdt, $priceRows, $mails, $ctx['integrated_col']),
            'items' => $items, 'review' => $review, 'totals' => $totals, 'can_finish' => $totals['waiting'] === 0, 'previous' => $previous,
            'summary' => self::summary($label, $months, $totals), 'decisions' => self::DECISIONS, 'note_required' => self::NOTE_REQUIRED,
            'problem_labels' => array_map(fn($p) => $p[0], self::PROBLEMS),
            'done' => $done ? ['summary' => $done['summary'], 'at' => self::label((string) $done['day']) . ', ' . self::hm((string) $done['done_at']), 'by' => $done['by_login'] ?? null] : null];
    }

    /** Un client : problèmes de la fiche, décisions, état. */
    private function item(array $c, ?array $orders, array $ctx, array $decisions, string $month): array
    {
        $id = (int) $c['id'];
        $integrated = $ctx['integrated_col'] && trim((string) ($c['franchisee_uid'] ?? '')) !== '';
        $problems = $this->problems($c, $ctx, $integrated);
        $codes = array_column($problems, 'code');
        $mine = null;
        $earlier = null;
        foreach ($decisions as $d) {
            if (substr((string) $d['month_start'], 0, 10) === $month) { $mine ??= $d; continue; }
            $earlier ??= $d;
        }
        $covers = fn(?array $d) => $d !== null && array_diff($codes, array_filter(explode(',', (string) $d['flags']))) === [];
        $covered = $problems !== [] && (($mine !== null && $mine['decision'] !== 'fixed' && $covers($mine))
            || ($mine === null && $earlier !== null && $earlier['decision'] === 'not_applicable' && $covers($earlier)));
        $waiting = $problems !== [] && !$covered && $orders !== null;
        $name = trim((string) ($c['company'] ?? '')) ?: trim(trim((string) ($c['name'] ?? '')) . ' ' . trim((string) ($c['surname'] ?? ''))) ?: ('#' . $id);
        $reason = null;
        if ($waiting && $mine !== null) $reason = $mine['decision'] === 'fixed' ? 'problem wrócił po « Poprawione »' : 'nowy problem po decyzji';
        elseif ($waiting && $earlier !== null && $earlier['decision'] === 'not_applicable') $reason = 'inne problemy niż przy « Nie dotyczy »';
        return ['key' => 'c:' . $id, 'id_customer' => $id, 'name' => $name, 'town' => trim((string) ($c['fac_town'] ?? '')) ?: null,
            'card_active' => !array_key_exists('active', $c) || (int) $c['active'] === 1, 'integrated' => $integrated,
            'is_active' => $orders !== null, 'orders' => $orders['orders'] ?? 0, 'last_order' => $orders['last_order'] ?? null,
            'last_order_label' => isset($orders['last_order']) ? self::label($orders['last_order']) : null,
            'problems' => $problems, 'codes' => $codes, 'in_review' => ($problems !== [] && $orders !== null) || $mine !== null, 'waiting' => $waiting, 'reason' => $reason,
            'decision' => $mine ? $this->presentDecision($mine) : null,
            'earlier' => $mine === null && $earlier !== null && $earlier['decision'] === 'not_applicable' ? $this->presentDecision($earlier) : null,
            'link' => ['href' => sprintf(self::CARD_LINK, $id), 'label' => 'Karta klienta']];
    }

    /**
     * Problèmes de la fiche d'un client (dans l'ordre de PROBLEMS). Chaque entrée : code, libellé, détail (seulement ce
     * que la fiche affiche), ce que cela casse, lien vers l'écran où corriger.
     * @return list<array{code:string, label:string, detail:string, breaks:string, link:array}>
     */
    private function problems(array $c, array $ctx, bool $integrated): array
    {
        $id = (int) $c['id'];
        $v = fn(string $k) => trim((string) ($c[$k] ?? ''));
        $ok = fn(string $check) => $ctx['checks'][$check] === [];
        $out = [];
        $add = function (string $code, string $detail) use (&$out, $id) {
            [$label, $breaks, $field, $screen] = self::PROBLEMS[$code];
            $link = $screen === 'price_list' ? ['href' => sprintf(self::PRICE_LINK, $id), 'label' => 'Cennik klienta']
                : ['href' => sprintf(self::CARD_LINK, $id) . ($field ? '#' . $field : ''), 'label' => 'Karta klienta'];
            $out[$code] = ['code' => $code, 'label' => $label, 'detail' => $detail, 'breaks' => $breaks, 'link' => $link];
        };

        if ($ok('email')) {
            $notice = $v('email_head') ?: $v('email_salesman');
            $docs = $v('email_accountant') ?: $notice;
            if ($docs === '') $add('email_missing', 'puste pola : e-mail właściciela, handlowca i księgowości');
            elseif ($notice === '') $add('email_notice_missing', 'jest tylko e-mail księgowości');
            $bad = [];
            foreach (ClientService::EMAIL_FIELDS as $f => $l) if ($b = ClientService::invalidEmails($v($f))) $bad[] = $l . ' : ' . implode(', ', $b);
            if ($bad) $add('email_invalid', implode(' · ', $bad));
            if (is_array($ctx['mails']) && ($f = self::failedMail($ctx['mails'][$id] ?? [], $c)) !== null) {
                $add('email_failed', 'ostatni e-mail na ' . $f['to'] . ' (' . (self::MAIL_TYPES[$f['type']] ?? $f['type']) . ', ' . self::label(substr($f['at'], 0, 10)) . '.' . substr($f['at'], 0, 4) . ') z błędem, bez późniejszej udanej wysyłki');
            }
        }
        if ($ok('phone')) {
            $phones = array_values(array_filter([$v('phone_head'), $v('phone_alt')], fn($p) => $p !== ''));
            if (!$phones) $add('phone_missing', 'puste pola : telefon główny i dodatkowy');
            elseif ($this->sms->e164(implode(' ; ', $phones)) === null) $add('phone_invalid', 'numer « ' . implode(' ; ', $phones) . ' » nieczytelny dla bramki SMS');
        }
        if ($ok('lang') && !in_array($v('communication_lang'), DocumentLabels::LANGUAGES, true)) {
            $add('lang_missing', $v('communication_lang') === '' ? 'pole puste' : 'nieznany kod « ' . $v('communication_lang') . ' »');
        }
        if ($ok('payment') && $v('payment_termin') === '') $add('payment_missing', 'pole « Termin płatności » puste');
        if ($ok('tax')) {
            $tax = $v('tax_number');
            $country = $ctx['countries'][(int) ($c['id_country'] ?? 0)] ?? '';
            $abroad = $country !== '' && preg_match('/^(polska|poland|pologne|polen|pl)$/iu', trim($country)) !== 1;
            $wdtClient = isset($ctx['wdt'][$id]) || $abroad;
            if ($tax === '') $add('tax_missing', 'pole « NIP / VAT » puste');
            elseif ($wdtClient && !self::euVat($tax)) {
                $add('vat_eu_invalid', '« ' . $tax . ' » — ' . (isset($ctx['wdt'][$id]) ? 'klient z WDT w okresie' : 'kraj : ' . $country) . ', wymagany VAT UE z prefiksem kraju (nie PL)');
            }
        }
        if ($ok('address')) {
            $missing = [];
            foreach (self::ADDRESS_PARTS as $f => $l) if ($f === 'id_country' ? (int) ($c[$f] ?? 0) <= 0 : $v($f) === '') $missing[] = $l;
            if ($missing) $add('address_missing', 'brak : ' . implode(', ', $missing));
        }
        if (is_array($ctx['price_rows']) && !$integrated) {
            $rows = $ctx['price_rows'][$id] ?? 0;
            $list = $ctx['no_price'][$id] ?? [];
            if ($rows === 0) $add('price_list_missing', 'brak pozycji w cenniku klienta');
            elseif ($list) {
                $n = count($list);
                $names = array_column(array_slice($list, 0, 3), 'name');
                $add('price_missing', $n . ' ' . self::pl($n, 'produkt zamówiony', 'produkty zamówione', 'produktów zamówionych') . ' bez ceny : ' . implode(', ', $names) . ($n > 3 ? ' (+' . ($n - 3) . ')' : ''));
            }
        }
        return array_values($out);
    }

    /**
     * Dernier e-mail en erreur envoyé à une adresse encore sur la fiche, sans envoi réussi plus récent aux mêmes adresses.
     * @param list<array{id:int, type:string, to:string, status:string, at:string}> $mails du plus ancien au plus récent
     */
    public static function failedMail(array $mails, array $card): ?array
    {
        $onCard = [];
        foreach (array_keys(ClientService::EMAIL_FIELDS) as $f) {
            foreach (preg_split('/[\s,;]+/', strtolower(trim((string) ($card[$f] ?? ''))), -1, PREG_SPLIT_NO_EMPTY) as $a) $onCard[$a] = true;
        }
        $last = [];
        foreach ($mails as $m) {
            $to = preg_split('/[\s,;]+/', strtolower(trim($m['to'])), -1, PREG_SPLIT_NO_EMPTY);
            if (!$to) continue;
            sort($to);
            $last[implode(',', $to)] = $m + ['addresses' => $to];
        }
        $found = null;
        foreach ($last as $m) {
            if ($m['status'] !== 'failed' || array_diff($m['addresses'], array_keys($onCard)) !== []) continue;
            if ($found === null || [$m['at'], $m['id']] > [$found['at'], $found['id']]) $found = $m;
        }
        return $found === null ? null : ['to' => implode(', ', $found['addresses']), 'type' => $found['type'], 'at' => $found['at']];
    }

    /** État des sources : commandes, colonnes de la fiche, WDT, cennik, e-mails. */
    private function sources(array $active, array $checks, ?array $wdt, ?array $priceRows, ?array $mails, bool $integratedCol): array
    {
        $orders = $this->repo->hasTable('customer_order');
        $cards = [];
        foreach ($checks as $k => $missing) {
            $cards[$k] = ['label' => self::CHECK_LABELS[$k], 'available' => $missing === [],
                'note' => $missing === [] ? null : 'brak danych — brak kolumny customer.' . implode(', customer.', $missing) . ' : kontrola pominięta'];
        }
        return [
            'orders' => ['available' => $orders, 'from' => 'customer_order', 'note' => !$orders ? 'brak danych — brak tabeli zamówień : nie wiadomo, którzy klienci są aktywni'
                : ($active === [] ? 'brak zamówień w okresie : brak aktywnych klientów' : null)],
            'card' => $cards,
            'wdt' => ['available' => $wdt !== null, 'from' => 'document_wdt', 'note' => $wdt === null ? 'brak danych — brak tabeli WDT : VAT UE sprawdzany tylko u klientów z krajem innym niż Polska' : null],
            'price' => ['available' => $priceRows !== null, 'from' => 'final_product_customer_price', 'note' => $priceRows === null ? 'brak danych — brak tabeli cenników klientów : kontrola pominięta' : null,
                'integrated_note' => $integratedCol ? 'klienci zintegrowani (sklep z własnym cennikiem) : cennik nie jest sprawdzany — przychodzi ze sklepu przez sieć' : null],
            'mails' => ['available' => $mails !== null, 'from' => 'communication_message', 'note' => $mails === null ? 'brak danych — brak tabeli wysłanych e-maili : błędy wysyłki nie są sprawdzane' : null],
        ];
    }

    private function presentDecision(array $d): array
    {
        $flags = array_values(array_filter(explode(',', (string) $d['flags'])));
        return ['code' => (string) $d['decision'], 'label' => self::DECISIONS[$d['decision']] ?? (string) $d['decision'], 'note' => $d['note'], 'flags' => $flags,
            'flag_labels' => array_map(fn($f) => self::PROBLEMS[$f][0] ?? $f, $flags),
            'month' => substr((string) $d['month_start'], 0, 7), 'month_label' => TaskWizards::monthLabel(substr((string) $d['month_start'], 0, 7)),
            'by' => $d['decided_by'], 'at' => self::label(substr((string) $d['updated_at'], 0, 10)) . ', ' . self::hm((string) $d['updated_at'])];
    }

    /**
     * Krok 2 : décision pour un client (une par mois du contrôle, la dernière compte). « Poprawione » n'est acceptée que si
     * la fiche n'a plus aucun problème maintenant ; les codes enregistrés sont alors ceux que l'écran avait signalés
     * (« seen », filtrés sur PROBLEMS). La fiche client n'est jamais écrite.
     */
    public function saveDecision(array $in, ?int $userId, ?string $today = null): array
    {
        $today ??= date('Y-m-d');
        $customerId = (int) ($in['id_customer'] ?? 0);
        $decision = (string) ($in['decision'] ?? '');
        $note = trim((string) ($in['note'] ?? ''));
        if (!isset(self::DECISIONS[$decision])) throw new CustomException('Wybierz decyzję : poprawione, brak danych od klienta albo nie dotyczy.', 'errors', 422);
        if (mb_strlen($note) > 500) throw new CustomException('Notatka : najwyżej 500 znaków.', 'errors', 422);
        if ($decision === 'no_data' && mb_strlen($note) < 3) throw new CustomException('Brak danych od klienta : wpisz, o co i kiedy prosiliście klienta.', 'errors', 422);
        if ($decision === 'not_applicable' && mb_strlen($note) < 3) throw new CustomException('Nie dotyczy : wpisz dlaczego (np. klient bez dostaw WDT, odbiór własny).', 'errors', 422);
        $d = $this->data($today);
        $it = array_values(array_filter($d['items'], fn($x) => $x['id_customer'] === $customerId))[0] ?? null;
        if ($it === null || !$it['is_active']) throw new CustomException('Tego klienta nie ma wśród aktywnych klientów (zamówienie w ostatnich ' . $d['params']['months'] . ' mies.).', 'errors', 422);
        if ($decision === 'fixed') {
            if ($it['problems']) throw new CustomException('Karta klienta nadal ma : ' . implode(', ', array_column($it['problems'], 'label')) . '. Popraw kartę klienta i zapisz ponownie.', 'errors', 422);
            $seen = array_values(array_intersect(array_keys(self::PROBLEMS), array_map('strval', (array) ($in['seen'] ?? []))));
            if (!$seen && $it['decision'] !== null) $seen = $it['decision']['flags'];
            if (!$seen) throw new CustomException('Ten klient nie miał problemu do poprawienia w tym miesiącu.', 'errors', 422);
            $flags = $seen;
        } else {
            if (!$it['problems']) throw new CustomException('Karta klienta jest już kompletna — wybierz « Poprawione ».', 'errors', 422);
            $flags = $it['codes'];
        }
        $this->repo->saveDecision($d['month_start'], $customerId, $decision, $note !== '' ? $note : null, implode(',', $flags), $userId);
        return $this->data($today);
    }

    /** Krok 3 : « Zakończ » — chaque client signalé a sa décision ; la tâche du mois se valide seule. */
    public function finish(?int $userId, ?string $today = null): array
    {
        $today ??= date('Y-m-d');
        $d = $this->data($today);
        $w = $d['totals']['waiting'];
        if ($w > 0) throw new CustomException('Najpierw zapisz decyzję dla ' . $w . ' ' . self::pl($w, 'klienta', 'klientów', 'klientów') . ' z kroku 2.', 'errors', 422);
        $this->wizards->markDone(self::CODE, $today, $d['summary'], $userId);
        return ['summary' => $d['summary'], 'data' => $this->data($today)];
    }

    /** « listopad 2026 : 42 aktywnych klientów (3 mies.) · 35 z kompletem danych · 7 z brakami · decyzje : 2 poprawione · 3 brak danych · 2 nie dotyczy » */
    public static function summary(string $month, int $months, array $t): string
    {
        $s = $month . ' : ' . $t['active'] . ' ' . self::pl($t['active'], 'aktywny klient', 'aktywni klienci', 'aktywnych klientów') . ' (' . $months . ' mies.)'
            . ' · ' . $t['complete'] . ' z kompletem danych · ' . $t['incomplete'] . ' z brakami';
        $dec = [];
        if ($t['fixed']) $dec[] = $t['fixed'] . ' poprawione';
        if ($t['no_data']) $dec[] = $t['no_data'] . ' brak danych od klienta';
        if ($t['not_applicable']) $dec[] = $t['not_applicable'] . ' nie dotyczy';
        return $dec ? $s . ' · decyzje : ' . implode(' · ', $dec) : $s;
    }

    /** Lecture du résumé d'un mois précédent (format de summary()) : nombres de clients complets et avec manques. */
    public static function parseSummary(string $s): array
    {
        $n = fn(string $re) => preg_match($re, $s, $m) === 1 ? (int) $m[1] : null;
        return ['active' => $n('/ : (\d+) aktywn/u'), 'complete' => $n('/(\d+) z kompletem danych/u'), 'incomplete' => $n('/(\d+) z brakami/u')];
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
