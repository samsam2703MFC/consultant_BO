# Wejście architektoniczne do priorytetyzacji

## Cel

Dokument nie jest roadmapą. Wskazuje zależności, bramy i jednostki zmiany, które powinny być uwzględnione w późniejszej priorytetyzacji.

## Fundamenty przekrojowe

| Fundament | Odblokowuje | Dowód gotowości |
|---|---|---|
| schema baseline + test environment | każdą bezpieczną migrację | czysta baza/upgrade/schema diff/test runner PASS |
| IAM + organization scope | wszystkie moduły i audit actor | default deny, tenant negative tests, revoke |
| command/audit/idempotency context | korekty, integracje, operacje | identyczny retry = jeden skutek; trace E2E |
| temporal snapshot contract | sprzedaż, receptury, koszt, reporting | zmiana master data nie zmienia historycznego faktu |
| correction/period contract | wszystkie ledgery i raporty | original immutable, adjustment/reopen audytowany |
| outbox/inbox + ops visibility | wszystkie zewnętrzne skutki | duplicate/failure/dead/manual retry test |

## Zdolności źródłowe o najwyższym wpływie

1. recipe version przed wiarygodnym production/cost;
2. stock movement ledger przed reservation/transfer/stocktake/production receipt;
3. canonical sale przed raportami sprzedaży i marżą;
4. production order/events przed KPI produkcji i cost lot;
5. cost entry/period przed rentownością i stabilnym raportem;
6. employee assignment/actual time przed KPI pracy;
7. PO/receipt przed supplier score i material cost.

## Zalecana jednostka zmiany

Priorytetyzować pionowy, testowalny fragment jednej zdolności, np. „receipt → stock movement → audit → read model”, a nie warstwę techniczną wszystkich domen. Wyjątkiem są małe fundamenty wspólne: IAM policy, command context, outbox envelope i audit schema.

Każdy fragment powinien obejmować:

- decyzję i kryterium biznesowe;
- model/status/permission;
- zapis i correction path;
- UI/API właściwego aktora;
- audit/observability;
- migrację/feature flag/reconciliation;
- test happy/exception/retry;
- dotknięty read model/KPI.

## Bramy decyzji

| Brama | Architektura zależna |
|---|---|
| BD-01/11/12 | canonical sale, payment/fiscal, POS sync, documents |
| BD-02/03/05/06 | ledger, lot, QC, FEFO, valuation |
| BD-04 | planning/production order strategy |
| BD-07/09 | correction, approval, period close/reopen |
| BD-08/14 | partial order/delivery/discrepancy workflows |
| BD-10 | tenant keys, deployment/data isolation, numbering |
| BD-13/15 | workforce data, audit/retention/privacy |

## Bramy dowodowe

- potwierdzony status deploymentu Agent przed decyzją reuse/retire;
- metadata i profil DB przed schema consolidation/backfill;
- aktywni konsumenci/trasy/cron przed deprecation;
- zamknięty okres kontrolny przed zmianą raportu;
- fizyczna/inwentaryzacyjna wartość kontrolna przed opening stock;
- sandbox/mocks przed włączeniem adaptera zewnętrznego;
- backup/restore test przed destructive cleanup.

## Czynniki kosztu utrzymania

Przy porównaniu wariantów doliczać: liczbę deploymentów, technologii/runtime, kontraktów sieciowych, schedulerów, baz, sekretów, dashboardów operacyjnych, dyżurów/alertów, migracji danych i niezależnych modeli auth. Wariant z krótszym kodem, ale nowym serwisem/brokerem może być droższy dla jednej osoby niż większy moduł w monolicie.

## Kryteria przyszłego wydzielenia komponentu

Moduł można rozważyć jako osobny serwis dopiero, gdy co najmniej jeden warunek jest potwierdzony metryką: niezależne skalowanie wielokrotnie przekracza resztę systemu, oddzielny wymóg bezpieczeństwa/deploymentu, niezależny zespół/tempo zmian, twarda izolacja awarii albo zewnętrzny produkt z własnym SLA. Sam rozmiar katalogu kodu nie wystarcza.

Broker lub analytics DB można rozważyć po przekroczeniu zmierzonego throughput/latency, niewystarczającej izolacji raportów lub pojawieniu się wielu niezależnych źródeł. Do tego czasu DB outbox i read models w tej samej bazie są wariantem preferowanym.

## Minimalny pakiet wejściowy do późniejszej kolejności

Potrzebne są: decyzje BD, wolumeny/peak/SLA, mapa deploymentu, profil schematu/danych, liczba użytkowników per aplikacja, aktywne integracje/urządzenia, koszt incydentów i ręcznych obejść, prawne deadline'y oraz dostępność właścicieli do UAT. Priorytety ryzyka z analizy luk nie są jeszcze harmonogramem.
