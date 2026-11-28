<?php
declare(strict_types=1);

namespace App\Services\Organization\Wizards;

use App\Core\AutoSend;
use App\Exceptions\CustomException;
use App\Repositories\Logistics\DeliveryNoticesCheckRepository;
use App\Repositories\Logistics\ShippingDayRepository;
use App\Repositories\Organization\LightWizardRepository;
use App\Repositories\Workshop\ParamRepository;
use App\Services\Logistics\DeliveryNoticeService;
use App\Services\Logistics\LogisticsService;

/**
 * Asystent « Powiadomienia o dostawie » [382] (Logistyka — chaque jour ; obligation LOGISTYKA 4 « Informacja o dostawie dla
 * klientów » de la matrice 224). L'avis de livraison (camion, chauffeur, palettes, heure) part par e-mail et / ou SMS depuis
 * Logistyka › Powiadomienia klientów ou par l'automate de la Dystrybucja dokumentów (règle « delivery_planned », « dzień
 * przed dostawą » : MailEventSource::candidates prend les jours d'expédition de CURDATE() à CURDATE() + days_before) ; chaque
 * avis est noté dans n_delivery_notice sous le jour d'expédition (ship_date). L'étape 3 de la barre Dzień wysyłki compte les
 * clients « powiadomionych » (ShippingDayRepository::notifiedCustomers) mais ne montre ni les échecs ni les clients sans
 * contact. L'assistant les fait vérifier :
 *   1. Przegląd : jours contrôlés = aujourd'hui, et le jour ouvré suivant (week.production_days, même règle que « Opóźnienia
 *      dostaw ») quand l'automate y envoie déjà l'avis la veille (règle active, system.auto_send ≠ 0, jour ouvré suivant ≤
 *      aujourd'hui + days_before) ; pour chaque jour, les clients de LogisticsService::day (même liste que la barre et
 *      l'écran des avis) avec tournée, véhicule, chauffeur et départ (camion, sinon tournée, sinon
 *      planning.default_departure_time — comme DeliveryNoticeService) ; « powiadomiony » selon la barre ; avis du jour par
 *      canal (n_delivery_notice.status, error, communication_message.status) ; contact du client (e-mail email_head sinon
 *      email_salesman, téléphone phone_head sinon phone_alt — comme l'écran des avis) ;
 *   2. Do wyjaśnienia : un client est signalé quand un avis du jour a échoué (status 'failed', ou e-mail rejeté par la file :
 *      communication_message.status 'failed' — comme DeliveryNoticeService::alreadySent), ou qu'il n'a encore aucun avis
 *      réussi (aucun avis, ou SMS seulement enregistré sans envoi : status 'logged'), ou qu'il n'a ni e-mail ni téléphone ;
 *      un client sans avis qui le reçoit ensuite sort seul de la liste (comme la barre), un échec reste jusqu'à la décision.
 *      Une décision par client et jour d'expédition (n_delivery_notice_check) : « Wysłane ponownie » (acceptée seulement si un
 *      avis réussi existe maintenant, postérieur au dernier échec ; un nouvel échec après elle la rend caduque), « Kontakt
 *      telefoniczny » (note obligatoire), « Bez powiadomienia — uzasadnione » (note obligatoire) ;
 *   3. Zatwierdź : récapitulatif ; « Zakończ » écrit n_wizard_done — bloqué tant qu'un client signalé attend ; la tâche
 *      du jour se valide seule.
 * L'assistant n'envoie rien (ni e-mail ni SMS) : le renvoi se fait sur l'écran existant (lien par jour). Lecture seule hors de
 * n_delivery_notice_check et n_wizard_done.
 */
final class DeliveryNoticesCheckService
{
    public const CODE = 'delivery_notices_checked';
    public const PATH = '/logistics/notices-check';
    public const DECISIONS = ['resent' => 'Wysłane ponownie', 'phone' => 'Kontakt telefoniczny', 'justified' => 'Bez powiadomienia — uzasadnione'];
    /** Décisions qui demandent une note (au moins 3 caractères). */
    public const NOTE_REQUIRED = ['phone', 'justified'];
    /** Signaux d'un client, dans l'ordre de priorité. */
    public const SIGNALS = ['mail_failed' => 'e-mail nie doszedł', 'sms_failed' => 'SMS nie doszedł', 'no_contact' => 'klient bez kontaktu', 'not_sent' => 'brak powiadomienia'];
    /** Écran existant où l'on envoie (et renvoie) l'avis : Dzień wysyłki › Powiadomienia klientów (jour en paramètre). */
    public const NOTICES_LINK = '/logistics/notices';
    /** Libellés des statuts, comme Views/logistics/notices.twig. */
    public const STATUS_LABELS = ['queued' => 'e-mail w kolejce', 'logged' => 'SMS zapisany (bez wysyłki)', 'sent' => 'wysłano', 'failed' => 'błąd'];
    public const NO_TABLE = 'brak danych — brak tabeli powiadomień w bazie';
    private const DAY_ABBR = [1 => 'pon.', 2 => 'wt.', 3 => 'śr.', 4 => 'czw.', 5 => 'pt.', 6 => 'sob.', 7 => 'niedz.'];

    public function __construct(private DeliveryNoticesCheckRepository $repo, private ShippingDayRepository $shippingDay, private LogisticsService $logistics,
        private LightWizardRepository $wizards, private ParamRepository $params)
    {
    }

    /** Jours ouvrés (week.production_days, comme DeliveryDelaysService::settings). @return list<int> */
    public function workdays(): array
    {
        $days = array_values(array_filter(array_map('intval', explode(',', $this->params->get('week.production_days', '1,2,3,4,5'))), fn($d) => $d >= 1 && $d <= 7)) ?: [1, 2, 3, 4, 5];
        sort($days);
        return array_values(array_unique($days));
    }

    /**
     * Jours contrôlés : aujourd'hui, et le jour ouvré suivant quand l'automate y envoie l'avis la veille.
     * @return array{days: list<string>, next: string, eve: bool, rule: ?array, auto_send: bool, reason: string}
     */
    public function window(string $today): array
    {
        $next = DeliveryDelaysService::workday($today, 1, $this->workdays());
        $rule = $this->repo->noticeRule();
        $auto = trim($this->params->get(AutoSend::CODE, '1')) !== '0';
        $gap = (int) round((strtotime($next) - strtotime($today)) / 86400);
        $eve = $rule !== null && $rule['enabled'] && $auto && $rule['days_before'] !== null && $gap <= $rule['days_before'];
        $reason = match (true) {
            $rule === null => 'brak reguły automatycznej w bazie — powiadomienia wysyła logistyka w dniu wysyłki',
            !$rule['enabled'] => 'automat powiadomień wyłączony (Dystrybucja dokumentów) — powiadomienia wysyła logistyka w dniu wysyłki',
            !$auto => 'wysyłki automatyczne wyłączone (system.auto_send = 0) — automat nie wysyła powiadomień',
            $eve => 'automat wysyła powiadomienie ' . $rule['days_before'] . ' ' . self::pl((int) $rule['days_before'], 'dzień', 'dni', 'dni') . ' przed wysyłką — sprawdzany też ' . self::label($next),
            default => 'automat wysyła ' . (int) $rule['days_before'] . ' ' . self::pl((int) $rule['days_before'], 'dzień', 'dni', 'dni') . ' przed wysyłką — powiadomienia na ' . self::label($next) . ' jeszcze nie wyszły',
        };
        return ['days' => $eve ? [$today, $next] : [$today], 'next' => $next, 'eve' => $eve, 'rule' => $rule, 'auto_send' => $auto, 'reason' => $reason];
    }

    /** Données des trois étapes. */
    public function data(?string $today = null): array
    {
        $today ??= date('Y-m-d');
        $w = $this->window($today);
        $notices = $this->repo->notices($w['days']);
        $available = $notices !== null;
        $byKey = [];
        foreach ($notices ?? [] as $n) $byKey[substr((string) $n['ship_date'], 0, 10) . '|' . (int) $n['id_customer']][] = $n;
        $decisions = $this->repo->decisions($w['days']);

        $days = [];
        $items = [];
        foreach ($w['days'] as $d) {
            $day = $this->logistics->day($d);
            $notified = $available ? array_flip($this->shippingDay->notifiedCustomers($d)) : [];
            $contacts = $this->repo->contacts(array_column($day['customers'], 'id'));
            $list = [];
            foreach ($day['customers'] as $c) {
                $list[] = $this->item($d, $day, $c, $contacts[(int) $c['id']] ?? [], $byKey[$d . '|' . (int) $c['id']] ?? [], isset($notified[(int) $c['id']]), $decisions[$d . '|' . (int) $c['id']] ?? null);
            }
            $items = [...$items, ...$list];
            $count = fn(callable $f) => count(array_filter($list, $f));
            $days[] = ['date' => $d, 'label' => self::label($d), 'today' => $d === $today, 'customers' => count($list),
                'notified' => $count(fn($i) => $i['notified_bar']), 'ok' => $count(fn($i) => $i['ok']),
                'failed' => $count(fn($i) => (bool) array_intersect(['mail_failed', 'sms_failed'], array_column($i['signals'], 'code'))),
                'no_contact' => $count(fn($i) => in_array('no_contact', array_column($i['signals'], 'code'), true)),
                'not_sent' => $count(fn($i) => in_array('not_sent', array_column($i['signals'], 'code'), true)),
                'link' => ['href' => self::NOTICES_LINK . '?date=' . $d, 'label' => 'Powiadomienia ' . self::dm($d)]];
        }

        $rank = array_flip(array_keys(self::SIGNALS));
        $review = array_values(array_filter($items, fn($i) => $i['review']));
        usort($review, fn($a, $b) => [$a['signals'] ? $rank[$a['signals'][0]['code']] : 99, $a['day'], $a['name']] <=> [$b['signals'] ? $rank[$b['signals'][0]['code']] : 99, $b['day'], $b['name']]);
        $byCode = array_fill_keys(array_keys(self::SIGNALS), 0);
        foreach ($review as $i) foreach ($i['signals'] as $s) $byCode[$s['code']]++;
        $decided = fn(string $k) => count(array_filter($review, fn($i) => $i['decision'] !== null && !$i['decision']['stale'] && $i['decision']['code'] === $k));
        $totals = ['customers' => count($items), 'notified' => count(array_filter($items, fn($i) => $i['notified_bar'])), 'ok' => count(array_filter($items, fn($i) => $i['ok'])),
            'mails' => count(array_filter($items, fn($i) => $i['email'] !== '')), 'phones' => count(array_filter($items, fn($i) => $i['phone'] !== '')),
            'by_code' => $byCode, 'review' => count($review), 'waiting' => count(array_filter($review, fn($i) => $i['waiting'])),
            'resolved' => count(array_filter($review, fn($i) => !$i['waiting'])), 'resent' => $decided('resent'), 'phone' => $decided('phone'), 'justified' => $decided('justified')];
        $done = $this->wizards->done(self::CODE, $today, self::addDays($today, 1));
        return ['today' => $today, 'label' => self::label($today), 'next_workday' => $w['next'], 'next_label' => self::label($w['next']), 'eve' => $w['eve'],
            'window_reason' => $w['reason'], 'rule' => $w['rule'], 'auto_send' => $w['auto_send'],
            'available' => $available, 'sources' => ['notices' => ['available' => $available, 'rows' => $notices === null ? null : count($notices), 'note' => $available ? null : self::NO_TABLE]],
            'days' => $days, 'customers' => $items, 'review' => $review, 'totals' => $totals,
            'can_finish' => $totals['waiting'] === 0, 'summary' => self::summary($days, $totals),
            'decisions' => self::DECISIONS, 'note_required' => self::NOTE_REQUIRED, 'signal_labels' => self::SIGNALS, 'notices_link' => self::NOTICES_LINK,
            'done' => $done ? ['summary' => $done['summary'], 'at' => self::hm((string) $done['done_at']), 'by' => $done['by_login'] ?? null] : null];
    }

    /** Un client d'un jour : transport, contact, avis par canal, signaux, décision. */
    private function item(string $d, array $day, array $c, array $contact, array $notices, bool $notifiedBar, ?array $r): array
    {
        // Transport : comme DeliveryNoticeService::customerNotice (camion du jour, sinon tournée, sinon départ par défaut).
        $truck = null;
        foreach ($day['fleet'] as $t) if ($c['vehicle_id'] !== null && (int) $t['id'] === (int) $c['vehicle_id']) $truck = $t;
        $tour = null;
        foreach ($day['tours'] as $t) if ($c['tour_id'] !== null && (int) $t['id'] === (int) $c['tour_id']) $tour = $t;
        $vehicle = $c['vehicle_id'] !== null ? ($day['all_vehicles'][$c['vehicle_id']] ?? null) : null;
        $route = $tour['customers'] ?? ($truck['customers'] ?? []);
        $pos = array_search($c['id'], $route, false);
        $departure = (string) ($truck['departure'] ?? ($tour['departure'] ?? ($day['settings']['default_departure'] ?? '')));
        $plate = trim((string) ($vehicle['regis_num'] ?? ''));
        $driver = trim((string) ($vehicle['driver'] ?? ''));

        $email = trim((string) ($contact['email_head'] ?? '')) ?: trim((string) ($contact['email_salesman'] ?? ''));
        $email = filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';
        $phone = DeliveryNoticeService::phone((string) ($contact['phone_head'] ?? '')) ?: DeliveryNoticeService::phone((string) ($contact['phone_alt'] ?? ''));

        $ok = fn(array $n) => !in_array($n['status'], ['failed', 'logged'], true) && ($n['mail_status'] ?? '') !== 'failed';
        $failed = fn(array $n) => $n['status'] === 'failed' || ($n['mail_status'] ?? '') === 'failed';
        $list = array_map(fn($n) => ['id' => (int) $n['id'], 'channel' => (string) $n['channel'], 'channel_label' => $n['channel'] === 'sms' ? 'SMS' : 'E-mail',
            'status' => (string) $n['status'], 'status_label' => ($n['mail_status'] ?? '') === 'failed' ? 'odrzucony przez kolejkę e-mail' : (self::STATUS_LABELS[$n['status']] ?? (string) $n['status']),
            'mail_status' => $n['mail_status'], 'error' => trim((string) ($n['error'] ?? '')) !== '' ? mb_substr(trim((string) $n['error']), 0, 200) : null,
            'recipient' => (string) $n['recipient'], 'by' => $n['created_by'], 'automatic' => str_starts_with((string) $n['created_by'], 'automat:'),
            'at' => self::hm((string) $n['created_at']), 'at_label' => self::label(substr((string) $n['created_at'], 0, 10)) . ', ' . self::hm((string) $n['created_at']),
            'ok' => $ok($n), 'failed' => $failed($n)], $notices);
        $success = array_values(array_filter($list, fn($n) => $n['ok']));
        $failures = array_values(array_filter($list, fn($n) => $n['failed']));
        $lastFail = $failures ? max(array_column($failures, 'id')) : 0;
        $after = array_values(array_filter($success, fn($n) => $n['id'] > $lastFail));
        $resend = $after ? end($after) : null;

        $signals = [];
        $sig = function (string $code, string $detail) use (&$signals) { $signals[] = ['code' => $code, 'label' => self::SIGNALS[$code], 'detail' => $detail]; };
        foreach (['mail' => 'mail_failed', 'sms' => 'sms_failed'] as $ch => $code) {
            $f = array_values(array_filter($failures, fn($n) => $n['channel'] === $ch));
            if (!$f) continue;
            $n = end($f);
            $sig($code, ($ch === 'sms' ? 'SMS' : 'E-mail') . ' do ' . $n['recipient'] . ' (' . $n['at'] . ($n['automatic'] ? ', automat' : ($n['by'] ? ', ' . $n['by'] : '')) . ') — '
                . ($n['error'] ?? $n['status_label']) . (count($f) > 1 ? ' · ' . count($f) . ' ' . self::pl(count($f), 'próba', 'próby', 'prób') : ''));
        }
        if (!$success) {
            if ($email === '' && $phone === '') $sig('no_contact', 'brak adresu e-mail (główny, handlowy) i telefonu na karcie klienta');
            elseif (!$failures) {
                $logged = array_values(array_filter($list, fn($n) => $n['status'] === 'logged'));
                $sig('not_sent', $logged ? 'tylko SMS zapisany (bez wysyłki — brak dostawcy SMS), klient nic nie dostał' : 'żadne powiadomienie nie wyszło' . ($email === '' ? ' · brak e-maila' : '') . ($phone === '' ? ' · brak telefonu' : ''));
            }
        }

        // Une décision « Wysłane ponownie » suivie d'un nouvel échec est caduque : le client attend de nouveau.
        $stale = $r !== null && $r['decision'] === 'resent' && $lastFail > (int) $r['id_notice'];
        $decision = $r !== null ? ['code' => (string) $r['decision'], 'label' => self::DECISIONS[$r['decision']] ?? (string) $r['decision'], 'note' => $r['note'],
            'id_notice' => $r['id_notice'] !== null ? (int) $r['id_notice'] : null, 'flags' => $r['flags'], 'by' => $r['decided_by'], 'stale' => $stale,
            'at' => self::label(substr((string) $r['updated_at'], 0, 10)) . ', ' . self::hm((string) $r['updated_at'])] : null;
        return ['key' => $d . '|' . (int) $c['id'], 'id' => (int) $c['id'], 'day' => $d, 'day_label' => self::label($d), 'name' => (string) $c['name'], 'town' => $c['town'],
            'tour' => $tour['name'] ?? null, 'stop' => $pos === false ? null : $pos + 1, 'stops' => count($route), 'vehicle' => $plate !== '' ? $plate : null,
            'driver' => $driver !== '' ? $driver : null, 'departure' => $departure !== '' ? $departure : null, 'pallets' => $c['pallets'],
            'email' => $email, 'phone' => $phone, 'notices' => $list, 'notified_bar' => $notifiedBar, 'ok' => (bool) $success,
            'resend_notice' => $resend ? ['id' => $resend['id'], 'channel_label' => $resend['channel_label'], 'at' => $resend['at'], 'recipient' => $resend['recipient']] : null,
            'signals' => $signals, 'decision' => $decision, 'review' => $signals !== [] || $decision !== null, 'waiting' => $signals !== [] && ($decision === null || $stale),
            'link' => ['href' => self::NOTICES_LINK . '?date=' . $d, 'label' => 'Powiadomienia ' . self::dm($d)]];
    }

    /** Krok 2 : décision pour un client signalé (une par jour d'expédition, la dernière compte). */
    public function saveDecision(array $in, ?int $userId, ?string $today = null): array
    {
        $today ??= date('Y-m-d');
        $key = (string) ($in['ship_date'] ?? '') . '|' . (int) ($in['id_customer'] ?? 0);
        $decision = (string) ($in['decision'] ?? '');
        $note = trim((string) ($in['note'] ?? ''));
        if (!isset(self::DECISIONS[$decision])) throw new CustomException('Wybierz decyzję : wysłane ponownie, kontakt telefoniczny albo bez powiadomienia — uzasadnione.', 'errors', 422);
        if (mb_strlen($note) > 500) throw new CustomException('Notatka : najwyżej 500 znaków.', 'errors', 422);
        if (in_array($decision, self::NOTE_REQUIRED, true) && mb_strlen($note) < 3) {
            throw new CustomException($decision === 'phone' ? 'Kontakt telefoniczny : wpisz notatkę (z kim, kiedy, co przekazano).'
                : 'Bez powiadomienia — uzasadnione : wpisz notatkę (dlaczego klient nie dostaje powiadomienia).', 'errors', 422);
        }
        $d = $this->data($today);
        $c = array_values(array_filter($d['review'], fn($x) => $x['key'] === $key))[0] ?? null;
        if ($c === null) throw new CustomException('Tego klienta nie ma na liście do wyjaśnienia (powiadomienie wysłane bez błędu albo klient bez dostawy w sprawdzanych dniach).', 'errors', 422);
        if ($decision === 'resent' && $c['resend_notice'] === null) {
            throw new CustomException('Wysłane ponownie : nie ma jeszcze udanego powiadomienia ' . ($c['notices'] ? 'po ostatnim błędzie' : 'dla tego klienta') . ' (' . $c['day_label']
                . '). Wyślij je w « Powiadomienia klientów » — asystent niczego nie wysyła.', 'errors', 422);
        }
        $this->repo->saveDecision($c['day'], $c['id'], $today, $decision, $note !== '' ? $note : null, $decision === 'resent' ? $c['resend_notice']['id'] : null,
            implode(',', array_column($c['signals'], 'code')), $userId);
        return $this->data($today);
    }

    /** Krok 3 : « Zakończ » — chaque client signalé a sa décision ; la tâche du jour se valide seule. */
    public function finish(?int $userId, ?string $today = null): array
    {
        $today ??= date('Y-m-d');
        $d = $this->data($today);
        $w = $d['totals']['waiting'];
        if ($w > 0) throw new CustomException('Najpierw zapisz decyzję dla ' . $w . ' ' . self::pl($w, 'klienta', 'klientów', 'klientów') . ' z kroku 2.', 'errors', 422);
        $this->wizards->markDone(self::CODE, $today, $d['summary'], $userId);
        return ['summary' => $d['summary'], 'data' => $this->data($today)];
    }

    /** « 24.11 : 12 klientów, 10 powiadomionych · 25.11 : 8 klientów, 8 powiadomionych · 1 błąd e-mail · … · 3 do wyjaśnienia : 1 wysłane ponownie · … ». */
    public static function summary(array $days, array $t): string
    {
        $parts = [];
        foreach ($days as $d) {
            $parts[] = self::dm($d['date']) . ' : ' . ($d['customers'] ? $d['customers'] . ' ' . self::pl($d['customers'], 'klient', 'klientów', 'klientów') . ', ' . $d['notified'] . ' '
                . self::pl($d['notified'], 'powiadomiony', 'powiadomionych', 'powiadomionych') : 'brak dostaw');
        }
        $s = implode(' · ', $parts);
        $b = $t['by_code'];
        if ($b['mail_failed']) $s .= ' · ' . $b['mail_failed'] . ' ' . self::pl($b['mail_failed'], 'błąd e-mail', 'błędy e-mail', 'błędów e-mail');
        if ($b['sms_failed']) $s .= ' · ' . $b['sms_failed'] . ' ' . self::pl($b['sms_failed'], 'błąd SMS', 'błędy SMS', 'błędów SMS');
        if ($b['no_contact']) $s .= ' · ' . $b['no_contact'] . ' bez kontaktu';
        if ($b['not_sent']) $s .= ' · ' . $b['not_sent'] . ' bez powiadomienia';
        if ($t['review'] === 0) return $s . ' · nic do wyjaśnienia';
        $s .= ' · ' . $t['review'] . ' do wyjaśnienia';
        $p = [];
        if ($t['resent']) $p[] = $t['resent'] . ' wysłane ponownie';
        if ($t['phone']) $p[] = $t['phone'] . ' ' . self::pl($t['phone'], 'telefon', 'telefony', 'telefonów');
        if ($t['justified']) $p[] = $t['justified'] . ' ' . self::pl($t['justified'], 'uzasadnione', 'uzasadnione', 'uzasadnionych');
        return $p ? $s . ' : ' . implode(' · ', $p) : $s;
    }

    public static function label(string $day): string
    {
        $t = strtotime($day);
        return $t === false ? $day : self::DAY_ABBR[(int) date('N', $t)] . ' ' . date('j.m', $t);
    }

    private static function dm(string $day): string
    {
        return date('j.m', strtotime($day));
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
