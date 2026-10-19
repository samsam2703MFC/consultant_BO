<?php
use FastRoute\RouteCollector;


// Zwróć jedną closure, którą później przekażemy do simpleDispatcher
return function(RouteCollector $r) {
    // rekurencyjnie wszystkie pliki *Routes.php w podkatalogach
    $pattern = __DIR__ . '/*/*Routes.php';

    foreach (glob($pattern) as $file) {
        $routesDef = require $file;
        if (is_callable($routesDef)) {
            $routesDef($r);
        }
    }
};