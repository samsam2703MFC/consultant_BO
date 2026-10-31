# Kandydaci do wycofania

## Zasada bezpieczeństwa

Żadna pozycja nie jest rekomendacją natychmiastowego usunięcia. Przed `REMOVE` trzeba potwierdzić runtime, konsumentów, dane, zależności integracyjne, obowiązki retencyjne i ścieżkę migracji. W razie braku dowodu moduł należy odłączyć logicznie lub oznaczyć jako legacy, nie kasować danych.

| Kandydat | Powód | Wstępna decyzja | Analiza przed wycofaniem | Warunek bezpiecznego końca |
|---|---|---|---|---|
| `Agent/api_app_dir` jako całość | brak front controllera/routingu w workspace; setki funkcji mogą należeć do innego deploymentu | INVESTIGATE, nie REMOVE | deployment, DNS/traffic, cron, baza, właściciel, logi, wersja frontendu | każdy aktywny konsument wskazany; wartościowe moduły zmigrowane lub formalnie utrzymane |
| starsze mechanizmy login/token obok docelowego IAM | duplikacja i niespójne bezpieczeństwo | REPLACE_THEN_REMOVE | wszystkie entrypointy, sesje, urządzenia, integracje i konta serwisowe | brak ruchu/starych tokenów, revoke/migracja kont, testy auth PASS |
| pole `user.permission` po wdrożeniu RBAC | konkuruje z rolami/permissions Agent | REPLACE_THEN_ARCHIVE | odczyty/zapisy pola, raporty, uprawnienia historyczne | mapowanie do grantów, brak runtime reads, zachowany audit |
| jedna z rodzin faktur App/Agent | dwie implementacje i tabele | REQUIRES_DECISION | jurysdykcje, numeracje, Peppol, korekty, dokumenty historyczne | jedno source of truth lub jawna granica; read-only archive starych dokumentów |
| `carton_stock` jako źródło prawdy | bieżący status kartonu bez potwierdzonego pełnego ledgera | REPLACE_AS_SOURCE, zachować dane migracyjne | wszystkie statusy, dokumenty, unikalność kartonu, historia/palety | saldo otwarcia i ledger uzgodnione; stara tabela tylko projection/archive |
| Agent `material_inventory*` jako równoległe source of truth | niepotwierdzony runtime i konflikt z docelowym ledgerem | INVESTIGATE_THEN_MERGE | liczności, log completeness, saldo, sklepy, reasons, constraints | ruchy zmigrowane/uzgodnione; brak podwójnego zapisu |
| lokalne outboxy o odmiennych statusach | wiele kontraktów, brak centralnej konsoli | CONSOLIDATE | producenci/konsumenci, payload versions, retention, pending/dead messages | backlog zero/migrated, każdy producent używa wspólnego kontraktu |
| stare/zakomentowane RabbitMQ dispatchery | kod nieaktywny zwiększa niepewność | REMOVE_AFTER_CONFIRMATION | deployment config, queue traffic, monitoring, repo history | brak aktywnych consumers/producers, decyzja adaptera integracyjnego |
| `App/ToRefactor` i `*/ToRefactor/*` | jawny legacy/debt | INVESTIGATE_PER_CLASS | referencje DI/routes/static calls, data access, cron | zero referencji/runtime traffic i test zastępczej funkcji |
| pliki `.backup`, `.tmp`, `new_old.view.php` | duplikaty widoków mogą nie być używane | REMOVE_AFTER_ROUTE_SCAN | routing, includes, asset links, operator bookmarks | zero referencji oraz smoke aktualnego widoku |
| widok kategorii przeniesiony do `.backup` | niejasny stan aktualnego frontendu | REQUIRES_DECISION | route/controller/template resolution i regresja UI | jeden zatwierdzony widok, brak linków do backupu |
| tymczasowe `production_list_tmp` jako trwały mechanizm | nazwa i sposób agregacji sugerują projekcję/obejście | REPLACE_WITH_PLAN_MODEL | lifecycle rekordów, cleanup, consumers, wieloużytkownikowość | plan/order model daje ten sam zatwierdzony wynik, dane przejściowe nie są źródłem prawdy |
| spłaszczone tabele receptur jako źródło historyczne | projekcja może być nadpisywana i ma TODO zapisu | KEEP_AS_REBUILDABLE_PROJECTION | wszystkie consumers, rebuild algorithm, version association | projekcja ma recipe_version i można ją deterministycznie odbudować |
| dashboardy oparte wyłącznie na WDT | WDT nie jest pełnym faktem sprzedaży | REPLACE_DATA_SOURCE, nie usuwać UI automatycznie | użytkownicy, eksporty, definicje KPI, okresy porównawcze | UI korzysta z kanonicznego faktu, stary wynik oznaczony/archiwalny |
| raport rentowności z bieżącej receptury/ceny | wynik historycznie niestabilny | DISABLE_OR_LABEL_UNTIL_REBUILT | odbiorcy, decyzje podejmowane na raporcie, zakres historii | nowy snapshot kosztu uzgodniony; stary raport jasno oznaczony jako estimate |
| stare migracje SQL/MD bez runnera | nie można ich bezpiecznie stosować jako ciągu | ARCHIVE_AFTER_BASELINE | które środowiska użyły każdej delty, checksumy schematu | zatwierdzony baseline i mapa wersji; stare pliki zachowane audytowo, nie wykonywalnie |
| tabele changelog/history bez konsumentów | mogą być cenne prawnie mimo braku UI | KEEP_UNTIL_RETENTION_DECISION | liczność, zakres dat, PII, FK, raporty/audyt, polityka retencji | decyzja właściciela danych i zgodność prawna; ewentualny eksport/archive |

## Dowód braku użycia

Minimum przed wycofaniem mechanizmu to: brak referencji statycznych, brak rejestracji DI/tras/cronów, brak ruchu w logach przez uzgodniony okres, brak zapisów/odczytów DB, brak zewnętrznych konsumentów, zatwierdzony właściciel biznesowy oraz test procesu zastępczego. Sam brak entrypointu w tym workspace nie wystarcza.
