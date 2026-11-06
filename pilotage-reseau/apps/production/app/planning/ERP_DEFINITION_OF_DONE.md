# ERP Definition of Done

## Zasada

„Kod gotowy” nie oznacza funkcji gotowej. Poniższa checklista obowiązuje każdy milestone i każdą funkcjonalność; pozycja może być `N/A` tylko z pisemnym uzasadnieniem zaakceptowanym przez ownera i architekta.

## Checklist funkcjonalny

- [ ] Outcome, aktor, owner procesu i zakres organizacyjny są jawne.
- [ ] Happy path, wyjątki, statusy i dozwolone przejścia są zatwierdzone.
- [ ] Cancel/correction/reopen/retry są obsłużone albo udokumentowanie nie dotyczą funkcji.
- [ ] Rezultat można zweryfikować biznesowo i uzgodnić z dokumentem/faktem.
- [ ] UI istnieje dla użytkownika albo brak UI jest uzasadniony (np. worker/adapter).
- [ ] Operacja nie wymaga rutynowego SQL, serwera, skryptu ani programisty.

## Model danych

- [ ] Wskazano source of truth i ownera tabel/encji.
- [ ] Model ma canonical ID, organization scope, business/recorded time i wersję tam, gdzie potrzebna.
- [ ] Constraints DB chronią unikalność, FK, zakresy i invariants możliwe do egzekwowania.
- [ ] Historyczne fakty mają wymagane snapshoty; projekcja jest odbudowywalna.
- [ ] Posted/issued facts są immutable; correction wskazuje oryginał i deltę.
- [ ] Retencja, PII i redakcja są określone.

## Backend i transakcja

- [ ] Application command egzekwuje invariants i nie przyjmuje dowolnego statusu/handlera/tabeli.
- [ ] Granica transakcji obejmuje stan, business/audit event i outbox.
- [ ] Idempotency chroni retry/double click; optimistic locking chroni stale write.
- [ ] Cross-module effect używa publicznego kontraktu/eventu, nie zapisu cudzej tabeli.
- [ ] Błędy są sklasyfikowane i bezpiecznie komunikowane z correlation ID.

## Bezpieczeństwo

- [ ] Uwierzytelnienie jest bezpieczne; brak sekretów/default credentials w kodzie/logu.
- [ ] Permission i scope są sprawdzane server-side, default deny.
- [ ] Testy insufficient permission i cross-tenant/scope przechodzą.
- [ ] HIGH/CRITICAL ma reason, preview, before/after i compensation; maker-checker/step-up zgodnie z polityką.
- [ ] Eksport/payload/audit redaguje sekrety i PII.

## Audyt i operacyjność

- [ ] Audit zapisuje aktora, rolę/sesję, scope, action/permission, object/version, reason/approver, correlation i outcome.
- [ ] Metrics/log/health pozwalają wykryć failure, backlog, latency i brak konsumenta.
- [ ] Retry/dead/cancel i partial failure mają runbook oraz ownera/SLA.
- [ ] Alert jest actionable i nie ujawnia danych wrażliwych.

## Testy i jakość

- [ ] Unit tests pokrywają reguły/statusy/obliczenia i boundary values.
- [ ] Integration tests pokrywają repozytorium, constraints, transakcję/rollback i concurrency.
- [ ] Contract tests pokrywają payload version, timeout, duplicate i test double/sandbox.
- [ ] E2E pokrywa happy, exception oraz correction/cancel/retry.
- [ ] Test migracji: clean install, upgrade, repeat/no-op i schema diff.
- [ ] Test danych: count/null/duplicate/orphan/sums/min-max oraz trace sample.
- [ ] Krytyczna regresja `ERP-042` przechodzi w CI.

## Migracja i wydanie

- [ ] Source/target mapping, transform version, lineage i rejects są zapisane.
- [ ] Backfill i shadow comparison mają control totals oraz zaakceptowane odchylenia.
- [ ] Feature flag ma ownera, scope, telemetrykę i expiry.
- [ ] Rollback przed cutover lub forward compensation po cutover jest przetestowany.
- [ ] Cutover runbook, backup/restore i odpowiedzialność hypercare są gotowe.
- [ ] Stara ścieżka nie jest usuwana przed obserwacją, zero-use evidence i retencją.

## Dokumentacja i odbiór

- [ ] Dokumentacja użytkownika opisuje wynik, błędy, correction/retry i uprawnienia.
- [ ] Runbook operacyjny opisuje monitoring, diagnozę, recovery i escalation.
- [ ] ADR/decision register i source-of-truth map są aktualne.
- [ ] Owner biznesowy zatwierdził UAT i reconciliation; security/data owner tam, gdzie wymagane.
- [ ] Known limitations oraz deferred scope są jawne.

## Statusy

`READY FOR UAT` wymaga wszystkich testów automatycznych i migracji PASS. `DONE` wymaga UAT, reconciliation, dokumentacji i obserwacji bez krytycznego incydentu przez uzgodnione okno. `RETIRED` wymaga dodatkowo zero traffic/read/write, retention decision i restore/archive evidence.

