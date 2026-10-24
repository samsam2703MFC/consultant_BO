<?php
declare(strict_types=1);

namespace App\Services\Mail;

/**
 * Wiadomość e-mail (RFC 822 / MIME) rozłożona na nagłówki, tekst, HTML i załączniki : nagłówki zakodowane
 * (=?UTF-8?B?…?=), multipart zagnieżdżony, base64 / quoted-printable, kodowanie znaków → UTF-8, nazwy plików
 * (także filename*=UTF-8''…). Bez rozszerzeń poza mbstring i iconv.
 */
final class MimeMessage
{
    /** @var array<string, list<string>> nazwa nagłówka (małe litery) => wartości surowe */
    public array $headers = [];
    public ?string $text = null;
    public ?string $html = null;
    /** @var list<array{index:int, filename:string, mime:string, size:int, cid:?string, inline:bool, content:string}> */
    public array $attachments = [];

    public static function parse(string $raw): self
    {
        $m = new self();
        [$headers, $body] = self::split($raw);
        $m->headers = $headers;
        $m->walk($headers, $body);
        return $m;
    }

    /** Same nagłówki (np. z BODY[HEADER.FIELDS …]). */
    public static function parseHeaders(string $raw): self
    {
        $m = new self();
        [$m->headers] = self::split($raw . "\r\n\r\n");
        return $m;
    }

    public function header(string $name): ?string
    {
        $v = $this->headers[strtolower($name)][0] ?? null;
        return $v === null ? null : self::decodeWords($v);
    }

    /** @return list<array{email:string, name:string}> */
    public function addresses(string $name): array
    {
        $out = [];
        foreach ($this->headers[strtolower($name)] ?? [] as $value) {
            foreach (self::splitAddresses($value) as $part) {
                $part = trim($part);
                if ($part === '') continue;
                if (preg_match('/^(.*)<([^>]+)>\s*$/s', $part, $mm)) {
                    $email = trim($mm[2]);
                    $label = trim(self::decodeWords(trim($mm[1])), " \t\"'");
                } else {
                    $email = trim($part, " \t\"'<>");
                    $label = '';
                }
                if (filter_var($email, FILTER_VALIDATE_EMAIL)) $out[] = ['email' => strtolower($email), 'name' => $label];
            }
        }
        return $out;
    }

    public function date(): ?string
    {
        $raw = $this->header('date');
        $t = $raw ? strtotime(preg_replace('/\s*\([^)]*\)\s*$/', '', $raw) ?? $raw) : false;
        return $t ? date('Y-m-d H:i:s', $t) : null;
    }

    public function messageId(string $header = 'message-id'): ?string
    {
        $v = $this->header($header);
        return $v && preg_match('/<[^>]+>/', $v, $mm) ? $mm[0] : null;
    }

    /** Tekst do listy, wyszukiwarki i odpowiedzi : text/plain, a bez niego HTML zamieniony na tekst. */
    public function plainText(): string
    {
        $text = $this->text !== null && trim($this->text) !== '' ? $this->text : ($this->html !== null ? self::htmlToText($this->html) : '');
        return trim(preg_replace("/\n{3,}/", "\n\n", str_replace("\r", '', $text)) ?? $text);
    }

    public function snippet(int $length = 240): string
    {
        $flat = trim(preg_replace('/\s+/u', ' ', $this->plainText()) ?? '');
        return mb_strlen($flat) > $length ? mb_substr($flat, 0, $length - 1) . '…' : $flat;
    }

    public static function htmlToText(string $html): string
    {
        $html = preg_replace('#<(script|style|head)\b[^>]*>.*?</\1>#is', '', $html) ?? $html;
        $html = preg_replace('#<br\s*/?>#i', "\n", $html) ?? $html;
        $html = preg_replace('#</(p|div|tr|li|h[1-6]|table|blockquote)>#i', "\n", $html) ?? $html;
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace("/[ \t\x{00a0}]+/u", ' ', $text) ?? $text;
        return trim(preg_replace("/\n\s*\n\s*\n+/", "\n\n", $text) ?? $text);
    }

    public static function decodeWords(string $value): string
    {
        if (!str_contains($value, '=?')) return self::toUtf8($value, 'UTF-8');
        // Słowa zakodowane obok siebie łączą się bez spacji (RFC 2047 § 6.2).
        $value = preg_replace('/\?=\s+=\?/', '?==?', $value) ?? $value;
        $decoded = @iconv_mime_decode($value, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');
        if ($decoded === false) $decoded = mb_decode_mimeheader($value);
        return self::toUtf8($decoded, 'UTF-8');
    }

    // --- rozbiór ---------------------------------------------------------------------------------

    /** @return array{0: array<string, list<string>>, 1: string} */
    private static function split(string $raw): array
    {
        $raw = ltrim($raw, "\r\n");
        $pos = strpos($raw, "\r\n\r\n");
        $sepLen = 4;
        $lf = strpos($raw, "\n\n");
        if ($pos === false || ($lf !== false && $lf < $pos)) { $pos = $lf; $sepLen = 2; }
        $head = $pos === false ? $raw : substr($raw, 0, $pos);
        $body = $pos === false ? '' : substr($raw, $pos + $sepLen);
        $headers = [];
        $head = preg_replace("/\r?\n[ \t]+/", ' ', $head) ?? $head;
        foreach (preg_split("/\r?\n/", $head) ?: [] as $line) {
            $i = strpos($line, ':');
            if ($i === false || $i === 0) continue;
            $headers[strtolower(trim(substr($line, 0, $i)))][] = trim(substr($line, $i + 1));
        }
        return [$headers, $body];
    }

    /** @param array<string, list<string>> $headers */
    private function walk(array $headers, string $body, int $depth = 0): void
    {
        [$type, $params] = self::contentType($headers['content-type'][0] ?? 'text/plain');
        if (str_starts_with($type, 'multipart/') && isset($params['boundary']) && $depth < 10) {
            foreach (self::parts($body, $params['boundary']) as $part) {
                [$h, $b] = self::split($part);
                $this->walk($h, $b, $depth + 1);
            }
            return;
        }
        $encoding = strtolower(trim($headers['content-transfer-encoding'][0] ?? '7bit'));
        $content = match ($encoding) {
            'base64' => (string) base64_decode(preg_replace('/[^A-Za-z0-9+\/=]/', '', $body) ?? '', false),
            'quoted-printable' => quoted_printable_decode($body),
            default => $body,
        };
        [$disposition, $dparams] = self::contentType($headers['content-disposition'][0] ?? '');
        $filename = $dparams['filename'] ?? $params['name'] ?? null;
        $isAttachment = $disposition === 'attachment' || ($filename !== null && !in_array($type, ['text/plain', 'text/html'], true))
            || (!str_starts_with($type, 'text/') && $type !== 'message/delivery-status');
        if (!$isAttachment && $type === 'text/html' && $this->html === null) { $this->html = self::toUtf8($content, $params['charset'] ?? null); return; }
        if (!$isAttachment && str_starts_with($type, 'text/')) {
            $text = self::toUtf8($content, $params['charset'] ?? null);
            $this->text = $this->text === null ? $text : $this->text . "\n\n" . $text;
            return;
        }
        $cid = isset($headers['content-id'][0]) ? trim($headers['content-id'][0], '<> ') : null;
        $ext = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png', 'message/rfc822' => 'eml', 'text/html' => 'html', 'text/plain' => 'txt'][$type] ?? 'bin';
        $this->attachments[] = [
            'index' => count($this->attachments), 'filename' => self::safeName($filename !== null ? self::decodeWords($filename) : 'zalacznik-' . (count($this->attachments) + 1) . '.' . $ext),
            'mime' => $type, 'size' => strlen($content), 'cid' => $cid, 'inline' => $disposition === 'inline' && $cid !== null, 'content' => $content,
        ];
    }

    /** @return list<string> */
    private static function parts(string $body, string $boundary): array
    {
        $parts = [];
        $chunks = preg_split('/^--' . preg_quote($boundary, '/') . '(--)?[ \t]*\r?$/m', $body) ?: [];
        array_shift($chunks); // preambuła
        foreach ($chunks as $chunk) {
            $chunk = preg_replace('/^\r?\n/', '', $chunk) ?? $chunk;
            $chunk = preg_replace('/\r?\n$/', '', $chunk) ?? $chunk;
            if (trim($chunk) === '') continue;
            $parts[] = $chunk;
        }
        return $parts;
    }

    /** « text/plain; charset="utf-8"; name*=UTF-8''%C5%BC.pdf » → [typ, parametry]. */
    private static function contentType(string $value): array
    {
        $segments = preg_split('/;(?=(?:[^"]*"[^"]*")*[^"]*$)/', $value) ?: [];
        $type = strtolower(trim((string) array_shift($segments)));
        $params = [];
        $continued = [];
        foreach ($segments as $seg) {
            $i = strpos($seg, '=');
            if ($i === false) continue;
            $key = strtolower(trim(substr($seg, 0, $i)));
            $val = trim(trim(substr($seg, $i + 1)), '"');
            if (preg_match('/^(\w+)\*(\d+)?\*?$/', $key, $mm)) {
                // RFC 2231 : filename*=UTF-8''… albo filename*0*=… filename*1*=…
                $continued[$mm[1]][(int) ($mm[2] ?? 0)] = [$val, str_ends_with($key, '*')];
                continue;
            }
            $params[$key] = $val;
        }
        foreach ($continued as $key => $pieces) {
            ksort($pieces);
            $joined = '';
            $charset = 'UTF-8';
            foreach ($pieces as $n => [$val, $encoded]) {
                if ($encoded && $n === 0 && preg_match("/^([^']*)'[^']*'(.*)$/", $val, $mm)) { $charset = $mm[1] ?: 'UTF-8'; $val = $mm[2]; }
                $joined .= $encoded ? rawurldecode($val) : $val;
            }
            $params[$key] = self::toUtf8($joined, $charset);
        }
        return [$type, $params];
    }

    /** @return list<string> */
    private static function splitAddresses(string $value): array
    {
        return preg_split('/,(?=(?:[^"]*"[^"]*")*[^"]*$)(?![^<]*>)/', $value) ?: [];
    }

    private static function toUtf8(string $s, ?string $charset): string
    {
        $charset = strtoupper(trim((string) $charset));
        if ($charset === '' || $charset === 'UTF-8' || $charset === 'US-ASCII' || $charset === 'UTF8') {
            return mb_check_encoding($s, 'UTF-8') ? $s : mb_convert_encoding($s, 'UTF-8', 'ISO-8859-2');
        }
        if ($charset === 'ISO-8859-1') $charset = 'WINDOWS-1252';
        $out = @iconv($charset, 'UTF-8//IGNORE', $s);
        return $out === false ? mb_convert_encoding($s, 'UTF-8', 'UTF-8') : $out;
    }

    private static function safeName(string $name): string
    {
        $name = trim(preg_replace('/[\x00-\x1f\/\\\\:*?"<>|]+/u', '_', $name) ?? '');
        return $name === '' ? 'zalacznik' : mb_substr($name, 0, 180);
    }
}
