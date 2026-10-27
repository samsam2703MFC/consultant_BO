<?php
declare(strict_types=1);

namespace App\Services\Logistics;

use App\Exceptions\CustomException;
use App\Repositories\Client\ClientRepository;
use App\Repositories\Communication\CommunicationTemplateRepository;
use App\Repositories\Company\ErpCompanyDataRepository;
use App\Repositories\Logistics\DeliveryNoticeRepository;
use App\Services\Communication\OutgoingMessageService;
use App\Services\Communication\TemplateRenderer;
use App\Services\Logistics\Sms\SmsTransport;
use App\Services\Utils\DocumentLabels;

/**
 * Powiadomienia klientów o dostawie : dane z Załadunku dnia (trasa, przystanek, pojazd, kierowca i jego telefon,
 * palety, kartony, wyjazd) + godzina przyjazdu podana przez logistykę, w języku klienta (karta klienta).
 * E-mail idzie przez kolejkę wiadomości (zasady wysyłki MAIL_DELIVERY_MODE), SMS przez SmsTransport.
 */
class DeliveryNoticeService
{
    private const LINES = [
        'fr' => ['pallets' => 'Palettes', 'cartons' => 'Cartons', 'weight' => 'Poids', 'tour' => 'Tournée', 'stop' => 'arrêt %d sur %d', 'vehicle' => 'Camion',
            'driver' => 'Chauffeur', 'phone' => 'tél.', 'departure' => 'Départ de notre atelier', 'eta' => 'Arrivée prévue', 'at' => 'à', 'orders' => 'Commandes'],
        'nl' => ['pallets' => 'Pallets', 'cartons' => 'Dozen', 'weight' => 'Gewicht', 'tour' => 'Ronde', 'stop' => 'stop %d van %d', 'vehicle' => 'Vrachtwagen',
            'driver' => 'Chauffeur', 'phone' => 'tel.', 'departure' => 'Vertrek uit ons atelier', 'eta' => 'Verwachte aankomst', 'at' => 'om', 'orders' => 'Bestellingen'],
        'pl' => ['pallets' => 'Palety', 'cartons' => 'Kartony', 'weight' => 'Waga', 'tour' => 'Trasa', 'stop' => 'przystanek %d z %d', 'vehicle' => 'Pojazd',
            'driver' => 'Kierowca', 'phone' => 'tel.', 'departure' => 'Wyjazd z pracowni', 'eta' => 'Planowany przyjazd', 'at' => 'o', 'orders' => 'Zamówienia'],
        'en' => ['pallets' => 'Pallets', 'cartons' => 'Cartons', 'weight' => 'Weight', 'tour' => 'Route', 'stop' => 'stop %d of %d', 'vehicle' => 'Truck',
            'driver' => 'Driver', 'phone' => 'phone', 'departure' => 'Departure from our workshop', 'eta' => 'Expected arrival', 'at' => 'at', 'orders' => 'Orders'],
    ];
    private const SMS = [
        'fr' => ['pal' => 'pal.', 'crt' => 'crt', 'eta' => 'arrivée', 'driver' => 'chauffeur', 'truck' => 'camion'],
        'nl' => ['pal' => 'pal.', 'crt' => 'dozen', 'eta' => 'aankomst', 'driver' => 'chauffeur', 'truck' => 'wagen'],
        'pl' => ['pal' => 'pal.', 'crt' => 'krt', 'eta' => 'przyjazd', 'driver' => 'kierowca', 'truck' => 'pojazd'],
        'en' => ['pal' => 'pal.', 'crt' => 'ctn', 'eta' => 'arrival', 'driver' => 'driver', 'truck' => 'truck'],
    ];
    private const DAYS = [
        'fr' => ['lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi', 'dimanche'],
        'nl' => ['maandag', 'dinsdag', 'woensdag', 'donderdag', 'vrijdag', 'zaterdag', 'zondag'],
        'pl' => ['poniedziałek', 'wtorek', 'środa', 'czwartek', 'piątek', 'sobota', 'niedziela'],
        'en' => ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'],
    ];
    /** Wyjazd od tej godziny = dostawa następnego dnia (domyślnie ; logistyka może zmienić datę). */
    private const NEXT_DAY_FROM = '16:00';

    public function __construct(
        private LogisticsService $logistics,
        private ClientRepository $clients,
        private CommunicationTemplateRepository $templates,
        private TemplateRenderer $renderer,
        private OutgoingMessageService $outgoing,
        private SmsTransport $sms,
        private DeliveryNoticeRepository $notices,
        private ErpCompanyDataRepository $company,
    ) {
    }

    /**
     * Klienci dnia wysyłki z danymi dostawy, kontaktami, podglądem e-maila i SMS i ostatnimi powiadomieniami.
     * $overrides : id klienta => ['arrival_date' => Y-m-d, 'eta' => tekst].
     */
    public function day(string $date, array $overrides = []): array
    {
        $day = $this->logistics->day($date);
        $last = $this->notices->lastByCustomer($date);
        $rows = [];
        foreach ($day['customers'] as $c) {
            $rows[] = $this->customerNotice($day, $c, $overrides[$c['id']] ?? []) + ['last' => $last[$c['id']] ?? []];
        }
        return [
            'date' => $date,
            'dates' => $day['dates'],
            'customers' => $rows,
            'sms_provider' => $this->sms->name(),
            'sms_delivers' => $this->sms->delivers(),
            'history' => $this->notices->forDay($date),
        ];
    }

    /**
     * Wysyła powiadomienia : $items = [['id' => klient, 'mail' => bool, 'sms' => bool, 'arrival_date' => …, 'eta' => …, 'resend' => bool], …].
     * Jedno powiadomienie na klienta, dzień i kanał : automat (Dystrybucja dokumentów) i ta strona nie wysyłają dwa razy ;
     * drugi raz tylko z « resend » (strona : « wyślij ponownie »), nigdy z automatu. Błąd poprzedniego nie blokuje.
     * @return array{sent: list<array>, problems: list<string>}
     */
    public function send(string $date, array $items, ?string $author): array
    {
        $day = $this->logistics->day($date);
        $last = $this->notices->lastByCustomer($date);
        $automatic = str_starts_with((string) $author, 'automat:');
        $sent = [];
        $problems = [];
        foreach ($items as $item) {
            $id = (int) ($item['id'] ?? 0);
            $c = $day['by_id'][$id] ?? null;
            if ($c === null) { $problems[] = "Klient {$id} nie ma dostawy {$date}."; continue; }
            $n = $this->customerNotice($day, $c, $item);
            // adresse imposée (Dystrybucja dokumentów : principal, comptabilité, commercial ou autre)
            if (filter_var(trim((string) ($item['email'] ?? '')), FILTER_VALIDATE_EMAIL)) $n['email'] = trim((string) $item['email']);
            foreach (self::alreadySent($last[$id] ?? [], $item, $automatic) as $channel => $previous) {
                $item[$channel] = false;
                $problems[] = "{$c['name']} : " . ($channel === 'sms' ? 'SMS' : 'e-mail') . ' już wysłany ' . date('H:i', strtotime((string) $previous['created_at'])) . ' (' . ($previous['created_by'] ?: '—') . ')'
                    . ($automatic ? '.' : ' — zaznacz « wyślij ponownie », aby wysłać drugi raz.');
            }
            if (!empty($item['mail'])) {
                if ($n['email'] === '') $problems[] = "{$c['name']} : brak adresu e-mail.";
                elseif (!$n['mail']['template']) $problems[] = "{$c['name']} : brak szablonu e-maila « delivery_details ».";
                else {
                    $messageId = $this->outgoing->queue([
                        'message_type' => 'delivery_details', 'template' => $n['mail']['template'], 'recipient' => $n['email'],
                        'subject' => $n['mail']['subject'], 'body' => $n['mail']['body'], 'variables' => $n['variables'],
                        'id_customer' => $id, 'links' => [], 'created_by' => $author,
                    ]);
                    $this->notices->insert(['ship_date' => $date, 'id_customer' => $id, 'channel' => 'mail', 'language' => $n['language'], 'recipient' => $n['email'],
                        'subject' => $n['mail']['subject'], 'body' => $n['mail']['body'], 'status' => 'queued', 'id_message' => $messageId, 'created_by' => $author]);
                    $sent[] = ['customer' => $c['name'], 'channel' => 'mail', 'status' => 'queued', 'recipient' => $n['email'], 'id_message' => $messageId];
                }
            }
            if (!empty($item['sms'])) {
                if ($n['phone'] === '') $problems[] = "{$c['name']} : brak numeru telefonu.";
                elseif (!$n['sms']['template']) $problems[] = "{$c['name']} : brak szablonu SMS « delivery_details_sms ».";
                else {
                    $result = $this->sms->send($n['phone'], $n['sms']['body']);
                    $this->notices->insert(['ship_date' => $date, 'id_customer' => $id, 'channel' => 'sms', 'language' => $n['language'], 'recipient' => $n['phone'],
                        'body' => $n['sms']['body'], 'status' => $result['status'], 'provider' => $this->sms->name(), 'error' => $result['error'], 'created_by' => $author]);
                    $sent[] = ['customer' => $c['name'], 'channel' => 'sms', 'status' => $result['status'], 'recipient' => $n['phone']];
                    if ($result['status'] === 'failed') $problems[] = "{$c['name']} : SMS nie wysłany ({$result['error']}).";
                }
            }
        }
        return ['sent' => $sent, 'problems' => $problems];
    }

    /**
     * Kanały, których nie wysyłamy drugi raz : poprzednie powiadomienie dnia doszło (nie błąd, nie SMS tylko zapisany
     * bez dostawcy, nie e-mail odrzucony przez kolejkę) i nie ma « resend » ze strony (automat nigdy nie wysyła drugi raz).
     * @param array<string, array> $last kanał => ostatnie powiadomienie dnia ; @return array<string, array> kanał => to powiadomienie
     */
    public static function alreadySent(array $last, array $item, bool $automatic): array
    {
        $out = [];
        foreach (['mail', 'sms'] as $channel) {
            $previous = $last[$channel] ?? null;
            if (empty($item[$channel]) || $previous === null || (!empty($item['resend']) && !$automatic)) continue;
            if (in_array($previous['status'], ['failed', 'logged'], true) || ($previous['mail_status'] ?? '') === 'failed') continue;
            $out[$channel] = $previous;
        }
        return $out;
    }

    private function customerNotice(array $day, array $c, array $override): array
    {
        $client = $this->clients->first(['id' => $c['id']]);
        $lang = DocumentLabels::lang($client['communication_lang'] ?? null, 'fr');
        $truck = null;
        foreach ($day['fleet'] as $t) if ((int) $t['id'] === (int) $c['vehicle_id']) $truck = $t;
        $vehicle = $c['vehicle_id'] !== null ? ($day['all_vehicles'][$c['vehicle_id']] ?? null) : null;
        $tour = null;
        foreach ($day['tours'] as $t) if ((int) $t['id'] === (int) $c['tour_id']) $tour = $t;
        // przystanek : kolejność rozładunku na trasie, inaczej w pojeździe
        $route = $tour['customers'] ?? ($truck['customers'] ?? []);
        $position = array_search($c['id'], $route, false);
        $stop = $position === false ? null : $position + 1;
        $departure = $truck['departure'] ?? ($tour['departure'] ?? ($day['settings']['default_departure'] ?? ''));
        $arrival = $override['arrival_date'] ?? ($departure !== '' && $departure >= self::NEXT_DAY_FROM ? date('Y-m-d', strtotime($day['date'] . ' +1 day')) : $day['date']);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $arrival)) $arrival = $day['date'];
        $eta = trim((string) ($override['eta'] ?? ''));

        $L = self::LINES[$lang];
        $driver = trim((string) ($vehicle['driver'] ?? ''));
        $driverPhone = trim((string) ($vehicle['driver_phone'] ?? ''));
        $plate = trim((string) ($vehicle['regis_num'] ?? ''));
        $carrier = trim((string) ($vehicle['carrier'] ?? ''));
        $weight = DocumentLabels::number($c['weight_kg'], 0) . ' kg';
        $details = array_filter([
            "{$L['pallets']} : {$c['pallets']} · {$L['cartons']} : " . DocumentLabels::number($c['cartons']) . " · {$L['weight']} : {$weight}",
            $tour ? "{$L['tour']} : {$tour['name']}" . ($stop ? ' — ' . sprintf($L['stop'], $stop, count($route)) : '') : null,
            $plate !== '' ? "{$L['vehicle']} : {$plate}" . ($carrier !== '' ? " ({$carrier})" : '') : null,
            $driver !== '' ? "{$L['driver']} : {$driver}" . ($driverPhone !== '' ? " · {$L['phone']} {$driverPhone}" : '') : null,
            $departure !== '' ? "{$L['departure']} : " . $this->dayLabel($day['date'], $lang) . " {$L['at']} {$departure}" : null,
            $eta !== '' ? "{$L['eta']} : " . $this->dayLabel($arrival, $lang) . ", {$eta}" : null,
        ]);
        $S = self::SMS[$lang];
        $smsDetails = implode(' · ', array_filter([
            "{$c['pallets']} {$S['pal']} / " . DocumentLabels::number($c['cartons']) . " {$S['crt']}",
            $eta !== '' ? "{$S['eta']} {$eta}" : null,
            $driver !== '' ? "{$S['driver']} {$driver}" . ($driverPhone !== '' ? " {$driverPhone}" : '') : null,
            $plate !== '' ? "{$S['truck']} {$plate}" : null,
        ]));

        $variables = [
            'client' => ['company' => (string) ($client['company'] ?? $c['name']), 'contact_name' => trim(($client['name'] ?? '') . ' ' . ($client['surname'] ?? ''))],
            'delivery' => [
                'date' => $this->dayLabel($arrival, $lang), 'ship_date' => $this->dayLabel($day['date'], $lang), 'eta' => $eta,
                'pallets' => (string) $c['pallets'], 'cartons' => DocumentLabels::number($c['cartons']), 'weight' => $weight,
                'orders' => implode(', ', array_map(fn($o) => '#' . $o, $c['order_ids'])),
                'tour' => (string) ($tour['name'] ?? ''), 'stop' => (string) ($stop ?? ''), 'stops' => (string) count($route),
                'vehicle' => $plate, 'carrier' => $carrier, 'driver' => $driver, 'driver_phone' => $driverPhone, 'departure' => $departure,
                'details' => implode("\n", $details), 'sms_details' => $smsDetails,
            ],
            'company' => ['name' => $this->companyName()],
            'today' => ['date' => date('d/m/Y')],
        ];
        $email = trim((string) ($client['email_head'] ?? '')) ?: trim((string) ($client['email_salesman'] ?? ''));
        $phone = self::phone((string) ($client['phone_head'] ?? '')) ?: self::phone((string) ($client['phone_alt'] ?? ''));
        return [
            'id' => (int) $c['id'], 'name' => $c['name'], 'town' => $c['town'], 'language' => $lang,
            'email' => filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '', 'phone' => $phone,
            'pallets' => $c['pallets'], 'cartons' => $c['cartons'], 'tour' => $tour['name'] ?? null, 'stop' => $stop, 'stops' => count($route),
            'vehicle' => $plate, 'driver' => $driver, 'driver_phone' => $driverPhone, 'departure' => $departure,
            'arrival_date' => $arrival, 'eta' => $eta,
            'variables' => $variables,
            'mail' => $this->render('delivery_details', $lang, $variables),
            'sms' => $this->render('delivery_details_sms', $lang, $variables),
        ];
    }

    private function render(string $type, string $lang, array $variables): array
    {
        $template = $this->templates->getDefault($type, $lang) ?: $this->templates->getDefault($type, 'fr');
        if (!$template) return ['template' => null, 'subject' => '', 'body' => '', 'error' => "Brak szablonu {$type}."];
        try {
            return ['template' => $template, 'subject' => $this->renderer->render($template['subject_template'], $variables),
                'body' => $this->renderer->render($template['body_template'], $variables), 'error' => null];
        } catch (CustomException $e) {
            return ['template' => null, 'subject' => '', 'body' => '', 'error' => $e->getMessage()];
        }
    }

    private function dayLabel(string $date, string $lang): string
    {
        $t = strtotime($date);
        return self::DAYS[$lang][(int) date('N', $t) - 1] . ' ' . date('d/m/Y', $t);
    }

    /** Numer do SMS : cyfry i « + » (00 → +) ; za krótki = brak. */
    public static function phone(string $raw): string
    {
        $n = preg_replace('/[^0-9+]/', '', $raw);
        if (str_starts_with($n, '00')) $n = '+' . substr($n, 2);
        return strlen(ltrim($n, '+')) >= 8 ? $n : '';
    }

    private function companyName(): string
    {
        $row = $this->company->query('SELECT wdt_company_name FROM erp_company_data ORDER BY id LIMIT 1');
        return (string) ($row[0]['wdt_company_name'] ?? '');
    }
}
