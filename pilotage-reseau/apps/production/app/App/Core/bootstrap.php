<?php

use DI\ContainerBuilder;
use Psr\Container\ContainerInterface;

use function DI\get;
use function DI\autowire;
use function DI\factory;

require_once __DIR__ . '/../../public/vendor/autoload.php';

$builder = new ContainerBuilder();

// Tu możesz zarejestrować mapowania ręczne
$builder->addDefinitions([
    App\Core\Database::class => factory(fn() => new App\Core\Database()),
    PDO::class => factory(fn(ContainerInterface $c) => $c->get(App\Core\Database::class)->getConnection()),
    App\Services\Communication\SmtpTransport::class => get(App\Services\Communication\GuardedSmtpTransport::class),
    App\Services\Communication\GuardedSmtpTransport::class => autowire()
        ->constructorParameter('smtp', get(App\Services\Communication\GmailSmtpTransport::class)),
    // Kontrola faktur : faktury zakupowe z KSeF (odczyt) ; w testach zastępowane.
    App\Services\Workshop\Supply\KsefInvoiceSource::class => get(App\Services\Workshop\Supply\KsefPurchaseSource::class),
    // Kolejka « Do obsłużenia » : faktury z podwyżką ceny ponad cenę bazową (parametr opcjonalny — autowiring go pomija).
    App\Services\Client\Order\OrderQueueService::class => autowire()
        ->constructorParameter('invoices', get(App\Services\Workshop\Supply\InvoiceService::class))
        ->constructorParameter('buy', get(App\Services\Workshop\Supply\BuyService::class)),
    // SMS : bramka SMSAPI (jak CRM) ; bez tokenu albo na kopii testowej tylko zapis (n_sms_log).
    App\Services\Logistics\Sms\SmsTransport::class => get(App\Services\Logistics\Sms\SmsGateway::class),
    // [438] Różnice kursowe : API publiczne NBP (kursy średnie, tabela A) ; w testach fałszywy klient.
    App\Services\Finances\Fx\NbpHttpClient::class => get(App\Services\Finances\Fx\CurlNbpHttpClient::class),
    // [439] Wyciągi bankowe : ING (format do uzupełnienia po przykładowym wyciągu — na razie « nieobsługiwany », wpisy ręczne).
    App\Services\Finances\Fx\BankStatementParser::class => get(App\Services\Finances\Fx\IngStatementParser::class),

]);

$container = $builder->build();

return $container;
