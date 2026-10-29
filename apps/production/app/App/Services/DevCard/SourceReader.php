<?php
declare(strict_types=1);

namespace App\Services\DevCard;

use App\Exceptions\CustomException;

/**
 * Odczyt linku do filmu z przepisem [196] bez logowania : reel / film Facebook albo pin Pinterest. Pobiera autora,
 * pełny opis (przepis), okładkę (og:image) i adres pliku wideo (Facebook : browser_native_hd_url / playable_url, najlepiej
 * 720p ; Pinterest : V_720P / contentUrl). Ściana logowania Facebooka = tylko okładka i uwaga w raporcie.
 * Bezpieczeństwo : tylko https, tylko domeny Facebooka / Pinteresta (każde przekierowanie sprawdzone), wideo i obrazy
 * tylko z ich serwerów plików (fbcdn.net, pinimg.com), limity rozmiaru i czasu.
 */
final class SourceReader
{
    public const MAX_PAGE_BYTES = 4_000_000;
    public const MAX_VIDEO_BYTES = 150_000_000;
    public const MAX_IMAGE_BYTES = 8_000_000;
    private const UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36';
    private const PAGE_HOSTS = ['facebook.com', 'fb.watch', 'fb.com', 'pin.it', 'pinterest.com'];
    private const FILE_HOSTS = ['fbcdn.net', 'pinimg.com'];
    /** Testy : fn(string $url, int $maxBytes): array{0:int status, 1:string body, 2:?string location} — zamiast sieci. */
    public static ?\Closure $transport = null;

    /** @return array{kind:string, url:string} facebook | pinterest */
    public static function detect(string $url): array
    {
        $url = trim($url);
        if (strlen($url) > 1000 || !preg_match('~^https?://~i', $url)) {
            throw new CustomException('Wklej pełny link do filmu (https://…).', 'errors', 422, ['fields' => ['url' => 'Pełny link https://…']]);
        }
        $url = preg_replace('~^http://~i', 'https://', $url);
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if (self::hostIn($host, ['facebook.com', 'fb.watch', 'fb.com'])) return ['kind' => 'facebook', 'url' => $url];
        if (self::hostIn($host, ['pin.it']) || self::isPinterest($host)) return ['kind' => 'pinterest', 'url' => $url];
        throw new CustomException('Obsługiwane linki : Facebook (reel, film) i Pinterest (pin). Inny film : dodaj plik wideo albo wklej opis.', 'errors', 422,
            ['fields' => ['url' => 'Link z Facebooka albo Pinteresta.']]);
    }

    /**
     * @return array{kind:string, url:string, author:?string, title:?string, text:?string, image:?string, video:?string,
     *               login_wall:bool, source_link:?string, notes:list<string>}
     */
    public function read(string $url): array
    {
        $d = self::detect($url);
        [$html, $final] = $this->page($d['url']);
        $meta = self::meta($html);
        $out = ['kind' => $d['kind'], 'url' => $final, 'author' => null, 'title' => $meta['og:title'] ?? null, 'text' => null,
            'image' => $meta['og:image'] ?? null, 'video' => null, 'video_sd' => null, 'login_wall' => false, 'source_link' => null, 'notes' => []];
        return $d['kind'] === 'facebook' ? $this->facebook($html, $meta, $out) : $this->pinterest($html, $meta, $out);
    }

    private function facebook(string $html, array $meta, array $out): array
    {
        // Tytuł bez licznika na początku (« 9,2 tys. wyświetleń · 398 reakcji | … », « 12K views · 1.1K reactions | … »).
        if ($out['title'] !== null) {
            $out['title'] = preg_replace('~^[^|]*\b(?:wyświetle\w*|reakcj\w*|views?|reactions?|vues|réactions)\b[^|]*\|\s*~iu', '', $out['title']);
        }
        // Opis : najdłuższy z og:description i tekstów posta w danych strony (og:description bywa skrócony).
        $texts = array_filter([$meta['og:description'] ?? null, $meta['description'] ?? null]);
        foreach (['~"message":\{"text":"((?:[^"\\\\]|\\\\.)*)"~', '~"description":\{"text":"((?:[^"\\\\]|\\\\.)*)"~'] as $re) {
            if (preg_match_all($re, $html, $m)) foreach ($m[1] as $raw) $texts[] = self::jsonString($raw);
        }
        $out['text'] = self::longest($texts);
        foreach (['~"owner":\{"__typename":"(?:User|Page)"[^{}]*?"name":"((?:[^"\\\\]|\\\\.)*)"~', '~"actors":\[\{[^{}]*?"name":"((?:[^"\\\\]|\\\\.)*)"~',
                     '~"video_owner":\{[^{}]*?"name":"((?:[^"\\\\]|\\\\.)*)"~'] as $re) {
            if (preg_match($re, $html, $m)) { $out['author'] = self::jsonString($m[1]); break; }
        }
        if ($out['author'] === null && isset($meta['og:title']) && preg_match('~(?:\||·)\s*(?:By\s+)?([^|·]+?)\s*(?:\|\s*Facebook)?$~u', $meta['og:title'], $m)) {
            $out['author'] = trim($m[1]) !== 'Facebook' ? trim($m[1]) : null;
        }
        // HD do klatek ; wersja SD obok — zapas, gdy plik HD (długi film) przekracza limit pobierania.
        $find = function (array $keys) use ($html): ?string {
            foreach ($keys as $k) if (preg_match('~"' . $k . '":"((?:[^"\\\\]|\\\\.)+)"~', $html, $m)) return self::jsonString($m[1]);
            return null;
        };
        $hd = $find(['browser_native_hd_url', 'playable_url_quality_hd']);
        $sd = $find(['browser_native_sd_url', 'playable_url']);
        $out['video'] = $hd ?? $sd;
        $out['video_sd'] = $hd !== null && $sd !== null && $sd !== $hd ? $sd : null;
        if ($hd === null && $sd !== null) $out['notes'][] = 'Wideo w jakości SD (brak wersji 720p).';
        $out['video'] ??= $meta['og:video:secure_url'] ?? $meta['og:video:url'] ?? $meta['og:video'] ?? null;
        $loginWall = str_contains($out['url'], '/login') || (str_contains($html, 'id="login_form"') && $out['text'] === null && $out['video'] === null);
        if ($loginWall) {
            $out['login_wall'] = true;
            $out['notes'][] = 'Facebook pokazał ścianę logowania : opis niedostępny bez logowania (nie logujemy się). Wklej opis ręcznie albo dodaj plik wideo.';
        }
        if ($out['text'] === null && !$loginWall) $out['notes'][] = 'Brak opisu w stronie : wklej przepis ręcznie, jeśli jest w komentarzu albo na stronie autora.';
        if ($out['video'] === null) $out['notes'][] = 'Nie znaleziono pliku wideo : zdjęcia etapów tylko z okładki (albo dodaj plik .mp4).';
        return self::clean($out);
    }

    private function pinterest(string $html, array $meta, array $out): array
    {
        $out['text'] = self::longest(array_filter([$meta['og:description'] ?? null, $meta['description'] ?? null]));
        $out['source_link'] = $meta['pinterestapp:source'] ?? $meta['og:see_also'] ?? null;
        if (preg_match('~"(?:full_name|username)":"((?:[^"\\\\]|\\\\.)*)"~', $html, $m)) $out['author'] = self::jsonString($m[1]);
        if (preg_match('~"V_720P":\{[^{}]*?"url":"((?:[^"\\\\]|\\\\.)+?\.mp4)"~', $html, $m)
            || preg_match('~"contentUrl":"(https:(?:\\\\/|/){2}v1\.pinimg\.com[^"]+?\.mp4)"~', $html, $m)
            || preg_match('~(https://v1\.pinimg\.com/videos/[^"\'\s]+?\.mp4)~', $html, $m)) {
            $out['video'] = self::jsonString($m[1]);
        }
        $out['notes'][] = 'Pin zwykle nie zawiera pełnego przepisu' . ($out['source_link'] ? ' — źródło : ' . $out['source_link'] : '') . '.';
        if ($out['video'] === null) $out['notes'][] = 'Pin bez pliku wideo (zdjęcie albo HLS) : zdjęcia etapów tylko z okładki.';
        return self::clean($out);
    }

    /** Pobranie pliku wideo do pliku tymczasowego (dla kreatora : klatki wycina przeglądarka). @return int bajty */
    public function downloadVideo(string $url, string $dest): int
    {
        self::assertFileHost($url);
        [$status, $body] = $this->get($url, self::MAX_VIDEO_BYTES, $dest);
        if ($status !== 200) throw new CustomException('Nie udało się pobrać wideo (HTTP ' . $status . ') — link mógł wygasnąć : odczytaj link ponownie.', 'errors', 502);
        return $body === '' ? (int) @filesize($dest) : (int) file_put_contents($dest, $body);
    }

    /** Okładka (og:image) : JPEG / PNG / WebP z serwerów Facebooka / Pinteresta. @return array{mime:string, bytes:string}|null */
    public function image(?string $url): ?array
    {
        if ($url === null || $url === '') return null;
        try {
            self::assertFileHost($url);
            [$status, $body] = $this->get($url, self::MAX_IMAGE_BYTES);
        } catch (CustomException) {
            return null;
        }
        if ($status !== 200 || $body === '') return null;
        $info = @getimagesizefromstring($body);
        $mime = is_array($info) ? (string) ($info['mime'] ?? '') : '';
        return in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true) ? ['mime' => $mime, 'bytes' => $body] : null;
    }

    // --- HTTP ------------------------------------------------------------------------------------------

    /** Strona z przekierowaniami (każde sprawdzone : https, domena Facebooka / Pinteresta). @return array{0:string html, 1:string final url} */
    private function page(string $url): array
    {
        for ($hop = 0; $hop < 6; $hop++) {
            $host = strtolower((string) parse_url($url, PHP_URL_HOST));
            if (strtolower((string) parse_url($url, PHP_URL_SCHEME)) !== 'https' || !(self::hostIn($host, self::PAGE_HOSTS) || self::isPinterest($host))) {
                throw new CustomException('Link przekierowuje poza Facebooka / Pinteresta — nie otwieramy go.', 'errors', 422);
            }
            [$status, $body, $location] = $this->get($url, self::MAX_PAGE_BYTES);
            if (in_array($status, [301, 302, 303, 307, 308], true) && $location) {
                $url = self::absolute($location, $url);
                continue;
            }
            if ($status === 404) throw new CustomException('Strona nie istnieje (404) — sprawdź link.', 'errors', 422);
            if ($status !== 200) throw new CustomException('Strona odpowiedziała błędem HTTP ' . $status . '.', 'errors', 502);
            return [$body, $url];
        }
        throw new CustomException('Za dużo przekierowań.', 'errors', 502);
    }

    /** @return array{0:int, 1:string, 2:?string} status, treść (pusta, gdy zapisana do $dest), Location */
    private function get(string $url, int $maxBytes, ?string $dest = null): array
    {
        if (self::$transport !== null) {
            [$status, $body, $location] = (self::$transport)($url, $maxBytes) + [2 => null];
            if (strlen($body) > $maxBytes) throw new CustomException('Plik za duży.', 'errors', 413);
            if ($dest !== null && $status === 200) { file_put_contents($dest, $body); return [$status, '', $location]; }
            return [$status, $body, $location];
        }
        $ch = curl_init($url);
        $fh = $dest !== null ? fopen($dest, 'wb') : null;
        $buf = '';
        $size = 0;
        $location = null;
        curl_setopt_array($ch, [
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => $dest !== null ? 150 : 25, CURLOPT_ENCODING => '', CURLOPT_USERAGENT => self::UA,
            CURLOPT_HTTPHEADER => ['Accept: text/html,application/xhtml+xml,*/*;q=0.8', 'Accept-Language: pl,en;q=0.8,fr;q=0.6'],
            CURLOPT_HEADERFUNCTION => function ($c, string $h) use (&$location) {
                if (stripos($h, 'location:') === 0) $location = trim(substr($h, 9));
                return strlen($h);
            },
            CURLOPT_WRITEFUNCTION => function ($c, string $chunk) use (&$buf, &$size, $maxBytes, $fh) {
                $size += strlen($chunk);
                if ($size > $maxBytes) return 0; // przerwanie : za duży plik
                if ($fh) fwrite($fh, $chunk); else $buf .= $chunk;
                return strlen($chunk);
            },
        ]);
        $ok = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_errno($ch);
        curl_close($ch);
        if ($fh) fclose($fh);
        if ($size > $maxBytes) {
            if ($dest !== null) @unlink($dest);
            throw new CustomException('Plik za duży (najwyżej ' . round($maxBytes / 1_000_000) . ' MB).', 'errors', 413);
        }
        if ($ok === false && $err !== 0) throw new CustomException('Brak połączenia ze stroną (sieć serwera).', 'errors', 502);
        return [$status, $buf, $location];
    }

    // --- Pomocnicze ------------------------------------------------------------------------------------

    /** Meta og:* / name=* (oba porządki atrybutów), encje HTML zdekodowane. @return array<string,string> */
    public static function meta(string $html): array
    {
        $out = [];
        if (preg_match_all('~<meta\s[^>]*>~i', $html, $tags)) {
            foreach ($tags[0] as $tag) {
                if (!preg_match('~\b(?:property|name)\s*=\s*["\']([^"\']+)["\']~i', $tag, $k) || !preg_match('~\bcontent\s*=\s*(["\'])(.*?)\1~is', $tag, $v)) continue;
                $key = strtolower(trim($k[1]));
                if (!isset($out[$key])) $out[$key] = trim(html_entity_decode($v[2], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            }
        }
        return array_filter($out, fn($v) => $v !== '');
    }

    private static function jsonString(string $raw): string
    {
        $s = json_decode('"' . $raw . '"');
        return is_string($s) ? $s : stripslashes($raw);
    }

    private static function longest(array $texts): ?string
    {
        $best = null;
        foreach ($texts as $t) {
            $t = trim((string) $t);
            if ($t !== '' && ($best === null || mb_strlen($t) > mb_strlen($best))) $best = $t;
        }
        return $best;
    }

    private static function clean(array $out): array
    {
        foreach (['author', 'title', 'text', 'image', 'video', 'video_sd', 'source_link'] as $k) {
            if (is_string($out[$k])) $out[$k] = trim($out[$k]) !== '' ? trim($out[$k]) : null;
        }
        if ($out['text'] !== null) $out['text'] = mb_substr($out['text'], 0, 20000);
        if ($out['author'] !== null) $out['author'] = mb_substr($out['author'], 0, 255);
        if ($out['title'] !== null) $out['title'] = mb_substr($out['title'], 0, 500);
        foreach (['video', 'video_sd'] as $k) {
            if ($out[$k] !== null) {
                try { self::assertFileHost($out[$k]); } catch (CustomException) { $out[$k] = null; }
            }
        }
        return $out;
    }

    public static function assertFileHost(string $url): void
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if (strtolower((string) parse_url($url, PHP_URL_SCHEME)) !== 'https' || !self::hostIn($host, self::FILE_HOSTS) || strlen($url) > 2000) {
            throw new CustomException('Plik spoza serwerów Facebooka / Pinteresta — nie pobieramy go.', 'errors', 422);
        }
    }

    private static function hostIn(string $host, array $domains): bool
    {
        foreach ($domains as $d) if ($host === $d || str_ends_with($host, '.' . $d)) return true;
        return false;
    }

    /** pinterest.com, pl.pinterest.com, pinterest.fr, pinterest.co.uk… */
    private static function isPinterest(string $host): bool
    {
        return (bool) preg_match('~(^|\.)pinterest\.(com|[a-z]{2}|co\.[a-z]{2}|com\.[a-z]{2})$~', $host);
    }

    private static function absolute(string $location, string $base): string
    {
        if (preg_match('~^https?://~i', $location)) return $location;
        $p = parse_url($base);
        return ($p['scheme'] ?? 'https') . '://' . ($p['host'] ?? '') . (str_starts_with($location, '/') ? $location : '/' . $location);
    }
}
