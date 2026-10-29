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
    // SMS do klientów (logistyka) : bez skonfigurowanego dostawcy tylko zapis w historii.
    App\Services\Logistics\Sms\SmsTransport::class => get(App\Services\Logistics\Sms\LogOnlySmsTransport::class),

]);

$container = $builder->build();

return $container;
