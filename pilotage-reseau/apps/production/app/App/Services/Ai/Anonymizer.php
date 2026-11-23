<?php
declare(strict_types=1);

namespace App\Services\Ai;

/**
 * [387] Anonimizacja danych wysyłanych do AI (rutyny poranne). Klienci, dostawcy i przewoźnicy, osoby, pojazdy i trasy
 * stają się kodami (K1, D1, P1, V1, T1 — kolejno w obrębie jednego przebiegu) ; przy każdym kodzie ERP pamięta klucz
 * stały BEZ nazwy (customer:12, a gdy brak numeru — skrót SHA-1 nazwy), zapisany przy przebiegu, i nazwę tylko w pamięci
 * procesu (do wyświetlenia). Teksty swobodne przechodzą przez scrub() : znane nazwy → kody, potem usunięte e-maile,
 * adresy WWW, telefony, numery NIP / VAT / IBAN i inne długie ciągi cyfr, ulice. Do AI mogą iść liczby, daty, nazwy
 * produktów, statusy i kody sygnałów — nigdy nazwa, e-mail, telefon, adres, NIP ani treść e-maila.
 * Odwrotnie : reveal() zamienia kody w tekście AI na nazwy (tylko przy wyświetleniu w ERP).
 */
final class Anonymizer
{
    /** typ → prefiks kodu */
    public const PREFIX = ['customer' => 'K', 'supplier' => 'D', 'person' => 'P', 'vehicle' => 'V', 'tour' => 'T'];
    /** Kod w tekście : prefiks + numer, jako osobne słowo. */
    public const CODE_RE = '/\b([KDPVT])(\d{1,4})\b/u';

    /** @var array<string, string> klucz → kod */
    private array $byKey = [];
    /** @var array<string, string> kod → klucz */
    private array $keys = [];
    /** @var array<string, string> kod → nazwa (tylko w pamięci) */
    private array $names = [];
    /** @var array<string, int> */
    private array $next = [];

    /** Klucz stały bez nazwy : typ:id, a bez id — typ:# + 12 znaków SHA-1 nazwy (małe litery, bez odstępów na brzegach). */
    public static function key(string $type, int|string|null $id, ?string $name): string
    {
        if ($id !== null && $id !== '' && (string) $id !== '0') return $type . ':' . $id;
        return $type . ':#' . substr(sha1(mb_strtolower(trim((string) $name))), 0, 12);
    }

    /** Kod podmiotu (ten sam podmiot → ten sam kod w przebiegu) ; pusta nazwa bez id → null. */
    public function code(string $type, int|string|null $id, ?string $name): ?string
    {
        if (!isset(self::PREFIX[$type])) throw new \InvalidArgumentException('Nieznany typ podmiotu : ' . $type);
        $name = $name !== null ? trim($name) : '';
        if ($name === '' && ($id === null || $id === '' || (string) $id === '0')) return null;
        $key = self::key($type, $id, $name);
        if (isset($this->byKey[$key])) {
            $c = $this->byKey[$key];
            if ($name !== '' && !isset($this->names[$c])) $this->names[$c] = $name;
            return $c;
        }
        $p = self::PREFIX[$type];
        $this->next[$p] = ($this->next[$p] ?? 0) + 1;
        $c = $p . $this->next[$p];
        $this->byKey[$key] = $c;
        $this->keys[$c] = $key;
        if ($name !== '') $this->names[$c] = $name;
        return $c;
    }

    /** Zarejestrowana nazwa podmiotu bez kodu w danych (np. nazwa trasy w tekście), żeby scrub() ją zastąpił. */
    public function known(string $type, int|string|null $id, ?string $name): void
    {
        $this->code($type, $id, $name);
    }

    /** Czy nazwa ma już kod (bez względu na wielkość liter). */
    public function hasName(string $name): bool
    {
        $n = mb_strtolower(trim($name));
        foreach ($this->names as $x) if (mb_strtolower($x) === $n) return true;
        return false;
    }

    /** @return array<string, string> kod → klucz stały (zapisywane przy przebiegu ; bez nazw) */
    public function codes(): array
    {
        return $this->keys;
    }

    /** @return array<string, string> kod → nazwa (tylko do wyświetlenia, nigdy do AI) */
    public function names(): array
    {
        return $this->names;
    }

    /** @return array<string, string> klucz stały → nazwa (do odtworzenia nazw przebiegu z innego dnia / procesu) */
    public function namesByKey(): array
    {
        $out = [];
        foreach ($this->names as $c => $n) $out[$this->keys[$c]] = $n;
        return $out;
    }

    /**
     * Tekst swobodny bezpieczny dla AI : znane nazwy → kody (najdłuższe najpierw, bez względu na wielkość liter), potem
     * e-maile, adresy WWW, telefony, NIP / VAT / IBAN, inne ciągi ≥ 7 cyfr, ulice ; skrócony do $max znaków.
     */
    public function scrub(?string $text, int $max = 200): string
    {
        $t = trim((string) $text);
        if ($t === '') return '';
        $pairs = [];
        foreach ($this->names as $c => $n) if (mb_strlen($n) >= 2) $pairs[$n] = $c;
        uksort($pairs, fn($a, $b) => mb_strlen($b) <=> mb_strlen($a));
        foreach ($pairs as $n => $c) $t = preg_replace('/(?<![\p{L}\p{N}])' . preg_quote($n, '/') . '(?![\p{L}\p{N}])/iu', $c, $t) ?? $t;
        return mb_substr(self::scrubPatterns($t), 0, $max);
    }

    /** Wzorce danych osobowych (bez znajomości nazw). */
    public static function scrubPatterns(string $t): string
    {
        $rules = [
            '/[\p{L}\p{N}._%+\-]+@[\p{L}\p{N}.\-]+\.\p{L}{2,}/u' => '[e-mail]',
            '~\b(?:https?://|www\.)\S+~iu' => '[link]',
            // IBAN (PL… / BE…) i numery VAT UE (PL1234567890, BE0123456789).
            '/\b[A-Z]{2}\d{2}(?:[ ]?[A-Z0-9]{4}){3,7}\b/u' => '[nr]',
            '/\b(?:PL|BE|FR|DE|NL|LU)[ ]?\d{8,12}\b/u' => '[nr]',
            '/\d{7,}/u' => '[nr]',
            // Ulica z numerem (ul. Długa 5, rue de la Loi 12, Avenue Louise 54).
            '/\b(?:ul\.|ulica|al\.|aleja|os\.|pl\.|rue|avenue|av\.|chaussée|boulevard|bd|straat|laan)\s+[\p{L}\'’.\- ]{2,40}\s\d+[a-z]?(?:\/\d+)?/iu' => '[adres]',
        ];
        // NIP z kreskami (526-104-08-28) i telefony z odstępami (+48 600 300 400, 0476 12 34 56, 02/123 45 67) — daty
        // (2026-11-24, 24.11.2026) zostają.
        foreach ($rules as $re => $rep) $t = preg_replace($re, $rep, $t) ?? $t;
        return preg_replace_callback('~(?<![\d\p{L}])\+?\d{2,4}(?:[ \-./]\d{2,4}){2,4}(?!\d)~u',
            fn($m) => preg_match('/^(\d{4}-\d{2}-\d{2}|\d{1,2}\.\d{1,2}\.\d{2,4})$/', $m[0]) ? $m[0] : '[nr]', $t) ?? $t;
    }

    /** Kody w tekście AI → nazwy (wyświetlanie) ; nieznany kod zostaje. @param array<string, string> $names kod → nazwa */
    public static function reveal(?string $text, array $names): string
    {
        $t = (string) $text;
        if ($t === '' || !$names) return $t;
        return preg_replace_callback(self::CODE_RE, fn($m) => $names[$m[0]] ?? $m[0], $t) ?? $t;
    }

    /**
     * Nazwy kodów zapisanego przebiegu na podstawie dzisiejszych danych : kod → klucz (przebieg) i klucz → nazwa (dziś).
     * @param array<string, string> $codes kod → klucz
     * @param array<string, string> $namesByKey klucz → nazwa
     * @return array<string, string> kod → nazwa
     */
    public static function namesFor(array $codes, array $namesByKey): array
    {
        $out = [];
        foreach ($codes as $c => $k) if (isset($namesByKey[$k])) $out[$c] = $namesByKey[$k];
        return $out;
    }
}
