<?php
declare(strict_types=1);

namespace App\Services\Organization\Wizards;

use App\Domain\Params\ParamRules;
use App\Exceptions\CustomException;
use App\Repositories\Logistics\InvoicesKsefDayRepository;
use App\Repositories\Organization\LightWizardRepository;
use App\Repositories\Workshop\ParamRepository;

/**
 * Asystent « Faktury i KSeF dnia » [378] (Administracja i fakturowanie, chaque jour ; obligation 2 « Proformy i faktury
 * (KSeF) » de la matrice 224). Chaque expédition (shipment_log) a une facture WDT (document_wdt) que l'on envoie à KSeF
 * depuis « Wysyłki i dokumenty » (bouton « Wyślij KSeF ») ou le kreator « Wyślij dzień » ; les statuts sont relus par le
 * cron (KsefSubmissionService::refreshPendingStatuses). L'assistant contrôle que rien ne reste en chemin.
 *   1. Przegląd : les expéditions du jour ouvré précédent (ou de la dernière validation, rattrapage borné par
 *      ksef_day.catchup_days) à aujourd'hui, leur WDT et leur statut KSeF, avec les signaux :
 *        - no_wdt : expédition sans WDT (id_wdt vide ou document absent) ;
 *        - not_invoiced : WDT sans date de facture (invoiced_date, champ P_1 exigé par KsefWdtInvoiceXmlMapper) ;
 *        - ksef_not_sent : WDT pas envoyée (aucune soumission, ou « ready ») depuis ksef_day.pending_days jours (date de
 *          facture, sinon d'expédition), seulement à partir de la première expédition envoyée à KSeF (avant : hors KSeF,
 *          comme OrderQueueRepository::ksefPending) ; dernière erreur du journal KSeF affichée (validation refusée) ;
 *        - ksef_rejected : soumission « rejected » (error_code, error_message) ;
 *        - ksef_failed : soumission « failed » (error_message) ;
 *        - ksef_pending : « submitted » / « processing » sans réponse depuis ksef_day.pending_days jours (submitted_at,
 *          sinon updated_at) ;
 *      plus les expéditions des jours précédents (au plus ksef_day.lookback_days jours) dont un signal reste ouvert et
 *      qui n'ont pas de résultat définitif (« zaległe ») ;
 *   2. Do wyjaśnienia : un résultat par document signalé (n_invoice_ksef_check) — Wysłane ponownie / poprawione (vérifié :
 *      KSeF « accepted » et plus aucun signal au moment de l'enregistrement, revérifié à chaque lecture), W toku (note
 *      obligatoire, vaut pour le jour où elle est écrite : le lendemain, le document revient tant que le problème
 *      reste), Bez faktury — uzasadnione (note obligatoire, définitif) ; lien vers l'écran existant du document ;
 *   3. Zatwierdź : résumé, « Zakończ » écrit n_wizard_done — la tâche du poste se valide seule.
 * Lecture seule hors de n_invoice_ksef_check et n_wizard_done : rien n'est envoyé ni renvoyé à KSeF (aucun appel
 * KsefSubmissionService / KsefApiClient), aucune facture modifiée, aucun e-mail ni SMS. Aucun montant affiché.
 */
final class InvoicesKsefDayService
{
    public const CODE = 'invoices_ksef_checked';
    public const PATH = '/shipments/ksef-day';
    public const RESULTS = ['fixed' => 'Wysłane ponownie / poprawione', 'in_progress' => 'W toku', 'justified' => 'Bez faktury — uzasadnione'];
    /** Signaux d'un document, dans l'ordre d'affichage. */
    public const SIGNALS = ['no_wdt' => 'wysyłka bez faktury WDT', 'not_invoiced' => 'WDT bez daty faktury', 'ksef_not_sent' => 'nie wysłana do KSeF',
        'ksef_rejected' => 'odrzucona przez KSeF', 'ksef_failed' => 'błąd wysyłki do KSeF', 'ksef_pending' => 'bez odpowiedzi KSeF'];
    /** Statuts KSeF (ksef_submission.status, migration 053) : libellés de OrderTimelineService / OrderQueueService. */
    public const KSEF_LABELS = ['accepted' => 'przyjęta', 'submitted' => 'w toku', 'processing' => 'w toku', 'ready' => 'przygotowana, niewysłana',
        'rejected' => 'odrzucona', 'failed' => 'błąd wysyłki'];
    /** Écrans existants : liste des expéditions (bouton « Wyślij KSeF », filtre « KSeF : nieprzyjęte ») et prix de la WDT. */
    public const SHIPMENTS_LINK = '/shipments';
    public const SHIPMENTS_KSEF_LINK = '/shipments?type=ksef-pending';
    public const WDT_LINK = '/wdt-documents/%d/products';
    private const PARAMS = ['catchup' => ['ksef_day.catchup_days', 7], 'pending' => ['ksef_day.pending_days', 1], 'lookback' => ['ksef_day.lookback_days', 30]];
    private const DAY_ABBR = [1 => 'pon.', 2 => 'wt.', 3 => 'śr.', 4 => 'czw.', 5 => 'pt.', 6 => 'sob.', 7 => 'niedz.'];

    public function __construct(private InvoicesKsefDayRepository $repo, private LightWizardRepository $wizards, private ParamRepository $params)
    {
    }

    /** Réglage ksef_day.* (n_prod_param, borné par ParamRules ; valeur invalide → défaut). */
    public function param(string $name): int
    {
        [$code, $default] = self::PARAMS[$name];
        $raw = trim($this->params->get($code, (string) $default));
        $v = ctype_digit($raw) ? (int) $raw : $default;
        [$min, $max] = ParamRules::RANGES[$code];
        return $v < $min || $v > $max ? $default : $v;
    }

    /** Jour ouvré précédent (week.production_days, lundi–vendredi par défaut). */
    public function previousWorkday(string $today): string
    {
        $days = array_values(array_filter(array_map('intval', explode(',', $this->params->get('week.production_days', '1,2,3,4,5'))), fn($d) => $d >= 1 && $d <= 7)) ?: [1, 2, 3, 4, 5];
        $d = $today;
        for ($i = 0; $i < 10; $i++) {
            $d = self::addDays($d, -1);
            if (in_array((int) date('N', strtotime($d)), $days, true)) return $d;
        }
        return self::addDays($today, -1);
    }

    /**
     * Fenêtre : du jour ouvré précédent à aujourd'hui ; si la dernière validation est plus ancienne, depuis son jour
     * (rattrapage), au plus ksef_day.catchup_days jours en arrière.
     */
    public function window(string $today): array
    {
        $prev = $this->previousWorkday($today);
        $last = $this->repo->lastDoneDay(self::CODE, $today);
        $from = $prev;
        if ($last !== null && $last < $prev) $from = min($prev, max($last, self::addDays($today, -$this->param('catchup'))));
        $kind = $from < $prev ? 'catchup' : 'workday';
        return ['from' => $from, 'to' => $today, 'kind' => $kind, 'last_done' => $last,
            'label' => self::label($from) . ' – ' . self::label($today) . ($kind === 'catchup' ? ' (nadrabianie od ostatniego zatwierdzenia)' : '')];
    }

    public function data(?string $today = null): array
    {
        $today ??= date('Y-m-d');
        $w = $this->window($today);
        $pending = $this->param('pending');
        $lookback = $this->param('lookback');
        $since = $this->repo->ksefSince();
        $docs = [];
        foreach ($this->repo->shipments($w['from'], $w['to']) as $r) $docs[] = $this->doc($r, $today, $since, $pending, false);
        // Zaległe : signal encore ouvert sur une expédition plus ancienne, sans résultat définitif (ou expliquée aujourd'hui).
        $lbFrom = self::addDays($today, -$lookback);
        $carried = [];
        if ($lbFrom < $w['from']) {
            foreach ($this->repo->shipments($lbFrom, self::addDays($w['from'], -1), $today) as $r) {
                $d = $this->doc($r, $today, $since, $pending, true);
                $checkedToday = $d['check'] !== null && $d['check']['on'] === $today;
                if ($checkedToday || ($d['signals'] && $d['waiting'])) $carried[] = $d;
            }
        }
        $all = array_merge($docs, $carried);
        $reviewed = array_values(array_filter($all, fn($d) => $d['review']));
        $count = fn(string $res) => count(array_filter($reviewed, fn($d) => !$d['waiting'] && $d['check'] !== null && $d['check']['result'] === $res));
        $byCode = array_fill_keys(array_keys(self::SIGNALS), 0);
        foreach ($all as $d) foreach ($d['signals'] as $s) $byCode[$s['code']]++;
        $withWdt = array_values(array_filter($docs, fn($d) => $d['wdt'] !== null));
        $totals = ['shipments' => count($docs), 'wdt' => count($withWdt), 'accepted' => count(array_filter($docs, fn($d) => $d['ksef']['status'] === 'accepted')),
            'out_of_ksef' => count(array_filter($docs, fn($d) => $d['ksef']['out'])), 'carried' => count($carried),
            'review' => count($reviewed), 'clean' => count($docs) - count(array_filter($docs, fn($d) => $d['review'])),
            'waiting' => count(array_filter($all, fn($d) => $d['waiting'])), 'resolved' => count(array_filter($reviewed, fn($d) => !$d['waiting'])),
            'signals' => array_sum($byCode), 'by_code' => $byCode, 'fixed' => $count('fixed'), 'in_progress' => $count('in_progress'), 'justified' => $count('justified')];
        $done = $this->wizards->done(self::CODE, $today, self::addDays($today, 1));
        return ['today' => $today, 'label' => self::label($today), 'window' => $w, 'catchup_days' => $this->param('catchup'), 'pending_days' => $pending,
            'lookback_days' => $lookback, 'ksef_since' => $since, 'docs' => $docs, 'carried' => $carried, 'totals' => $totals,
            'can_finish' => $totals['waiting'] === 0, 'summary' => self::summary($totals), 'results' => self::RESULTS, 'signal_labels' => self::SIGNALS,
            'shipments_link' => self::SHIPMENTS_KSEF_LINK,
            'done' => $done ? ['summary' => $done['summary'], 'at' => self::hm((string) $done['done_at']), 'by' => $done['by_login'] ?? null] : null];
    }

    /** Un document d'expédition : WDT, statut KSeF, signaux, résultat enregistré, liens vers les écrans existants. */
    private function doc(array $r, string $today, ?string $since, int $pendingDays, bool $carried): array
    {
        $date = substr((string) $r['shipment_date'], 0, 10);
        $wdt = $r['wdt_id'] !== null ? ['id' => (int) $r['wdt_id'], 'number' => trim((string) $r['wdt_num']) !== '' ? trim((string) $r['wdt_num']) : ('#' . (int) $r['wdt_id']),
            'invoiced' => self::date($r['invoiced_date'])] : null;
        $status = $r['ksef_status'] !== null ? (string) $r['ksef_status'] : null;
        $signals = [];
        $sig = function (string $code, string $detail) use (&$signals) { $signals[] = ['code' => $code, 'label' => self::SIGNALS[$code], 'detail' => $detail]; };
        $out = false;
        $age = null;
        if ($wdt === null) {
            $sig('no_wdt', (int) $r['sl_id_wdt'] > 0 ? 'WDT #' . (int) $r['sl_id_wdt'] . ' nie istnieje w dokumentach' : 'wysyłka ' . self::dmy($date) . ' bez dokumentu WDT');
        } elseif ($wdt['invoiced'] === null) {
            $sig('not_invoiced', $wdt['number'] . ' bez daty faktury — KSeF jej wymaga');
        } elseif ($status === null || $status === 'ready') {
            $out = $status === null && ($since === null || $date < $since);
            $age = self::days($wdt['invoiced'], $today);
            if (!$out && $age >= $pendingDays) {
                $detail = $wdt['number'] . ' z ' . self::dmy($wdt['invoiced']) . ' · ' . $age . ' ' . self::pl($age, 'dzień', 'dni', 'dni') . ($status === 'ready' ? ' · przygotowana, niewysłana' : '');
                if ($r['last_event'] !== null && $r['last_event'] !== '') {
                    [$at, $stage, $msg] = array_pad(explode('|', (string) $r['last_event'], 3), 3, '');
                    $detail .= ' · ostatni błąd (' . trim($stage) . ', ' . self::dm($at) . ') : ' . mb_substr(trim($msg), 0, 200);
                }
                $sig('ksef_not_sent', $detail);
            }
        } elseif ($status === 'rejected') {
            $sig('ksef_rejected', trim(($r['error_code'] !== null && $r['error_code'] !== '' ? 'kod ' . $r['error_code'] . ' : ' : '') . mb_substr(trim((string) $r['error_message']), 0, 240)) ?: 'bez komunikatu KSeF');
        } elseif ($status === 'failed') {
            $sig('ksef_failed', mb_substr(trim((string) $r['error_message']), 0, 240) ?: 'bez komunikatu');
        } elseif ($status === 'submitted' || $status === 'processing') {
            $at = (string) ($r['submitted_at'] ?? $r['ksef_updated_at'] ?? '');
            $age = $at !== '' ? self::days(substr($at, 0, 10), $today) : 0;
            if ($age >= $pendingDays) $sig('ksef_pending', 'wysłana ' . self::dm($at) . ' ' . self::hm($at) . ' · ' . $age . ' ' . self::pl($age, 'dzień', 'dni', 'dni') . ' bez odpowiedzi (status ' . $status . ')');
        }
        $ck = $r['result'] !== null ? ['result' => (string) $r['result'], 'label' => self::RESULTS[$r['result']] ?? (string) $r['result'], 'note' => $r['note'],
            'flags' => $r['flags'], 'ksef' => $r['check_ksef_status'], 'by' => $r['check_by'], 'on' => substr((string) $r['checked_on'], 0, 10), 'at' => self::hm((string) $r['check_at'])] : null;
        // Résultat valable : « poprawione » tant qu'aucun signal ne revient, « w toku » le jour où il est écrit, « bez faktury » toujours.
        $valid = $ck !== null && match ($ck['result']) {
            'fixed' => $signals === [],
            'in_progress' => $signals === [] || $ck['on'] === $today,
            default => true,
        };
        $review = $signals !== [] || $ck !== null;
        $links = [];
        if ($wdt !== null && $status !== 'accepted') $links[] = ['label' => 'Wysyłki › Wyślij KSeF', 'url' => self::SHIPMENTS_KSEF_LINK, 'icon' => 'bi-cloud-upload'];
        if ($wdt !== null && $status !== 'accepted') $links[] = ['label' => 'Ceny ' . $wdt['number'], 'url' => sprintf(self::WDT_LINK, $wdt['id']), 'icon' => 'bi-receipt'];
        if ($wdt === null) $links[] = ['label' => 'Wysyłki i dokumenty', 'url' => self::SHIPMENTS_LINK, 'icon' => 'bi-truck'];
        return ['id' => (int) $r['id'], 'key' => (string) (int) $r['id'], 'shipment_date' => $date, 'day_label' => self::label($date), 'today' => $date === $today, 'carried' => $carried,
            'customer' => ['id' => $r['id_customer'] !== null ? (int) $r['id_customer'] : null, 'name' => $r['customer'] ?? ('klient #' . (int) $r['id_customer'])],
            'wdt' => $wdt,
            'ksef' => ['status' => $status, 'label' => $out ? 'poza KSeF' : ($status === null ? 'nie wysłano' : (self::KSEF_LABELS[$status] ?? $status)), 'out' => $out,
                'env' => $r['ksef_env'] ?? null, 'number' => $r['ksef_number'] ?? null, 'age' => $age],
            'signals' => $signals, 'review' => $review, 'check' => $ck, 'waiting' => $review && !$valid, 'links' => $links];
    }

    /** Krok 2 : résultat pour un document signalé (un par expédition, le dernier compte). */
    public function saveResult(array $input, ?int $userId, ?string $today = null): array
    {
        $today ??= date('Y-m-d');
        $id = (int) ($input['id_shipment'] ?? 0);
        $result = (string) ($input['result'] ?? '');
        $note = trim((string) ($input['note'] ?? ''));
        if (!isset(self::RESULTS[$result])) throw new CustomException('Wybierz wynik : wysłane ponownie / poprawione, w toku albo bez faktury — uzasadnione.', 'errors', 422);
        if (mb_strlen($note) > 500) throw new CustomException('Notatka : najwyżej 500 znaków.', 'errors', 422);
        if ($result === 'in_progress' && mb_strlen($note) < 3) throw new CustomException('W toku : wpisz notatkę (co jest robione, kto, do kiedy).', 'errors', 422);
        if ($result === 'justified' && mb_strlen($note) < 3) throw new CustomException('Bez faktury — uzasadnione : wpisz notatkę (dlaczego faktura nie jest potrzebna albo gdzie została wystawiona).', 'errors', 422);
        $d = $this->data($today);
        $doc = array_values(array_filter(array_merge($d['docs'], $d['carried']), fn($x) => $x['id'] === $id))[0] ?? null;
        if ($doc === null) throw new CustomException('Tej wysyłki nie ma na liście (poza zakresem dni albo bez problemu do wyjaśnienia).', 'errors', 422);
        if (!$doc['review'] && $result !== 'fixed') throw new CustomException('Ta wysyłka nie ma problemu do wyjaśnienia — wystarczy « Wysłane ponownie / poprawione ».', 'errors', 422);
        // « Poprawione » : vérifié sur l'état réel (aucun appel KSeF ici — le statut vient du cron des statuts).
        if ($result === 'fixed' && ($doc['signals'] !== [] || $doc['ksef']['status'] !== 'accepted')) {
            $why = $doc['wdt'] === null ? 'wysyłka nadal nie ma faktury WDT'
                : ($doc['wdt']['invoiced'] === null ? $doc['wdt']['number'] . ' nadal nie ma daty faktury'
                    : 'KSeF nie przyjął jeszcze faktury ' . $doc['wdt']['number'] . ' (status : « ' . $doc['ksef']['label'] . ' »)');
            throw new CustomException('Nie można zapisać « Wysłane ponownie / poprawione » : ' . $why . '. Wyślij ponownie w « Wysyłki i dokumenty » (przycisk « Wyślij KSeF ») i poczekaj na odświeżenie statusu (co 5 minut) albo wybierz « W toku » lub « Bez faktury — uzasadnione ».', 'errors', 422);
        }
        $flags = implode(',', array_column($doc['signals'], 'code'));
        $this->repo->saveCheck($doc['id'], $doc['shipment_date'], $doc['wdt']['id'] ?? null, $result, $note !== '' ? $note : null, $flags, $doc['ksef']['status'], $today, $userId);
        return $this->data($today);
    }

    /** Krok 3 : « Zakończ » — tylko gdy każdy dokument do wyjaśnienia ma wynik ; zadanie stanowiska zalicza się samo. */
    public function finish(?int $userId, ?string $today = null): array
    {
        $today ??= date('Y-m-d');
        $d = $this->data($today);
        $w = $d['totals']['waiting'];
        if ($w > 0) throw new CustomException('Najpierw wyjaśnij ' . $w . ' ' . self::pl($w, 'dokument', 'dokumenty', 'dokumentów') . ' z kroku 2.', 'errors', 422);
        $this->wizards->markDone(self::CODE, $today, $d['summary'], $userId);
        return ['summary' => $d['summary'], 'data' => $this->data($today)];
    }

    /** « 6 wysyłek · 4 przyjęte w KSeF · 3 dokumenty do wyjaśnienia · 1 poprawiony · 1 w toku · 1 bez faktury » (carte de la tâche). */
    public static function summary(array $t): string
    {
        if ($t['shipments'] === 0 && $t['carried'] === 0) return 'brak wysyłek';
        $s = $t['shipments'] . ' ' . self::pl($t['shipments'], 'wysyłka', 'wysyłki', 'wysyłek') . ' · ' . $t['accepted'] . ' ' . self::pl($t['accepted'], 'przyjęta', 'przyjęte', 'przyjętych') . ' w KSeF';
        if ($t['review'] === 0) return $s . ' · bez problemów';
        $s .= ' · ' . $t['review'] . ' ' . self::pl($t['review'], 'dokument', 'dokumenty', 'dokumentów') . ' do wyjaśnienia';
        if ($t['carried']) $s .= ' (' . $t['carried'] . ' ' . self::pl($t['carried'], 'zaległy', 'zaległe', 'zaległych') . ')';
        foreach (['fixed' => ['poprawiony', 'poprawione', 'poprawionych'], 'in_progress' => ['w toku', 'w toku', 'w toku'], 'justified' => ['bez faktury', 'bez faktury', 'bez faktury']] as $k => $f) {
            if ($t[$k]) $s .= ' · ' . $t[$k] . ' ' . self::pl($t[$k], ...$f);
        }
        return $s;
    }

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

    private static function dmy(string $day): string
    {
        return date('d.m.Y', strtotime($day));
    }

    private static function dm(string $ts): string
    {
        return strlen($ts) >= 10 ? (int) substr($ts, 8, 2) . '.' . substr($ts, 5, 2) : '';
    }

    private static function date(mixed $v): ?string
    {
        $v = $v === null ? '' : substr((string) $v, 0, 10);
        return $v === '' || $v === '0000-00-00' ? null : $v;
    }

    private static function addDays(string $day, int $n): string
    {
        return date('Y-m-d', strtotime($day . ' ' . ($n >= 0 ? '+' : '') . $n . ' days'));
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
