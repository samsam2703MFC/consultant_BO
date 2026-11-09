<?php
declare(strict_types=1);

namespace App\Services\Finances\StorePrice;

use App\Exceptions\CustomException;
use App\Repositories\Finances\StorePriceRepository;

/**
 * Klient API panelu (konto konsultanta, tylko odczyt) : POST /consultant/auth/login {phone, password} → JWT,
 * potem GET z « Authorization: Bearer ». Token zaszyfrowany w bazie na 25 minut, odnowiony raz po 401.
 * Hasło nigdy nie trafia do komunikatów ani logów.
 */
class PanelApiClient
{
    private ?string $token = null;

    public function __construct(protected StorePriceRepository $repo, protected PanelSecretCipher $cipher)
    {
    }

    public function configured(): bool
    {
        $c = $this->repo->config();
        return trim((string) $c['phone']) !== '' && trim((string) $c['password_enc']) !== '';
    }

    /** Logowanie (test konta) : token albo wyjątek z powodem. */
    public function login(): string
    {
        $c = $this->repo->config();
        $phone = trim((string) $c['phone']);
        $password = $this->cipher->decrypt($c['password_enc'] ?? null);
        if ($phone === '' || $password === null || $password === '') {
            throw new CustomException('Konto API panelu nie jest skonfigurowane (Ustawienia → Konto API panelu) albo hasło trzeba wpisać ponownie.', 'errors', 422);
        }
        [$code, $res] = $this->http('POST', $this->base() . '/consultant/auth/login', ['phone' => $phone, 'password' => $password], null);
        $token = null;
        foreach ([$res['access_token'] ?? null, $res['token'] ?? null, $res['data']['access_token'] ?? null, $res['data']['token'] ?? null] as $cand) {
            if (is_string($cand) && $cand !== '') { $token = $cand; break; }
        }
        if ($token === null) {
            throw new CustomException('Logowanie do API panelu odrzucone (HTTP ' . $code . ')' . $this->detail($res) . '.', 'errors', 502);
        }
        $this->repo->saveConfig(['token_enc' => $this->cipher->encrypt($token), 'token_exp' => date('Y-m-d H:i:s', time() + 1500)]);
        return $this->token = $token;
    }

    /** GET : treść (pole data, jeśli jest) ; wyjątek przy błędzie HTTP. */
    public function get(string $path): mixed
    {
        $token = $this->token();
        [$code, $res] = $this->http('GET', $this->base() . $path, null, $token);
        if ($code === 401) {
            [$code, $res] = $this->http('GET', $this->base() . $path, null, $this->login());
        }
        if ($code < 200 || $code >= 300) {
            throw new CustomException('API panelu : GET ' . $path . ' → HTTP ' . $code . $this->detail($res) . '.', 'errors', 502);
        }
        return is_array($res) && array_key_exists('data', $res) ? $res['data'] : $res;
    }

    /**
     * Wiele GET naraz (do 4 równolegle) : klucz => treść albo null (błąd), bez przerywania pozostałych.
     *
     * @param array<string|int, string> $paths
     * @return array<string|int, mixed>
     */
    public function getMany(array $paths): array
    {
        $out = [];
        foreach (array_chunk($paths, 4, true) as $chunk) {
            foreach ($this->httpMany($chunk, $this->token()) as $k => [$code, $res]) {
                if ($code === 401) {
                    try { $res = $this->get($chunk[$k]); $out[$k] = $res; } catch (\Throwable $e) { $out[$k] = null; }
                    continue;
                }
                $out[$k] = $code >= 200 && $code < 300 ? (is_array($res) && array_key_exists('data', $res) ? $res['data'] : $res) : null;
            }
        }
        return $out;
    }

    /** Lista z odpowiedzi (lista albo obiekt z listą w data / items / results). */
    public static function rows(mixed $res): array
    {
        if (!is_array($res)) return [];
        if (array_is_list($res)) return array_values(array_filter($res, 'is_array'));
        foreach (['data', 'items', 'results', 'shops', 'products'] as $k) {
            if (isset($res[$k]) && is_array($res[$k])) return self::rows($res[$k]);
        }
        // Obiekt indeksowany identyfikatorami ({"12": {...}, "15": {...}}) : jego wartości.
        $values = array_filter($res, 'is_array');
        return $values && count($values) === count($res) ? array_values($values) : [];
    }

    private function token(): string
    {
        if ($this->token !== null) return $this->token;
        $c = $this->repo->config();
        if (!empty($c['token_enc']) && !empty($c['token_exp']) && strtotime((string) $c['token_exp']) > time() + 60) {
            $t = $this->cipher->decrypt($c['token_enc']);
            if ($t !== null && $t !== '') return $this->token = $t;
        }
        return $this->login();
    }

    private function base(): string
    {
        return rtrim((string) ($this->repo->config()['base_url'] ?? ''), '/');
    }

    private function detail(mixed $res): string
    {
        if (!is_array($res)) return '';
        foreach (['description', 'message', 'detail', 'error'] as $k) {
            if (!empty($res[$k]) && is_string($res[$k])) return ' : ' . mb_substr($res[$k], 0, 200);
        }
        return '';
    }

    /** @return array{0: int, 1: mixed} */
    protected function http(string $method, string $url, ?array $body, ?string $token, int $timeout = 20): array
    {
        $ch = curl_init($url);
        $headers = ['Accept: application/json', 'Content-Type: application/json'];
        if ($token !== null) $headers[] = 'Authorization: Bearer ' . $token;
        curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 6]);
        if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE));
        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($raw === false) throw new CustomException('API panelu niedostępne : ' . mb_substr($err, 0, 160) . '.', 'errors', 502);
        return [$code, json_decode((string) $raw, true)];
    }

    /**
     * @param array<string|int, string> $paths
     * @return array<string|int, array{0: int, 1: mixed}>
     */
    protected function httpMany(array $paths, string $token, int $timeout = 30): array
    {
        $multi = curl_multi_init();
        $handles = [];
        foreach ($paths as $k => $path) {
            $ch = curl_init($this->base() . $path);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ['Accept: application/json', 'Authorization: Bearer ' . $token],
                CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 6]);
            curl_multi_add_handle($multi, $ch);
            $handles[$k] = $ch;
        }
        do {
            $status = curl_multi_exec($multi, $running);
            if ($running) curl_multi_select($multi, 1.0);
        } while ($running && $status === CURLM_OK);
        $out = [];
        foreach ($handles as $k => $ch) {
            $raw = curl_multi_getcontent($ch);
            $out[$k] = [(int) curl_getinfo($ch, CURLINFO_HTTP_CODE), json_decode((string) $raw, true)];
            curl_multi_remove_handle($multi, $ch);
            curl_close($ch);
        }
        curl_multi_close($multi);
        return $out;
    }
}
