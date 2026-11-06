<?php


use FastRoute\RouteCollector;

return function(RouteCollector $r) {

    $r->addRoute('GET', '/me', [
        'controller' => \App\Employee\app\Http\Controllers\Me\ProfileController::class,
        'method'     => 'index'
    ]);

    // « Mon profil » (30/10/2026) : une fiche enregistrée, une photo de la carte d'identité — relayées au cockpit.
    $r->addRoute('POST', '/me/profil', [
        'controller' => \App\Employee\app\Http\Controllers\Me\ProfileController::class,
        'method'     => 'enregistrer'
    ]);
    $r->addRoute('POST', '/me/carte', [
        'controller' => \App\Employee\app\Http\Controllers\Me\ProfileController::class,
        'method'     => 'carte'
    ]);
    // Le PIN de caisse, changé par la personne elle-même — écrit au panel.
    $r->addRoute('POST', '/me/pin', [
        'controller' => \App\Employee\app\Http\Controllers\Me\ProfileController::class,
        'method'     => 'pin'
    ]);

};
