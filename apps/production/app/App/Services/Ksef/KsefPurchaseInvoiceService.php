<?php

namespace App\Services\Ksef;

use App\Repositories\Company\ErpCompanyDataRepository;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Stateless KSeF purchase-invoice browser. It never writes invoice data to the local database.
 */
final class KsefPurchaseInvoiceService
{
    private const PAGE_SIZE = 25;

    public function __construct(
        private KsefInvoiceSender $sender,
        private KsefApiClient $api,
        private ErpCompanyDataRepository $company,
    ) {
    }

    /** @return array{invoices:list<array<string,mixed>>,hasMore:bool,isTruncated:bool} */
    public function list(array $filters): array
    {
        $pageOffset = max(0, (int) ($filters['pageOffset'] ?? 0));
        $request = $this->filters($filters);
        $environment = $this->sender->environment();
        $query = '/invoices/query/metadata?sortOrder=Desc&pageOffset=' . $pageOffset . '&pageSize=' . self::PAGE_SIZE;
        $response = $this->withAccessToken(fn (string $token) => $this->api->post($environment, $query, $request, $token));

        $invoices = is_array($response['invoices'] ?? null) ? $response['invoices'] : [];
        $invoices = array_map(fn (mixed $invoice): mixed => is_array($invoice) ? $this->withQrUrl($invoice, $environment) : $invoice, $invoices);

        return [
            'invoices' => $invoices,
            'hasMore' => (bool) ($response['hasMore'] ?? false),
            'isTruncated' => (bool) ($response['isTruncated'] ?? false),
        ];
    }

    public function invoiceXml(string $ksefNumber): string
    {
        $number = trim($ksefNumber);
        if ($number === '') {
            throw new InvalidArgumentException('Brak numeru KSeF faktury.');
        }

        return $this->withAccessToken(fn (string $token) => $this->api->getRaw($this->sender->environment(), '/invoices/ksef/' . rawurlencode($number), $token));
    }

    /** @return array<string,mixed> */
    private function filters(array $input): array
    {
        $from = $this->date((string) ($input['from'] ?? ''));
        $to = $this->date((string) ($input['to'] ?? ''));
        if ($from === null || $to === null) {
            throw new InvalidArgumentException('Wybierz datę początkową i końcową zakresu.');
        }
        if ($from > $to) {
            throw new InvalidArgumentException('Data początkowa nie może być późniejsza niż końcowa.');
        }
        if ($from->modify('+3 months')->setTime(0, 0) <= $to->setTime(23, 59, 59)) {
            throw new InvalidArgumentException('KSeF pozwala wyszukiwać okres krótszy niż 3 miesiące w jednym zapytaniu.');
        }

        $dateType = (string) ($input['dateType'] ?? 'PermanentStorage');
        if (!in_array($dateType, ['Issue', 'Invoicing', 'PermanentStorage'], true)) {
            throw new InvalidArgumentException('Nieprawidłowy typ daty dla wyszukiwania KSeF.');
        }

        $subjectType = (string) ($input['subjectType'] ?? 'Subject2');
        if (!in_array($subjectType, ['Subject2', 'Subject3'], true)) {
            throw new InvalidArgumentException('Nieprawidłowa rola podmiotu dla wyszukiwania KSeF.');
        }

        $filters = [
            'subjectType' => $subjectType,
            'dateRange' => [
                'dateType' => $dateType,
                'from' => $from->setTime(0, 0)->format(DATE_ATOM),
                'to' => $to->setTime(23, 59, 59)->format(DATE_ATOM),
            ],
        ];
        foreach (['sellerNip', 'invoiceNumber', 'ksefNumber'] as $field) {
            $value = trim((string) ($input[$field] ?? ''));
            if ($value !== '') {
                $filters[$field] = $field === 'sellerNip' ? preg_replace('/\D/', '', $value) : $value;
            }
        }
        $currency = strtoupper(trim((string) ($input['currency'] ?? '')));
        if ($currency !== '') {
            if (!preg_match('/^[A-Z]{3}$/', $currency)) {
                throw new InvalidArgumentException('Kod waluty musi mieć trzy litery, np. PLN lub EUR.');
            }
            $filters['currencyCodes'] = [$currency];
        }

        return $filters;
    }

    private function date(string $value): ?DateTimeImmutable
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('Europe/Warsaw'));
        return $date instanceof DateTimeImmutable ? $date : null;
    }

    private function companyNip(): string
    {
        $company = $this->company->first(['id' => 1]);
        $nip = preg_replace('/\D/', '', (string) ($company['wdt_company_nip'] ?? $company['wz_company_nip'] ?? ''));
        if (!preg_match('/^\d{10}$/', $nip)) {
            throw new \RuntimeException('Brak poprawnego NIP firmy w danych ERP. Uzupełnij go przed użyciem przeglądarki KSeF.');
        }
        return $nip;
    }

    /** @param array<string,mixed> $invoice @return array<string,mixed> */
    private function withQrUrl(array $invoice, string $environment): array
    {
        $seller = is_array($invoice['seller'] ?? null) ? $invoice['seller'] : [];
        $nip = preg_replace('/\D/', '', (string) ($seller['nip'] ?? ''));
        $date = trim((string) ($invoice['issueDate'] ?? ''));
        $hash = rtrim(strtr(trim((string) ($invoice['invoiceHash'] ?? '')), '+/', '-_'), '=');
        if (!preg_match('/^\d{10}$/', $nip) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $hash === '') {
            return $invoice;
        }

        $host = $environment === 'production' ? 'https://qr.ksef.mf.gov.pl' : 'https://qr-test.ksef.mf.gov.pl';
        $invoice['qrUrl'] = $host . '/invoice/' . $nip . '/' . substr($date, 8, 2) . '-' . substr($date, 5, 2) . '-' . substr($date, 0, 4) . '/' . $hash;
        return $invoice;
    }

    /**
     * Access token is kept only in the authenticated browser's PHP session. It is neither an
     * invoice nor a persistent integration setting; it prevents a new KSeF authentication for
     * every 25-record AJAX page.
     */
    private function withAccessToken(callable $operation): mixed
    {
        $key = 'ksef_purchase_access_' . $this->sender->environment() . '_' . $this->companyNip();
        $token = $_SESSION[$key] ?? null;
        if (!is_string($token) || $token === '') {
            $token = $this->sender->authenticate($this->companyNip());
            $_SESSION[$key] = $token;
        }

        try {
            return $operation($token);
        } catch (KsefApiException $exception) {
            if ($exception->httpStatus() !== 401) {
                throw $exception;
            }
            unset($_SESSION[$key]);
            $token = $this->sender->authenticate($this->companyNip());
            $_SESSION[$key] = $token;
            return $operation($token);
        }
    }
}
