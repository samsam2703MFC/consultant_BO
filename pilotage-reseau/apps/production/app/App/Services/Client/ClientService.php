<?php
namespace App\Services\Client;


use App\Core\TransactionManager;
use App\Exceptions\CustomException;
use App\Repositories\Client\ClientRepository;
use App\Repositories\Client\Order\OrderProductRepository;
use App\Repositories\Client\Order\OrderRepository;
use App\Repositories\Product\Price\PriceListRepository;
use App\Repositories\Utils\RecordHistoryRepository;
use App\Services\Client\Vies\ViesClient;
use App\Services\Client\Vies\ViesUnavailableException;
use InvalidArgumentException;

class ClientService
{
    private ClientRepository $clientRepository;
    private TransactionManager $transactionManager;
    private PriceListRepository $priceListRepository;
    private ViesClient $viesClient;

    public function __construct(
        ClientRepository $clientRepository,
        TransactionManager $transactionManager,
        PriceListRepository $priceListRepository,
        ViesClient $viesClient,
        private RecordHistoryRepository $history
    )
    {
        $this->clientRepository = $clientRepository;
        $this->transactionManager = $transactionManager;
        $this->priceListRepository = $priceListRepository;
        $this->viesClient = $viesClient;
    }

    public function getAll()
    {
        return $this->clientRepository->findAll();
    }

    /** Pola e-mail klienta : sterują dystrybucją dokumentów, więc każdy adres musi być poprawny. */
    public const EMAIL_FIELDS = ['email_head' => 'E-mail właściciela', 'email_salesman' => 'E-mail handlowca', 'email_accountant' => 'E-mail księgowości'];

    /** Termin płatności : liczba dni (puste = brak terminu). */
    public const PAYMENT_DAYS_MAX = 365;

    /**
     * Firma i NIP wymagane (dokumenty, KSeF, VIES) ; osoba kontaktowa opcjonalna ; e-maile sprawdzane
     * (kilka adresów rozdzielonych przecinkiem lub średnikiem) ; termin płatności w dniach 0–365.
     */
    public function validate($data)
    {
        $errors = [];

        if (trim((string) ($data['company'] ?? '')) === '') {
            $errors['company'] = "Wprowadź nazwę firmy";
        } elseif (mb_strlen(trim((string) $data['company'])) > 60) {
            $errors['company'] = "Nazwa firmy: najwyżej 60 znaków";
        }
        if (trim((string) ($data['tax_number'] ?? '')) === '') {
            $errors['tax_number'] = "Wprowadź NIP / numer VAT";
        }

        if (empty($data['fac_street'])) {
            $errors['fac_street'] = "Wprowadź nazwę ulicę";
        }
        if (empty($data['fac_street_num'])) {
            $errors['fac_street_num'] = "Wprowadź numer budynku";
        }
        if (empty($data['fac_zip'])) {
            $errors['fac_zip'] = "Wprowadź kod pocztowy";
        }
        if (empty($data['fac_town'])) {
            $errors['fac_town'] = "Wprowadź miejscowość";
        }
        if (empty($data['id_country'])) {
            $errors['id_country'] = "Wybierz kraj";
        }
        if (isset($data['communication_lang']) && !in_array($data['communication_lang'], \App\Services\Utils\DocumentLabels::LANGUAGES, true)) {
            $errors['communication_lang'] = "Wybierz język komunikacji";
        }
        foreach (self::EMAIL_FIELDS as $field => $label) {
            $bad = self::invalidEmails((string) ($data[$field] ?? ''));
            if ($bad) $errors[$field] = "{$label}: nieprawidłowy adres " . implode(', ', $bad);
        }
        $days = trim((string) ($data['payment_termin'] ?? ''));
        if ($days !== '' && (!preg_match('/^\d+$/', $days) || (int) $days > self::PAYMENT_DAYS_MAX)) {
            $errors['payment_termin'] = "Termin płatności: liczba dni od 0 do " . self::PAYMENT_DAYS_MAX;
        }

        return $errors;
    }

    /** Nieprawidłowe adresy z pola (adresy rozdzielone przecinkiem, średnikiem lub spacją). */
    public static function invalidEmails(string $value): array
    {
        $bad = [];
        foreach (preg_split('/[\s,;]+/', trim($value), -1, PREG_SPLIT_NO_EMPTY) as $email) {
            if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) $bad[] = $email;
        }
        return $bad;
    }

    /** Termin płatności : puste pole = brak terminu (NULL), inaczej liczba dni. */
    private function normalizePaymentTerm(array $data): array
    {
        if (array_key_exists('payment_termin', $data)) {
            $days = trim((string) $data['payment_termin']);
            $data['payment_termin'] = $days === '' ? null : (int) $days;
        }
        return $data;
    }

    public function getFranchisee()
    {
        return $this->clientRepository->getFranchisee();
    }
    public function getActive()
    {
        return $this->clientRepository->getActive();
    }
    public function getInactive()
    {
        return $this->clientRepository->getInactive();
    }

    public function update($id, $data)
    {
        $data = $this->normalizePaymentTerm($this->normalizeTaxNumber((array) $data));
        return $this->clientRepository->update($id, $data);
    }

    public function insert($data)
    {
        $data = $this->normalizePaymentTerm($this->normalizeTaxNumber((array) $data));
        return $this->transactionManager->execute(function () use ($data) {

            $resInsert = $this->clientRepository->insert($data);
            if(!$resInsert){
                throw new CustomException("INSERT_CLIENT_ERROR", 'errors', 500);
            }
            $insertedId = $resInsert;

            if(isset($data['price_list_type']) && $data['price_list_type'] == "standard_price_list"){
                $resPriceList = $this->priceListRepository->assignStandardPriceList(['id_customer' => $insertedId]);
                if(!$resPriceList){
                    throw new CustomException("INSERT_PRICE_LIST_ERROR", 'errors', 500);
                }
            } else {
                $resPriceList = $this->priceListRepository->copyStandardPriceList([
                    'id_customer' => $insertedId,
                    'id_copy_from_customer' => $data['customer_price_list_sel']
                ]);
                if(!$resPriceList){
                    throw new CustomException("INSERT_PRICE_LIST_ERROR", 'errors', 500);
                }
            }
            return $insertedId;
        });
    }

    public function lookupVies(string $vat): array
    {
        try {
            return ['ok' => true, 'data' => $this->viesClient->lookup($vat), 'code' => 200];
        } catch (InvalidArgumentException $exception) {
            return ['ok' => false, 'message' => $exception->getMessage(), 'code' => 422];
        } catch (ViesUnavailableException $exception) {
            return ['ok' => false, 'message' => $exception->getMessage(), 'code' => 503];
        }
    }

    private function normalizeTaxNumber(array $data): array
    {
        if (!isset($data['tax_number']) || trim((string) $data['tax_number']) === '') {
            return $data;
        }

        try {
            $data['tax_number'] = ViesClient::normalizeVat((string) $data['tax_number'])['vat'];
        } catch (InvalidArgumentException) {
            $data['tax_number'] = strtoupper(trim((string) $data['tax_number']));
        }

        return $data;
    }

    /** Klient z historią (zamówienia, dokumenty, palety) nie jest usuwany : dokumenty zostałyby bez klienta. */
    public function delete($id)
    {
        if ($used = $this->history->customer((int) $id)) {
            throw new CustomException('Klient ma historię (' . RecordHistoryRepository::describe($used)
                . ') i nie może zostać usunięty. Ustaw go jako nieaktywnego na liście klientów.', 'errors', 409);
        }
        return $this->clientRepository->delete($id);
    }

    public function getById($id)
    {
        return $this->clientRepository->first(['id' => $id]);
    }

    function generateFranchiseeCode(string $name): string
    {
        // Normalize the name (e.g. "Donut Planet" → "donut")
        $short = strtolower(preg_replace('/[^a-z0-9]/i', '', explode(' ', $name)[0]));
        $short = substr($short, 0, 8); // limit to 8 chars

        // Generate random suffix
        $random = substr(bin2hex(random_bytes(3)), 0, 6); // e.g. 'a3f91c'

        // Combine into code
        return "frz-{$short}-{$random}";
    }
}
