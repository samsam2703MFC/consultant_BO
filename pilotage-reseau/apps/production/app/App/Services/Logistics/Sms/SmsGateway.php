<?php
declare(strict_types=1);

namespace App\Services\Logistics\Sms;

use App\Core\Cohabitation;
use App\Repositories\Communication\SmsLogRepository;
use App\Repositories\Translation\AppSecretRepository;
use App\Repositories\Workshop\ParamRepository;
use App\Services\Communication\MailDeliveryPolicy;
use App\Services\Mail\MailboxSecretCipher;

/**
 * [313] Bramka SMS ERP = integracja SMSAPI z CRM (Cockpit, src/metadonnee.php MdSms) : POST {base}/sms.do,
 * nagłówek « Authorization: Bearer {token} » (token OAuth z uprawnieniem « sms »), to / message / from, encoding utf-8, format json.
 *
 * Token (nigdy w kodzie, nigdy w logach) : SMSAPI_TOKEN w .env serwera → erp_cles « smsapi_token » (portal commun,
 * wspólna tabela kluczy ERP) → Ustawienia › SMS (n_app_secret, szyfrowany). Adres API : SMSAPI_BASE → parametr sms.base.
 *
 * SMS wychodzi tylko, gdy : jest token, parametr sms.provider = smsapi i tryb wysyłki ERP to « live »
 * (MAIL_DELIVERY_MODE — kopia testowa w log_only : nic nie wychodzi, jak e-maile). W pozostałych przypadkach SMS
 * jest tylko zapisany. Każdy SMS (wysłany, zapisany, błąd) trafia do dziennika n_sms_log → historia wysyłek klienta.
 */
final class SmsGateway implements SmsTransport
{
    public const SECRET = 'smsapi_token';
    public const SHARED_KEY = 'smsapi_token';
    /** Testy : zamiast HTTP — fn(string $url, ?array $fields, string $token): array{0:int,1:mixed} (fields null = GET). */
    public static ?\Closure $http = null;
    /** Testy : tryb wysyłki zamiast MAIL_DELIVERY_MODE. */
    public static ?string $modeOverride = null;
    private ?array $resolved = null;

    public function __construct(private ParamRepository $params, private AppSecretRepository $secrets, private MailboxSecretCipher $cipher,
                                private SmsLogRepository $log)
    {
    }

    /** @return array{token:string, source:?string, hint:?string} source : env | erp_cles | settings | null */
    public function token(): array
    {
        if ($this->resolved !== null) return $this->resolved;
        $env = trim((string) ($_ENV['SMSAPI_TOKEN'] ?? getenv('SMSAPI_TOKEN') ?: ''));
        if ($env !== '') return $this->resolved = ['token' => $env, 'source' => 'env', 'hint' => substr($env, -4)];
        $shared = $this->log->sharedKey(self::SHARED_KEY);
        if ($shared !== '') return $this->resolved = ['token' => $shared, 'source' => 'erp_cles', 'hint' => substr($shared, -4)];
        $row = $this->secrets->get(self::SECRET);
        $key = $row ? trim((string) $this->cipher->decrypt($row['secret'])) : '';
        return $this->resolved = $key !== '' ? ['token' => $key, 'source' => 'settings', 'hint' => $row['hint']] : ['token' => '', 'source' => null, 'hint' => null];
    }

    public function forget(): void
    {
        $this->resolved = null;
    }

    public function base(): string
    {
        $env = trim((string) ($_ENV['SMSAPI_BASE'] ?? getenv('SMSAPI_BASE') ?: ''));
        return rtrim($env !== '' ? $env : trim($this->params->get('sms.base', '')), '/');
    }

    /**
     * Tryb wysyłki SMS : jak e-maile (MAIL_DELIVERY_MODE), z jednym wyjątkiem [318] — serwer atelier w trybie
     * współistnienia (COHABITATION=1, prawdziwa baza) może wysyłać SMS naprawdę, gdy administrator włączy
     * sms.live_cohab, choć e-maile zostają w trybie testowym. Kopia testowa (bez współistnienia) : nigdy.
     */
    public function mode(): string
    {
        if (self::$modeOverride !== null) return self::$modeOverride;
        $mail = $this->mailMode();
        if ($mail !== 'live' && Cohabitation::on() && $this->params->get('sms.live_cohab', '0') === '1') return 'live';
        return $mail;
    }

    public function mailMode(): string
    {
        return self::$modeOverride ?? (new MailDeliveryPolicy())->mode();
    }

    /** [318] Czy przełącznik « SMS naprawdę na serwerze atelier » ma sens (współistnienie, e-maile w trybie testowym). */
    public function cohabitationSwitch(): bool
    {
        return Cohabitation::on() && $this->mailMode() !== 'live';
    }

    public function enabled(): bool
    {
        return $this->params->get('sms.provider', 'smsapi') !== 'log_only';
    }

    public function name(): string
    {
        return $this->token()['token'] !== '' && $this->enabled() ? 'smsapi' : 'log_only';
    }

    public function delivers(): bool
    {
        return $this->name() === 'smsapi' && $this->mode() === 'live' && $this->base() !== '';
    }

    /** Dlaczego SMS nie wychodzi (Ustawienia, komunikaty) ; null gdy wychodzi. */
    public function blocker(): ?string
    {
        if ($this->token()['token'] === '') return 'brak tokenu SMSAPI';
        if (!$this->enabled()) return 'wyłączone w Ustawieniach (sms.provider = log_only)';
        if ($this->base() === '') return 'brak adresu API (parametr sms.base)';
        if ($this->mode() !== 'live') return $this->cohabitationSwitch()
            ? 'serwer atelier : e-maile w trybie testowym — włącz « SMS naprawdę z tego serwera » poniżej'
            : 'kopia testowa ERP (tryb wysyłki ' . $this->mode() . ') — SMS tylko zapisane';
        return null;
    }

    public function send(string $to, string $text, array $context = []): array
    {
        $number = $this->e164($to);
        if ($number === null) {
            $result = ['status' => 'failed', 'provider_id' => null, 'error' => 'Nieczytelny numer telefonu : ' . mb_substr($to, 0, 40)];
        } elseif (!$this->delivers()) {
            $result = ['status' => 'logged', 'provider_id' => null, 'error' => null];
        } else {
            $fields = ['to' => ltrim($number, '+'), 'message' => $text, 'encoding' => 'utf-8', 'format' => 'json'];
            $from = trim($this->params->get('sms.from', ''));
            if ($from !== '') $fields['from'] = $from;
            [$code, $res] = $this->call('/sms.do', $fields);
            $note = null;
            // [320] Nadawca odrzucony (14 : nazwa niezatwierdzona w SMSAPI) : SMS ponownie z nadawcą domyślnym konta — nie ginie.
            if (isset($fields['from']) && is_array($res) && (int) ($res['error'] ?? 0) === 14) {
                unset($fields['from']);
                [$code, $res] = $this->call('/sms.do', $fields);
                $note = 'nadawca « ' . trim($this->params->get('sms.from', '')) . ' » niezatwierdzony w SMSAPI — wysłany z nadawcą domyślnym konta';
            }
            $result = $code >= 200 && $code < 300 && is_array($res) && !isset($res['error'])
                ? ['status' => 'sent', 'provider_id' => (string) ($res['list'][0]['id'] ?? '') ?: null, 'error' => $note]
                : ['status' => 'failed', 'provider_id' => null, 'error' => self::error($code, $res)];
        }
        $this->log->log(['id_customer' => isset($context['customer']) ? (int) $context['customer'] : null, 'context' => (string) ($context['context'] ?? 'other'),
            'ref_id' => isset($context['ref']) ? (int) $context['ref'] : null, 'recipient' => $number ?? $to, 'body' => $text, 'status' => $result['status'],
            'provider' => $this->name(), 'provider_id' => $result['provider_id'], 'error' => $result['error'], 'created_by' => $context['author'] ?? null]);
        return $result;
    }

    /** Konto SMSAPI (GET /profile) : czy token działa — bez wysyłania SMS. @return array{ok:bool, message:string, points:?float} */
    public function profile(?string $token = null, ?string $base = null): array
    {
        $token ??= $this->token()['token'];
        if ($token === '') return ['ok' => false, 'message' => 'brak tokenu SMSAPI', 'points' => null];
        [$code, $res] = $this->call('/profile', null, $token, $base);
        if ($code >= 200 && $code < 300 && is_array($res) && !isset($res['error'])) {
            $pts = isset($res['points']) ? (float) $res['points'] : null;
            return ['ok' => true, 'message' => trim((string) ($res['username'] ?? ($res['name'] ?? 'konto SMSAPI'))), 'points' => $pts];
        }
        return ['ok' => false, 'message' => self::error($code, $res), 'points' => null];
    }

    /**
     * [319] Token sprawdzony na platformach SMSAPI (sms.bases : .com i .pl) — bieżąca najpierw.
     * @return array{ok:bool, base:?string, message:string, points:?float, tried:array<string,string>}
     */
    public function probe(string $token): array
    {
        $bases = array_values(array_unique(array_filter(array_merge([$this->base()],
            preg_split('/[\s,;]+/', $this->params->get('sms.bases', '')) ?: []), fn($b) => str_starts_with((string) $b, 'https://'))));
        $tried = [];
        foreach ($bases as $b) {
            $b = rtrim($b, '/');
            $p = $this->profile($token, $b);
            if ($p['ok']) return ['ok' => true, 'base' => $b, 'message' => $p['message'], 'points' => $p['points'], 'tried' => $tried];
            $tried[$b] = $p['message'];
        }
        return ['ok' => false, 'base' => null, 'message' => implode(' · ', array_map(fn($b, $m) => parse_url($b, PHP_URL_HOST) . ' : ' . $m, array_keys($tried), $tried)), 'points' => null, 'tried' => $tried];
    }

    /** Numer w formacie E.164 (+32…, +48…) ; bez prefiksu : parametr sms.default_prefix (jak w CRM : Belgia). */
    public function e164(string $raw): ?string
    {
        // [321] Pole z kilkoma numerami (« 0476 12 34 56 / 0478… », « …; … », « … lub … ») : pierwszy czytelny numer.
        $parts = preg_split('/\s*(?:[\/,;|\n]|\s-\s|\s(?:lub|ou|of|or)\s)\s*/iu', trim($raw)) ?: [$raw];
        if (count($parts) > 1) {
            foreach ($parts as $part) if (($n = $this->e164One($part)) !== null) return $n;
            return null;
        }
        return $this->e164One($raw);
    }

    private function e164One(string $raw): ?string
    {
        $s = (string) preg_replace('/[^\d+]/', '', $raw);
        if ($s === '' || $s === '+') return null;
        if (str_starts_with($s, '00')) $s = '+' . substr($s, 2);
        if (str_starts_with($s, '+')) {
            $d = (string) preg_replace('/\D/', '', substr($s, 1));
            if (preg_match('/^(32|48|31|33|49)0/', $d, $m)) $d = $m[1] . substr($d, strlen($m[1]) + 1); // « +32 (0)476… »
        } else {
            $prefix = preg_replace('/\D/', '', $this->params->get('sms.default_prefix', '32')) ?: '32';
            $d = str_starts_with($s, '0') ? $prefix . substr($s, 1) : (strlen($s) <= 9 ? $prefix . $s : $s);
        }
        return strlen($d) >= 9 && strlen($d) <= 15 ? '+' . $d : null;
    }

    private function call(string $path, ?array $fields, ?string $token = null, ?string $base = null): array
    {
        $token ??= $this->token()['token'];
        $url = ($base ?? $this->base()) . $path;
        if (self::$http !== null) return (self::$http)($url, $fields, $token);
        $ch = curl_init($url);
        if ($fields !== null) curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($fields)]);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token],
            CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 6]);
        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return [$raw === false ? 0 : $code, $raw === false ? null : json_decode((string) $raw, true)];
    }

    /** Błąd SMSAPI ze wskazówką dla znanych kodów (jak w CRM). */
    private static function error(int $code, mixed $res): string
    {
        $num = is_array($res) && isset($res['error']) ? (int) $res['error'] : 0;
        $msg = is_array($res) ? (string) ($res['message'] ?? '') : '';
        $help = [101 => ' — token odrzucony : token OAuth z uprawnieniem « sms », na właściwej platformie (smsapi.com / smsapi.pl)',
            112 => ' — kraj zablokowany na koncie SMSAPI : zezwól na wysyłkę do tego kraju', 13 => ' — numer odrzucony',
            14 => ' — nadawca odrzucony : nazwa niezatwierdzona w SMSAPI'][$num] ?? '';
        return ($num ? '(' . $num . ') ' : '') . ($msg !== '' ? $msg : ($code ? 'HTTP ' . $code : 'SMSAPI nieosiągalne')) . $help;
    }
}
