<?php
use FastRoute\RouteCollector;

return function (RouteCollector $r) {
    $c = App\Controllers\Web\Organization\PositionController::class;

    $r->addRoute('GET', '/organization/positions', ['controller' => $c, 'method' => 'positions']);
    $r->addRoute('GET', '/organization/users/{id:\d+}', ['controller' => $c, 'method' => 'userSheet']);
    // Karty stanowisk (fiches de poste) : widok, edycja, zatwierdzenie, pracownicy produkcji, PDF.
    $r->addRoute('GET', '/organization/positions/{id:\d+}', ['controller' => $c, 'method' => 'sheet']);
    $r->addRoute('GET', '/organization/positions/{id:\d+}/sheet.pdf', ['controller' => $c, 'method' => 'sheetPdf']);
    $r->addRoute('PUT', '/ajax/organization/positions/{id:\d+}/sheet', ['controller' => $c, 'method' => 'saveSheet']);
    $r->addRoute('POST', '/ajax/organization/positions/{id:\d+}/sheet/validate', ['controller' => $c, 'method' => 'validateSheet']);
    $r->addRoute('PUT', '/ajax/organization/positions/{id:\d+}/employees', ['controller' => $c, 'method' => 'sheetEmployees']);
    // Uprawnienia stanowisk : które strony widzi każde stanowisko (tylko administrator).
    $r->addRoute('GET', '/organization/access', ['controller' => App\Controllers\Web\Organization\PageAccessController::class, 'method' => 'index']);
    $r->addRoute('PUT', '/ajax/organization/access/{id:\d+}', ['controller' => App\Controllers\Web\Organization\PageAccessController::class, 'method' => 'save']);
    $r->addRoute('PUT', '/ajax/organization/access/{id:\d+}/home', ['controller' => App\Controllers\Web\Organization\PageAccessController::class, 'method' => 'saveHome']);
    $r->addRoute('GET', '/home', ['controller' => App\Controllers\Web\Organization\PageAccessController::class, 'method' => 'home']);
    // Aktywność w ERP : zapis z każdej strony (wyświetlenie, puls) i raport administratora z kosztem godziny.
    $r->addRoute('GET', '/organization/activity', ['controller' => App\Controllers\Web\Organization\ActivityController::class, 'method' => 'index']);
    $r->addRoute('POST', '/ajax/activity/view', ['controller' => App\Controllers\Web\Organization\ActivityController::class, 'method' => 'start']);
    $r->addRoute('POST', '/ajax/activity/beat', ['controller' => App\Controllers\Web\Organization\ActivityController::class, 'method' => 'beat']);
    $r->addRoute('PUT', '/ajax/organization/activity/users/{id:\d+}/cost', ['controller' => App\Controllers\Web\Organization\ActivityController::class, 'method' => 'cost']);
    // Plan tygodnia stanowiska (administrator) : dni, minuty, link, czas rzeczywisty ; Dzisiaj « Mój tydzień » go pokazuje.
    $r->addRoute('GET', '/organization/week-plan/{id:\d+}', ['controller' => $c, 'method' => 'weekPlan']);
    $r->addRoute('GET', '/ajax/organization/week-plan/{id:\d+}', ['controller' => $c, 'method' => 'weekPlanData']);
    $r->addRoute('PUT', '/ajax/organization/week-plan/{id:\d+}', ['controller' => $c, 'method' => 'saveWeekPlan']);
    $r->addRoute('POST', '/ajax/organization/week-plan/{id:\d+}/copy', ['controller' => $c, 'method' => 'copyWeekPlan']);
    // Kto co zrobił [227] : wykonanie zadań wszystkich stanowisk (przełożony), przypomnienie.
    $r->addRoute('GET', '/organization/execution', ['controller' => $c, 'method' => 'execution']);
    $r->addRoute('GET', '/ajax/organization/execution', ['controller' => $c, 'method' => 'executionData']);
    $r->addRoute('POST', '/ajax/organization/execution/remind/{id:\d+}', ['controller' => $c, 'method' => 'remindTask']);
    // Zadania zespołu [232] (makiety 7 i 8) : tablica wszystkich grup i osób, filtry, przypomnienia (dzwonek + e-mail, tablet).
    $t = App\Controllers\Web\Organization\TeamTasksController::class;
    $r->addRoute('GET', '/organization/team-tasks', ['controller' => $t, 'method' => 'index']);
    $r->addRoute('GET', '/ajax/organization/team-tasks', ['controller' => $t, 'method' => 'data']);
    $r->addRoute('POST', '/ajax/organization/team-tasks/remind', ['controller' => $t, 'method' => 'remind']);
    $r->addRoute('GET', '/my-tasks', ['controller' => $c, 'method' => 'myTasks']);
    $r->addRoute('PATCH', '/ajax/my-tasks/{id:\d+}', ['controller' => $c, 'method' => 'setStatus']);
    $r->addRoute('POST', '/ajax/organization/positions', ['controller' => $c, 'method' => 'savePosition']);
    $r->addRoute('PATCH', '/ajax/organization/positions/{id:\d+}', ['controller' => $c, 'method' => 'savePosition']);
    $r->addRoute('POST', '/ajax/organization/positions/{id:\d+}/tasks', ['controller' => $c, 'method' => 'addTask']);
    $r->addRoute('PATCH', '/ajax/organization/tasks/{id:\d+}', ['controller' => $c, 'method' => 'updateTask']);
    $r->addRoute('POST', '/ajax/organization/task-categories', ['controller' => $c, 'method' => 'saveCategory']);
    $r->addRoute('PATCH', '/ajax/organization/task-categories/{id:\d+}', ['controller' => $c, 'method' => 'saveCategory']);

    // Zadania z formularzem (protokół zebrania) i śledzenie produkcji.
    $f = App\Controllers\Web\Organization\TaskFormController::class;
    $r->addRoute('GET', '/my-tasks/{id:\d+}/form', ['controller' => $f, 'method' => 'form']);
    $r->addRoute('PUT', '/ajax/my-tasks/{id:\d+}/form', ['controller' => $f, 'method' => 'save']);
    $r->addRoute('GET', '/task-forms', ['controller' => $f, 'method' => 'list']);
    $r->addRoute('GET', '/task-forms/{id:\d+}', ['controller' => $f, 'method' => 'show']);
    $r->addRoute('GET', '/production/follow-up', ['controller' => $f, 'method' => 'followUp']);
    $r->addRoute('PUT', '/ajax/organization/users/{id:\d+}/position', ['controller' => $c, 'method' => 'assign']);
    $r->addRoute('PUT', '/ajax/organization/users/{id:\d+}/module-role', ['controller' => $c, 'method' => 'moduleRole']);
};
