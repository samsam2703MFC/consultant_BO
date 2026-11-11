<?php
declare(strict_types=1);

namespace App\Services\Logistics;

use App\Exceptions\CustomException;
use App\Repositories\Logistics\CustomerDeliveryInfoRepository;

/**
 * Instrukcje dostawy dla kierowcy, inne u każdego klienta : procedura rozładunku, alarm (kod, jak wyłączyć), klucz
 * (gdzie jest, numer, skrytka), zamrażarka (gdzie, jak ułożyć), kontakt na miejscu. Wpisywane na karcie klienta,
 * pokazywane na tablecie kierowcy przy postoju. Zapis zaszyfrowany (DeliveryInfoCipher) ; nigdy w PDF dla klienta.
 */
final class CustomerDeliveryInfoService
{
    /** pole => [etykieta PL, etykieta FR, maks. długość, poufne (ukryte na tablecie do dotknięcia)] */
    public const FIELDS = [
        'procedure' => ['Procedura rozładunku', 'Procédure de déchargement', 2000, false],
        'alarm' => ['Alarm (kod, jak wyłączyć i włączyć)', 'Alarme (code, désactiver et réactiver)', 500, true],
        'key' => ['Klucz (gdzie jest, numer, skrytka)', 'Clé (où, numéro, boîte à clé)', 500, true],
        'freezer' => ['Zamrażarka (gdzie, jak ułożyć towar)', 'Congélateur (où, comment ranger)', 1000, false],
        'contact' => ['Kontakt na miejscu (osoba, telefon)', 'Contact sur place (personne, téléphone)', 300, false],
    ];

    public function __construct(private CustomerDeliveryInfoRepository $repo, private DeliveryInfoCipher $cipher)
    {
    }

    /** Instrukcje klienta do formularza : wszystkie pola (puste = ''), autor i data zmiany, nieczytelne po zmianie sekretu. */
    public function get(int $customerId): array
    {
        $row = $this->repo->row($customerId);
        $fields = $this->decode($row['payload'] ?? null);
        return [
            'fields' => $fields ?? array_fill_keys(array_keys(self::FIELDS), ''),
            'updated_by' => $row['updated_by'] ?? null,
            'updated_at' => $row['updated_at'] ?? null,
            'unreadable' => $row !== null && $fields === null,
        ];
    }

    /** @return array<int, array<string,string>> niepuste instrukcje klientów (tablet kierowcy) */
    public function forCustomers(array $customerIds): array
    {
        $out = [];
        foreach ($this->repo->rows($customerIds) as $id => $row) {
            $fields = array_filter($this->decode($row['payload']) ?? [], fn($v) => $v !== '');
            if ($fields) $out[(int) $id] = $fields;
        }
        return $out;
    }

    /** Zapis z formularza (pola delivery_*) ; wszystkie puste = usunięcie. */
    public function save(int $customerId, array $input, ?string $author): void
    {
        $fields = [];
        foreach (self::FIELDS as $key => [$label, , $max]) {
            $value = trim(str_replace("\r\n", "\n", (string) ($input['delivery_' . $key] ?? '')));
            if (mb_strlen($value) > $max) throw new CustomException("{$label} : najwyżej {$max} znaków.", 'errors', 422);
            $fields[$key] = $value;
        }
        if (implode('', $fields) === '') {
            $this->repo->remove($customerId);
            return;
        }
        $this->repo->save($customerId, $this->cipher->encrypt(json_encode($fields, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)), $author !== null ? mb_substr($author, 0, 160) : null);
    }

    /** @return array<string,string>|null */
    private function decode(?string $payload): ?array
    {
        if ($payload === null) return null;
        $plain = $this->cipher->decrypt($payload);
        $data = $plain !== null ? json_decode($plain, true) : null;
        if (!is_array($data)) return null;
        $out = [];
        foreach (array_keys(self::FIELDS) as $key) $out[$key] = is_string($data[$key] ?? null) ? $data[$key] : '';
        return $out;
    }
}
