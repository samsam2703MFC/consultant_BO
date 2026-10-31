# FND-002 — Test harness, izolowana baza i CI

## Metryka pakietu

| Pole | Wartość |
|---|---|
| typ / faza / rozmiar | Foundation / F1 / M |
| cel biznesowy | wykrywać regresję przed wdrożeniem i dać każdemu dalszemu pakietowi powtarzalną bramę jakości |
| problem | brak potwierdzonej suite, runtime, fixtures i jednej komendy testowej |
| rezultat | deterministyczna komenda uruchamia unit/integration smoke na izolowanej DB w CI |

## Zakres i granice

Wybór/konfiguracja runnera zgodnego z runtime, bootstrap test app/container, clock/UUID/config seams, isolated DB tworzonej przez FND-001, syntetyczne fixtures, test doubles z outbound deny, CI job/artifacts, pierwszy auth/schema/repository smoke. Poza zakresem: pełny ERP-042 i load tests.

## Repozytoria i strategia

App + CI/Infra; Agent tylko jeśli DSC-001 potwierdzi aktywne repo/runtime — wtedy osobny prompt test-client i koordynator. Kolejność: command contract → local runtime → DB bootstrap → fixtures → doubles → CI → documentation.

## Tabele, migracje, endpointy i UI

- Tabele: wszystkie z baseline w ephemeral DB; bez nowych tabel produkcyjnych.
- Migracje: test environment zawsze stosuje FND-001 od empty; seed jako fixtures, nie produkcyjna migration.
- Endpoint/UI: `N/A` dla funkcji biznesowej; test client może wywołać istniejący smoke route tylko w test env.
- Permissions/audit: test credentials/scope syntetyczne; CI secrets minimalne; test log redagowany.

## Testy meta i dane testowe

Harness sam ma test determinism/repeat, clean isolation, failing assertion exits non-zero, DB rollback, outbound block i artifact redaction. Fixtures: dwie organizations, active/disabled user, minimal product/unit, kolejka success/failure — wyłącznie syntetyczne.

## Kryteria akceptacji

1. Jedna udokumentowana komenda działa lokalnie i w CI.
2. Dwa kolejne runy dają ten sam wynik i nie współdzielą DB/state.
3. Failure blokuje merge/release i daje czytelny artifact bez sekretów.
4. Realne outbound connections są domyślnie zabronione.
5. Clean DB używa migration runner; brak ręcznego seed SQL poza fixture contract.
6. Co najmniej schema smoke, DB transaction rollback i jeden application smoke PASS.

## Definition of Done

Backend test bootstrap, CI, fixtures, config/security, documentation i operational ownership complete. UI/business audit/correction `N/A`.

## Ryzyka, rollback i ręczna weryfikacja

- Ryzyka: flaky time/IDs, produkcyjny side effect, tests against wrong DB, wolne CI.
- Rollback: usunąć/wyłączyć wadliwy job bez zmiany produkcji, ale merge gate pozostaje blokowany do forward fix; nie ignorować flaky tests.
- Ręcznie: developer od czystego checkoutu uruchamia command; reviewer wywołuje celowy failure i sprawdza redaction/outbound deny.

## Dokumentacja

Local/CI test guide, fixture conventions, sandbox policy, failure triage i Definition of Ready dla kolejnych pakietów.

