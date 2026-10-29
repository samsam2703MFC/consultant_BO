<?php
use FastRoute\RouteCollector;

return function(RouteCollector $r) {

    
    $r->addRoute('GET', '/test-products', [
        'controller' => App\Controllers\Web\Product\TestProductController::class,
        'method'     => 'overview'
    ]);

    $r->addRoute('GET', '/test-products/new', [
        'controller' => App\Controllers\Web\Product\TestProductController::class,
        'method'     => 'newProduct'
    ]);

    $r->addRoute('POST', '/test-products/new', [
        'controller' => App\Controllers\Web\Product\TestProductController::class,
        'method'     => 'newProduct'
    ]);

    $r->addRoute('GET', '/test-products/{id:\d+}/edit', [
        'controller' => App\Controllers\Web\Product\TestProductController::class,
        'method'     => 'editProduct'
    ]);

    $r->addRoute('POST', '/test-products/{id:\d+}/edit', [
        'controller' => App\Controllers\Web\Product\TestProductController::class,
        'method'     => 'editProduct'
    ]);

    $r->addRoute('GET', '/test-products/{id:\d+}/delete', [
        'controller' => App\Controllers\Web\Product\TestProductController::class,
        'method'     => 'deleteProduct'
    ]);

    $r->addRoute('POST', '/test-products/{id:\d+}/delete', [
        'controller' => App\Controllers\Web\Product\TestProductController::class,
        'method'     => 'deleteProduct'
    ]);

    $r->addRoute('PATCH', '/ajax/test-products/{id:\d+}', [
        'controller' => App\Controllers\Web\Product\TestProductController::class,
        'method'     => 'ajaxEditProduct'
    ]);




    $r->addRoute('GET', '/test-products/{idProduct:\d+}/changelogs', [
        'controller' => App\Controllers\Web\Product\TestProductChangelogController::class,
        'method'     => 'overview'
    ]);

    $r->addRoute('GET', '/test-products/{idProduct:\d+}/changelogs/new', [
        'controller' => App\Controllers\Web\Product\TestProductChangelogController::class,
        'method'     => 'newLog'
    ]);

    $r->addRoute('POST', '/test-products/{idProduct:\d+}/changelogs/new', [
        'controller' => App\Controllers\Web\Product\TestProductChangelogController::class,
        'method'     => 'newLog'
    ]);

    $r->addRoute('GET', '/test-products/{idProduct:\d+}/changelogs/{id:\d+}/edit', [
        'controller' => App\Controllers\Web\Product\TestProductChangelogController::class,
        'method'     => 'editLog'
    ]);

    $r->addRoute('POST', '/test-products/{idProduct:\d+}/changelogs/{id:\d+}/edit', [
        'controller' => App\Controllers\Web\Product\TestProductChangelogController::class,
        'method'     => 'editLog'
    ]);

    $r->addRoute('GET', '/test-products/{idProduct:\d+}/changelogs/{id:\d+}/delete', [
        'controller' => App\Controllers\Web\Product\TestProductChangelogController::class,
        'method'     => 'deleteLog'
    ]);


    $r->addRoute('POST', '/test-products/{idProduct:\d+}/changelogs/{id:\d+}/delete', [
        'controller' => App\Controllers\Web\Product\TestProductChangelogController::class,
        'method'     => 'deleteLog'
    ]);



    $r->addRoute('GET', '/test-product-stages', [
        'controller' => App\Controllers\Web\Product\TestProductStageController::class,
        'method'     => 'overview'
    ]);

    $r->addRoute('GET', '/test-product-stages/new', [
        'controller' => App\Controllers\Web\Product\TestProductStageController::class,
        'method'     => 'newStage'
    ]);

    $r->addRoute('POST', '/test-product-stages/new', [
        'controller' => App\Controllers\Web\Product\TestProductStageController::class,
        'method'     => 'newStage'
    ]);

    $r->addRoute('GET', '/test-product-stages/{id:\d+}/edit', [
        'controller' => App\Controllers\Web\Product\TestProductStageController::class,
        'method'     => 'editStage'
    ]);

    $r->addRoute('POST', '/test-product-stages/{id:\d+}/edit', [
        'controller' => App\Controllers\Web\Product\TestProductStageController::class,
        'method'     => 'editStage'
    ]);

    $r->addRoute('GET', '/test-product-stages/{id:\d+}/delete', [
        'controller' => App\Controllers\Web\Product\TestProductStageController::class,
        'method'     => 'deleteStage'
    ]);


    $r->addRoute('POST', '/test-product-stages/{id:\d+}/delete', [
        'controller' => App\Controllers\Web\Product\TestProductStageController::class,
        'method'     => 'deleteStage'
    ]);


    $r->addRoute('PATCH', '/ajax/test-product-stages/prio', [
        'controller' => App\Controllers\Web\Product\TestProductStageController::class,
        'method'     => 'ajaxUpdatePriority'
    ]);

    // Nowe produkty (CRM) : tablica etapów, karta produktu (grupa i jej tablet, testy, etap, wprowadzenie), film, notatki, zdjęcia.
    $d = App\Controllers\Web\Product\DevProductController::class;
    $r->addRoute('GET', '/test-products/pipeline', ['controller' => $d, 'method' => 'pipeline']);
    $r->addRoute('POST', '/ajax/test-products/quick', ['controller' => $d, 'method' => 'create']);
    $r->addRoute('POST', '/ajax/test-products/{id:\d+}/trials', ['controller' => $d, 'method' => 'planTrial']);
    $r->addRoute('DELETE', '/ajax/test-products/{id:\d+}/trials/{trialId:\d+}', ['controller' => $d, 'method' => 'cancelTrial']);
    $r->addRoute('PUT', '/ajax/test-products/{id:\d+}/stage', ['controller' => $d, 'method' => 'setStage']);
    $r->addRoute('PUT', '/ajax/test-products/{id:\d+}/launch', ['controller' => $d, 'method' => 'launch']);
    $r->addRoute('GET', '/test-products/trial-photos/{photoId:\d+}', ['controller' => $d, 'method' => 'trialPhoto']);
    $r->addRoute('GET', '/test-products/{id:\d+}/tablet', ['controller' => $d, 'method' => 'tablet']);
    $r->addRoute('GET', '/test-products/photos/{photoId:\d+}', ['controller' => $d, 'method' => 'photo']);
    $r->addRoute('PUT', '/ajax/test-products/{id:\d+}/tablet', ['controller' => $d, 'method' => 'save']);
    $r->addRoute('POST', '/ajax/test-products/{id:\d+}/photos', ['controller' => $d, 'method' => 'addPhoto']);
    $r->addRoute('DELETE', '/ajax/test-products/{id:\d+}/photos/{photoId:\d+}', ['controller' => $d, 'method' => 'deletePhoto']);

    // Nowy produkt z filmu [196] : link + prompt → asystent AI (klucz Anthropic z Ustawień) → karta technologiczna (.docx, PDF, tablet).
    $v = App\Controllers\Web\Product\DevCardController::class;
    $r->addRoute('GET', '/test-products/from-video', ['controller' => $v, 'method' => 'page']);
    $r->addRoute('GET', '/test-products/from-video/{id:\d+}', ['controller' => $v, 'method' => 'page']);
    $r->addRoute('GET', '/test-products/cards/{id:\d+}/card.docx', ['controller' => $v, 'method' => 'docx']);
    $r->addRoute('GET', '/test-products/cards/{id:\d+}/card.pdf', ['controller' => $v, 'method' => 'pdf']);
    $r->addRoute('POST', '/ajax/dev-cards', ['controller' => $v, 'method' => 'create']);
    $r->addRoute('POST', '/ajax/dev-cards/import', ['controller' => $v, 'method' => 'import']);
    $r->addRoute('POST', '/ajax/dev-cards/{id:\d+}/read', ['controller' => $v, 'method' => 'read']);
    $r->addRoute('GET', '/ajax/dev-cards/{id:\d+}/video', ['controller' => $v, 'method' => 'video']);
    $r->addRoute('POST', '/ajax/dev-cards/{id:\d+}/frames', ['controller' => $v, 'method' => 'frames']);
    $r->addRoute('GET', '/ajax/dev-cards/{id:\d+}/frames/{frameId:\d+}', ['controller' => $v, 'method' => 'frame']);
    $r->addRoute('POST', '/ajax/dev-cards/{id:\d+}/generate', ['controller' => $v, 'method' => 'generate']);
    $r->addRoute('GET', '/ajax/dev-cards/{id:\d+}', ['controller' => $v, 'method' => 'show']);
    $r->addRoute('PUT', '/ajax/dev-cards/{id:\d+}', ['controller' => $v, 'method' => 'update']);
    $r->addRoute('POST', '/ajax/dev-cards/{id:\d+}/save', ['controller' => $v, 'method' => 'save']);
    $r->addRoute('DELETE', '/ajax/dev-cards/{id:\d+}', ['controller' => $v, 'method' => 'delete']);
    // Ustawienia › Asystent AI : klucz Anthropic (zaszyfrowany, tylko 4 ostatnie znaki widoczne), model, prompt w wersjach.
    $r->addRoute('GET', '/settings/ai', ['controller' => $v, 'method' => 'settings']);
    $r->addRoute('GET', '/ajax/settings/ai', ['controller' => $v, 'method' => 'settingsState']);
    $r->addRoute('PUT', '/ajax/settings/ai/key', ['controller' => $v, 'method' => 'saveKey']);
    $r->addRoute('POST', '/ajax/settings/ai/test', ['controller' => $v, 'method' => 'testKey']);
    $r->addRoute('PUT', '/ajax/settings/ai/model', ['controller' => $v, 'method' => 'saveModel']);
    $r->addRoute('PUT', '/ajax/settings/ai/prompt', ['controller' => $v, 'method' => 'savePrompt']);
};
