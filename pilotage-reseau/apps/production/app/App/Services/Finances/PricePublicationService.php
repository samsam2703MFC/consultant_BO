<?php
declare(strict_types=1);

namespace App\Services\Finances;

use App\Exceptions\CustomException;
use App\Repositories\Communication\CommunicationTemplateRepository;
use App\Repositories\Finances\PriceWizardRepository;
use App\Services\Communication\MailEventSource;
use App\Services\Communication\OutgoingMessageService;
use App\Services\Communication\TemplateRenderer;
use App\Services\Product\Price\ClientPriceListService;

/**
 * Publikacje cen od daty [218] (krok 3 kreatora cen), wykonywane przez dispatcher co 5 minut (Bin/mail_dispatch.php) :
 *  1. e-mail z cennikiem od daty (PDF w języku klienta) notice_days przed datą — adres wg Dystrybucji dokumentów ;
 *  2. w dniu zmiany : ceny standardowe i ceny klientów z planu (tylko gdy w bazie jest nadal cena z planu) ;
 *     klient, który dostał już e-mail z tym cennikiem, nie dostaje drugiego « nowy cennik » (stan cennika zapisany) ;
 *  3. cennik na stronę franczyzy (webhook cennika, PricelistPusher) : ponowienia co przebieg, najwyżej SITE_ATTEMPTS.
 * Kopia testowa : poczta log_only, klucze franczyzy wyczyszczone → strona « no_site », nic nie wychodzi.
 */
final class PricePublicationService
{
    public const SITE_ATTEMPTS = 12;
    private const AUTHOR = 'price-wizard';

    public function __construct(
        private PriceWizardRepository $repo,
        private OutgoingMessageService $outgoing,
        private CommunicationTemplateRepository $templates,
        private TemplateRenderer $renderer,
        private ClientPriceListService $priceLists,
        private PricelistPusher $pusher,
        private MailEventSource $events,
        private PriceWizardService $wizard,
    ) {
    }

    /** Przebieg dispatchera. @return array{notices: int, applied: int, sites: int} */
    public function run(?string $today = null): array
    {
        $today ??= date('Y-m-d');
        $out = ['notices' => 0, 'applied' => 0, 'sites' => 0];
        foreach ($this->repo->dueNotices($today) as $n) {
            $this->notice((int) $n['id_publication'], (int) $n['id_customer'], (string) $n['effective_date']);
            $out['notices']++;
        }
        foreach ($this->repo->duePublications($today) as $id) {
            if ($this->apply($id) !== null) $out['applied']++;
        }
        foreach ($this->repo->pendingSites() as $s) {
            $this->pushSite((int) $s['id_publication'], (int) $s['id_customer'], (int) $s['site_attempts']);
            $out['sites']++;
        }
        return $out;
    }

    /** E-mail « nowe ceny od » z cennikiem od daty ; brak adresu, szablonu albo cennika → skipped z powodem. */
    public function notice(int $publicationId, int $customerId, string $date): void
    {
        $now = date('Y-m-d H:i:s');
        try {
            $prices = [];
            foreach ($this->repo->items($publicationId, $customerId) as $i) $prices[(int) $i['id_product']] = (float) $i['new_price'];
            $c = $this->repo->customers([$customerId])[$customerId] ?? throw new \RuntimeException('Nie znaleziono klienta.');
            $address = $this->wizard->priceAddress($customerId, $c);
            if ($address === '') {
                $this->repo->updateClient($publicationId, $customerId, ['email_status' => 'skipped', 'email_note' => 'Brak adresu e-mail (Dystrybucja dokumentów : cennik).', 'email_at' => $now]);
                return;
            }
            $lang = in_array(strtolower((string) ($c['communication_lang'] ?? '')), ['fr', 'nl', 'pl', 'en'], true) ? strtolower((string) $c['communication_lang']) : 'fr';
            $template = $this->templates->getDefault('price_change_notice', $lang) ?: $this->templates->getDefault('price_change_notice', 'fr');
            if ($template === []) {
                $this->repo->updateClient($publicationId, $customerId, ['email_status' => 'skipped', 'email_note' => 'Brak szablonu e-maila « nowe ceny od ».', 'email_at' => $now]);
                return;
            }
            $vars = ['client' => ['company' => (string) $c['company'], 'contact_name' => trim(($c['name'] ?? '') . ' ' . ($c['surname'] ?? ''))],
                'company' => ['name' => $this->repo->companyName()], 'prices' => ['valid_from' => date('d/m/Y', strtotime($date)), 'changed' => count($prices)],
                'today' => ['date' => date('d/m/Y')]];
            $pdf = $this->priceLists->generateFuturePDF($customerId, $prices, $date);
            $messageId = $this->outgoing->queue([
                'message_type' => 'price_change_notice', 'template' => $template, 'recipient' => $address,
                'subject' => $this->renderer->render((string) $template['subject_template'], $vars),
                'body' => $this->renderer->render((string) $template['body_template'], $vars),
                'variables' => $vars, 'id_customer' => $customerId, 'created_by' => self::AUTHOR,
            ], [['filename' => (string) $pdf['filename'], 'mime_type' => 'application/pdf', 'content' => (string) $pdf['file_string']]]);
            $this->repo->updateClient($publicationId, $customerId, ['email_status' => 'queued', 'email_note' => $address, 'email_at' => $now, 'id_message' => $messageId]);
        } catch (\Throwable $e) {
            $this->repo->updateClient($publicationId, $customerId, ['email_status' => 'failed', 'email_note' => mb_substr($e->getMessage(), 0, 250), 'email_at' => $now]);
        }
    }

    /** Zastosowanie publikacji w dniu zmiany. @return array{applied: int, skipped: int, clients_ok: list<int>}|null */
    public function apply(int $publicationId): ?array
    {
        $r = $this->repo->apply($publicationId);
        if ($r === null) return null;
        // Klient z e-mailem « nowe ceny od » i wszystkimi cenami zmienionymi : nowy cennik jest znany — bez drugiego e-maila.
        $notified = [];
        foreach ($this->repo->clients($publicationId) as $c) if ($c['email_status'] === 'queued') $notified[(int) $c['id_customer']] = true;
        foreach ($r['clients_ok'] as $customerId) {
            if (!isset($notified[$customerId])) continue;
            $hash = $this->events->priceHash($customerId);
            if ($hash !== null) $this->events->acknowledge('price_list_changed', ['id_customer' => $customerId, 'hash' => $hash]);
        }
        return $r;
    }

    /** Cennik na stronę franczyzy (webhook) ; bez kluczy → no_site, błąd → ponowienie, po SITE_ATTEMPTS → failed. */
    public function pushSite(int $publicationId, int $customerId, int $attempts): string
    {
        $now = date('Y-m-d H:i:s');
        try {
            $ok = $this->pusher->pushPriceList($customerId);
        } catch (CustomException $e) {
            $note = in_array($e->getMessage(), ['MISSING_CLIENT', 'MISSING_CLIENT_CREDENTIALS'], true)
                ? 'Brak połączenia ze stroną franczyzy (klucz webhooka cennika).' : $e->getMessage();
            $this->repo->updateClient($publicationId, $customerId, ['site_status' => 'no_site', 'site_note' => mb_substr($note, 0, 250), 'site_at' => $now, 'site_attempts' => $attempts + 1]);
            return 'no_site';
        } catch (\Throwable $e) {
            $ok = false;
            error_log('[price-publication] site ' . $customerId . ': ' . $e->getMessage());
        }
        if ($ok) {
            $this->repo->updateClient($publicationId, $customerId, ['site_status' => 'sent', 'site_note' => null, 'site_at' => $now,
                'site_attempts' => $attempts + 1, 'site_version' => $this->repo->pricelistVersion($customerId)]);
            return 'sent';
        }
        $status = $attempts + 1 >= self::SITE_ATTEMPTS ? 'failed' : 'retry';
        $this->repo->updateClient($publicationId, $customerId, ['site_status' => $status, 'site_note' => 'Strona franczyzy nie potwierdziła cennika (próba ' . ($attempts + 1) . ').',
            'site_at' => $now, 'site_attempts' => $attempts + 1]);
        return $status;
    }

    /** Lista publikacji (zaplanowane najpierw). */
    public function publications(): array
    {
        return array_map(fn($p) => $p + ['notice_date' => date('Y-m-d', strtotime($p['effective_date'] . ' -' . (int) $p['notice_days'] . ' days'))], $this->repo->publications());
    }

    /** Szczegóły : ceny standardowe, klienci (e-mail, strona) i liczba cen klientów. */
    public function detail(int $id): array
    {
        $p = $this->repo->publication($id) ?? throw new CustomException('Nie znaleziono publikacji.', 'errors', 404);
        $items = $this->repo->items($id);
        $standard = array_values(array_filter($items, fn($i) => (int) $i['id_customer'] === 0));
        $perClient = [];
        foreach ($items as $i) if ((int) $i['id_customer'] > 0) $perClient[(int) $i['id_customer']] = ($perClient[(int) $i['id_customer']] ?? 0) + 1;
        $clients = array_map(fn($c) => $c + ['prices' => $perClient[(int) $c['id_customer']] ?? 0], $this->repo->clients($id));
        return ['publication' => $p, 'standard' => $standard, 'clients' => $clients];
    }

    public function cancel(int $id, ?int $userId): string
    {
        $p = $this->repo->publication($id) ?? throw new CustomException('Nie znaleziono publikacji.', 'errors', 404);
        if ($p['status'] !== 'planned' || !$this->repo->cancel($id, $userId)) throw new CustomException('Publikacja jest już zastosowana albo anulowana.', 'errors', 409);
        $sent = count(array_filter($this->repo->clients($id), fn($c) => $c['email_status'] === 'queued'));
        return 'Publikacja od ' . date('d.m.Y', strtotime($p['effective_date'])) . ' anulowana — ceny bez zmian.'
            . ($sent ? " Uwaga : {$sent} klientów dostało już e-mail z nowym cennikiem." : '');
    }

    /** Cennik klienta od daty (PDF) : z publikacji. */
    public function clientPdf(int $publicationId, int $customerId): array
    {
        $p = $this->repo->publication($publicationId) ?? throw new CustomException('Nie znaleziono publikacji.', 'errors', 404);
        $prices = [];
        foreach ($this->repo->items($publicationId, $customerId) as $i) $prices[(int) $i['id_product']] = (float) $i['new_price'];
        return $this->priceLists->generateFuturePDF($customerId, $prices, (string) $p['effective_date']);
    }

    /** Cennik klienta od daty (PDF) : podgląd przed zaplanowaniem (ceny z kroku 3). @param array<int, float> $prices */
    public function previewPdf(int $customerId, array $prices, string $date): array
    {
        return $this->priceLists->generateFuturePDF($customerId, $prices, $date);
    }
}
