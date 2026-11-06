<?php

declare(strict_types=1);

/**
 * Autoloader PSR-4 minimal du module RH (`Hr\` → src/hr/), comme celui du module marketing : le cockpit
 * n'a pas de Composer. Le jour où le module rejoint l'ERP, il suffit de déclarer « "Hr\\": "src/hr/" »
 * dans son composer.json.
 */
spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'Hr\\')) {
        return;
    }
    $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, 3)) . '.php';
    if (is_file($file)) {
        require $file;
    }
});
