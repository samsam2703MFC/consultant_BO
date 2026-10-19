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

]);

$container = $builder->build();

return $container;
