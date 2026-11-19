<?php
declare(strict_types=1);

namespace App\Services\Communication;

use App\Exceptions\CustomException;
use App\Repositories\Communication\SmsLogRepository;
use App\Repositories\Translation\AppSecretRepository;
use App\Repositories\Workshop\ParamRepository;
use App\Services\Logistics\Sms\SmsGateway;
use App\Services\Mail\MailboxSecretCipher;
use App\Services\Organization\PageAccessService;

/**
 * [313] Ustawienia › SMS (SMSAPI, ta sama bramka co CRM) : skąd token (.env, erp_cles, Ustawienia), konto SMSAPI
 * (sprawdzenie GET /profile, punkty), nadawca, kraj domyślny, wyłącznik, SMS próbny, ostatnie SMS-y.
 * Token wpisuje administrator ; ERP pokazuje tylko 4 ostatnie znaki, nigdy nie wysyła go do przeglądarki.
 */
final class SmsSettingsService
{
    public function __construct(private SmsGateway $sms, private ParamRepository $params, private AppSecretRepository $secrets,
                                private MailboxSecretCipher $cipher, private SmsLogRepository $log, private PageAccessService $access)
    {
    }

    public function state(int $userId, bool $check = false): array
    {
        $t = $this->sms->token();
        return [
            'configured' => $t['token'] !== '',
            'source' => $t['source'],
            'hint' => $t['hint'],
            'enabled' => $this->sms->enabled(),
            'cohab_switch' => $this->sms->cohabitationSwitch(),
            'live_cohab' => $this->params->get('sms.live_cohab', '0') === '1',
            'mode' => $this->sms->mode(),
            'delivers' => $this->sms->delivers(),
            'blocker' => $this->sms->blocker(),
            'from' => $this->params->get('sms.from', ''),
            'prefix' => $this->params->get('sms.default_prefix', '32'),
            'base' => $this->sms->base(),
            'profile' => $check && $t['token'] !== '' ? $this->sms->profile() : null,
            'can_edit' => $this->access->isAdmin($userId),
            'recent' => $this->log->recent(10),
        ];
    }

    /** Token wpisany w Ustawieniach : sprawdzony w SMSAPI (GET /profile), zapisany zaszyfrowany ; pusty = usunięty. */
    public function saveKey(int $userId, string $token): array
    {
        $this->admin($userId);
        $source = $this->sms->token()['source'];
        if (in_array($source, ['env', 'erp_cles'], true)) {
            throw new CustomException($source === 'env' ? 'Token jest ustawiony w pliku .env serwera — zmiana tylko tam.'
                : 'Token jest we wspólnej tabeli kluczy ERP (erp_cles, smsapi_token) — zmiana tylko tam.', 'errors', 409);
        }
        // Wklejony token : bez « Bearer », cudzysłowów i spacji.
        $token = trim(preg_replace('/^\s*(authorization:\s*)?bearer\s+/i', '', trim($token)) ?? '', " \t\n\r\0\x0B\"'");
        $warning = null;
        if ($token === '') {
            $this->secrets->delete(SmsGateway::SECRET);
        } else {
            if (strlen($token) < 10 || preg_match('/\s/', $token)) throw new CustomException('To nie wygląda na token SMSAPI (jeden ciąg znaków, bez spacji).', 'errors', 422);
            // [319] Sprawdzenie na platformach .com i .pl ; właściwa zapamiętana. Token zapisany także, gdy SMSAPI go nie
            // potwierdzi (np. token bez uprawnienia « profile ») — z ostrzeżeniem ; błąd wysyłki pokaże się przy SMS próbnym.
            $p = $this->sms->probe($token);
            if ($p['ok'] && $p['base'] !== $this->sms->base()) $this->params->setValue('sms.base', $p['base'], $userId);
            if (!$p['ok']) $warning = 'Token zapisany, ale SMSAPI go nie potwierdziło (' . $p['message'] . '). Sprawdź : token OAuth z uprawnieniami « sms » i « profile », z właściwego konta (smsapi.com / smsapi.pl). Potem SMS próbny.';
            $this->secrets->put(SmsGateway::SECRET, $this->cipher->encrypt($token), substr($token, -4), $userId);
        }
        $this->sms->forget();
        $state = $this->state($userId, true);
        $state['message'] = $warning ?? ($token === '' ? 'Token usunięty.' : 'Token zapisany i potwierdzony przez SMSAPI (' . parse_url($this->sms->base(), PHP_URL_HOST) . ').');
        return $state;
    }

    public function save(int $userId, array $in): array
    {
        $this->admin($userId);
        if (array_key_exists('from', $in)) {
            $from = trim((string) $in['from']);
            if ($from !== '' && !preg_match('/^[A-Za-z0-9 .\-]{2,11}$/', $from)) throw new CustomException('Nadawca : 2–11 znaków (litery, cyfry, spacja, kropka, myślnik), nazwa zatwierdzona w SMSAPI.', 'errors', 422);
            $this->params->setValue('sms.from', $from, $userId);
        }
        if (array_key_exists('prefix', $in)) {
            $prefix = preg_replace('/\D/', '', (string) $in['prefix']);
            if ($prefix === '' || strlen($prefix) > 3) throw new CustomException('Numer kierunkowy kraju : 1–3 cyfry (np. 32, 48).', 'errors', 422);
            $this->params->setValue('sms.default_prefix', $prefix, $userId);
        }
        if (array_key_exists('enabled', $in)) $this->params->setValue('sms.provider', !empty($in['enabled']) ? 'smsapi' : 'log_only', $userId);
        if (array_key_exists('live_cohab', $in) && $this->sms->cohabitationSwitch()) $this->params->setValue('sms.live_cohab', !empty($in['live_cohab']) ? '1' : '0', $userId);
        return $this->state($userId);
    }

    /** SMS próbny na wskazany numer (administrator) — wychodzi naprawdę tylko w trybie « live ». */
    public function test(int $userId, string $phone): array
    {
        $this->admin($userId);
        $r = $this->sms->send($phone, 'ERP : SMS próbny (Ustawienia › SMS) ' . date('d.m.Y H:i') . '.', ['context' => 'test', 'author' => 'settings:' . $userId]);
        if ($r['status'] === 'failed') throw new CustomException('SMS nie wysłany : ' . $r['error'], 'errors', 502);
        return ['message' => $r['status'] === 'sent' ? 'SMS próbny wysłany' . ($r['error'] ? ' (' . $r['error'] . ').' : '.') : 'SMS zapisany, nie wysłany : ' . ($this->sms->blocker() ?? '—') . '.', 'state' => $this->state($userId)];
    }

    private function admin(int $userId): void
    {
        if (!$this->access->isAdmin($userId)) throw new CustomException('Ustawienia SMS zmienia administrator.', 'errors', 403);
    }
}
