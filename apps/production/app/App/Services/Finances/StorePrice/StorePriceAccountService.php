<?php
declare(strict_types=1);

namespace App\Services\Finances\StorePrice;

use App\Exceptions\CustomException;
use App\Repositories\Finances\StorePriceRepository;
use App\Services\Organization\PageAccessService;

/**
 * Konto API panelu (ceny w sklepach) : adres API, telefon i hasło konta konsultanta, wpisywane przez administratora.
 * Hasło zaszyfrowane, nigdy nie wraca do przeglądarki (tylko « ustawione ») ; puste pole = bez zmiany.
 */
final class StorePriceAccountService
{
    public function __construct(private StorePriceRepository $repo, private PanelSecretCipher $cipher, private PanelApiClient $api, private PageAccessService $access)
    {
    }

    public function show(): array
    {
        $c = $this->repo->config();
        $hasPassword = trim((string) $c['password_enc']) !== '';
        return ['base_url' => $c['base_url'], 'phone' => $c['phone'], 'password_set' => $hasPassword, 'source' => $c['source'] ?? null,
            'password_readable' => $hasPassword && $this->readable($c['password_enc']), 'updated_at' => $c['updated_at'],
            'sync' => $this->repo->lastSync(), 'synced' => $this->repo->lastSuccessfulSync()];
    }

    public function save(array $input, ?int $userId): array
    {
        $this->admin($userId);
        $base = rtrim(trim((string) ($input['base_url'] ?? '')), '/');
        if (!preg_match('#^https://[a-z0-9.-]+(:\d+)?(/[A-Za-z0-9._~/-]*)?$#i', $base)) throw new CustomException('Adres API : pełny adres https://…/api/v1.', 'errors', 422);
        $phone = preg_replace('/\s+/', '', (string) ($input['phone'] ?? ''));
        if (!preg_match('/^\+?\d{6,15}$/', $phone)) throw new CustomException('Telefon konta : same cyfry (z + na początku), 6–15 znaków.', 'errors', 422);
        $data = ['base_url' => $base, 'phone' => $phone, 'updated_by' => $userId, 'token_enc' => null, 'token_exp' => null, 'source' => 'manual'];
        $password = (string) ($input['password'] ?? '');
        if ($password !== '') {
            if (mb_strlen($password) > 200) throw new CustomException('Hasło : maks. 200 znaków.', 'errors', 422);
            $data['password_enc'] = $this->cipher->encrypt($password);
        } elseif (trim((string) $this->repo->config()['password_enc']) === '') {
            throw new CustomException('Wpisz hasło konta.', 'errors', 422);
        }
        $this->repo->saveConfig($data);
        return $this->show();
    }

    /**
     * Kopia konta z cockpitu konsultanta (wdrożenie, na serwerze) : ten sam telefon i hasło. Nie nadpisuje konta wpisanego
     * w ERP ręcznie ; konto z cockpitu odświeżane, gdy w cockpicie się zmieniło.
     *
     * @param array{base?: ?string, phone?: ?string, password?: ?string} $cockpit
     * @return string copied / unchanged / manual / empty
     */
    public function importFromCockpit(array $cockpit): string
    {
        $phone = trim((string) ($cockpit['phone'] ?? ''));
        $password = (string) ($cockpit['password'] ?? '');
        if ($phone === '' || $password === '') return 'empty';
        $c = $this->repo->config();
        $hasAccount = trim((string) $c['phone']) !== '' && trim((string) $c['password_enc']) !== '';
        if ($hasAccount && ($c['source'] ?? null) === 'manual') return 'manual';
        $base = rtrim(trim((string) ($cockpit['base'] ?? '')), '/');
        $base = $base !== '' && str_starts_with($base, 'https://') ? $base : (string) $c['base_url'];
        if ($hasAccount && $c['phone'] === $phone && $c['base_url'] === $base && $this->cipher->decrypt($c['password_enc']) === $password) return 'unchanged';
        $this->repo->saveConfig(['base_url' => $base, 'phone' => $phone, 'password_enc' => $this->cipher->encrypt($password),
            'token_enc' => null, 'token_exp' => null, 'source' => 'cockpit', 'updated_by' => null]);
        return 'copied';
    }

    /** Test : logowanie i liczba sklepów konta. */
    public function test(?int $userId): array
    {
        $this->admin($userId);
        $this->api->login();
        $shops = StorePriceSyncService::shops(PanelApiClient::rows($this->api->get('/consultant/shops')));
        return ['shops' => count($shops), 'names' => array_values(array_slice($shops, 0, 30))];
    }

    private function readable(?string $enc): bool
    {
        try { return $this->cipher->decrypt($enc) !== null; } catch (\Throwable $e) { return false; }
    }

    private function admin(?int $userId): void
    {
        if (!$this->access->isAdmin($userId)) throw new CustomException('Konto API panelu ustawia administrator.', 'errors', 403);
    }
}
