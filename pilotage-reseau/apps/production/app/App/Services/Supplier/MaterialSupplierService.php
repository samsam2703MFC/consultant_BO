<?php
namespace App\Services\Supplier;

use App\Repositories\Supplier\MaterialSupplierRepository;
use App\Repositories\Workshop\InvoiceRepository;

/**
 * Dostawcy surowców. Kreator (audyt 2, lot C) : nazwa, kontakt, NIP (faktury KSeF tego NIP-u od razu przypisane
 * do dostawcy), zwykłe dni dostaw (podpowiedź daty w zamówieniach), język zamówień e-mailem, aktywny — od utworzenia.
 */
class MaterialSupplierService
{
    /** Dni dostaw : maska bitowa (poniedziałek = 1 … niedziela = 64). */
    public const WEEKDAYS = [1 => 'pon.', 2 => 'wt.', 3 => 'śr.', 4 => 'czw.', 5 => 'pt.', 6 => 'sob.', 7 => 'niedz.'];
    public const ORDER_LANGS = ['pl' => 'polski', 'fr' => 'francuski', 'nl' => 'niderlandzki', 'en' => 'angielski', 'de' => 'niemiecki'];

    public function __construct(
        private MaterialSupplierRepository $supplierRepository,
        private InvoiceRepository $invoices
    )
    {
    }

    /** @return array<string, string> pole => komunikat (nazwa wymagana ; e-mail i NIP sprawdzane, gdy podane) */
    public function validate($data)
    {
        $errors = [];
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') $errors['name'] = 'Wprowadź nazwę dostawcy';
        elseif (mb_strlen($name) > 255) $errors['name'] = 'Nazwa : najwyżej 255 znaków';
        $email = trim((string) ($data['email'] ?? ''));
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors['email'] = 'E-mail jest nieprawidłowy : ' . $email;
        $nip = self::normalizeNip($data['nip'] ?? '');
        if ($nip !== '' && !preg_match('/^[A-Z]{0,2}[0-9A-Z]{8,14}$/', $nip)) $errors['nip'] = 'NIP / VAT : 8–14 znaków (cyfry, opcjonalnie prefiks kraju)';
        $lang = (string) ($data['order_lang'] ?? '');
        if ($lang !== '' && !isset(self::ORDER_LANGS[$lang])) $errors['order_lang'] = 'Nieznany język zamówień';
        return $errors;
    }

    public function getActive()
    {
        return $this->supplierRepository->getActive();
    }

    public function insert($data)
    {
        $row = $this->row((array) $data) + ['is_active' => array_key_exists('is_active', (array) $data) ? (int) !empty($data['is_active']) : 1];
        $id = $this->supplierRepository->insert($row);
        if ($id && $row['nip'] !== null) $this->invoices->rememberSupplier($row['nip'], (int) $id);
        return $id;
    }

    public function update($id, $data)
    {
        $row = $this->row((array) $data);
        if (array_key_exists('is_active', (array) $data)) $row['is_active'] = (int) !empty($data['is_active']);
        $ok = $this->supplierRepository->update($id, $row);
        if ($ok && $row['nip'] !== null) $this->invoices->rememberSupplier($row['nip'], (int) $id);
        return $ok;
    }

    public function delete($id)
    {
        return $this->supplierRepository->delete($id);
    }

    public function getById($id)
    {
        return $this->supplierRepository->first(['id' => $id]);
    }

    public static function normalizeNip($nip): string
    {
        return strtoupper((string) preg_replace('/[\s\-.]/', '', (string) $nip));
    }

    /** Dni z formularza (delivery_days[] = 1…7) → maska ; brak = null (nieznane). */
    public static function weekdayMask(array $days): ?int
    {
        $mask = 0;
        foreach ($days as $d) if (isset(self::WEEKDAYS[(int) $d])) $mask |= 1 << ((int) $d - 1);
        return $mask ?: null;
    }

    /** @return list<int> dni 1…7 z maski */
    public static function weekdays(?int $mask): array
    {
        return array_values(array_filter(array_keys(self::WEEKDAYS), fn($d) => ($mask ?? 0) & (1 << ($d - 1))));
    }

    private function row(array $data): array
    {
        $nip = self::normalizeNip($data['nip'] ?? '');
        $lang = (string) ($data['order_lang'] ?? '');
        return [
            'name' => trim((string) ($data['name'] ?? '')),
            'phone' => trim((string) ($data['phone'] ?? '')),
            'email' => trim((string) ($data['email'] ?? '')),
            'nip' => $nip !== '' ? $nip : null,
            'delivery_weekdays' => self::weekdayMask((array) ($data['delivery_days'] ?? [])),
            'order_lang' => isset(self::ORDER_LANGS[$lang]) ? $lang : null,
        ];
    }
}
