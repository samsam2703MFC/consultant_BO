# DSC-001 — Mapa runtime, deploymentów, danych i konsumentów

## Metryka pakietu

| Pole | Wartość |
|---|---|
| typ / faza / rozmiar | Foundation discovery / F0 / S |
| cel biznesowy | podejmować decyzje reuse/migrate/retire na podstawie aktywnego systemu, nie samego repo |
| problem | entrypoint Agent, bazy, crony, ruch i konsumenci nie są potwierdzeni |
| rezultat | podpisany rejestr każdego komponentu/source/consumer z dowodem i ownerem |

## Zakres i granice

Read-only inventory: App/Agent entrypoints, DNS/deployment/version, DI/routes, workers/cron, databases/schema versions, read/write tables, queues/outbox, external integrations, secrets aliases, traffic/log last seen, owners, retention candidates i test blockers. Poza zakresem: zmiany kodu/schema, wyłączanie cronów, usuwanie danych, aktywne wywołanie realnej integracji.

## Repozytoria i sposób wykonania

Wszystkie repozytoria/środowiska read-only; **jeden prompt koordynujący discovery**, bez promptów mutujących per repo. Dowody z repo muszą zostać zestawione z runtime/DB metadata; brak dostępu ma status `BLOCKED`, nie `inactive`.

## Dane, migracje, endpointy i UI

- Tabele/migracje: `N/A`; schema metadata i counts są tylko odczytywane, wartości biznesowe/PII nie są eksportowane.
- Endpointy/UI: `N/A`; rezultat to wersjonowany rejestr Markdown/CSV bez sekretów.
- Uprawnienia: read-only least privilege; brak dostępu do payload/PII, jeśli niepotrzebny.
- Audit: lista wykonanych zapytań/komend, czas, environment i wykonawca.

## Testy i dane testowe

Completeness checks: każda trasa/DI/cron ma deployment status; każda tabela/outbox ma writer/reader/owner; każda integracja config alias/health/sandbox; każdy kandydat retire ma traffic window. Cross-check repo→runtime→DB, bez testowego side effect. Test data nie dotyczy.

## Kryteria akceptacji

1. App, Agent i każdy worker ma `ACTIVE/INACTIVE/UNKNOWN/BLOCKED` z dowodem.
2. Każdy DB/source/outbox ma producentów, konsumentów i ownera albo jawny brak.
3. Są wskazane wersje schema/deployment i różnice środowisk.
4. Lista danych/PII/retencji i integracji nie ujawnia wartości sekretów.
5. FND-001, FND-003, INT-001 i MIG-001 mają konkretne wejście.

## Definition of Done

Backend/UI/migration/correction `N/A` jako pakiet read-only; test completeness, security, documentation i business/technical sign-off obowiązują.

## Ryzyka, rollback, ręczna weryfikacja

- Ryzyka: fałszywe „inactive”, niepełne log window, produkcyjny side effect, PII exposure.
- Rollback: brak mutacji; usunąć z raportu błędne/wrażliwe dane i dodać sprostowanie zamiast przepisywać dowód.
- Ręcznie: właściciele deploymentów, DBA i integracji potwierdzają status; sponsor akceptuje `UNKNOWN/BLOCKED` jako blokery.

## Dokumentacja

Runtime inventory, source/consumer matrix, environment/schema diff summary, ownership map, blocked checks i evidence commands.

