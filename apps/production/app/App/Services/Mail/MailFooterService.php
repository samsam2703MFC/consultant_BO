<?php
declare(strict_types=1);

namespace App\Services\Mail;

use App\Exceptions\CustomException;
use App\Repositories\Mail\MailFooterRepository;
use App\Repositories\Mail\UserMailRepository;
use App\Repositories\Translation\TranslationLogRepository;
use App\Repositories\Workshop\ParamRepository;
use App\Services\Organization\PageAccessService;
use App\Services\Translation\DeepLTranslator;

/**
 * Stopki e-maili firmy w językach klientów (Ustawienia › Stopki e-maili i tłumacz) : pod podpisem w Poczcie i pod treścią
 * e-maili automatycznych, w języku wiadomości (brak stopki w tym języku → angielska → polska). Stan tłumacza DeepL.
 */
final class MailFooterService
{
    public const LANGS = ['pl' => 'polski', 'fr' => 'francuski', 'nl' => 'niderlandzki', 'en' => 'angielski'];
    public const PAGE = '/settings/mail-footers';
    private const MAX = 1500;
    private const TAX = ['pl' => 'NIP', 'fr' => 'TVA', 'nl' => 'btw', 'en' => 'VAT'];
    private const CONFIDENTIAL = [
        'pl' => 'Ta wiadomość i jej załączniki są poufne i przeznaczone wyłącznie dla adresata.',
        'fr' => 'Ce message et ses pièces jointes sont confidentiels et destinés exclusivement à leur destinataire.',
        'nl' => 'Dit bericht en de bijlagen zijn vertrouwelijk en uitsluitend bestemd voor de geadresseerde.',
        'en' => 'This message and its attachments are confidential and intended solely for the addressee.',
    ];
    private ?array $cache = null;

    public function __construct(
        private MailFooterRepository $repo,
        private ParamRepository $params,
        private DeepLTranslator $translator,
        private TranslationLogRepository $log,
        private PageAccessService $access,
        private UserMailRepository $mail,
    ) {
    }

    /** Stopka dla języka wiadomości : ten język → angielski → polski ; null = brak stopki. */
    public function footer(?string $lang): ?string
    {
        $all = $this->cache ??= $this->repo->footers();
        foreach (array_unique(array_filter([strtolower((string) $lang), 'en', 'pl'])) as $l) {
            if (trim((string) ($all[$l] ?? '')) !== '') return trim($all[$l]);
        }
        return null;
    }

    /** Poczta : stopka pod podpisem (parametr mail.footer_user_mail). */
    public function forUserMail(?string $lang): ?string
    {
        return $this->params->get('mail.footer_user_mail', '1') === '1' ? $this->footer($lang) : null;
    }

    /** E-mail automatyczny z kolejki : język szablonu albo klienta (parametr mail.footer_system). */
    public function forMessage(array $message): ?string
    {
        if ($this->params->get('mail.footer_system', '1') !== '1') return null;
        return $this->footer($this->repo->messageLang($message));
    }

    public function state(int $userId): array
    {
        $this->guard($userId);
        $box = $this->mail->mailbox($userId);
        $footers = $this->repo->footers();
        return [
            'langs' => self::LANGS,
            'footers' => array_map(fn($l) => (string) ($footers[$l] ?? ''), array_combine(array_keys(self::LANGS), array_keys(self::LANGS))),
            'user_mail' => $this->params->get('mail.footer_user_mail', '1') === '1',
            'system' => $this->params->get('mail.footer_system', '1') === '1',
            'suggestions' => $this->suggestions(),
            'signature' => $box['signature'] ?? null,
            'sender' => $box ? ($box['display_name'] ?: $box['email']) : null,
            'updated_at' => $this->repo->updatedAt(),
            'translator' => $this->translatorState($userId),
            'max' => self::MAX,
        ];
    }

    public function save(int $userId, array $in): array
    {
        $this->guard($userId);
        $errors = [];
        $clean = [];
        foreach (array_keys(self::LANGS) as $l) {
            $text = trim(str_replace("\r\n", "\n", (string) ($in['footers'][$l] ?? '')));
            $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;
            if (mb_strlen($text) > self::MAX) $errors['footer_' . $l] = 'Najwyżej ' . self::MAX . ' znaków.';
            $clean[$l] = $text === '' ? null : $text;
        }
        if ($errors) throw new CustomException('Popraw zaznaczone pola.', 'errors', 422, ['fields' => $errors]);
        foreach ($clean as $l => $text) $this->repo->save($l, $text, $userId);
        if (array_key_exists('user_mail', $in)) $this->params->setValue('mail.footer_user_mail', !empty($in['user_mail']) ? '1' : '0', $userId);
        if (array_key_exists('system', $in)) $this->params->setValue('mail.footer_system', !empty($in['system']) ? '1' : '0', $userId);
        $this->cache = null;
        return $this->state($userId);
    }

    /** Stan tłumacza : skąd klucz (.env albo Ustawienia), plan, licznik konta DeepL i zużycie w ERP w tym miesiącu. */
    public function translatorState(?int $userId = null): array
    {
        $configured = $this->translator->configured();
        return [
            'configured' => $configured,
            'plan' => $this->translator->plan(),
            'source' => $this->translator->source(),
            'hint' => $this->translator->hint(),
            'can_set_key' => $userId !== null && $this->access->isAdmin($userId),
            'usage' => $configured ? $this->translator->usage() : null,
            'month' => $this->log->month(),
            'daily_cap' => (int) $this->params->get('translate.daily_chars_per_user', '60000'),
        ];
    }

    /** Klucz DeepL wpisany w Ustawieniach (administrator) : sprawdzony w DeepL, zapisany zaszyfrowany ; pusty = usunięty. */
    public function saveKey(int $userId, string $key): array
    {
        $this->guard($userId);
        if (!$this->access->isAdmin($userId)) throw new CustomException('Klucz DeepL wpisuje administrator.', 'errors', 403);
        if ($this->translator->source() === 'env') throw new CustomException('Klucz jest ustawiony w pliku .env serwera — zmiana tylko tam.', 'errors', 409);
        $this->translator->saveKey($key, $userId);
        return $this->translatorState($userId);
    }

    /** Propozycje stopek z danych firmy (jak na WDT) — przycisk « Wstaw dane firmy ». */
    private function suggestions(): array
    {
        $c = $this->repo->company();
        $name = trim((string) ($c['name'] ?? '')) ?: 'SL Food';
        $address = implode(', ', array_filter([trim((string) ($c['address_1'] ?? '')), trim((string) ($c['address_2'] ?? ''))]));
        $nip = preg_replace('/\D+/', '', (string) ($c['nip'] ?? ''));
        $out = [];
        foreach (array_keys(self::LANGS) as $l) {
            $out[$l] = implode("\n", array_filter([
                $name . ' · L\'Atelier By',
                $address,
                $nip !== '' ? self::TAX[$l] . ' : ' . ($l === 'pl' ? $nip : 'PL' . $nip) : '',
                self::CONFIDENTIAL[$l],
            ]));
        }
        return $out;
    }

    private function guard(int $userId): void
    {
        if (!$this->access->canSee($userId, self::PAGE)) throw new CustomException('Stopki e-maili ustawia administrator (Ustawienia).', 'errors', 403);
    }
}
