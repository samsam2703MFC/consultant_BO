# ERP Test Strategy

## Cel

Testy mają dowodzić reguł biznesowych, integralności danych, kontroli dostępu, odporności retry i prawidłowości migracji. Liczba testów nie zastępuje reconciliation z dokumentem kontrolnym.

## Piramida

| Warstwa | Zakres | Przykłady | Kiedy |
|---|---|---|---|
| jednostkowe | state machines, invariants, calculations, policies, transformations | legal transitions, recipe cycle/yield, VAT/price validity, allocation sums, permission policy | każdy commit |
| integracyjne | repozytoria, constraints, transaction/outbox, locks, DB queries | rollback, unique idempotency, FK/scope, concurrent reservation/movement, audit atomicity | każdy PR/CI |
| kontraktowe | adapter request/response, payload version, sandbox/test doubles | POS/order, email, S3, Peppol, webhook timeout/duplicate/backoff | zmiana adaptera/kontraktu |
| E2E | krytyczne pionowe procesy | receipt→stock, sale→document→correction, recipe→production→cost, retry recovery | gate milestone/release |
| migracji | clean install, upgrade, repeat/no-op, backfill, rollback/forward fix, schema diff | legacy ID mapping, opening balance, recipe baseline | każda migracja/cutover |
| danych | profile, DQ, reconciliation, historical stability | count/null/dup/orphan/sums/hash/sample trace | przed/po backfill i cyklicznie |
| regresyjne | mały stabilny critical pack ERP-042 | auth/scope, duplicate retry, ledger balance, tax/numbering, period stability | każdy release |
| niefunkcjonalne | security, concurrency, load, recovery/restore | CSRF/session, cross-tenant, lost update, worker recovery, RTO/RPO | adekwatnie do ryzyka; pełniej F7 |

## Standard scenariusza E2E

Każdy proces krytyczny obejmuje: happy path, validation failure, insufficient permission/wrong scope, stale/concurrent update, partial/timeout, duplicate click/message, cancel/correction/reopen, audit trace, downstream effect, report/reconciliation i rollback/compensation. External effects używają sandboxu/mocków z zakazem realnych credentials.

## Minimalne bramy faz

| Faza | Brama jakości |
|---|---|
| F0 | secret scan; auth negative; smoke aktywnych entrypointów; brak realnego side effect |
| F1 | clean/upgrade/schema diff; deterministic CI; deny/cross-scope/revoke; transaction rollback; audit completeness; duplicate/retry one-effect |
| F2 | uniqueness/FK; temporal overlap; recipe cycle/atomic activation; historical stability; staged import validation/rollback |
| F3 | E2E per vertical slice z partial/cancel/correction/retry; ledger invariant; numbering/VAT; lot genealogy; source reconciliation |
| F4 | action permission/scope, maker-checker, preview hash, double click, batch partial, redaction, compensation |
| F5 | KPI definition/query, rebuild/watermark/freshness, totals/drill-down, role access i performance budget |
| F6 | source→entry→allocation totals, completeness, adjustment, close blockers/reopen, historical report hash |
| F7 | plan-vs-actual, backtest vs naive, bias/drift/shadow, alert precision; load/concurrency/restore/recovery |

`FAIL` bramy blokuje release/cutover. `BLOCKED` wymaga jawnego ownera i nie może być uznany za PASS.

## Środowiska i dane testowe

- izolowana baza odtwarzana z migration baseline;
- syntetyczne fixtures bez danych osobowych i z deterministycznym czasem/UUID;
- seed obejmuje organizacje/scopes, units, tax, product/recipe, sale, receipt, stock, cost i corrections;
- sandbox/mocks dla integracji; outbound allowlist wyłączona domyślnie;
- control pack z zatwierdzonymi dokumentami i totals dla reconciliation;
- test clocks/timezones/currency precision i late-arriving events.

## Testy migracji i danych

Każda migracja przechodzi: empty→latest, previous→latest, repeat/no-op, partial failure/restart, backfill count/sums/hash, rejects, shadow compare, rollback przed cutover lub forward correction po cutover. Schema diff musi być zero względem oczekiwanego modelu. PII/secrets nie trafiają do fixtures/logów.

## Regresja krytyczna ERP-042

Pakiet rośnie wraz z domenami, ale pozostaje mały i deterministyczny. Minimalnie: login/scope/revoke; integration duplicate/retry; receipt→movement; sale→document/correction; production consume/output; cost entry/period; historical snapshot stability; end-to-end audit/correlation and reconciliation.

## Ownership i defect policy

Autor slice’u utrzymuje testy; business owner zatwierdza expected outcome/control totals; security zatwierdza P0 access; DBA/data owner migracje i DQ. Defect naruszający auth, ledger, tax, idempotency, correction lub reconciliation jest release blockerem. Flaky test jest defektem, nie akceptowanym wyjątkiem.

