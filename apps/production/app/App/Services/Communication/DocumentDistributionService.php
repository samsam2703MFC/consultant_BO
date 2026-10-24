<?php
namespace App\Services\Communication;

use App\Exceptions\CustomException;
use App\Repositories\Communication\DocumentDistributionRepository;
use App\Services\Utils\DocumentLabels;

/**
 * Dystrybucja dokumentów : kto dostaje który dokument, jak (e-mail na adres z karty klienta albo inny, papier z dostawą
 * w N egzemplarzach, SMS) i kiedy (moment wspólny dla wszystkich klientów). Na tej podstawie plik dostawy zawiera tylko
 * papier klienta, a e-maile automatyczne (MailAutomationService) wysyłają resztę — operator niczego nie wybiera.
 */
final class DocumentDistributionService
{
    public const DEFAULTS = 0;
    public const ACCOUNTING = -1;
    public const RECIPIENTS = ['', 'head', 'acc', 'sales', 'other'];
    public const MAX_COPIES = 9;

    /**
     * Kolumny tabeli : etykieta, dozwolone momenty, papier z dostawą, SMS, kopia dla biura rachunkowego,
     * nazwa w e-mailu (język klienta).
     */
    public const DOCS = [
        'proforma' => ['label' => 'Proforma', 'moments' => ['order_received', 'proforma_issued'], 'accounting' => true,
            'names' => ['fr' => 'Facture proforma', 'nl' => 'Proforma-factuur', 'pl' => 'Proforma', 'en' => 'Proforma invoice']],
        'wdt' => ['label' => 'Faktura WDT', 'moments' => ['wdt_ksef_accepted', 'wdt_issued'], 'paper' => true, 'accounting' => true,
            'names' => ['fr' => 'Facture (WDT)', 'nl' => 'Factuur (WDT)', 'pl' => 'Faktura WDT', 'en' => 'Invoice (WDT)']],
        'invoice' => ['label' => 'Faktura', 'moments' => ['invoice_issued'], 'accounting' => true,
            'names' => ['fr' => 'Facture', 'nl' => 'Factuur', 'pl' => 'Faktura', 'en' => 'Invoice']],
        'wz' => ['label' => 'WZ', 'moments' => ['shipment_sent'], 'paper' => true,
            'names' => ['fr' => 'Bon de livraison (WZ)', 'nl' => 'Leveringsbon (WZ)', 'pl' => 'WZ', 'en' => 'Delivery note (WZ)']],
        'cmr' => ['label' => 'CMR', 'moments' => ['shipment_sent'], 'paper' => true,
            'names' => ['fr' => 'Lettre de voiture CMR', 'nl' => 'Vrachtbrief CMR', 'pl' => 'CMR', 'en' => 'CMR consignment note']],
        'diff' => ['label' => 'Różnice', 'moments' => ['difference_issued'], 'paper' => true,
            'names' => ['fr' => 'Écarts commande / facture', 'nl' => 'Verschillen bestelling / factuur', 'pl' => 'Różnice zamówienie / faktura', 'en' => 'Order / invoice differences']],
        'ppwr' => ['label' => 'Opakowania PPWR', 'moments' => ['shipment_sent'], 'paper' => true,
            'names' => ['fr' => 'Emballages (PPWR)', 'nl' => 'Verpakkingen (PPWR)', 'pl' => 'Opakowania (PPWR)', 'en' => 'Packaging (PPWR)']],
        'notice' => ['label' => 'Powiadomienie o dostawie', 'moments' => ['delivery_planned'], 'sms' => true,
            'names' => ['fr' => 'Avis de livraison', 'nl' => 'Leveringsbericht', 'pl' => 'Powiadomienie o dostawie', 'en' => 'Delivery notice']],
        'price' => ['label' => 'Cennik', 'moments' => ['price_list_changed'],
            'names' => ['fr' => 'Tarif', 'nl' => 'Prijslijst', 'pl' => 'Cennik', 'en' => 'Price list']],
        'summary' => ['label' => 'Podsumowanie sprzedaży', 'moments' => ['month_start'], 'franchise' => true,
            'names' => ['fr' => 'Récapitulatif des ventes', 'nl' => 'Verkoopoverzicht', 'pl' => 'Podsumowanie sprzedaży', 'en' => 'Sales summary']],
        'goal' => ['label' => 'Cel obrotu', 'moments' => ['month_start'], 'franchise' => true,
            'names' => ['fr' => 'Objectif de chiffre d\'affaires', 'nl' => 'Omzetdoel', 'pl' => 'Cel obrotu', 'en' => 'Revenue target']],
    ];

    /** Moment wysłania (nagłówek « Kiedy ») = zdarzenie reguły e-maili. */
    public const MOMENTS = [
        'order_received' => 'po zamówieniu',
        'proforma_issued' => 'po wystawieniu proformy',
        'wdt_issued' => 'po wystawieniu WDT',
        'wdt_ksef_accepted' => 'po przyjęciu w KSeF',
        'invoice_issued' => 'po wystawieniu',
        'shipment_sent' => 'z dostawą',
        'difference_issued' => 'po WDT (gdy ≠ 0)',
        'delivery_planned' => 'dzień przed dostawą',
        'price_list_changed' => 'przy zmianie cen',
        'month_start' => '1. dnia miesiąca',
    ];

    public const RECIPIENT_LABELS = ['head' => 'adres główny', 'acc' => 'księgowość', 'sales' => 'handlowiec', 'other' => 'inny adres'];
    public const RECIPIENT_SHORT = ['head' => 'gł.', 'acc' => 'ks.', 'sales' => 'hand.', 'other' => 'inny'];

    public function __construct(
        private DocumentDistributionRepository $repo,
        private MailEventSource $events,
    ) {
    }

    /** Cała tabela : ustawienia domyślne, biuro rachunkowe, klienci (z adresami i ostrzeżeniami). */
    public function matrix(): array
    {
        $cells = $this->repo->cells();
        $moments = $this->moments();
        $rows = [
            $this->row(['id' => self::DEFAULTS, 'company' => 'Domyślnie dla nowych klientów', 'communication_lang' => '', 'franchise' => 0], $cells[self::DEFAULTS] ?? [], false),
            $this->row(['id' => self::ACCOUNTING, 'company' => 'Biuro rachunkowe SL Food (kopia)', 'communication_lang' => 'pl', 'franchise' => 0], $cells[self::ACCOUNTING] ?? [], false),
        ];
        foreach ($this->repo->customers() as $c) {
            $own = $cells[(int) $c['id']] ?? [];
            $rows[] = $this->row($c, $own ?: $this->defaultsFor($c, $cells[self::DEFAULTS] ?? []), $own === []);
        }
        $docs = [];
        foreach (self::DOCS as $key => $d) {
            $docs[] = ['key' => $key, 'label' => $d['label'], 'moment' => $moments[$key], 'moments' => array_intersect_key(self::MOMENTS, array_flip($d['moments'])),
                'paper' => !empty($d['paper']), 'sms' => !empty($d['sms']), 'accounting' => !empty($d['accounting'])];
        }
        return ['docs' => $docs, 'rows' => $rows, 'moment_labels' => self::MOMENTS];
    }

    /** Zapis komórki : klient bez własnych ustawień najpierw dostaje kopię domyślnych. */
    public function saveCell(int $customerId, string $doc, array $input, ?string $author): array
    {
        $spec = self::DOCS[$doc] ?? throw new CustomException('Nieznany dokument.', 'errors', 422);
        $mailTo = (string) ($input['mail_to'] ?? '');
        if (!in_array($mailTo, self::RECIPIENTS, true)) throw new CustomException('Nieprawidłowy odbiorca.', 'errors', 422);
        $address = trim((string) ($input['mail_address'] ?? ''));
        if ($mailTo !== 'other') $address = '';
        if ($address !== '' && !filter_var($address, FILTER_VALIDATE_EMAIL)) throw new CustomException('Nieprawidłowy adres e-mail.', 'errors', 422);
        $paper = filter_var($input['paper_copies'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => self::MAX_COPIES]]);
        if ($paper === false) throw new CustomException('Liczba egzemplarzy : od 0 do ' . self::MAX_COPIES . '.', 'errors', 422);
        if (empty($spec['paper'])) $paper = 0;
        $sms = !empty($spec['sms']) && !empty($input['sms']);

        if ($customerId === self::ACCOUNTING) {
            if (empty($spec['accounting'])) throw new CustomException('Biuro rachunkowe dostaje tylko proformy i faktury.', 'errors', 422);
            if (!in_array($mailTo, ['', 'other'], true)) throw new CustomException('Biuro rachunkowe : tylko własny adres.', 'errors', 422);
            $paper = 0;
            $sms = false;
        } elseif ($customerId !== self::DEFAULTS) {
            $client = $this->repo->customers($customerId)[0] ?? throw new CustomException('Nie znaleziono klienta.', 'errors', 404);
            $this->materialize($client, $author);
        }
        if ($mailTo === 'other' && $address === '' && $customerId !== self::ACCOUNTING) throw new CustomException('Podaj adres e-mail.', 'errors', 422);
        $this->repo->saveCell($customerId, $doc, $mailTo, $address === '' ? null : $address, $paper, $sms, $author);
        return $this->rowFor($customerId);
    }

    /** Moment wspólny dla dokumentu ; reguły e-maili włączone dokładnie dla używanych momentów (od teraz, bez historii). */
    public function saveMoment(string $doc, string $moment, ?string $author): array
    {
        $spec = self::DOCS[$doc] ?? throw new CustomException('Nieznany dokument.', 'errors', 422);
        if (!in_array($moment, $spec['moments'], true)) throw new CustomException('Ten moment nie jest dostępny dla dokumentu.', 'errors', 422);
        $this->repo->saveMoment($doc, $moment, $author);
        $this->syncRules($author);
        return $this->moments();
    }

    /** Kopiuje ustawienia jednego klienta (albo domyślne) do innych klientów. */
    public function copyClient(int $fromId, array $toIds, ?string $author): int
    {
        if ($fromId === self::ACCOUNTING) throw new CustomException('Nie można kopiować ustawień biura rachunkowego.', 'errors', 422);
        $source = $this->forCustomer($fromId);
        $count = 0;
        foreach (array_unique(array_map('intval', $toIds)) as $id) {
            if ($id <= 0 || $id === $fromId) continue;
            if (!$this->repo->customers($id)) continue;
            foreach ($source as $doc => $cell) {
                $this->repo->saveCell($id, $doc, $cell['mail_to'], $cell['mail_address'], $cell['paper_copies'], $cell['sms'], $author);
            }
            $count++;
        }
        return $count;
    }

    /** @return array<string, array{mail_to:string, mail_address:?string, paper_copies:int, sms:bool}> ustawienia klienta albo domyślne */
    public function forCustomer(int $customerId): array
    {
        $cells = $this->repo->cells($customerId);
        if ($customerId === self::DEFAULTS || $customerId === self::ACCOUNTING) return $this->normalize($cells[$customerId] ?? []);
        $own = $cells[$customerId] ?? [];
        if ($own) return $this->normalize($own);
        $client = $this->repo->customers($customerId)[0] ?? ['franchise' => 0];
        return $this->normalize($this->defaultsFor($client, $cells[self::DEFAULTS] ?? []));
    }

    /** @return array<string, int> liczba egzemplarzy papieru z dostawą (wz, cmr, wdt, diff, ppwr) */
    public function paperCopies(int $customerId): array
    {
        $copies = [];
        foreach ($this->forCustomer($customerId) as $doc => $cell) {
            if (!empty(self::DOCS[$doc]['paper'])) $copies[$doc] = $cell['paper_copies'];
        }
        return $copies;
    }

    /** @return array<string, string> doc_key => moment */
    public function moments(): array
    {
        $saved = $this->repo->moments();
        $moments = [];
        foreach (self::DOCS as $key => $d) {
            $moments[$key] = in_array($saved[$key] ?? '', $d['moments'], true) ? $saved[$key] : $d['moments'][0];
        }
        return $moments;
    }

    /**
     * Co wysłać przy zdarzeniu : e-maile klienta pogrupowane po adresie (wszystkie dokumenty tego momentu w jednej
     * wiadomości), kopie dla biura rachunkowego (po jednej na dokument), SMS i dokumenty bez adresu.
     *
     * @param list<string> $available dokumenty, które istnieją dla tego zdarzenia
     * @return array{client: list<array{address:string, docs:list<string>}>, accounting: list<array{address:string, doc:string}>,
     *               sms: bool, unreachable: array<string,string>, docs: list<string>}
     */
    public function plan(string $event, int $customerId, array $emails, array $available): array
    {
        $moments = $this->moments();
        $docs = array_values(array_filter($available, fn($d) => isset(self::DOCS[$d]) && $moments[$d] === $event));
        $cells = $this->forCustomer($customerId);
        $accounting = $this->forCustomer(self::ACCOUNTING);
        $byAddress = [];
        $plan = ['client' => [], 'accounting' => [], 'sms' => false, 'unreachable' => [], 'docs' => $docs];
        foreach ($docs as $doc) {
            $cell = $cells[$doc] ?? null;
            if ($cell && $cell['mail_to'] !== '') {
                $address = self::address($cell['mail_to'], $cell['mail_address'], $emails);
                if ($address === '') {
                    $plan['unreachable'][$doc] = self::RECIPIENT_LABELS[$cell['mail_to']];
                } else {
                    $byAddress[strtolower($address)] ??= ['address' => $address, 'docs' => []];
                    $byAddress[strtolower($address)]['docs'][] = $doc;
                }
            }
            if ($cell && $cell['sms']) $plan['sms'] = true;
            $copy = $accounting[$doc] ?? null;
            if ($copy && $copy['mail_to'] === 'other' && !empty(self::DOCS[$doc]['accounting'])) {
                $plan['accounting'][] = ['address' => (string) ($copy['mail_address'] ?? ''), 'doc' => $doc];
            }
        }
        $plan['client'] = array_values($byAddress);
        return $plan;
    }

    /**
     * Logistyka › Dokumenty dostawy : dla każdej wysyłki dnia papier dla kierowcy (egzemplarze z tabeli) i e-maile
     * automatyczne z ich stanem (w kolejce, pominięte z powodem, czeka na moment, np. przyjęcie w KSeF).
     */
    public function shipmentDay(?string $date): array
    {
        $days = $this->repo->shipmentDays();
        if ($date === null || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !strtotime($date)) {
            $date = isset($days[date('Y-m-d')]) || !$days ? date('Y-m-d') : (string) array_key_first($days);
        }
        $moments = $this->moments();
        $rules = $this->repo->distributionRules();
        $shipments = $this->repo->shipmentsOfDay($date);
        $keys = [];
        foreach ($shipments as $s) $keys = array_merge($keys, array_values(self::shipmentKeys($s, $date)));
        $dispatches = $this->repo->dispatchesFor(array_values(array_unique($keys)));
        $rows = [];
        $totalCopies = 0;
        foreach ($shipments as $s) {
            $cells = $this->forCustomer((int) $s['id_customer']);
            $client = $this->repo->customers((int) $s['id_customer'])[0] ?? [];
            $emails = ['head' => self::clean($client['email_head'] ?? ''), 'accountant' => self::clean($client['email_accountant'] ?? ''), 'salesman' => self::clean($client['email_salesman'] ?? '')];
            $available = ['wz' => true, 'cmr' => (bool) $s['id_cmr'], 'wdt' => true, 'ppwr' => true, 'diff' => (bool) $s['has_difference'], 'notice' => true];
            $paper = [];
            foreach (['wz', 'cmr', 'wdt', 'ppwr', 'diff'] as $doc) {
                $n = $cells[$doc]['paper_copies'];
                if ($n > 0 && ($available[$doc] || $doc === 'diff' && $s['diff_rows'])) { $paper[] = ['doc' => $doc, 'label' => self::DOCS[$doc]['label'], 'copies' => $n]; $totalCopies += $n; }
            }
            $keysFor = self::shipmentKeys($s, $date);
            $mails = [];
            foreach ($available as $doc => $exists) {
                $cell = $cells[$doc];
                if (!$exists || ($cell['mail_to'] === '' && !$cell['sms'])) continue;
                $moment = $moments[$doc];
                $key = $keysFor[$moment] ?? null;
                $d = $key !== null ? ($dispatches[$key] ?? null) : null;
                // événement antérieur à l'activation de l'envoi automatique : jamais envoyé (pas d'historique)
                $since = $rules[$moment]['enabled_at'] ?? null;
                $eventAt = $moment === 'wdt_ksef_accepted' ? $s['ksef_at'] : $s['insert_timestamp'];
                $state = match (true) {
                    $d !== null && $d['status'] === 'queued' => $d['message_status'] === 'sent' ? (str_starts_with((string) $d['provider_message_id'], 'LOG_ONLY:') ? 'logged' : 'sent') : 'queued',
                    $d !== null && $d['status'] === 'failed' => 'failed',
                    $d !== null => 'skipped',
                    empty($rules[$moment]['enabled']) => 'off',
                    $moment === 'wdt_ksef_accepted' && $s['ksef_status'] !== 'accepted' => 'ksef',
                    $since !== null && $eventAt !== null && $moment !== 'delivery_planned' && strtotime((string) $eventAt) < strtotime((string) $since) => 'before',
                    $moment === 'delivery_planned' && $since !== null && $date < substr((string) $since, 0, 10) => 'before',
                    default => 'pending',
                };
                $address = $cell['mail_to'] !== '' ? self::address($cell['mail_to'], $cell['mail_address'], $emails) : '';
                $mails[] = [
                    'doc' => $doc, 'label' => self::DOCS[$doc]['label'], 'moment' => self::MOMENTS[$moment],
                    'to' => $cell['mail_to'] !== '' ? self::RECIPIENT_LABELS[$cell['mail_to']] : null, 'address' => $address, 'sms' => $cell['sms'],
                    'state' => $cell['mail_to'] !== '' && $address === '' && $state === 'pending' ? 'no_address' : $state,
                    'reason' => $d['reason'] ?? null, 'dispatch_id' => $d['id'] ?? null, 'id_message' => $d['id_message'] ?? null, 'ksef_status' => $s['ksef_status'],
                ];
            }
            $rows[] = ['id' => (int) $s['id'], 'customer' => (int) $s['id_customer'], 'company' => $s['company'], 'wz_number' => $s['wz_number'], 'wdt_number' => $s['wdt_number'],
                'paper' => $paper, 'mails' => $mails];
        }
        return ['date' => $date, 'days' => $days, 'shipments' => $rows, 'total_copies' => $totalCopies];
    }

    /** Clé d'événement (n_mail_dispatch.ref_key) de chaque moment pour une expédition. */
    private static function shipmentKeys(array $s, string $date): array
    {
        return [
            'shipment_sent' => "shipment:{$s['id']}",
            'wdt_ksef_accepted' => "wdtksef:{$s['id_wdt']}",
            'wdt_issued' => "wdt:{$s['id_wdt']}",
            'difference_issued' => "diff:{$s['id_diff']}",
            'delivery_planned' => "delivery:{$s['id_customer']}:{$date}",
        ];
    }

    /** Adres e-mail według wyboru (z zapasowym adresem z karty klienta, jak dotąd w regułach). */
    public static function address(string $mailTo, ?string $other, array $emails): string
    {
        $address = match ($mailTo) {
            'head' => ($emails['head'] ?? '') ?: ($emails['salesman'] ?? ''),
            'acc' => ($emails['accountant'] ?? '') ?: ($emails['head'] ?? ''),
            'sales' => ($emails['salesman'] ?? '') ?: ($emails['head'] ?? ''),
            'other' => trim((string) $other),
            default => '',
        };
        return filter_var($address, FILTER_VALIDATE_EMAIL) ? $address : '';
    }

    /** Nazwy dokumentów w języku klienta (« Documents joints : … »). */
    public static function names(array $docs, string $lang): string
    {
        $lang = DocumentLabels::lang($lang, 'fr');
        return implode(', ', array_map(fn($d) => self::DOCS[$d]['names'][$lang] ?? self::DOCS[$d]['label'], $docs));
    }

    private function syncRules(?string $author): void
    {
        $used = array_flip($this->moments());
        foreach ($this->repo->distributionRules() as $event => $rule) {
            $want = isset($used[$event]);
            if ($want && !$rule['enabled']) $this->repo->setRuleEnabled((int) $rule['id'], true, $this->events->lastRefId($event), $author);
            if (!$want && $rule['enabled']) $this->repo->setRuleEnabled((int) $rule['id'], false, null, $author);
        }
    }

    /** Klient bez własnych wierszy dostaje kopię ustawień domyślnych (potem zmienia się tylko jego komórka). */
    private function materialize(array $client, ?string $author): void
    {
        $cells = $this->repo->cells((int) $client['id']);
        if (!empty($cells[(int) $client['id']])) return;
        foreach ($this->normalize($this->defaultsFor($client, $cells[self::DEFAULTS] ?? [])) as $doc => $cell) {
            $this->repo->saveCell((int) $client['id'], $doc, $cell['mail_to'], $cell['mail_address'], $cell['paper_copies'], $cell['sms'], $author);
        }
    }

    /** Ustawienia domyślne dla klienta : podsumowanie sprzedaży i cel obrotu tylko dla franczyzobiorców. */
    private function defaultsFor(array $client, array $defaults): array
    {
        foreach (self::DOCS as $doc => $spec) {
            if (empty($spec['franchise'])) continue;
            $defaults[$doc] = ['mail_to' => !empty($client['franchise']) ? (($defaults[$doc]['mail_to'] ?? '') ?: 'head') : '', 'mail_address' => null, 'paper_copies' => 0, 'sms' => 0];
        }
        return $defaults;
    }

    private function normalize(array $cells): array
    {
        $out = [];
        foreach (self::DOCS as $doc => $spec) {
            $c = $cells[$doc] ?? [];
            $out[$doc] = [
                'mail_to' => in_array($c['mail_to'] ?? '', self::RECIPIENTS, true) ? (string) ($c['mail_to'] ?? '') : '',
                'mail_address' => ($c['mail_address'] ?? '') !== '' ? (string) $c['mail_address'] : null,
                'paper_copies' => !empty($spec['paper']) ? max(0, min(self::MAX_COPIES, (int) ($c['paper_copies'] ?? 0))) : 0,
                'sms' => !empty($spec['sms']) && !empty($c['sms']),
            ];
        }
        return $out;
    }

    private function rowFor(int $customerId): array
    {
        $cells = $this->repo->cells($customerId);
        if ($customerId === self::DEFAULTS) return $this->row(['id' => 0, 'company' => 'Domyślnie dla nowych klientów', 'communication_lang' => '', 'franchise' => 0], $cells[0] ?? [], false);
        if ($customerId === self::ACCOUNTING) return $this->row(['id' => -1, 'company' => 'Biuro rachunkowe SL Food (kopia)', 'communication_lang' => 'pl', 'franchise' => 0], $cells[-1] ?? [], false);
        $client = $this->repo->customers($customerId)[0] ?? throw new CustomException('Nie znaleziono klienta.', 'errors', 404);
        $own = $cells[$customerId] ?? [];
        return $this->row($client, $own ?: $this->defaultsFor($client, $cells[0] ?? []), $own === []);
    }

    /** Wiersz tabeli z rozwiązanymi adresami i podsumowaniem (papier, e-maile automatyczne, brakujące adresy). */
    private function row(array $client, array $cells, bool $inherited): array
    {
        $id = (int) $client['id'];
        $isClient = $id > 0;
        $emails = [
            'head' => self::clean($client['email_head'] ?? ''), 'accountant' => self::clean($client['email_accountant'] ?? ''), 'salesman' => self::clean($client['email_salesman'] ?? ''),
        ];
        $hasPhone = trim((string) ($client['phone_head'] ?? '')) !== '' || trim((string) ($client['phone_alt'] ?? '')) !== '';
        $out = [];
        $paper = 0;
        $mails = 0;
        $problems = 0;
        foreach ($this->normalize($cells) as $doc => $cell) {
            $resolved = null;
            $problem = null;
            $fallback = false;
            if ($cell['mail_to'] !== '') {
                $mails++;
                if ($isClient) {
                    $resolved = self::address($cell['mail_to'], $cell['mail_address'], $emails);
                    $direct = ['head' => $emails['head'], 'acc' => $emails['accountant'], 'sales' => $emails['salesman'], 'other' => $resolved][$cell['mail_to']];
                    $fallback = $resolved !== '' && $direct === '';
                    if ($resolved === '') { $problem = 'Brak adresu e-mail w karcie klienta.'; $problems++; }
                } elseif ($id === self::ACCOUNTING) {
                    $resolved = $cell['mail_address'] ?? '';
                }
            }
            if ($cell['sms'] && $isClient && !$hasPhone) { $problem = trim(($problem ?? '') . ' Brak telefonu do SMS.'); $problems++; }
            $paper += $cell['paper_copies'];
            $out[$doc] = $cell + ['address' => $resolved, 'fallback' => $fallback, 'problem' => $problem];
        }
        return [
            'id' => $id, 'name' => (string) $client['company'], 'lang' => strtoupper((string) ($client['communication_lang'] ?? '')),
            'franchise' => !empty($client['franchise']), 'inherited' => $inherited, 'special' => !$isClient,
            'emails' => ['head' => $emails['head'] !== '', 'acc' => $emails['accountant'] !== '', 'sales' => $emails['salesman'] !== ''], 'phone' => $hasPhone,
            'cells' => $out, 'paper' => $paper, 'mails' => $mails, 'problems' => $problems,
        ];
    }

    private static function clean(?string $value): string
    {
        $value = trim((string) $value);
        return filter_var($value, FILTER_VALIDATE_EMAIL) ? $value : '';
    }
}
