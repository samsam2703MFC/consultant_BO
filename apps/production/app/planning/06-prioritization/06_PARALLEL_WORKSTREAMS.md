# Strumienie równoległe

## Strumienie programu

| Strumień | Inicjatywy | Może ruszyć | Łączy się w |
|---|---|---|---|
| WS-A Security & platform | 001–008, 039, 042 | natychmiast 001/002 | każda domena |
| WS-B Master data & policy | 009–015, 032 | po 003/005/006 | sale/inventory/production |
| WS-C Sales & documents | 016–018, 025, 036 | po snapshot/price/outbox | 030/031/038/042 |
| WS-D Inventory & purchasing | 019–022, 035 | po master data/snapshot | 023/028/031/042 |
| WS-E Production & workforce | 023–027, 034 | po ledger/recipe/IAM | 030/031/042 |
| WS-F Cost, reporting & control | 028–031, 037/038/041 | po faktach źródłowych | controlling/forecast |
| WS-G Migration & retirement | 002/003/040 | discovery od początku, cutover po replacement | niższy koszt utrzymania |

## Rzeczywista równoległość dla jednej osoby

Jedna osoba nie prowadzi siedmiu strumieni implementacyjnych. Zalecany limit:

- **WIP 1:** jeden pakiet mutujący model lub produkcyjne dane;
- **WIP +1:** mały read-only discovery, dokumentacja decyzji albo test fixture;
- zero równoległych cutoverów danych;
- UAT następnego slice’u może być przygotowywany podczas obserwacji poprzedniego.

## Bezpieczne pary

| Pakiet główny | Praca równoległa | Dlaczego bezpieczna |
|---|---|---|
| ERP-001 remediation | ERP-002 discovery | oba są pilne, drugi read-only |
| ERP-003 baseline | katalog BD/ownerów | decyzje nie zmieniają schematu |
| ERP-005 IAM | inventory audit events dla ERP-006 | projekt kontraktu bez drugiego cutoveru |
| ERP-007 adapter jednego outboxu | read-only projekt Operations Inbox | UI/queries bez mutującego retry |
| ERP-009 kartoteka produktu | analiza jednostek ERP-010 | wspólne modele, jeden późniejszy migration slice |
| ERP-019 shadow ledger | przygotowanie control stocktake | źródło kontrolne, nie drugi zapis |
| ERP-022 receipt slice | definicja supplier KPI ERP-035 | definicja bez dashboardu |
| ERP-023 production pilot | przygotowanie UAT/time mapping | fixtures i role, nie równoległy core |
| ERP-028 source cost type | definicja close checklist | read-only przygotowanie ERP-029 |
| dowolny vertical slice | rozszerzenie ERP-042 | test jest częścią slice’u |

## Konflikty zabraniające równoległości

- IAM App i osobny „docelowy” IAM Agent;
- dwa ledgery inventory albo dwa write cutover dla tego samego produktu/lokalizacji;
- canonical sale i równoległa przebudowa faktur bez decyzji BD-01/11;
- recipe version i production execution na bieżącej recepturze;
- cost ledger i nowy dashboard marży na starym źródle;
- deprecacja oraz aktywna migracja bez okresu obserwacji;
- dwa duże backfille na tej samej bazie bez pomiaru obciążenia.

## Synchronizacja

Raz w tygodniu aktualizować decision log, dependency register, migrację i ryzyka. Po każdym slice: schema diff, reconciliation, E2E, runbook, feature flag owner/expiry i decyzja continue/rollback. Granica domeny jest punktem kontraktu, nie pretekstem do tworzenia osobnego repozytorium.

