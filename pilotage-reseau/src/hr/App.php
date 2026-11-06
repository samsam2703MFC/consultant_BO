<?php

declare(strict_types=1);

namespace Hr;

use Hr\Dimona\DbTokenCache;
use Hr\Dimona\DimonaAuthService;
use Hr\Dimona\DimonaClient;
use Hr\Dimona\DimonaService;
use Hr\Directory\Directory;
use Hr\Support\Config;
use Hr\Support\Crypto;
use Hr\Support\HttpClient;

/** Le module assemblé : configuration, chiffrement, client ONSS, services, annuaire. */
final class App
{
    public readonly DimonaAuthService $auth;
    public readonly DimonaClient $client;
    public readonly DimonaService $dimona;

    public function __construct(
        public readonly Config $config,
        public readonly Crypto $crypto,
        public readonly HttpClient $http,
        public readonly Directory $directory,
    ) {
        $this->auth = new DimonaAuthService($http, $config, new DbTokenCache($crypto));
        $this->client = new DimonaClient($http, $this->auth, $config);
        $this->dimona = new DimonaService($config, $crypto, $this->client, $this->auth);
    }
}
