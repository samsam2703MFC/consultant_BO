<?php
declare(strict_types=1);

namespace App\Services\Organization\Wizards;

use App\Domain\Params\ParamRules;
use App\Exceptions\CustomException;
use App\Repositories\Mail\CustomerMailCheckRepository;
use App\Repositories\Mail\UserMailRepository;
use App\Repositories\Organization\LightWizardRepository;
use App\Repositories\Workshop\ParamRepository;
use App\Services\Organization\PageAccessService;

/**
 * Asystent « Poczta od klientów » [383] (Administracja — chaque jour ; obligation ADMINISTRACJA 6 « Korespondencja z
 * klientami » de la matrice 224). Les e-mails arrivent dans la boîte Poczta de la personne (n_user_mail, synchronisée toutes
 * les 5 minutes par Bin/user_mail_sync.php ou à l'ouverture de Poczta) ; l'expéditeur reconnu comme client porte id_customer.
 * L'assistant fait vérifier qu'aucun client n'attend de réponse :
 *   1. Przegląd : la boîte — EXACTEMENT celle que la personne lit dans Poczta (MailController::mailUser : la sienne ;
 *      l'administrateur dans la session d'un poste lit celle de la personne de ce poste, UserMailRepository::
 *      mailboxOwnerForPosition ; poczta wstrzymana par l'administrateur = rien, comme UserMailService::box) — et l'état de sa
 *      synchronisation (dernière synchro, erreur, pas de synchro depuis SYNC_STALE_MIN minutes) : sans synchronisation sûre,
 *      l'assistant n'affiche jamais « 0 e-mail » comme un résultat ; les e-mails de clients des customer_mail.lookback_days
 *      derniers jours, par client : reçus, lus (seen), répondus, sans réponse après customer_mail.reply_hours heures ;
 *   2. Do wyjaśnienia : un e-mail est signalé quand il attend une réponse depuis plus de reply_hours heures comptées sur les
 *      jours ouvrés (week.production_days : samedi, dimanche ne comptent pas, la nuit compte — pas d'heures de bureau dans
 *      l'ERP), ou qu'il a reçu aujourd'hui une réponse datée arrivée après ce délai. « Répondu » : n_user_mail.answered = 1
 *      (drapeau \Answered, ou réponse envoyée depuis Poczta), ou une réponse dans le fil (n_user_mail_sent.id_reply_to, status
 *      'sent' ; ou dossier Wysłane de la boîte, in_reply_to = message_id). Une décision par e-mail (n_customer_mail_check) :
 *      « Odpowiedziane » (acceptée seulement si le message est maintenant répondu — l'assistant n'envoie rien), « Bez
 *      odpowiedzi — nie wymaga » (note obligatoire), « Przekazane » (note obligatoire, personne et poste facultatifs, comme
 *      l'assistant OTIF [375]). Lien vers le message dans Poczta (/mail?id=…, l'écran de lecture existant) ;
 *   3. Zatwierdź : récapitulatif (délai moyen de réponse des réponses datées d'aujourd'hui, si au moins une) ; « Zakończ »
 *      écrit n_wizard_done — bloqué tant qu'un e-mail signalé attend, ou sans boîte lisible.
 * Aucune connexion à la boîte (ni IMAP ni SMTP), aucun mot de passe lu, aucun message marqué lu ou répondu, aucun contenu
 * copié : n_customer_mail_check ne garde que l'id du message. Lecture seule hors de n_customer_mail_check et n_wizard_done.
 */
final class CustomerMailCheckService
{
    public const CODE = 'customer_mail_checked';
    public const PATH = '/mail/customer-check';
    /** Écran de lecture existant : Poczta, le message s'ouvre par ?id= (assets/js/mail.js). */
    public const MAIL_LINK = '/mail';
    public const DECISIONS = ['answered' => 'Odpowiedziane', 'not_needed' => 'Bez odpowiedzi — nie wymaga', 'forwarded' => 'Przekazane'];
    /** Décisions qui demandent une note (au moins 3 caractères). */
    public const NOTE_REQUIRED = ['not_needed', 'forwarded'];
    public const SIGNALS = ['unanswered' => 'bez odpowiedzi po terminie', 'late_reply' => 'odpowiedź po terminie'];
    /** Synchronisation automatique toutes les 5 minutes (deploy/server.sh) : au-delà d'une heure sans synchro, la boîte est en retard. */
    public const SYNC_STALE_MIN = 60;
    public const SYNC_LABELS = ['ok' => 'synchronizacja OK', 'stale' => 'synchronizacja nieaktualna', 'error' => 'błąd synchronizacji',
        'never' => 'skrzynka jeszcze niezsynchronizowana', 'stopped' => 'poczta wstrzymana przez administratora', 'none' => 'brak skrzynki'];
    public const REPLY_SOURCES = ['flag' => 'oznaczona jako odpowiedziana', 'erp' => 'odpowiedź z Poczty ERP', 'sent' => 'odpowiedź w folderze Wysłane'];
    public const MODE_LABELS = ['live' => '', 'redirect' => 'kopia testowa — na adres testowy', 'log_only' => 'kopia testowa — tylko dziennik'];
    private const PARAMS = ['hours' => ['customer_mail.reply_hours', 24], 'lookback' => ['customer_mail.lookback_days', 30]];
    private const DAY_ABBR = [1 => 'pon.', 2 => 'wt.', 3 => 'śr.', 4 => 'czw.', 5 => 'pt.', 6 => 'sob.', 7 => 'niedz.'];

    public function __construct(private CustomerMailCheckRepository $repo, private UserMailRepository $boxes, private PageAccessService $access,
        private LightWizardRepository $wizards, private ParamRepository $params)
    {
    }

    /** Réglage customer_mail.* (n_prod_param, borné par ParamRules ; valeur invalide → défaut). */
    public function param(string $name): int
    {
        [$code, $default] = self::PARAMS[$name];
        $raw = trim($this->params->get($code, (string) $default));
        $v = ctype_digit($raw) ? (int) $raw : $default;
        [$min, $max] = ParamRules::RANGES[$code];
        return $v < $min || $v > $max ? $default : $v;
    }

    /** Jours ouvrés (week.production_days, comme DeliveryNoticesCheckService::workdays). @return list<int> */
    public function workdays(): array
    {
        $days = array_values(array_filter(array_map('intval', explode(',', $this->params->get('week.production_days', '1,2,3,4,5'))), fn($d) => $d >= 1 && $d <= 7)) ?: [1, 2, 3, 4, 5];
        sort($days);
        return array_values(array_unique($days));
    }

    /**
     * Boîte lue par la personne : la même règle que MailController::mailUser (Poczta) — l'administrateur dans la session d'un
     * poste voit la boîte de la personne de ce poste ; sinon, et pour tous les autres, la sienne.
     */
    public function owner(int $userId): int
    {
        $position = $this->access->viewPosition($userId);
        if ($position !== null) {
            $owner = $this->boxes->mailboxOwnerForPosition($position);
            if ($owner !== null) return $owner;
        }
        return $userId;
    }

    /** Heures écoulées entre deux instants, comptées seulement sur les jours ouvrés (24 h par jour ouvré). */
    public static function workHours(string $from, string $to, array $workdays): float
    {
        $a = strtotime($from);
        $b = strtotime($to);
        if ($a === false || $b === false || $b <= $a) return 0.0;
        $s = 0;
        $day = strtotime(date('Y-m-d', $a));
        for ($i = 0; $i < 800 && $day < $b; $i++) {
            $next = strtotime(date('Y-m-d', $day) . ' +1 day');
            if (in_array((int) date('N', $day), $workdays, true)) $s += max(0, min($b, $next) - max($a, $day));
            $day = $next;
        }
        return $s / 3600;
    }

    /** Instant où $hours heures de jours ouvrés se sont écoulées depuis $from (termin odpowiedzi). */
    public static function deadline(string $from, int $hours, array $workdays): string
    {
        $t = strtotime($from);
        if ($t === false) return $from;
        $left = $hours * 3600;
        for ($i = 0; $i < 800; $i++) {
            $next = strtotime(date('Y-m-d', $t) . ' +1 day');
            if (in_array((int) date('N', $t), $workdays, true)) {
                if ($next - $t >= $left) return date('Y-m-d H:i:s', $t + $left);
                $left -= $next - $t;
            }
            $t = $next;
        }
        return date('Y-m-d H:i:s', $t);
    }

    /** État de la boîte : code (ok, stale, error, never, stopped, none), libellé, détail. */
    public function syncState(?array $box, bool $stopped, string $now): array
    {
        $last = $box['last_sync_at'] ?? null;
        $error = trim((string) ($box['last_error'] ?? ''));
        $minutes = $last ? (int) floor((strtotime($now) - strtotime((string) $last)) / 60) : null;
        $code = match (true) {
            $box === null => 'none',
            $stopped => 'stopped',
            $error !== '' => 'error',
            $last === null => 'never',
            $minutes > self::SYNC_STALE_MIN => 'stale',
            default => 'ok',
        };
        $detail = match ($code) {
            'none' => 'Brak skrzynki w Poczcie — podłącz ją w Poczta › Ustawienia skrzynki (albo ustawi ją administrator).',
            'stopped' => 'Poczta tej skrzynki jest wstrzymana przez administratora : wiadomości nie są pobierane ani pokazywane.',
            'error' => 'Ostatnia synchronizacja nie powiodła się : ' . mb_substr($error, 0, 200) . ($last ? ' (ostatnia udana : ' . self::when((string) $last) . ')' : '') . '. Nowe e-maile mogą nie być widoczne.',
            'never' => 'Skrzynka nie była jeszcze pobrana — brak danych o e-mailach.',
            'stale' => 'Ostatnia synchronizacja : ' . self::when((string) $last) . ' (' . self::ago($minutes) . ' temu, automat co 5 minut) — nowe e-maile mogą nie być widoczne.',
            default => 'Ostatnia synchronizacja : ' . self::when((string) $last) . ' (' . self::ago(max(0, $minutes)) . ' temu).',
        };
        return ['code' => $code, 'label' => self::SYNC_LABELS[$code], 'detail' => $detail, 'last_sync_at' => $last, 'minutes' => $minutes,
            'error' => $error !== '' ? mb_substr($error, 0, 200) : null, 'readable' => !in_array($code, ['none', 'stopped'], true),
            'reliable' => $code === 'ok', 'can_finish' => !in_array($code, ['none', 'stopped', 'never'], true)];
    }

    /** Données des trois étapes pour la personne $userId (boîte selon la règle de Poczta). */
    public function data(int $userId, ?string $now = null): array
    {
        $now ??= date('Y-m-d H:i:s');
        $today = substr($now, 0, 10);
        $hours = $this->param('hours');
        $lookback = $this->param('lookback');
        $workdays = $this->workdays();
        $owner = $this->owner($userId);
        $box = $this->repo->mailbox($owner);
        $stopped = $box !== null && $this->boxes->adminPolicy($owner)['stopped'];
        $sync = $this->syncState($box, $stopped, $now);
        $since = date('Y-m-d H:i:s', strtotime($now . ' -' . $lookback . ' days'));

        // Boîte absente ou wstrzymana : Poczta n'affiche rien, l'assistant non plus.
        $rows = $sync['readable'] ? $this->repo->customerInbox($owner, $since) : [];
        $erp = $sync['readable'] ? $this->repo->erpReplies($owner, array_column($rows, 'id')) : [];
        $sent = $sync['readable'] ? $this->repo->sentReplies($owner, array_column($rows, 'message_id')) : [];
        $decisions = $sync['readable'] ? $this->repo->decisions($owner) : [];

        $items = [];
        foreach ($rows as $m) {
            $items[] = $this->item($m, $erp[(int) $m['id']] ?? [], $sent[(string) $m['message_id']] ?? [], $decisions[(int) $m['id']] ?? null, $now, $today, $hours, $workdays);
        }
        usort($items, fn($a, $b) => [$b['waiting'], $a['received_at'], $a['id']] <=> [$a['waiting'], $b['received_at'], $b['id']]);
        $review = array_values(array_filter($items, fn($i) => $i['review']));

        // Par client : reçus, sans réponse, signalés, plus ancien sans réponse.
        $byCustomer = [];
        foreach ($items as $i) {
            $c = &$byCustomer[$i['id_customer']];
            $c ??= ['id' => $i['id_customer'], 'name' => $i['customer'], 'mails' => 0, 'unread' => 0, 'replied' => 0, 'unanswered' => 0, 'overdue' => 0, 'waiting' => 0, 'oldest_hours' => null];
            $c['mails']++;
            if (!$i['seen']) $c['unread']++;
            if ($i['replied']) $c['replied']++;
            else {
                $c['unanswered']++;
                $c['oldest_hours'] = max($c['oldest_hours'] ?? 0, $i['age_hours']);
            }
            if (in_array('unanswered', array_column($i['signals'], 'code'), true)) $c['overdue']++;
            if ($i['waiting']) $c['waiting']++;
            unset($c);
        }
        $customers = array_values($byCustomer);
        usort($customers, fn($a, $b) => [$b['waiting'], $b['overdue'], $b['unanswered'], (string) $a['name']] <=> [$a['waiting'], $a['overdue'], $a['unanswered'], (string) $b['name']]);

        // Délai moyen de réponse : réponses datées (Poczta ERP, dossier Wysłane) d'aujourd'hui ; le drapeau seul n'a pas de date.
        $today_replies = array_values(array_filter($items, fn($i) => $i['reply'] !== null && $i['reply']['at'] !== null && substr($i['reply']['at'], 0, 10) === $today));
        $avg = $today_replies ? round(array_sum(array_column($today_replies, 'reply_hours')) / count($today_replies), 1) : null;

        $has = fn(array $i, string $code) => in_array($code, array_column($i['signals'], 'code'), true);
        $decided = fn(string $k) => count(array_filter($review, fn($i) => $i['decision'] !== null && !$i['decision']['stale'] && $i['decision']['code'] === $k));
        $totals = ['mails' => count($items), 'customers' => count($customers), 'unread' => count(array_filter($items, fn($i) => !$i['seen'])),
            'replied' => count(array_filter($items, fn($i) => $i['replied'])), 'unanswered' => count(array_filter($items, fn($i) => !$i['replied'])),
            'overdue' => count(array_filter($items, fn($i) => $has($i, 'unanswered'))), 'late_reply' => count(array_filter($items, fn($i) => $has($i, 'late_reply'))),
            'earlier' => count(array_filter($items, fn($i) => $i['signals'] !== [] && !$i['review'])),
            'forwarded_open' => count(array_filter($items, fn($i) => $i['decision'] !== null && $i['decision']['code'] === 'forwarded' && !$i['replied'])),
            'review' => count($review), 'waiting' => count(array_filter($review, fn($i) => $i['waiting'])), 'resolved' => count(array_filter($review, fn($i) => !$i['waiting'])),
            'answered' => $decided('answered'), 'not_needed' => $decided('not_needed'), 'forwarded' => $decided('forwarded'),
            'avg_reply_hours' => $avg, 'replies_today' => count($today_replies)];
        $done = $this->wizards->done(self::CODE, $today, self::addDays($today, 1));
        return ['today' => $today, 'now' => $now, 'label' => self::label($today), 'hours' => $hours, 'lookback_days' => $lookback,
            'hours_rule' => $hours . ' h liczone w dniach roboczych (' . self::workdaysLabel($workdays) . ' — weekend się nie liczy, noc tak)',
            'mailbox' => ['owner' => $owner, 'own' => $owner === $userId, 'email' => $box['email'] ?? null, 'display_name' => $box['display_name'] ?? null, 'sync' => $sync],
            'available' => $sync['readable'], 'items' => $items, 'customers' => $customers, 'review' => $review, 'totals' => $totals,
            'can_finish' => $sync['can_finish'] && $totals['waiting'] === 0, 'summary' => self::summary($sync, $hours, $lookback, $totals),
            'decisions' => self::DECISIONS, 'note_required' => self::NOTE_REQUIRED, 'signal_labels' => self::SIGNALS, 'mail_link' => self::MAIL_LINK,
            'users' => $this->wizards->users(), 'positions' => $this->repo->positions(),
            'done' => $done ? ['summary' => $done['summary'], 'at' => self::hm((string) $done['done_at']), 'by' => $done['by_login'] ?? null] : null];
    }

    /** Un e-mail de client : réponse (drapeau, fil), délai, signaux, décision. */
    private function item(array $m, array $erp, array $sent, ?array $r, string $now, string $today, int $hours, array $workdays): array
    {
        $received = (string) $m['sent_at'];
        // Première réponse datée dans le fil : Poczta ERP (n_user_mail_sent) ou dossier Wysłane (postérieure au message).
        $dated = [];
        foreach ($erp as $e) $dated[] = ['source' => 'erp', 'id' => (int) $e['id'], 'at' => (string) $e['created_at'], 'mode' => (string) $e['mode']];
        foreach ($sent as $s) if ((string) $s['sent_at'] >= $received) $dated[] = ['source' => 'sent', 'id' => (int) $s['id'], 'at' => (string) $s['sent_at'], 'mode' => null];
        usort($dated, fn($a, $b) => [$a['at'], $a['id']] <=> [$b['at'], $b['id']]);
        $reply = $dated[0] ?? ((int) $m['answered'] === 1 ? ['source' => 'flag', 'id' => null, 'at' => null, 'mode' => null] : null);
        $replied = $reply !== null;
        $age = self::workHours($received, $now, $workdays);
        $replyHours = $reply !== null && $reply['at'] !== null ? self::workHours($received, $reply['at'], $workdays) : null;
        $deadline = self::deadline($received, $hours, $workdays);

        $signals = [];
        if (!$replied && $age >= $hours) {
            $signals[] = ['code' => 'unanswered', 'label' => self::SIGNALS['unanswered'], 'detail' => 'od ' . self::when($received) . ' — ' . self::hours($age) . ' w dniach roboczych, termin minął '
                . self::when($deadline) . ((int) $m['seen'] === 1 ? '' : ' · nieprzeczytany')];
        } elseif ($replyHours !== null && $replyHours >= $hours && substr((string) $reply['at'], 0, 10) === $today) {
            $signals[] = ['code' => 'late_reply', 'label' => self::SIGNALS['late_reply'], 'detail' => 'odpowiedź ' . self::when((string) $reply['at']) . ' po ' . self::hours($replyHours)
                . ' (termin ' . self::when($deadline) . ')'];
        }

        // « Odpowiedziane » enregistré puis plus aucune réponse (drapeau retiré, réponse supprimée) : décision caduque.
        $stale = $r !== null && $r['decision'] === 'answered' && !$replied;
        $decision = $r !== null ? ['code' => (string) $r['decision'], 'label' => self::DECISIONS[$r['decision']] ?? (string) $r['decision'], 'note' => $r['note'],
            'reply_source' => $r['reply_source'], 'id_responsible' => $r['id_responsible'] !== null ? (int) $r['id_responsible'] : null, 'responsible' => $r['responsible_name'],
            'id_position' => $r['id_position'] !== null ? (int) $r['id_position'] : null, 'position' => $r['position_name'], 'by' => $r['decided_by'], 'stale' => $stale,
            'today' => substr((string) $r['checked_on'], 0, 10) === $today, 'checked_on' => substr((string) $r['checked_on'], 0, 10),
            'at' => self::when((string) $r['updated_at'])] : null;
        $waiting = $signals !== [] && ($decision === null || $stale);
        $from = trim((string) ($m['from_name'] ?? ''));
        return ['id' => (int) $m['id'], 'id_customer' => (int) $m['id_customer'], 'customer' => $m['customer_name'] !== null ? (string) $m['customer_name'] : 'klient #' . (int) $m['id_customer'],
            'from' => $from !== '' ? $from : (string) $m['from_email'], 'from_email' => (string) $m['from_email'], 'subject' => trim((string) ($m['subject'] ?? '')) ?: '(bez tematu)',
            'received_at' => $received, 'received_label' => self::when($received), 'seen' => (int) $m['seen'] === 1, 'answered_flag' => (int) $m['answered'] === 1,
            'replied' => $replied, 'reply' => $reply !== null ? $reply + ['label' => self::REPLY_SOURCES[$reply['source']], 'at_label' => $reply['at'] !== null ? self::when((string) $reply['at']) : null,
                'mode_label' => $reply['mode'] !== null ? (self::MODE_LABELS[$reply['mode']] ?? '') : ''] : null,
            'age_hours' => round($age, 1), 'reply_hours' => $replyHours !== null ? round($replyHours, 1) : null, 'deadline' => $deadline, 'deadline_label' => self::when($deadline),
            'signals' => $signals, 'decision' => $decision,
            'review' => ($signals !== [] && ($decision === null || $stale || $decision['today'])) || ($decision !== null && $decision['today']), 'waiting' => $waiting,
            'link' => ['href' => self::MAIL_LINK . '?id=' . (int) $m['id'], 'label' => 'Otwórz w Poczcie']];
    }

    /** Krok 2 : décision pour un e-mail signalé de la boîte visible (une par message, la dernière compte). */
    public function saveDecision(array $in, int $userId, ?string $now = null): array
    {
        $now ??= date('Y-m-d H:i:s');
        $id = (int) ($in['id_mail'] ?? 0);
        $decision = (string) ($in['decision'] ?? '');
        $note = trim((string) ($in['note'] ?? ''));
        if (!isset(self::DECISIONS[$decision])) throw new CustomException('Wybierz decyzję : odpowiedziane, bez odpowiedzi — nie wymaga albo przekazane.', 'errors', 422);
        if (mb_strlen($note) > 500) throw new CustomException('Notatka : najwyżej 500 znaków.', 'errors', 422);
        if (in_array($decision, self::NOTE_REQUIRED, true) && mb_strlen($note) < 3) {
            throw new CustomException($decision === 'forwarded' ? 'Przekazane : wpisz notatkę (komu, kiedy, co dalej).'
                : 'Bez odpowiedzi — nie wymaga : wpisz notatkę (dlaczego e-mail nie wymaga odpowiedzi).', 'errors', 422);
        }
        $responsible = $decision === 'forwarded' ? ((int) ($in['id_responsible'] ?? 0) ?: null) : null;
        $position = $decision === 'forwarded' ? ((int) ($in['id_position'] ?? 0) ?: null) : null;
        if ($responsible !== null && !in_array($responsible, array_column($this->wizards->users(), 'id'), true)) throw new CustomException('Nieznana osoba.', 'errors', 422);
        if ($position !== null && !in_array($position, array_column($this->repo->positions(), 'id'), true)) throw new CustomException('Nieznane stanowisko.', 'errors', 422);
        $d = $this->data($userId, $now);
        // Seulement un message de la boîte que la personne lit dans Poczta, et signalé (ou déjà décidé aujourd'hui).
        $m = array_values(array_filter($d['review'], fn($x) => $x['id'] === $id))[0] ?? null;
        if ($m === null) throw new CustomException('Tej wiadomości nie ma na liście do wyjaśnienia (odpowiedziana w terminie, spoza Twojej skrzynki albo spoza sprawdzanych dni).', 'errors', 422);
        if ($decision === 'answered' && !$m['replied']) {
            throw new CustomException('Odpowiedziane : wiadomość nie jest jeszcze oznaczona jako odpowiedziana i w wątku nie ma wysłanej odpowiedzi. Odpowiedz w Poczcie — asystent niczego nie wysyła.', 'errors', 422);
        }
        $this->repo->saveDecision($m['id'], $d['mailbox']['owner'], $d['today'], $decision, $note !== '' ? $note : null,
            $decision === 'answered' ? $m['reply']['source'] : null, $decision === 'answered' ? $m['reply']['id'] : null, $responsible, $position,
            implode(',', array_column($m['signals'], 'code')), $userId);
        return $this->data($userId, $now);
    }

    /** Krok 3 : « Zakończ » — chaque e-mail signalé a sa décision ; la tâche du jour se valide seule. */
    public function finish(int $userId, ?string $now = null): array
    {
        $now ??= date('Y-m-d H:i:s');
        $d = $this->data($userId, $now);
        if (!$d['mailbox']['sync']['can_finish']) throw new CustomException('Nie można zakończyć : ' . $d['mailbox']['sync']['label'] . '. ' . $d['mailbox']['sync']['detail'], 'errors', 422);
        $w = $d['totals']['waiting'];
        if ($w > 0) throw new CustomException('Najpierw zapisz decyzję dla ' . $w . ' ' . self::pl($w, 'e-maila', 'e-maili', 'e-maili') . ' z kroku 2.', 'errors', 422);
        $this->wizards->markDone(self::CODE, $d['today'], $d['summary'], $userId);
        return ['summary' => $d['summary'], 'data' => $this->data($userId, $now)];
    }

    /** « synchronizacja OK · 12 e-maili od 5 klientów (30 dni) · 3 bez odpowiedzi po 24 h · … · śr. odpowiedź dziś 5,2 h (4) · 4 do wyjaśnienia : … ». */
    public static function summary(array $sync, int $hours, int $lookback, array $t): string
    {
        // Sans boîte lisible (ou jamais synchronisée), aucun compte n'est donné : « 0 e-mail » serait faux.
        if (!$sync['readable'] || $sync['code'] === 'never') return $sync['label'] . ' — e-maile od klientów nie sprawdzone';
        $s = $sync['label'] . ($sync['reliable'] ? '' : ' (wynik niepełny)') . ' · ' . $t['mails'] . ' ' . self::pl($t['mails'], 'e-mail', 'e-maile', 'e-maili') . ' od ' . $t['customers'] . ' '
            . self::pl($t['customers'], 'klienta', 'klientów', 'klientów') . ' (' . $lookback . ' dni)';
        if ($t['overdue']) $s .= ' · ' . $t['overdue'] . ' bez odpowiedzi po ' . $hours . ' h';
        if ($t['late_reply']) $s .= ' · ' . $t['late_reply'] . ' ' . self::pl($t['late_reply'], 'odpowiedź', 'odpowiedzi', 'odpowiedzi') . ' po terminie';
        if ($t['avg_reply_hours'] !== null) $s .= ' · śr. odpowiedź dziś ' . str_replace('.', ',', (string) $t['avg_reply_hours']) . ' h (' . $t['replies_today'] . ')';
        if ($t['review'] === 0) return $s . ' · nic do wyjaśnienia';
        $s .= ' · ' . $t['review'] . ' do wyjaśnienia';
        $p = [];
        if ($t['answered']) $p[] = $t['answered'] . ' ' . self::pl($t['answered'], 'odpowiedziany', 'odpowiedziane', 'odpowiedzianych');
        if ($t['not_needed']) $p[] = $t['not_needed'] . ' nie wymaga';
        if ($t['forwarded']) $p[] = $t['forwarded'] . ' ' . self::pl($t['forwarded'], 'przekazany', 'przekazane', 'przekazanych');
        return $p ? $s . ' : ' . implode(' · ', $p) : $s;
    }

    public static function label(string $day): string
    {
        $t = strtotime($day);
        return $t === false ? $day : self::DAY_ABBR[(int) date('N', $t)] . ' ' . date('j.m', $t);
    }

    /** « wt. 24.11, 9:05 ». */
    private static function when(string $ts): string
    {
        return strlen($ts) >= 16 ? self::label(substr($ts, 0, 10)) . ', ' . self::hm($ts) : $ts;
    }

    private static function hours(float $h): string
    {
        return str_replace('.', ',', (string) round($h, 1)) . ' h' . ($h >= 48 ? ' (' . (int) floor($h / 24) . ' dni rob.)' : '');
    }

    private static function ago(int $minutes): string
    {
        return $minutes < 120 ? $minutes . ' min' : ($minutes < 2880 ? (int) floor($minutes / 60) . ' h' : (int) floor($minutes / 1440) . ' dni');
    }

    private static function workdaysLabel(array $days): string
    {
        return implode(', ', array_map(fn($d) => self::DAY_ABBR[$d], $days));
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
