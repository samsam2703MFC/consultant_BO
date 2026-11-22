<?php
declare(strict_types=1);

namespace App\Services\Client\Order;

use App\Exceptions\CustomException;
use App\Repositories\Client\Order\OrderSmsRepository;
use App\Services\Logistics\Sms\SmsTransport;

/**
 * [372] SMS de commande aux clients, un clic à la fois (jamais automatique) :
 *  - order_missing : relance du client régulier dont la commande attendue n'est pas arrivée (« Klienci bez zamówienia ») ;
 *  - order_confirm : confirmation d'une commande reçue (« Zamówienia dnia ») ;
 *  - order_changed : la commande a été modifiée de notre côté (« Zamówienia dnia »).
 * Texte prérempli dans la langue du client (customer.communication_lang, défaut fr), modifiable avant l'envoi. La passerelle
 * (SmsGateway) n'envoie réellement qu'en mode « live » ; sinon le SMS est seulement inscrit dans l'historique (n_sms_log),
 * comme les rappels de paiement. Chaque SMS apparaît dans l'historique des envois du client.
 */
final class OrderSmsService
{
    public const KINDS = [
        'order_missing' => 'Brak zamówienia — przypomnienie',
        'order_confirm' => 'Potwierdzenie zamówienia',
        'order_changed' => 'Zmiana zamówienia',
    ];
    public const MAX = 480;
    private const TEXT = [
        'order_missing' => [
            'pl' => '{company} : nie otrzymaliśmy jeszcze Państwa zamówienia (zwykle {expected}). Czy przygotować dostawę? Prosimy o zamówienie albo odpowiedź. Dziękujemy.',
            'fr' => "{company} : nous n'avons pas encore reçu votre commande (habituellement le {expected}). Souhaitez-vous commander ? Merci de nous répondre.",
            'nl' => '{company}: we hebben uw bestelling nog niet ontvangen (meestal op {expected}). Wilt u bestellen? Graag uw antwoord. Dank u.',
            'en' => '{company}: we have not received your order yet (usually on {expected}). Would you like to order? Please reply. Thank you.',
        ],
        'order_confirm' => [
            'pl' => '{company} : zamówienie {num} przyjęte — wysyłka {date}, {cartons} kart. Dziękujemy.',
            'fr' => '{company} : commande {num} bien reçue — expédition le {date}, {cartons} cartons. Merci.',
            'nl' => '{company}: bestelling {num} goed ontvangen — verzending op {date}, {cartons} dozen. Dank u.',
            'en' => '{company}: order {num} received — shipping on {date}, {cartons} cartons. Thank you.',
        ],
        'order_changed' => [
            'pl' => '{company} : zamówienie {num} zostało zmienione : {detail} — wysyłka {date}. W razie pytań prosimy o kontakt.',
            'fr' => '{company} : votre commande {num} a été modifiée : {detail} — expédition le {date}. Pour toute question, contactez-nous.',
            'nl' => '{company}: uw bestelling {num} werd gewijzigd: {detail} — verzending op {date}. Vragen? Neem contact met ons op.',
            'en' => '{company}: your order {num} has been changed: {detail} — shipping on {date}. Any questions, please contact us.',
        ],
    ];

    public function __construct(private OrderSmsRepository $repo, private SmsTransport $sms)
    {
    }

    /**
     * Brouillon : destinataire, langue et texte prérempli.
     * @param array{expected?: ?string, detail?: ?string} $hints date attendue (order_missing), détail de la modification (order_changed)
     */
    public function draft(string $kind, int $id, array $hints = []): array
    {
        $t = $this->target($kind, $id);
        $lang = isset(self::TEXT[$kind][$t['language']]) ? $t['language'] : 'fr';
        $num = $t['order'] ? ($t['order']['reference'] !== null && trim((string) $t['order']['reference']) !== '' ? trim((string) $t['order']['reference']) . ' (nr ' . $t['order']['id'] . ')' : 'nr ' . $t['order']['id']) : '';
        $cartons = $t['order'] ? (float) $t['order']['cartons'] : 0.0;
        $detail = trim((string) ($hints['detail'] ?? ''));
        $text = strtr(self::TEXT[$kind][$lang], [
            '{company}' => $this->repo->companyName() ?: '—',
            '{num}' => $num,
            '{date}' => $t['order'] && $t['order']['shipping_date'] ? date('d.m.Y', strtotime((string) $t['order']['shipping_date'])) : '—',
            '{cartons}' => rtrim(rtrim(number_format($cartons, 1, ',', ' '), '0'), ','),
            '{expected}' => self::day($hints['expected'] ?? null) ?? '—',
            '{detail}' => $detail !== '' ? mb_substr($detail, 0, 200) : '…',
        ]);
        $last = $this->repo->last([$kind], [$t['ref']], date('Y-m-d H:i:s', strtotime('-30 days')))[$t['ref']] ?? null;
        return ['kind' => $kind, 'kind_label' => self::KINDS[$kind], 'id' => $id, 'customer' => (string) $t['company'], 'phone' => $t['phone'], 'language' => $lang,
            'text' => mb_substr($text, 0, self::MAX), 'max' => self::MAX, 'delivers' => $this->sms->delivers(),
            'last' => $last ? ['at' => date('d.m H:i', strtotime($last['created_at'])), 'status' => $last['status']] : null];
    }

    /** Envoi d'un SMS (texte relu par l'utilisateur). @return array{status:string, message:string, delivers:bool, customer:int, text:string} */
    public function send(string $kind, int $id, string $text, ?int $userId): array
    {
        $t = $this->target($kind, $id);
        $text = trim((string) preg_replace('/[ \t]+/', ' ', str_replace(["\r\n", "\r"], "\n", $text)));
        if (mb_strlen($text) < 10) throw new CustomException('SMS : wpisz treść wiadomości (co najmniej 10 znaków).', 'errors', 422);
        if (mb_strlen($text) > self::MAX) throw new CustomException('SMS : najwyżej ' . self::MAX . ' znaków.', 'errors', 422);
        if (str_contains($text, '…') && $kind === 'order_changed') throw new CustomException('SMS : uzupełnij « … » — co się zmieniło w zamówieniu.', 'errors', 422);
        if ($t['phone'] === null) throw new CustomException('Brak numeru telefonu klienta (fiche klienta : telefon).', 'errors', 422);
        $r = $this->sms->send($t['phone'], $text, ['customer' => (int) $t['id_customer'], 'context' => $kind, 'ref' => $t['ref'], 'author' => 'order_sms:' . ($userId ?? 0)]);
        if (($r['status'] ?? '') === 'failed') throw new CustomException('SMS nie wysłany : ' . ($r['error'] ?: 'błąd bramki') . '.', 'errors', 422);
        $delivers = $this->sms->delivers();
        return ['status' => (string) $r['status'], 'delivers' => $delivers, 'customer' => (int) $t['id_customer'], 'text' => $text,
            'message' => 'SMS do ' . $t['company'] . ($delivers ? ' wysłany.' : ' zapisany w historii klienta (bramka SMS nie wysyła — Ustawienia › SMS).')];
    }

    /** La passerelle envoie-t-elle vraiment (sinon : SMS seulement inscrit dans l'historique) ? */
    public function delivers(): bool
    {
        return $this->sms->delivers();
    }

    /**
     * Dernier SMS des lignes d'un assistant (badge « SMS 9:14 »).
     * @param list<int> $refs
     * @return array<int, array{kind:string, label:string, at:string, status:string}>
     */
    public function lastFor(array $kinds, array $refs, string $since): array
    {
        $out = [];
        foreach ($this->repo->last($kinds, $refs, $since) as $ref => $l) {
            $out[$ref] = ['kind' => $l['context'], 'label' => self::KINDS[$l['context']] ?? $l['context'], 'status' => $l['status'],
                'at' => date(substr($l['created_at'], 0, 10) === date('Y-m-d') ? 'H:i' : 'd.m H:i', strtotime($l['created_at']))];
        }
        return $out;
    }

    /** @return array{id_customer:int, company:string, phone:?string, language:string, ref:int, order:?array} */
    private function target(string $kind, int $id): array
    {
        if (!isset(self::KINDS[$kind])) throw new CustomException('Nieznany rodzaj SMS.', 'errors', 422);
        if ($id <= 0) throw new CustomException('Brak klienta albo zamówienia.', 'errors', 422);
        if ($kind === 'order_missing') {
            $c = $this->repo->customer($id) ?? throw new CustomException('Nie znaleziono klienta.', 'errors', 404);
            $order = null;
            $ref = (int) $c['id_customer'];
        } else {
            $c = $this->repo->order($id) ?? throw new CustomException('Nie znaleziono zamówienia.', 'errors', 404);
            $order = $c;
            $ref = (int) $c['id'];
        }
        // Téléphone principal, sinon le second (la passerelle prend le premier numéro lisible).
        $phone = implode(' ; ', array_filter([trim((string) $c['phone_head']), trim((string) $c['phone_alt'])]));
        return ['id_customer' => (int) $c['id_customer'], 'company' => (string) $c['company'], 'phone' => $phone !== '' ? $phone : null,
            'language' => strtolower(substr(trim((string) $c['language']), 0, 2)), 'ref' => $ref, 'order' => $order];
    }

    private static function day(?string $d): ?string
    {
        if ($d === null || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) return null;
        return date('d.m', strtotime($d));
    }
}
