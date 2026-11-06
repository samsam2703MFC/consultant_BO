<?php
declare(strict_types=1);

namespace App\Services\Mail;

use RuntimeException;

/**
 * Klient IMAP w czystym PHP (bez rozszerzenia imap, którego nie ma w PHP 8.4) : połączenie SSL albo STARTTLS,
 * logowanie, foldery, wyszukiwanie po UID, nagłówki, cała wiadomość, flagi, dopisanie do folderu (Wysłane).
 * Odpowiedzi serwera czytane z literałami {n} ; parser list, napisów w cudzysłowie i sekcji BODY[…].
 */
final class ImapClient
{
    /** @var resource|null */
    private $stream = null;
    private int $tag = 0;
    /** @var list<string> */
    private array $literals = [];

    public function __construct(
        private string $host,
        private int $port,
        private string $security = 'ssl',
        private bool $verifyTls = true,
        private int $timeout = 20,
    ) {
    }

    public function connect(): void
    {
        $context = stream_context_create(['ssl' => [
            'verify_peer' => $this->verifyTls, 'verify_peer_name' => $this->verifyTls, 'allow_self_signed' => !$this->verifyTls,
            'SNI_enabled' => true, 'peer_name' => $this->host,
        ]]);
        $address = ($this->security === 'ssl' ? 'ssl://' : 'tcp://') . $this->host . ':' . $this->port;
        $stream = @stream_socket_client($address, $errno, $error, $this->timeout, STREAM_CLIENT_CONNECT, $context);
        if ($stream === false) throw new RuntimeException("Brak połączenia z serwerem IMAP {$this->host}:{$this->port} ({$error}).");
        stream_set_timeout($stream, $this->timeout);
        $this->stream = $stream;
        $greeting = $this->line();
        if (!str_starts_with($greeting, '* OK') && !str_starts_with($greeting, '* PREAUTH')) throw new RuntimeException('Serwer IMAP odmówił połączenia.');
        if ($this->security === 'starttls') {
            $this->command('STARTTLS');
            if (!@stream_socket_enable_crypto($stream, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) throw new RuntimeException('STARTTLS : nie udało się zaszyfrować połączenia IMAP.');
        }
    }

    public function login(string $user, string $password): void
    {
        try {
            $this->command('LOGIN ' . self::quote($user) . ' ' . self::quote($password));
        } catch (RuntimeException $e) {
            throw new RuntimeException('Logowanie IMAP odrzucone : sprawdź login i hasło (Gmail : hasło aplikacji).');
        }
    }

    /** @return list<array{name:string, flags:list<string>}> */
    public function folders(): array
    {
        $out = [];
        foreach ($this->command('LIST "" "*"') as $r) {
            if (strtoupper((string) ($r[0] ?? '')) !== 'LIST') continue;
            $out[] = ['name' => self::utf7Decode((string) ($r[3] ?? '')), 'flags' => array_map('strval', is_array($r[1] ?? null) ? $r[1] : [])];
        }
        return $out;
    }

    /** @return array{uidvalidity:int, exists:int, uidnext:int} */
    public function select(string $folder, bool $readOnly = false): array
    {
        $state = ['uidvalidity' => 0, 'exists' => 0, 'uidnext' => 0];
        foreach ($this->command(($readOnly ? 'EXAMINE ' : 'SELECT ') . self::quote(self::utf7Encode($folder))) as $r) {
            if (is_string($r[1] ?? null) && strtoupper($r[1]) === 'EXISTS') $state['exists'] = (int) $r[0];
            if (strtoupper((string) ($r[0] ?? '')) === 'OK' && is_string($r[1] ?? null) && preg_match('/^\[(UIDVALIDITY|UIDNEXT) (\d+)\]$/i', $r[1], $m)) {
                $state[strtolower($m[1])] = (int) $m[2];
            }
        }
        return $state;
    }

    /** @return list<int> */
    public function uidSearch(string $criteria): array
    {
        $uids = [];
        foreach ($this->command('UID SEARCH ' . $criteria) as $r) {
            if (strtoupper((string) ($r[0] ?? '')) !== 'SEARCH') continue;
            foreach (array_slice($r, 1) as $uid) if (ctype_digit((string) $uid)) $uids[] = (int) $uid;
        }
        sort($uids);
        return $uids;
    }

    /**
     * Nagłówki, flagi i rozmiar wiadomości.
     * @param list<int> $uids
     * @return array<int, array{flags:list<string>, size:int, internaldate:string, headers:string}>
     */
    public function fetchHeaders(array $uids): array
    {
        if (!$uids) return [];
        $out = [];
        $responses = $this->command('UID FETCH ' . implode(',', $uids)
            . ' (UID FLAGS INTERNALDATE RFC822.SIZE BODY.PEEK[HEADER.FIELDS (FROM TO CC SUBJECT DATE MESSAGE-ID IN-REPLY-TO CONTENT-TYPE)])');
        foreach ($responses as $r) {
            $data = self::fetchData($r);
            if ($data === null || !isset($data['UID'])) continue;
            $headers = '';
            foreach ($data as $k => $v) if (str_starts_with($k, 'BODY[')) $headers = (string) $v;
            $out[(int) $data['UID']] = ['flags' => array_map('strval', is_array($data['FLAGS'] ?? null) ? $data['FLAGS'] : []),
                'size' => (int) ($data['RFC822.SIZE'] ?? 0), 'internaldate' => (string) ($data['INTERNALDATE'] ?? ''), 'headers' => $headers];
        }
        return $out;
    }

    /**
     * @param list<int> $uids
     * @return array<int, list<string>> uid => flagi
     */
    public function fetchFlags(array $uids): array
    {
        if (!$uids) return [];
        $out = [];
        foreach ($this->command('UID FETCH ' . implode(',', $uids) . ' (UID FLAGS)') as $r) {
            $data = self::fetchData($r);
            if ($data !== null && isset($data['UID'])) $out[(int) $data['UID']] = array_map('strval', is_array($data['FLAGS'] ?? null) ? $data['FLAGS'] : []);
        }
        return $out;
    }

    /** Cała wiadomość (RFC 822) bez oznaczania jako przeczytanej. */
    public function fetchRaw(int $uid): ?string
    {
        foreach ($this->command('UID FETCH ' . $uid . ' (UID BODY.PEEK[])') as $r) {
            $data = self::fetchData($r);
            if ($data === null || (int) ($data['UID'] ?? 0) !== $uid) continue;
            foreach ($data as $k => $v) if (str_starts_with($k, 'BODY[')) return (string) $v;
        }
        return null;
    }

    public function setFlag(int $uid, string $flag, bool $on = true): void
    {
        $this->command('UID STORE ' . $uid . ' ' . ($on ? '+' : '-') . 'FLAGS.SILENT (' . $flag . ')');
    }

    /** Dopisuje wiadomość do folderu (kopia wysłanej w « Wysłane »). */
    public function append(string $folder, string $raw, array $flags = ['\\Seen']): void
    {
        $raw = preg_replace("/\r?\n/", "\r\n", $raw);
        $this->command('APPEND ' . self::quote(self::utf7Encode($folder)) . ' (' . implode(' ', $flags) . ') {' . strlen($raw) . '}', $raw);
    }

    public function logout(): void
    {
        if ($this->stream === null) return;
        try { $this->command('LOGOUT'); } catch (RuntimeException) {}
        @fclose($this->stream);
        $this->stream = null;
    }

    public function __destruct()
    {
        if ($this->stream !== null) @fclose($this->stream);
    }

    // --- protokół -------------------------------------------------------------------------------

    /**
     * Wysyła polecenie i zwraca odpowiedzi bez tagu, każda jako lista tokenów (bez « * »).
     * @return list<list<mixed>>
     */
    private function command(string $command, ?string $literal = null): array
    {
        if ($this->stream === null) throw new RuntimeException('Brak połączenia IMAP.');
        $tag = 'A' . (++$this->tag);
        if ($literal !== null) {
            $this->write($tag . ' ' . $command . "\r\n");
            $cont = $this->line();
            if (!str_starts_with($cont, '+')) throw new RuntimeException('Serwer IMAP nie przyjął danych : ' . self::short($cont));
            $this->write($literal . "\r\n");
        } else {
            $this->write($tag . ' ' . $command . "\r\n");
        }
        $untagged = [];
        while (true) {
            $line = $this->logicalLine();
            if (str_starts_with($line, $tag . ' ')) {
                $status = strtoupper(substr($line, strlen($tag) + 1, 2));
                if ($status !== 'OK') throw new RuntimeException('IMAP : ' . self::short(substr($line, strlen($tag) + 1)));
                return $untagged;
            }
            if (str_starts_with($line, '* ')) $untagged[] = $this->tokenize(substr($line, 2));
        }
    }

    private function write(string $data): void
    {
        $len = strlen($data);
        for ($done = 0; $done < $len;) {
            $n = @fwrite($this->stream, substr($data, $done));
            if ($n === false || $n === 0) throw new RuntimeException('Połączenie IMAP przerwane (zapis).');
            $done += $n;
        }
    }

    private function line(): string
    {
        $line = fgets($this->stream);
        if ($line === false) {
            $meta = stream_get_meta_data($this->stream);
            throw new RuntimeException(!empty($meta['timed_out']) ? 'Serwer IMAP nie odpowiada (limit czasu).' : 'Połączenie IMAP przerwane.');
        }
        return rtrim($line, "\r\n");
    }

    /** Linia odpowiedzi z literałami {n} zastąpionymi znacznikiem \0L<indeks>\0 (treść w $this->literals). */
    private function logicalLine(): string
    {
        $this->literals = [];
        $out = '';
        while (true) {
            $line = $this->line();
            if (preg_match('/\{(\d+)\+?\}$/', $line, $m)) {
                $out .= substr($line, 0, -strlen($m[0]));
                $size = (int) $m[1];
                $data = '';
                while (strlen($data) < $size) {
                    $chunk = fread($this->stream, min(65536, $size - strlen($data)));
                    if ($chunk === false || $chunk === '') throw new RuntimeException('Połączenie IMAP przerwane (literał).');
                    $data .= $chunk;
                }
                $this->literals[] = $data;
                $out .= "\0L" . (count($this->literals) - 1) . "\0";
                continue;
            }
            return $out . $line;
        }
    }

    /** @return list<mixed> */
    private function tokenize(string $s): array
    {
        $pos = 0;
        return $this->parseList($s, $pos, false);
    }

    /** @return list<mixed> */
    private function parseList(string $s, int &$pos, bool $nested): array
    {
        $out = [];
        $len = strlen($s);
        while ($pos < $len) {
            $c = $s[$pos];
            if ($c === ' ') { $pos++; continue; }
            if ($c === ')') { $pos++; if ($nested) return $out; continue; }
            if ($c === '(') { $pos++; $out[] = $this->parseList($s, $pos, true); continue; }
            if ($c === '"') {
                $pos++;
                $str = '';
                while ($pos < $len && $s[$pos] !== '"') {
                    if ($s[$pos] === '\\' && $pos + 1 < $len) $pos++;
                    $str .= $s[$pos++];
                }
                $pos++;
                $out[] = $str;
                continue;
            }
            if ($c === "\0") {
                $end = strpos($s, "\0", $pos + 1);
                $out[] = $this->literals[(int) substr($s, $pos + 2, $end - $pos - 2)] ?? '';
                $pos = $end + 1;
                continue;
            }
            // atom ; sekcja BODY[...] z nawiasami i spacjami w środku należy do atomu
            $start = $pos;
            $depth = 0;
            while ($pos < $len) {
                $ch = $s[$pos];
                if ($ch === '[') $depth++;
                elseif ($ch === ']') $depth--;
                elseif ($depth <= 0 && ($ch === ' ' || $ch === '(' || $ch === ')' || $ch === "\0")) break;
                $pos++;
            }
            $atom = substr($s, $start, $pos - $start);
            $out[] = strtoupper($atom) === 'NIL' ? null : $atom;
        }
        return $out;
    }

    /** « n FETCH (k v k v …) » → [k => v] ; klucze wielkimi literami. */
    private static function fetchData(array $r): ?array
    {
        if (count($r) < 3 || strtoupper((string) $r[1]) !== 'FETCH' || !is_array($r[2])) return null;
        $data = [];
        $items = $r[2];
        for ($i = 0; $i + 1 < count($items); $i += 2) $data[strtoupper((string) $items[$i])] = $items[$i + 1];
        return $data;
    }

    private static function quote(string $s): string
    {
        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $s) . '"';
    }

    private static function short(string $s): string
    {
        return mb_substr(trim($s), 0, 160);
    }

    /** Nazwy folderów IMAP : zmodyfikowany UTF-7 (RFC 3501) ↔ UTF-8. */
    public static function utf7Decode(string $s): string
    {
        return preg_replace_callback('/&([^-]*)-/', static function ($m) {
            if ($m[1] === '') return '&';
            $b = base64_decode(str_replace(',', '/', $m[1]) . str_repeat('=', (4 - strlen($m[1]) % 4) % 4));
            return $b === false ? $m[0] : mb_convert_encoding($b, 'UTF-8', 'UTF-16BE');
        }, $s) ?? $s;
    }

    public static function utf7Encode(string $s): string
    {
        return preg_replace_callback('/&|[^\x20-\x7e]+/u', static function ($m) {
            if ($m[0] === '&') return '&-';
            return '&' . rtrim(str_replace('/', ',', base64_encode(mb_convert_encoding($m[0], 'UTF-16BE', 'UTF-8'))), '=') . '-';
        }, $s) ?? $s;
    }
}
