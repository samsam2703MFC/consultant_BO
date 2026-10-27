<?php
declare(strict_types=1);

namespace App\Services\Translation;

use App\Exceptions\CustomException;
use App\Repositories\Translation\AppSecretRepository;
use App\Repositories\Translation\TranslationLogRepository;
use App\Repositories\Workshop\ParamRepository;
use App\Services\Mail\MailboxSecretCipher;

/**
 * Tłumacz DeepL (API v2) : wiadomości przychodzące w Poczcie, odpowiedź w języku klienta, stopki, treść w Komunikacji.
 * Klucz : DEEPL_API_KEY w .env serwera, a bez niego klucz wpisany w Ustawieniach (n_app_secret, zaszyfrowany) ; klucz Free
 * kończy się na « :fx » → api-free.deepl.com. Każde tłumaczenie trafia do n_translation_log (limit dzienny na osobę :
 * parametr translate.daily_chars_per_user).
 */
final class DeepLTranslator
{
    /** Języki docelowe ERP → kod DeepL. */
    public const TARGETS = ['pl' => 'PL', 'fr' => 'FR', 'nl' => 'NL', 'en' => 'EN-GB', 'de' => 'DE'];
    /** « na … » */
    public const TO = ['pl' => 'polski', 'fr' => 'francuski', 'nl' => 'niderlandzki', 'en' => 'angielski', 'de' => 'niemiecki'];
    /** « z … » (języki wykrywane przez DeepL) */
    public const FROM = ['pl' => 'polskiego', 'fr' => 'francuskiego', 'nl' => 'niderlandzkiego', 'en' => 'angielskiego', 'de' => 'niemieckiego',
        'it' => 'włoskiego', 'es' => 'hiszpańskiego', 'pt' => 'portugalskiego', 'cs' => 'czeskiego', 'sk' => 'słowackiego', 'uk' => 'ukraińskiego',
        'ro' => 'rumuńskiego', 'lt' => 'litewskiego', 'lv' => 'łotewskiego', 'hu' => 'węgierskiego', 'ru' => 'rosyjskiego', 'bg' => 'bułgarskiego',
        'da' => 'duńskiego', 'sv' => 'szwedzkiego', 'fi' => 'fińskiego', 'el' => 'greckiego', 'tr' => 'tureckiego'];
    public const CONTEXTS = ['mail_in', 'mail_out', 'footer', 'communication'];
    public const MAX_CHARS = 30000;
    /** Testy : zamiast HTTP — fn(string $method, string $url, array $headers, ?array $body): array{0:int,1:string}. */
    public static ?\Closure $transport = null;
    private const SECRET = 'deepl_api_key';
    private ?array $resolved = null;

    public function __construct(private TranslationLogRepository $log, private ParamRepository $params, private AppSecretRepository $secrets,
                                private MailboxSecretCipher $cipher)
    {
    }

    /** @return array{key:string, source:?string, hint:?string} source : env | settings | null */
    private function resolve(): array
    {
        if ($this->resolved !== null) return $this->resolved;
        $env = trim((string) ($_ENV['DEEPL_API_KEY'] ?? getenv('DEEPL_API_KEY') ?: ''));
        if ($env !== '') return $this->resolved = ['key' => $env, 'source' => 'env', 'hint' => substr($env, -4)];
        $row = $this->secrets->get(self::SECRET);
        $key = $row ? (string) $this->cipher->decrypt($row['secret']) : '';
        return $this->resolved = $key !== '' ? ['key' => $key, 'source' => 'settings', 'hint' => $row['hint']] : ['key' => '', 'source' => null, 'hint' => null];
    }

    private function key(): string
    {
        return $this->resolve()['key'];
    }

    public function configured(): bool
    {
        return $this->key() !== '';
    }

    /** env | settings | null */
    public function source(): ?string
    {
        return $this->resolve()['source'];
    }

    public function hint(): ?string
    {
        return $this->resolve()['hint'];
    }

    /** free | pro | null (brak klucza) */
    public function plan(?string $key = null): ?string
    {
        $key ??= $this->key();
        return $key === '' ? null : (str_ends_with($key, ':fx') ? 'free' : 'pro');
    }

    /** Klucz wpisany w Ustawieniach : sprawdzony w DeepL (licznik konta) przed zapisem ; pusty = usunięcie. */
    public function saveKey(string $key, ?int $userId): void
    {
        $key = trim($key);
        if ($key === '') {
            $this->secrets->delete(self::SECRET);
            $this->resolved = null;
            return;
        }
        if (!preg_match('/^[A-Za-z0-9:\-]{20,100}$/', $key)) throw new CustomException('To nie wygląda na klucz DeepL (np. xxxxxxxx-xxxx-…:fx).', 'errors', 422, ['fields' => ['key' => 'Wklej klucz z konta DeepL (Klucze API).']]);
        [$status] = $this->request('GET', '/v2/usage', null, $key);
        if ($status !== 200) throw new CustomException(self::error($status), 'errors', 422, ['fields' => ['key' => self::error($status)]]);
        $this->secrets->put(self::SECRET, $this->cipher->encrypt($key), substr($key, -4), $userId);
        $this->resolved = null;
    }

    /**
     * @param list<string> $texts
     * @return list<array{text:string, source:?string}>
     */
    public function translate(array $texts, string $target, string $context, ?int $userId, ?string $source = null): array
    {
        if (!$this->configured()) {
            throw new CustomException('Tłumacz nie jest włączony : administrator dodaje klucz DeepL (DEEPL_API_KEY) na serwerze — Ustawienia › Stopki e-maili i tłumacz.', 'errors', 409);
        }
        if (!isset(self::TARGETS[$target])) throw new CustomException('Nieznany język docelowy.', 'errors', 422);
        if (!in_array($context, self::CONTEXTS, true)) $context = 'mail_out';
        $texts = array_values(array_map(fn($t) => (string) $t, $texts));
        $todo = array_filter($texts, fn($t) => trim($t) !== '');
        $out = array_map(fn($t) => ['text' => $t, 'source' => null], $texts);
        if (!$todo) return $out;
        $chars = (int) array_sum(array_map('mb_strlen', $todo));
        if ($chars > self::MAX_CHARS) throw new CustomException('Za długi tekst do tłumaczenia (najwyżej 30 000 znaków naraz).', 'errors', 413);
        $cap = (int) $this->params->get('translate.daily_chars_per_user', '60000');
        if ($userId && $cap > 0 && $this->log->charsToday($userId) + $chars > $cap) {
            throw new CustomException('Dzienny limit tłumaczeń wyczerpany (' . number_format($cap, 0, ',', ' ') . ' znaków na osobę) — jutro znowu albo zmiana parametru translate.daily_chars_per_user.', 'errors', 429);
        }
        $body = ['text' => array_values($todo), 'target_lang' => self::TARGETS[$target], 'preserve_formatting' => true];
        if ($source !== null && isset(self::TARGETS[$source]) && $source !== $target) $body['source_lang'] = strtoupper($source);
        [$status, $raw] = $this->request('POST', '/v2/translate', $body);
        if ($status !== 200) throw new CustomException(self::error($status), 'errors', $status === 429 ? 429 : 502);
        $res = json_decode($raw, true);
        $list = is_array($res['translations'] ?? null) ? $res['translations'] : [];
        if (count($list) !== count($todo)) throw new CustomException('Tłumacz DeepL zwrócił niepełną odpowiedź — spróbuj ponownie.', 'errors', 502);
        $detected = null;
        foreach (array_keys($todo) as $i => $k) {
            $src = strtolower(substr((string) ($list[$i]['detected_source_language'] ?? ''), 0, 2)) ?: null;
            $out[$k] = ['text' => (string) ($list[$i]['text'] ?? ''), 'source' => $src];
            $detected ??= $src;
        }
        $this->log->add($userId, $context, $chars, $detected, $target);
        return $out;
    }

    /** Licznik konta DeepL (znaki w okresie rozliczeniowym) albo null, gdy brak klucza lub błąd. */
    public function usage(): ?array
    {
        if (!$this->configured()) return null;
        try {
            [$status, $raw] = $this->request('GET', '/v2/usage', null);
        } catch (\Throwable) {
            return null;
        }
        $u = $status === 200 ? json_decode($raw, true) : null;
        return is_array($u) && isset($u['character_count']) ? ['count' => (int) $u['character_count'], 'limit' => (int) ($u['character_limit'] ?? 0)] : null;
    }

    public static function label(?string $code, bool $from = true): string
    {
        $code = strtolower((string) $code);
        return ($from ? self::FROM : self::TO)[$code] ?? strtoupper($code);
    }

    private function base(string $key): string
    {
        $custom = trim((string) ($_ENV['DEEPL_API_URL'] ?? getenv('DEEPL_API_URL') ?: ''));
        if ($custom !== '') return rtrim($custom, '/');
        return $this->plan($key) === 'free' ? 'https://api-free.deepl.com' : 'https://api.deepl.com';
    }

    private static function error(int $status): string
    {
        return match ($status) {
            403 => 'Klucz DeepL odrzucony — sprawdź DEEPL_API_KEY na serwerze (klucz Free kończy się na « :fx »).',
            456 => 'Limit znaków konta DeepL na ten okres wyczerpany — zwiększ plan w DeepL.',
            429 => 'DeepL : za dużo zapytań naraz — spróbuj za chwilę.',
            413 => 'Tekst za długi dla DeepL.',
            default => 'Tłumacz DeepL niedostępny' . ($status ? " (kod {$status})" : '') . ' — spróbuj później.',
        };
    }

    /** @return array{0:int, 1:string} */
    private function request(string $method, string $path, ?array $body, ?string $key = null): array
    {
        $key ??= $this->key();
        $url = $this->base($key) . $path;
        $headers = ['Authorization: DeepL-Auth-Key ' . $key, 'Content-Type: application/json', 'Accept: application/json', 'User-Agent: SLFood-ERP/1.0'];
        if (self::$transport !== null) return (self::$transport)($method, $url, $headers, $body);
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $headers, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 25,
            CURLOPT_CUSTOMREQUEST => $method]);
        if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE));
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return [$raw === false ? 0 : $status, $raw === false ? '' : (string) $raw];
    }
}
