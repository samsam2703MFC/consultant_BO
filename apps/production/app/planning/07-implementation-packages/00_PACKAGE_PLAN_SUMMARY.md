# Plan pakietów implementacyjnych — podsumowanie

## Decyzja

Master plan został podzielony na **62 wdrażalne pakiety**. Żaden pakiet nie ma wielkości XL. Pakiety L wymagają etapowania wewnętrznego, ale zachowują jeden source of truth, jeden proces lub jedną niepodzielną granicę cutoveru.

Pakiet jest jednostką outcome, nie warstwą techniczną. Obejmuje migrację, backend, UI lub jawne `N/A`, permissions, audit, testy, dokumentację, wdrożenie i rollback. Frontend-only, backend-only i „dodać tabelę” nie są samodzielnymi pakietami.

## Pierwsza fala

Pierwsza fala obejmuje 11 pakietów realizujących MS-01–MS-03:

1. `STB-001` — rotacja sekretów i granica konfiguracji.
2. `STB-002` — bezpieczne logowanie i sesja App.
3. `DSC-001` — mapa runtime, deploymentów, baz i konsumentów.
4. `FND-001` — baseline schematu i runner migracji.
5. `FND-002` — test harness, izolowana DB i CI.
6. `FND-003` — canonical organization/identity mapping.
7. `FND-004` — RBAC/scope w trybie shadow i route cutover.
8. `FND-005` — command context i append-only audit na jednym procesie.
9. `FND-006` — approval/correction kernel na jednej komendzie.
10. `INT-001` — wspólny envelope i migracja jednego outboxu.
11. `SS-001` — Operations Inbox z bezpiecznym retry jednej kolejki.

WIP dla jednej osoby: jeden pakiet mutujący oraz jeden mały read-only/discovery. Kolejność `STB-001 ∥ DSC-001 → STB-002/FND-001 → FND-002 → FND-003 → FND-004 → FND-005 → FND-006/INT-001 → SS-001`.

## Typy i zasady wielkości

| Typ | Zastosowanie | Maksymalny bez podziału |
|---|---|---|
| Stabilization | zamknięcie konkretnej wady istniejącej ścieżki | M |
| Foundation | mały kontrakt wspólny z pierwszym realnym konsumentem | L |
| Vertical feature | jeden proces aktora od danych do UI/E2E | L |
| Migration | jeden model, source, scope i cutover | L |
| Self-service | jedna klasa ingerencji programisty | M/L |
| Reporting | jeden zestaw KPI na potwierdzonym fakcie | M |

XS/S może być realizowany jednym promptem. M/L zwykle wymaga promptu koordynującego i promptów wykonawczych per logiczny krok lub repozytorium. Finalne prompty nie są częścią tego etapu.

## Niezmienne bramy

- brak cutoveru bez clean/upgrade/schema diff i reconciliation;
- brak mutującego UI bez server-side permission/scope i audit;
- brak retry bez stable idempotency key i one-effect E2E;
- brak correction bez immutable original i compensation;
- brak reportingu bez KPI `AVAILABLE` dla zakresu;
- brak profitability przed source cost, allocation, period close i reconciliation;
- brak retire/purge bez zero-use evidence, retencji i restore.

## Dokumenty

- `01_PACKAGE_CATALOG.md` — trwałe identyfikatory i outcome każdego pakietu.
- `02_PACKAGE_DEPENDENCIES.md` — graf, hard/soft dependencies i critical chain.
- `03_REPOSITORY_EXECUTION_ORDER.md` — sposób pracy jedno- i wielorepozytoryjnej.
- `04_PHASE_GATES.md` — bramki wejścia/wyjścia.
- `05_FIRST_IMPLEMENTATION_WAVE.md` — kolejność, WIP i release units pierwszej fali.
- `06`–`10` — widoki typów pakietów i deferred scope.
- `packages/` — pełne specyfikacje 11 pakietów pierwszej fali.

## Ograniczenia

Nazwy tabel i endpointów w dalszych pakietach są kontraktami docelowymi, nie potwierdzeniem aktualnego schematu. `DSC-001/FND-001` muszą ustalić rzeczywiste mapowanie. Pakiet nie może rozpocząć migracji na podstawie nazwy z dokumentu bez profilu DB i dependency inventory.

